<?php

namespace Drupal\commerce_montonio\Service\PaymentStatus;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_montonio\Service\MontonioLogger;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Manager for payment status processing strategies.
 */
class PaymentStatusStrategyManager {

  /**
   * The available strategies.
   *
   * @var PaymentStatusStrategyInterface[]
   */
  protected array $strategies = [];

  /**
   * Constructs a PaymentStatusStrategyManager object.
   *
   * @param \Drupal\commerce_montonio\Service\MontonioLogger $logger
   *   The Montonio logger service.
   */
  public function __construct(
    protected MontonioLogger $logger,
  ) {}

  /**
   * Adds a strategy to the manager.
   *
   * @param PaymentStatusStrategyInterface $strategy
   *   The strategy to add.
   */
  public function addStrategy(PaymentStatusStrategyInterface $strategy): void {
    $this->strategies[$strategy->getStatus()] = $strategy;
  }

  /**
   * Processes the payment status using the appropriate strategy.
   *
   * @param \Drupal\commerce_montonio\Dto\MontonioTokenDto $token
   *   The decoded Montonio token.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   The payment gateway entity.
   */
  public function processPaymentStatus(
    MontonioTokenDto $token,
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
  ): void {
    $status = $token->getPaymentStatus();

    if (!isset($this->strategies[$status])) {
      $this->logger->error('Unhandled payment status @status for order id @order', [
        '@status' => $status,
        '@order' => $order->id(),
      ]);
      return;
    }

    $this->strategies[$status]->process($token, $order, $gateway);
  }

}

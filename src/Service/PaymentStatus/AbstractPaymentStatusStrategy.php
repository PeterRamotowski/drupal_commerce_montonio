<?php

namespace Drupal\commerce_montonio\Service\PaymentStatus;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_montonio\Repository\PaymentRepositoryInterface;
use Drupal\commerce_montonio\Service\MontonioLogger;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_price\Price;

/**
 * Abstract base class for payment status strategies.
 */
abstract class AbstractPaymentStatusStrategy {

  /**
   * Constructs a new AbstractPaymentStatusStrategy object.
   *
   * @param \Drupal\commerce_montonio\Service\MontonioLogger $logger
   *   The Montonio logger service.
   * @param \Drupal\commerce_montonio\Repository\PaymentRepositoryInterface $paymentRepository
   *   The payment repository.
   */
  public function __construct(
    protected MontonioLogger $logger,
    protected PaymentRepositoryInterface $paymentRepository,
  ) {}

  /**
   * Creates a new payment entity.
   *
   * @param \Drupal\commerce_montonio\Dto\MontonioTokenDto $token
   *   The decoded Montonio token.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   The payment gateway entity.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param string $state
   *   The payment state.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface
   *   The created payment entity.
   */
  protected function createPayment(
    MontonioTokenDto $token,
    PaymentGatewayInterface $gateway,
    OrderInterface $order,
    string $state,
  ) {
    return $this->paymentRepository->create([
      'state' => $state,
      'amount' => new Price($token->getGrandTotal(), $token->getCurrency()),
      'payment_gateway' => $gateway->id(),
      'order_id' => $order->id(),
      'remote_id' => $token->getUuid(),
      'remote_state' => $token->getPaymentStatus(),
    ]);
  }

}

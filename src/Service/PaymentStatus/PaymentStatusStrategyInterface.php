<?php

namespace Drupal\commerce_montonio\Service\PaymentStatus;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Interface for payment status processing strategies.
 */
interface PaymentStatusStrategyInterface {

  /**
   * Processes the payment status for the given token and order.
   *
   * @param \Drupal\commerce_montonio\Dto\MontonioTokenDto $token
   *   The decoded Montonio token.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   The payment gateway entity.
   */
  public function process(
    MontonioTokenDto $token,
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
  ): void;

  /**
   * Returns the status this strategy handles.
   *
   * @return string
   *   The payment status identifier.
   */
  public function getStatus(): string;

}

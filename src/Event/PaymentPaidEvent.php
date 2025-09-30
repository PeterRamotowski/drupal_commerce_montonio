<?php

namespace Drupal\commerce_montonio\Event;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Event dispatched when a payment is marked as paid.
 */
class PaymentPaidEvent extends PaymentStatusEvent {

  /**
   * The event name for payment paid status.
   */
  public const EVENT_NAME = 'commerce_montonio.payment_paid';

  /**
   * Constructs a new PaymentPaidEvent.
   *
   * @param MontonioTokenDto $token
   *   The decoded Montonio token.
   * @param OrderInterface $order
   *   The order entity.
   * @param PaymentGatewayInterface $gateway
   *   The payment gateway entity.
   */
  public function __construct(
    MontonioTokenDto $token,
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
  ) {
    parent::__construct($token, $order, $gateway);
  }

}
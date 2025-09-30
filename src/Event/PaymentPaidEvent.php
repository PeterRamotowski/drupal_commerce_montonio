<?php

namespace Drupal\commerce_montonio\Event;

/**
 * Event dispatched when a payment is marked as paid.
 */
class PaymentPaidEvent extends PaymentStatusEvent {

  /**
   * The event name for payment paid status.
   */
  public const EVENT_NAME = 'commerce_montonio.payment_paid';

}

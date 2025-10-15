<?php

namespace Drupal\commerce_montonio\Event;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Service for dispatching payment-related events.
 */
class PaymentEventDispatcher {

  /**
   * Constructs a new PaymentEventDispatcher object.
   *
   * @param \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The Symfony event dispatcher.
   */
  public function __construct(
    protected EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * Dispatches a payment status change event.
   *
   * @param \Drupal\commerce_montonio\Dto\MontonioTokenDto $token
   *   The decoded Montonio token.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   The payment gateway entity.
   */
  public function dispatchPaymentStatusEvent(
    MontonioTokenDto $token,
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
  ): void {
    $event = new PaymentStatusEvent($token, $order, $gateway);
    $this->eventDispatcher->dispatch($event, PaymentStatusEvent::PAYMENT_STATUS_CHANGED);

    $this->dispatchSpecificPaymentEvent($token, $order, $gateway);
  }

  /**
   * Dispatches a specific payment status event.
   *
   * @param \Drupal\commerce_montonio\Dto\MontonioTokenDto $token
   *   The decoded Montonio token.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   The payment gateway entity.
   */
  protected function dispatchSpecificPaymentEvent(
    MontonioTokenDto $token,
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
  ): void {
    switch ($token->getPaymentStatus()) {
      case 'PAID':
        $event = new PaymentPaidEvent($token, $order, $gateway);
        $this->eventDispatcher->dispatch($event, PaymentPaidEvent::EVENT_NAME);
        break;
    }
  }

}

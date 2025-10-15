<?php

namespace Drupal\commerce_montonio\Event;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when a payment status changes via webhook.
 */
class PaymentStatusEvent extends Event {

  /**
   * The event name for payment status changes.
   */
  public const PAYMENT_STATUS_CHANGED = 'commerce_montonio.payment_status_changed';

  /**
   * Constructs a new PaymentStatusEvent object.
   *
   * @param \Drupal\commerce_montonio\Dto\MontonioTokenDto $token
   *   The decoded Montonio token.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   The payment gateway entity.
   */
  public function __construct(
    protected MontonioTokenDto $token,
    protected OrderInterface $order,
    protected PaymentGatewayInterface $gateway,
  ) {}

  /**
   * Gets the Montonio token.
   */
  public function getToken(): MontonioTokenDto {
    return $this->token;
  }

  /**
   * Gets the order.
   */
  public function getOrder(): OrderInterface {
    return $this->order;
  }

  /**
   * Gets the payment gateway.
   */
  public function getPaymentGateway(): PaymentGatewayInterface {
    return $this->gateway;
  }

  /**
   * Gets the payment status.
   */
  public function getPaymentStatus(): string {
    return $this->token->getPaymentStatus();
  }

  /**
   * Gets the order UUID.
   */
  public function getOrderUuid(): ?string {
    return $this->token->getUuid();
  }

  /**
   * Gets the grand total.
   */
  public function getGrandTotal(): ?string {
    return $this->token->getGrandTotal();
  }

  /**
   * Gets the currency.
   */
  public function getCurrency(): ?string {
    return $this->token->getCurrency();
  }

}

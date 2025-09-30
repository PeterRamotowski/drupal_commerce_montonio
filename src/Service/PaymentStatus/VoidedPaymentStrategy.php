<?php

namespace Drupal\commerce_montonio\Service\PaymentStatus;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Strategy for handling VOIDED payment status.
 */
class VoidedPaymentStrategy extends AbstractPaymentStatusStrategy implements PaymentStatusStrategyInterface {

  /**
   * {@inheritdoc}
   */
  public function process(
    MontonioTokenDto $token,
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
  ): void {
    $payment = $this->paymentRepository->findByRemoteIdAndOrderId($token->getUuid(), $order->id());

    if (!$payment) {
      return;
    }

    $payment->setState('voided');
    $payment->setRemoteState($token->getPaymentStatus());
    $this->paymentRepository->save($payment);

    $this->logger->warning('Payment @payment was voided by the bank for order id @order', [
      '@payment' => $payment->id(),
      '@order' => $order->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return 'VOIDED';
  }

}

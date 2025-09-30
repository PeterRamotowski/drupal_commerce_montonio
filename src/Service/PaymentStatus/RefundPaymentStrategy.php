<?php

namespace Drupal\commerce_montonio\Service\PaymentStatus;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Strategy for handling PARTIALLY_REFUNDED and REFUNDED payment statuses.
 */
class RefundPaymentStrategy extends AbstractPaymentStatusStrategy implements PaymentStatusStrategyInterface {

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

    $newState = $token->getPaymentStatus() === 'PARTIALLY_REFUNDED' ? 'partially_refunded' : 'refunded';
    $payment->setState($newState);
    $payment->setRemoteState($token->getPaymentStatus());
    $this->paymentRepository->save($payment);

    $this->logger->info('Payment @payment updated to @status', [
      '@payment' => $payment->id(),
      '@status' => $token->getPaymentStatus(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return 'REFUND';
  }

}

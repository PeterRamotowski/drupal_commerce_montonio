<?php

namespace Drupal\commerce_montonio\Service\PaymentStatus;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Strategy for handling fully refunded payments.
 */
class RefundedPaymentStrategy extends AbstractRefundPaymentStrategy implements PaymentStatusStrategyInterface {

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

    $refundAmount = $this->resolveRefundPrice($token) ?? $payment->getAmount();
    if ($refundAmount) {
      $payment->setRefundedAmount($refundAmount);
    }

    $payment->setState('refunded');
    $payment->setRemoteState($token->getPaymentStatus());
    $this->paymentRepository->save($payment);

    $this->logger->info('Payment @payment marked as refunded for order id @order', [
      '@payment' => $payment->id(),
      '@order' => $order->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return 'REFUNDED';
  }

}

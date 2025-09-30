<?php

namespace Drupal\commerce_montonio\Service\PaymentStatus;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Strategy for handling ABANDONED payment status.
 */
class AbandonedPaymentStrategy extends AbstractPaymentStatusStrategy implements PaymentStatusStrategyInterface {

  /**
   * {@inheritdoc}
   */
  public function process(
    MontonioTokenDto $token,
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
  ): void {
    $payments = $this->paymentRepository->findByOrderIdAndState($order->id(), 'new');

    foreach ($payments as $payment) {
      if ($payment->getRemoteId() === $token->getUuid()) {
        $payment->setState('canceled');
        $payment->setRemoteState($token->getPaymentStatus());
        $this->paymentRepository->save($payment);

        $this->logger->info('Payment @payment marked as abandoned for order id @order', [
          '@payment' => $payment->id(),
          '@order' => $order->id(),
        ]);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return 'ABANDONED';
  }

}

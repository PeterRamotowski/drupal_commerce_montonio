<?php

namespace Drupal\commerce_montonio\Service\PaymentStatus;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Strategy for handling AUTHORIZED payment status.
 */
class AuthorizedPaymentStrategy extends AbstractPaymentStatusStrategy implements PaymentStatusStrategyInterface {

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
      $payment = $this->createPayment($token, $gateway, $order, 'authorization');
      $this->paymentRepository->save($payment);

      $this->logger->info('Payment authorized for order id @order, amount: @amount', [
        '@order' => $order->id(),
        '@amount' => $token->getGrandTotal() . ' ' . $token->getCurrency(),
      ]);
    }
    else {
      if ($payment->getState()->getId() === 'new') {
        $payment->setState('authorization');
        $payment->setRemoteState($token->getPaymentStatus());
        $this->paymentRepository->save($payment);
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return 'AUTHORIZED';
  }

}

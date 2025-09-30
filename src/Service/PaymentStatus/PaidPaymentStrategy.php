<?php

namespace Drupal\commerce_montonio\Service\PaymentStatus;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;

/**
 * Strategy for handling PAID payment status.
 */
class PaidPaymentStrategy extends AbstractPaymentStatusStrategy implements PaymentStatusStrategyInterface {

  /**
   * {@inheritdoc}
   */
  public function process(
    MontonioTokenDto $token,
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
  ): void {
    if ($this->paymentRepository->paymentExists($token->getUuid(), $order->id())) {
      $this->logger->info('Payment already exists for order id @order, skipping creation', [
        '@order' => $order->id(),
      ]);
      return;
    }

    $payment = $this->createPayment($token, $gateway, $order, 'completed');
    $this->paymentRepository->save($payment);

    $this->logger->info('Payment created for order id @order, amount: @amount', [
      '@order' => $order->id(),
      '@amount' => $token->getGrandTotal() . ' ' . $token->getCurrency(),
    ]);

    // Transition order to placed state if it's still a draft.
    if ($order->getState()->getId() === 'draft') {
      $order->getState()->applyTransitionById('place');
      $order->unlock();
      $order->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return 'PAID';
  }

}

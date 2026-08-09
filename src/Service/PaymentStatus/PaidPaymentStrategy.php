<?php

namespace Drupal\commerce_montonio\Service\PaymentStatus;

use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_montonio\Repository\PaymentRepositoryInterface;
use Drupal\commerce_montonio\Service\MontonioLogger;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\Lock\LockBackendInterface;

/**
 * Strategy for handling PAID payment status.
 */
class PaidPaymentStrategy extends AbstractPaymentStatusStrategy implements PaymentStatusStrategyInterface {

  /**
   * Constructs a new PaidPaymentStrategy object.
   *
   * @param \Drupal\commerce_montonio\Service\MontonioLogger $logger
   *   The Montonio logger.
   * @param \Drupal\commerce_montonio\Repository\PaymentRepositoryInterface $paymentRepository
   *   The payment repository.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The lock backend for atomic duplicate prevention.
   */
  public function __construct(
    MontonioLogger $logger,
    PaymentRepositoryInterface $paymentRepository,
    protected LockBackendInterface $lock,
  ) {
    parent::__construct($logger, $paymentRepository);
  }

  /**
   * {@inheritdoc}
   */
  public function process(
    MontonioTokenDto $token,
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
  ): void {
    $lockName = 'commerce_montonio:webhook:' . $order->id() . ':' . $token->getUuid();

    if (!$this->lock->acquire($lockName, 30.0)) {
      $this->logger->warning(
        'Concurrent webhook processing detected for order @order, remote ID @id. Skipping.',
        ['@order' => $order->id(), '@id' => $token->getUuid()],
      );
      return;
    }

    try {
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
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return 'PAID';
  }

}

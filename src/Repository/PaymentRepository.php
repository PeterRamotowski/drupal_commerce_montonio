<?php

namespace Drupal\commerce_montonio\Repository;

use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Repository for payment operations.
 */
class PaymentRepository implements PaymentRepositoryInterface {

  /**
   * Constructs a new PaymentRepository object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function findByRemoteIdAndOrderId(string $remoteId, int $orderId): ?PaymentInterface {
    $payments = $this->entityTypeManager
      ->getStorage('commerce_payment')
      ->loadByProperties([
        'remote_id' => $remoteId,
        'order_id' => $orderId,
      ]);

    if (empty($payments)) {
      return NULL;
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = reset($payments);

    return $payment;
  }

  /**
   * {@inheritdoc}
   */
  public function findByOrderId(int $orderId): array {
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface[] $payments */
    $payments = $this->entityTypeManager
      ->getStorage('commerce_payment')
      ->loadByProperties([
        'order_id' => $orderId,
      ]);
    return $payments;
  }

  /**
   * {@inheritdoc}
   */
  public function findByOrderIdAndState(int $orderId, string $state): array {
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface[] $payments */
    $payments = $this->entityTypeManager
      ->getStorage('commerce_payment')
      ->loadByProperties([
        'order_id' => $orderId,
        'state' => $state,
      ]);
    return $payments;
  }

  /**
   * {@inheritdoc}
   */
  public function create(array $paymentData): PaymentInterface {
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entityTypeManager
      ->getStorage('commerce_payment')
      ->create($paymentData);
    return $payment;
  }

  /**
   * {@inheritdoc}
   */
  public function save(PaymentInterface $payment): void {
    $payment->save();
  }

  /**
   * {@inheritdoc}
   */
  public function paymentExists(string $remoteId, int $orderId): bool {
    return $this->findByRemoteIdAndOrderId($remoteId, $orderId) !== NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function findCompletedByOrderIdAndPaymentGateway(int $orderId, string $paymentGatewayId): array {
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface[] $payments */
    $payments = $this->entityTypeManager
      ->getStorage('commerce_payment')
      ->loadByProperties([
        'order_id' => $orderId,
        'payment_gateway' => $paymentGatewayId,
        'state' => 'completed',
      ]);
    return $payments;
  }

}

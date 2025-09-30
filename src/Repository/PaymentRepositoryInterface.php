<?php

namespace Drupal\commerce_montonio\Repository;

use Drupal\commerce_payment\Entity\PaymentInterface;

/**
 * Interface for payment repository operations.
 */
interface PaymentRepositoryInterface {

  /**
   * Finds a payment by remote ID and order ID.
   *
   * @param string $remoteId
   *   The remote payment ID.
   * @param int $orderId
   *   The order ID.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface|null
   *   The payment entity or NULL if not found.
   */
  public function findByRemoteIdAndOrderId(string $remoteId, int $orderId): ?PaymentInterface;

  /**
   * Finds payments by order ID.
   *
   * @param int $orderId
   *   The order ID.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface[]
   *   Array of payment entities.
   */
  public function findByOrderId(int $orderId): array;

  /**
   * Finds payments by order ID and state.
   *
   * @param int $orderId
   *   The order ID.
   * @param string $state
   *   The payment state.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface[]
   *   Array of payment entities.
   */
  public function findByOrderIdAndState(int $orderId, string $state): array;

  /**
   * Creates a new payment entity.
   *
   * @param array $paymentData
   *   The payment data.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface
   *   The created payment entity.
   */
  public function create(array $paymentData): PaymentInterface;

  /**
   * Saves a payment entity.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment entity to save.
   */
  public function save(PaymentInterface $payment): void;

  /**
   * Checks if a payment already exists for the given remote ID and order.
   *
   * @param string $remoteId
   *   The remote payment ID.
   * @param int $orderId
   *   The order ID.
   *
   * @return bool
   *   TRUE if payment exists, FALSE otherwise.
   */
  public function paymentExists(string $remoteId, int $orderId): bool;

  /**
   * Finds completed payments by order ID and payment gateway.
   *
   * @param int $orderId
   *   The order ID.
   * @param string $paymentGatewayId
   *   The payment gateway ID.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentInterface[]
   *   Array of completed payment entities.
   */
  public function findCompletedByOrderIdAndPaymentGateway(int $orderId, string $paymentGatewayId): array;

}

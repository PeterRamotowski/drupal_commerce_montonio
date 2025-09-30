<?php

namespace Drupal\commerce_montonio\Repository;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Repository for order operations.
 */
class OrderRepository implements OrderRepositoryInterface {

  /**
   * The order storage service.
   */
  protected EntityStorageInterface $orderStorage;

  /**
   * Constructs a new OrderRepository.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->orderStorage = $entityTypeManager->getStorage('commerce_order');
  }

  /**
   * {@inheritdoc}
   */
  public function findOrderByReference(string $merchantReference): ?OrderInterface {
    $orders = $this->orderStorage->loadByProperties([
      'order_number' => $merchantReference,
    ]);

    if (empty($orders)) {
      return NULL;
    }

    $order = reset($orders);
    return $order instanceof OrderInterface ? $order : NULL;
  }

}

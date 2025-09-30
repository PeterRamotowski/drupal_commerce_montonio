<?php

namespace Drupal\commerce_montonio\Repository;

use Drupal\commerce_order\Entity\OrderInterface;

/**
 * Interface for order repository operations.
 */
interface OrderRepositoryInterface {

  /**
   * Finds an order by its merchant reference (order number).
   *
   * @param string $merchantReference
   *   The merchant reference to search for.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The order entity or NULL if not found.
   */
  public function findOrderByReference(string $merchantReference): ?OrderInterface;

}

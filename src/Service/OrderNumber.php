<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Service to set the order number for Commerce orders.
 */
class OrderNumber {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Sets the order number.
   *
   * The number is generated using the number pattern specified by the
   * order type. If no number pattern was specified, the order ID is
   * used as a fallback.
   *
   * Skipped if the order number has already been set.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   */
  public function setOrderNumber(OrderInterface $order) {
    if (!$order->getOrderNumber()) {
      $orderTypeStorage = $this->entityTypeManager->getStorage('commerce_order_type');
      /** @var \Drupal\commerce_order\Entity\OrderTypeInterface $orderType */
      $orderType = $orderTypeStorage->load($order->bundle());
      /** @var \Drupal\commerce_number_pattern\Entity\NumberPatternInterface $numberPattern */
      $numberPattern = $orderType->getNumberPattern();
      if ($numberPattern) {
        $orderNumber = $numberPattern->getPlugin()->generate($order);
      }
      else {
        $orderNumber = $order->id();
      }

      $order->setOrderNumber($orderNumber);
    }
  }

}

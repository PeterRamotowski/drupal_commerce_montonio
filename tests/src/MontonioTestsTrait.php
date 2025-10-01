<?php

namespace Drupal\Tests\commerce_montonio;

use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_order\Entity\OrderItem;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\commerce_price\Price;
use Drupal\commerce_product\Entity\Product;
use Drupal\commerce_product\Entity\ProductVariation;
use Drupal\commerce_product\Entity\ProductVariationInterface;
use Drupal\commerce_store\Entity\StoreInterface;
use Drupal\commerce_store\StoreCreationTrait;
use Drupal\profile\Entity\Profile;
use Drupal\Tests\user\Traits\UserCreationTrait;

/**
 * Trait for Montonio tests.
 */
trait MontonioTestsTrait {

  use StoreCreationTrait;
  use UserCreationTrait;

  /**
   * The API key for testing Montonio.
   *
   * @var string
   */
  public static $accessKey = '123';

  /**
   * The signature key for Montonio.
   *
   * @var string
   */
  public static $signatureKey = '456';

  /**
   * Creates a payment gateway for testing.
   *
   * @return \Drupal\commerce_payment\Entity\PaymentGatewayInterface
   *   The created payment gateway.
   */
  protected function createTestPaymentGateway() {
    $gateway = PaymentGateway::create([
      'id' => 'montonio_test',
      'label' => 'montonio Test',
      'plugin' => 'montonio_redirect_checkout',
    ]);
    $gateway->getPlugin()->setConfiguration([
      'access_key' => self::$accessKey,
      'secret_key' => self::$signatureKey,
      'default_payment_method' => 'blik',
      'enabled_payment_methods' => [],
    ]);
    $gateway->save();

    return $gateway;
  }

  /**
   * Creates a test product.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The store.
   *
   * @return \Drupal\commerce_product\Entity\ProductVariationInterface
   *   The test product.
   */
  protected function createTestProduct(?StoreInterface $store = NULL): ProductVariationInterface {
    if (!$store) {
      $store = $this->createStore();
    }

    $variation = ProductVariation::create([
      'type' => 'default',
      'sku' => 'test-product',
      'title' => 'Test Product',
      'price' => new Price('29.99', 'PLN'),
    ]);
    $variation->save();

    $product = Product::create([
      'type' => 'default',
      'title' => 'Test Product',
      'variations' => [$variation],
      'stores' => [$store],
    ]);
    $product->save();

    $variation = ProductVariation::load($variation->id());

    return $variation;
  }

  /**
   * Creates a test order with items.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface|null $store
   *   The store.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface
   *   The test order.
   */
  protected function createTestOrder(?StoreInterface $store = NULL): OrderInterface {
    $variation = $this->createTestProduct($store);

    $orderItem = OrderItem::create([
      'type' => 'default',
      'purchased_entity' => $variation,
      'quantity' => 2,
      'unit_price' => $variation->getPrice(),
    ]);
    $orderItem->save();

    $profile = Profile::create([
      'type' => 'customer',
      'address' => [
        'country_code' => 'PL',
        'administrative_area' => 'MZ',
        'locality' => 'Warsaw',
        'postal_code' => '00124',
        'address_line1' => 'ONZ 1',
        'given_name' => 'Jan',
        'family_name' => 'Dudek',
      ],
    ]);
    $profile->save();

    $order = Order::create([
      'type' => 'default',
      'store_id' => $store?->id() ?? 1,
      'order_items' => [$orderItem],
      'state' => 'draft',
      'mail' => 'test@example.com',
      'billing_profile' => $profile,
      'placed' => time(),
      'order_number' => '12345',
    ]);
    $order->save();

    return $order;
  }

}

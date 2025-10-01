<?php

namespace Drupal\Tests\commerce_montonio\Functional;

use Drupal\Core\Url;
use Drupal\Tests\commerce\Functional\CommerceBrowserTestBase;
use Drupal\Tests\commerce_montonio\MontonioTestsTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;

/**
 * Tests the Montonio checkout process.
 */
#[Group('commerce_montonio')]
#[IgnoreDeprecations]
class MontonioCheckoutTest extends CommerceBrowserTestBase {

  use MontonioTestsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'address',
    'commerce',
    'commerce_cart',
    'commerce_checkout',
    'commerce_number_pattern',
    'commerce_order',
    'commerce_payment',
    'commerce_price',
    'commerce_product',
    'commerce_store',
    'entity',
    'entity_reference_revisions',
    'field',
    'filter',
    'inline_entity_form',
    'language',
    'options',
    'path',
    'path_alias',
    'profile',
    'state_machine',
    'system',
    'text',
    'user',
    'views',
    'commerce_montonio',
  ];

  /**
   * The payment gateway.
   *
   * @var \Drupal\commerce_payment\Entity\PaymentGatewayInterface
   */
  protected $gateway;

  /**
   * A test product.
   *
   * @var \Drupal\commerce_product\Entity\ProductInterface
   */
  protected $product;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $currency_importer = $this->container->get('commerce_price.currency_importer');
    $currency_importer->import('PLN');

    $this->store->set('default_currency', 'PLN');
    $this->store->set('billing_countries', ['PL']);
    $this->store->save();

    $this->gateway = $this->createTestPaymentGateway();
    $this->createTestProduct($this->store);
  }

  /**
   * Tests that the payment gateway configuration is accessible.
   */
  public function testPaymentGatewayConfiguration() {
    // Test that the payment gateway has the expected configuration.
    $plugin = $this->gateway->getPlugin();
    $configuration = $plugin->getConfiguration();

    $this->assertArrayHasKey('access_key', $configuration);
    $this->assertArrayHasKey('secret_key', $configuration);
    $this->assertArrayHasKey('default_payment_method', $configuration);
    $this->assertArrayHasKey('enabled_payment_methods', $configuration);

    $this->assertEquals('blik', $configuration['default_payment_method']);
    $this->assertIsArray($configuration['enabled_payment_methods']);
  }

  /**
   * Tests basic checkout flow with Montonio payment gateway.
   */
  public function testBasicCheckoutFlow() {
    $this->drupalGet('/product/1');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->buttonExists('Add to cart');
    $this->submitForm([], 'Add to cart');

    $this->drupalGet('/cart');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Test Product');

    $this->submitForm([], 'Checkout');
    $this->assertSession()->statusCodeEquals(200);

    $this->assertSession()->fieldExists('payment_information[billing_information][address][0][address][given_name]');

    $this->getSession()->getPage()->fillField('payment_information[billing_information][address][0][address][given_name]', 'Jan');
    $this->getSession()->getPage()->fillField('payment_information[billing_information][address][0][address][family_name]', 'Dudek');
    $this->getSession()->getPage()->fillField('payment_information[billing_information][address][0][address][address_line1]', 'ONZ 1');
    $this->getSession()->getPage()->fillField('payment_information[billing_information][address][0][address][locality]', 'Warsaw');
    $this->getSession()->getPage()->fillField('payment_information[billing_information][address][0][address][postal_code]', '00-124');

    $this->submitForm([], 'Continue to review');

    $this->assertSession()->pageTextContains('Montonio');
  }

  /**
   * Tests that the payment gateway entity exists and is configured.
   */
  public function testPaymentGatewayEntity() {
    $this->assertNotNull($this->gateway);
    $this->assertEquals('montonio_test', $this->gateway->id());
    $this->assertEquals('montonio_redirect_checkout', $this->gateway->getPluginId());

    $plugin = $this->gateway->getPlugin();
    $this->assertNotNull($plugin);

    $configuration = $plugin->getConfiguration();
    $this->assertArrayHasKey('access_key', $configuration);
    $this->assertArrayHasKey('secret_key', $configuration);
  }

  /**
   * Tests that products can be added to cart.
   */
  public function testAddToCart() {
    $this->drupalGet('/product/1');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Test Product');
    $this->assertSession()->buttonExists('Add to cart');

    $this->submitForm([], 'Add to cart');

    $this->drupalGet('/cart');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Test Product');
    $this->assertSession()->pageTextContains('29.99');
  }

  /**
   * Tests successful payment return URL structure.
   */
  public function testSuccessfulPaymentReturn() {
    $order = $this->createTestOrder($this->store);
    $order->set('state', 'draft');
    $order->set('checkout_step', 'payment');
    $order->save();

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->container->get('entity_type.manager')
      ->getStorage('commerce_payment')
      ->create([
        'state' => 'new',
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $this->gateway->id(),
        'order_id' => $order->id(),
      ]);
    $payment->save();

    $return_url = Url::fromRoute('commerce_payment.checkout.return', [
      'commerce_order' => $order->id(),
      'step' => 'payment',
    ], [
      'absolute' => TRUE,
    ]);

    // Verify the URL is properly structured.
    $url_string = $return_url->toString();
    $this->assertStringContainsString('checkout', $url_string);
    $this->assertStringContainsString((string) $order->id(), $url_string);
    $this->assertStringContainsString('payment', $url_string);

    // Verify order is in correct state for payment return.
    $this->assertEquals('draft', $order->getState()->getId());
    $this->assertEquals('payment', $order->get('checkout_step')->value);
  }

  /**
   * Tests payment cancellation URL structure.
   */
  public function testPaymentCancellation() {
    $order = $this->createTestOrder($this->store);
    $order->set('state', 'draft');
    $order->set('checkout_step', 'payment');
    $order->save();

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->container->get('entity_type.manager')
      ->getStorage('commerce_payment')
      ->create([
        'state' => 'new',
        'amount' => $order->getTotalPrice(),
        'payment_gateway' => $this->gateway->id(),
        'order_id' => $order->id(),
      ]);
    $payment->save();

    $cancel_url = Url::fromRoute('commerce_payment.checkout.cancel', [
      'commerce_order' => $order->id(),
      'step' => 'payment',
    ], [
      'absolute' => TRUE,
    ]);

    $url_string = $cancel_url->toString();
    $this->assertStringContainsString('checkout', $url_string);
    $this->assertStringContainsString((string) $order->id(), $url_string);
    $this->assertStringContainsString('cancel', $url_string);

    // Verify payment is in correct initial state.
    $this->assertEquals('new', $payment->getState()->getId());
    $this->assertEquals($order->id(), $payment->getOrderId());
  }

  /**
   * Tests the checkout flow.
   */
  public function testCompleteCheckoutFlow() {
    $this->drupalGet('/product/1');
    $this->submitForm([], 'Add to cart');

    $this->drupalGet('/cart');
    $this->assertSession()->pageTextContains('Test Product');
    $this->submitForm([], 'Checkout');

    $this->getSession()->getPage()->fillField('payment_information[billing_information][address][0][address][given_name]', 'Jan');
    $this->getSession()->getPage()->fillField('payment_information[billing_information][address][0][address][family_name]', 'Dudek');
    $this->getSession()->getPage()->fillField('payment_information[billing_information][address][0][address][address_line1]', 'ONZ 1');
    $this->getSession()->getPage()->fillField('payment_information[billing_information][address][0][address][locality]', 'Warsaw');
    $this->getSession()->getPage()->fillField('payment_information[billing_information][address][0][address][postal_code]', '00-124');

    $this->submitForm([], 'Continue to review');

    // Should reach the review/payment step.
    $this->assertSession()->statusCodeEquals(200);

    // Verify we're on a checkout page.
    $current_url = $this->getSession()->getCurrentUrl();
    $this->assertStringContainsString('checkout', $current_url);
  }

}

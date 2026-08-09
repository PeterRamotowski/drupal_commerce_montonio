<?php

namespace Drupal\Tests\commerce_montonio\Kernel;

use Drupal\commerce_montonio\Controller\MontonioWebhookController;
use Drupal\commerce_montonio\Dto\MontonioTokenDto;
use Drupal\commerce_montonio\Service\MontonioApiClient;
use Drupal\commerce_montonio\Service\MontonioApiClientFactory;
use Drupal\commerce_order\Entity\Order;
use Drupal\commerce_payment\Entity\Payment;
use Drupal\commerce_payment\Entity\PaymentGateway;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\commerce_montonio\MontonioTestsTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the Montonio webhook handling.
 */
#[Group('commerce_montonio')]
#[IgnoreDeprecations]
class MontonioWebhookTest extends KernelTestBase {

  use ProphecyTrait;
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
   * The test order.
   *
   * @var \Drupal\commerce_order\Entity\OrderInterface
   */
  protected $order;

  /**
   * The mocked API client.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $mockedApiClient;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('commerce_store');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_order_item');
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_payment_gateway');
    $this->installConfig(['commerce_order', 'commerce_payment']);

    $currency_importer = $this->container->get('commerce_price.currency_importer');
    $currency_importer->import('EUR');

    $this->mockedApiClient = $this->prophesize(MontonioApiClient::class);

    // Mock the factory to return the mocked API client.
    $mockFactory = $this->prophesize(MontonioApiClientFactory::class);
    $mockFactory->createFromPaymentGateway(Argument::any())
      ->willReturn($this->mockedApiClient->reveal());
    $this->container->set('commerce_montonio.api_client_factory', $mockFactory->reveal());

    $this->gateway = PaymentGateway::create([
      'id' => 'montonio_test',
      'label' => 'Montonio Test',
      'plugin' => 'montonio_redirect_checkout',
      'configuration' => [
        'access_key' => 'test_access_key',
        'secret_key' => 'test_secret_key',
        'mode' => 'test',
      ],
      'status' => TRUE,
    ]);
    $this->gateway->save();

    $this->order = Order::create([
      'type' => 'default',
      'store_id' => 1,
      'uid' => 1,
      'order_number' => '12345',
      'order_items' => [],
      'state' => 'draft',
    ]);
    $this->order->save();
  }

  /**
   * Tests successful webhook notification processing.
   */
  public function testSuccessfulWebhookNotification() {
    $decodedTokenMock = $this->prophesize(MontonioTokenDto::class);
    $decodedTokenMock->getUuid()->willReturn('test_montonio_uuid');
    $decodedTokenMock->getPaymentStatus()->willReturn('PAID');
    $decodedTokenMock->getGrandTotal()->willReturn('99.99');
    $decodedTokenMock->getCurrency()->willReturn('EUR');
    $decodedTokenMock->getMerchantReference()->willReturn('12345');
    $decodedTokenMock->getAccessKey()->willReturn('test_access_key');

    $this->mockedApiClient->decodeToken('mock_jwt_token')
      ->willReturn($decodedTokenMock->reveal())
      ->shouldBeCalled();

    $request = Request::create(
      '/payment/notify/montonio_test',
      'GET',
      ['order-token' => 'mock_jwt_token'],
      [],
      [],
      ['REMOTE_ADDR' => '35.156.245.42']
    );

    $controller = MontonioWebhookController::create($this->container);
    $response = $controller->handle($request, $this->gateway);

    $this->assertEquals(200, $response->getStatusCode());

    $payments = \Drupal::entityTypeManager()
      ->getStorage('commerce_payment')
      ->loadByProperties([
        'remote_id' => 'test_montonio_uuid',
        'order_id' => $this->order->id(),
      ]);

    $this->assertCount(1, $payments);
    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = reset($payments);
    $this->assertEquals('completed', $payment->getState()->getId());
  }

  /**
   * Tests webhook notification with invalid token.
   */
  public function testWebhookNotificationInvalidToken() {
    $this->mockedApiClient->decodeToken('invalid_token')
      ->willThrow(new \Exception('Invalid token'))
      ->shouldBeCalled();

    $request = Request::create(
      '/payment/notify/montonio_test',
      'GET',
      ['order-token' => 'invalid_token'],
      [],
      [],
      ['REMOTE_ADDR' => '35.156.245.42']
    );

    $controller = MontonioWebhookController::create($this->container);
    $response = $controller->handle($request, $this->gateway);

    $this->assertEquals(500, $response->getStatusCode());

    $payments = \Drupal::entityTypeManager()
      ->getStorage('commerce_payment')
      ->loadByProperties([
        'order_id' => $this->order->id(),
      ]);

    $this->assertCount(0, $payments);
  }

  /**
   * Tests webhook notification without duplicate payment creation.
   */
  public function testWebhookNotificationNoDuplicates() {
    $existing_payment = Payment::create([
      'type' => 'payment_default',
      'payment_gateway' => $this->gateway->id(),
      'order_id' => $this->order->id(),
      'remote_id' => 'test_montonio_uuid',
      'amount' => ['number' => '99.99', 'currency_code' => 'EUR'],
      'state' => 'completed',
    ]);
    $existing_payment->save();

    $decodedTokenMock = $this->prophesize(MontonioTokenDto::class);
    $decodedTokenMock->getUuid()->willReturn('test_montonio_uuid');
    $decodedTokenMock->getPaymentStatus()->willReturn('PAID');
    $decodedTokenMock->getGrandTotal()->willReturn('99.99');
    $decodedTokenMock->getCurrency()->willReturn('EUR');
    $decodedTokenMock->getMerchantReference()->willReturn('12345');
    $decodedTokenMock->getAccessKey()->willReturn('test_access_key');

    $this->mockedApiClient->decodeToken('mock_jwt_token')
      ->willReturn($decodedTokenMock->reveal())
      ->shouldBeCalled();

    $request = Request::create(
      '/payment/notify/montonio_test',
      'GET',
      ['order-token' => 'mock_jwt_token'],
      [],
      [],
      ['REMOTE_ADDR' => '35.156.245.42']
    );

    $controller = MontonioWebhookController::create($this->container);
    $response = $controller->handle($request, $this->gateway);

    $this->assertEquals(200, $response->getStatusCode());

    $payments = \Drupal::entityTypeManager()
      ->getStorage('commerce_payment')
      ->loadByProperties([
        'remote_id' => 'test_montonio_uuid',
        'order_id' => $this->order->id(),
      ]);

    $this->assertCount(1, $payments);
  }

}

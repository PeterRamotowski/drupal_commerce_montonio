<?php

namespace Drupal\Tests\commerce_montonio\Unit;

use Drupal\commerce_montonio\Service\MontonioApiClient;
use Drupal\commerce_montonio\Service\MontonioJwtService;
use Drupal\commerce_montonio\Service\MontonioLogger;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Psr\Http\Message\RequestInterface;

/**
 * Unit tests for the Montonio API client.
 */
#[Group('commerce_montonio')]
#[IgnoreDeprecations]
class MontonioApiClientTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The mocked HTTP client.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $httpClient;

  /**
   * The mocked logger channel.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $loggerChannel;

  /**
   * The mocked logger factory.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $loggerFactory;

  /**
   * The API client under test.
   *
   * @var \Drupal\commerce_montonio\Service\MontonioApiClient
   */
  protected $apiClient;

  /**
   * The mocked Montonio logger.
   *
   * @var \Prophecy\Prophecy\ObjectProphecy
   */
  protected $montonioLogger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->httpClient = $this->prophesize(ClientInterface::class);
    $this->loggerChannel = $this->prophesize(LoggerChannelInterface::class);
    $this->loggerFactory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $this->loggerFactory->get('commerce_montonio')->willReturn($this->loggerChannel->reveal());

    $this->montonioLogger = $this->prophesize(MontonioLogger::class);

    $jwt_service = $this->prophesize(MontonioJwtService::class);
    $jwt_service->setConfiguration('test_access_key', 'test_secret_key')->shouldBeCalled();
    $jwt_service->generateToken(Argument::type('array'), 10)->willReturn('mock_jwt_token');

    $this->apiClient = new MontonioApiClient(
      $this->httpClient->reveal(),
      $this->montonioLogger->reveal(),
      $jwt_service->reveal()
    );

    $this->apiClient->setConfiguration('test_access_key', 'test_secret_key', TRUE);
  }

  /**
   * Tests JWT token generation.
   */
  public function testGenerateToken() {
    $payload = ['test' => 'data'];

    $this->httpClient->request('POST', Argument::containingString('/orders'), Argument::type('array'))
      ->willReturn(new Response(200, [], json_encode(['test' => 'response'])));

    $token = $this->apiClient->generateToken($payload);

    $this->assertIsString($token);
    $this->assertNotEmpty($token);
  }

  /**
   * Tests invalid token validation.
   */
  public function testValidateInvalidToken() {
    $http_client = $this->prophesize(ClientInterface::class);
    $logger_channel = $this->prophesize(LoggerChannelInterface::class);
    $logger_factory = $this->prophesize(LoggerChannelFactoryInterface::class);
    $logger_factory->get('commerce_montonio')->willReturn($logger_channel->reveal());

    $montonio_logger = $this->prophesize(MontonioLogger::class);
    $jwt_service = $this->prophesize(MontonioJwtService::class);
    $jwt_service->setConfiguration('test_access_key', 'test_secret_key')->shouldBeCalled();
    $jwt_service->decodeToken('invalid_token')->willThrow(new \Exception('Invalid token'));

    $api_client = new MontonioApiClient(
      $http_client->reveal(),
      $montonio_logger->reveal(),
      $jwt_service->reveal()
    );

    $api_client->setConfiguration('test_access_key', 'test_secret_key', TRUE);

    $this->expectException(\Exception::class);
    $this->expectExceptionMessage('Invalid token');

    $api_client->decodeToken('invalid_token');
  }

  /**
   * Tests successful payment methods retrieval.
   */
  public function testGetPaymentMethodsSuccess() {
    $response_data = [
      'paymentMethods' => [
        'cardPayments' => ['processor' => 'stripe'],
        'paymentInitiation' => ['processor' => 'montonio'],
      ],
    ];

    $response = new Response(200, [], json_encode($response_data));
    $this->httpClient->request('GET', Argument::containingString('/stores/payment-methods'), Argument::type('array'))
      ->willReturn($response)
      ->shouldBeCalled();

    $result = $this->apiClient->getPaymentMethods();
    $this->assertEquals($response_data['paymentMethods'], $result);
  }

  /**
   * Tests payment methods retrieval failure.
   */
  public function testGetPaymentMethodsFailure() {
    $this->httpClient->request('GET', Argument::containingString('/stores/payment-methods'), Argument::type('array'))
      ->willThrow(new RequestException('API Error', $this->prophesize(RequestInterface::class)->reveal()))
      ->shouldBeCalled();

    $this->montonioLogger->error('Failed to get payment methods: @error', ['@error' => 'API Error'])
      ->shouldBeCalled();

    $result = $this->apiClient->getPaymentMethods();
    $this->assertEquals([], $result);
  }

  /**
   * Tests successful order creation.
   */
  public function testCreateOrderSuccess() {
    $order_data = [
      'merchantReference' => '12345',
      'grandTotal' => 99.99,
      'currency' => 'EUR',
    ];

    $response_data = [
      'uuid' => 'test_order_uuid',
      'paymentUrl' => 'https://example.com/pay',
      'paymentStatus' => 'PENDING',
    ];

    $response = new Response(200, [], json_encode($response_data));
    $this->httpClient->request('POST', Argument::containingString('/orders'), Argument::type('array'))
      ->willReturn($response)
      ->shouldBeCalled();

    $result = $this->apiClient->createOrder($order_data);
    $this->assertEquals($response_data, $result);
  }

  /**
   * Tests order creation failure.
   */
  public function testCreateOrderFailure() {
    $order_data = ['test' => 'data'];

    $this->httpClient->request('POST', Argument::containingString('/orders'), Argument::type('array'))
      ->willThrow(new RequestException('API Error', $this->prophesize(RequestInterface::class)->reveal()))
      ->shouldBeCalled();

    $this->montonioLogger->error('Failed to create order: @error', ['@error' => 'API Error'])
      ->shouldBeCalled();

    $result = $this->apiClient->createOrder($order_data);
    $this->assertEquals([], $result);
  }

}

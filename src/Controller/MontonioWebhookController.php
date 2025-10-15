<?php

namespace Drupal\commerce_montonio\Controller;

use Drupal\commerce_montonio\Repository\OrderRepositoryInterface;
use Drupal\commerce_montonio\Service\MontonioApiClientFactory;
use Drupal\commerce_montonio\Service\MontonioLogger;
use Drupal\commerce_montonio\Service\MontonioPaymentService;
use Drupal\commerce_montonio\Service\WebhookValidator;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles Montonio webhook notifications.
 */
class MontonioWebhookController implements ContainerInjectionInterface {

  /**
   * The Montonio API client factory.
   *
   * @var \Drupal\commerce_montonio\Service\MontonioApiClientFactory
   */
  protected $apiClientFactory;

  /**
   * Montonio logger.
   *
   * @var \Drupal\commerce_montonio\Service\MontonioLogger
   */
  protected $montonioLogger;

  /**
   * Montonio payment service.
   *
   * @var \Drupal\commerce_montonio\Service\MontonioPaymentService
   */
  protected $paymentService;

  /**
   * Order repository.
   *
   * @var \Drupal\commerce_montonio\Repository\OrderRepositoryInterface
   */
  protected $orderRepository;

  /**
   * The webhook validator service.
   *
   * @var \Drupal\commerce_montonio\Service\WebhookValidator
   */
  protected $webhookValidator;

  /**
   * Constructs a new MontonioWebhookController object.
   *
   * @param \Drupal\commerce_montonio\Service\MontonioApiClientFactory $apiClientFactory
   *   The Montonio API client factory.
   * @param \Drupal\commerce_montonio\Service\MontonioLogger $montonioLogger
   *   The Montonio logger.
   * @param \Drupal\commerce_montonio\Service\MontonioPaymentService $paymentService
   *   The Montonio payment service.
   * @param \Drupal\commerce_montonio\Repository\OrderRepositoryInterface $orderRepository
   *   The order repository.
   * @param \Drupal\commerce_montonio\Service\WebhookValidator $webhookValidator
   *   The webhook validator service.
   */
  public function __construct(
    MontonioApiClientFactory $apiClientFactory,
    MontonioLogger $montonioLogger,
    MontonioPaymentService $paymentService,
    OrderRepositoryInterface $orderRepository,
    WebhookValidator $webhookValidator,
  ) {
    $this->apiClientFactory = $apiClientFactory;
    $this->montonioLogger = $montonioLogger;
    $this->paymentService = $paymentService;
    $this->orderRepository = $orderRepository;
    $this->webhookValidator = $webhookValidator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('commerce_montonio.api_client_factory'),
      $container->get('commerce_montonio.logger'),
      $container->get('commerce_montonio.payment_service'),
      $container->get('commerce_montonio.order_repository'),
      $container->get('commerce_montonio.webhook_validator')
    );
  }

  /**
   * Handles incoming webhook notifications from Montonio.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $commerce_payment_gateway
   *   The payment gateway entity.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function handle(Request $request, PaymentGatewayInterface $commerce_payment_gateway): Response {
    try {
      $order_token = $request->query->get('order-token');

      if (!$this->webhookValidator->validateWebhookSource($request)) {
        return new Response('Unauthorized', 403);
      }

      if (!$order_token) {
        $this->montonioLogger->warning('Webhook received without orderToken for gateway @gateway', [
          '@gateway' => $commerce_payment_gateway->id(),
        ]);
        return new Response('Missing orderToken', 400);
      }

      $gateway_plugin = $commerce_payment_gateway->getPlugin();
      $configuration = $gateway_plugin->getConfiguration();

      $apiClient = $this->apiClientFactory->createFromPaymentGateway($commerce_payment_gateway);

      $decodedToken = $apiClient->decodeToken($order_token);

      if (!$decodedToken) {
        $this->montonioLogger->error('Invalid order token received in webhook for gateway @gateway', [
          '@gateway' => $commerce_payment_gateway->id(),
        ]);
        return new Response('Invalid token', 400);
      }

      // Verify the access key matches.
      if (!$decodedToken->getAccessKey() || $decodedToken->getAccessKey() !== $configuration['access_key']) {
        $this->montonioLogger->error('Access key mismatch in webhook token for gateway @gateway', [
          '@gateway' => $commerce_payment_gateway->id(),
        ]);
        return new Response('Access key mismatch', 403);
      }

      $order = $this->orderRepository->findOrderByReference($decodedToken->getMerchantReference());

      if (!$order) {
        $this->montonioLogger->warning('Order not found for merchant reference @ref in webhook', [
          '@ref' => $decodedToken->getMerchantReference(),
        ]);
        return new Response('Order not found', 404);
      }

      $this->paymentService->processWebhook($decodedToken, $order, $commerce_payment_gateway);

      $this->montonioLogger->info('Webhook processed successfully for order id @order_id, status: @status', [
        '@order_id' => $order->id(),
        '@status' => $decodedToken->getPaymentStatus(),
      ]);

      return new Response('OK', 200);
    }
    catch (\Exception $e) {
      $this->montonioLogger->error('Error processing webhook: @error', [
        '@error' => $e->getMessage(),
      ]);
      return new Response('Internal server error', 500);
    }
  }

}

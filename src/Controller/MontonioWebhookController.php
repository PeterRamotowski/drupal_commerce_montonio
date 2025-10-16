<?php

namespace Drupal\commerce_montonio\Controller;

use Drupal\commerce_montonio\Exception\MontonioJwtException;
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
    protected MontonioApiClientFactory $apiClientFactory,
    protected MontonioLogger $montonioLogger,
    protected MontonioPaymentService $paymentService,
    protected OrderRepositoryInterface $orderRepository,
    protected WebhookValidator $webhookValidator,
  ) {
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
        return new Response('Unauthorized', Response::HTTP_FORBIDDEN);
      }

      if (!$order_token) {
        $this->montonioLogger->warning('Webhook received without orderToken for gateway @gateway', [
          '@gateway' => $commerce_payment_gateway->id(),
        ]);
        return new Response('Missing orderToken', Response::HTTP_BAD_REQUEST);
      }

      $gateway_plugin = $commerce_payment_gateway->getPlugin();
      $configuration = $gateway_plugin->getConfiguration();

      $apiClient = $this->apiClientFactory->createFromPaymentGateway($commerce_payment_gateway);

      try {
        $decodedToken = $apiClient->decodeToken($order_token);
      }
      catch (MontonioJwtException $e) {
        $this->montonioLogger->error('Invalid order token received in webhook for gateway @gateway: @error', [
          '@gateway' => $commerce_payment_gateway->id(),
          '@error' => $e->getMessage(),
        ]);
        return new Response('Invalid token', Response::HTTP_BAD_REQUEST);
      }

      // Verify the access key matches.
      if (!$decodedToken->getAccessKey() || $decodedToken->getAccessKey() !== $configuration['access_key']) {
        $this->montonioLogger->error('Access key mismatch in webhook token for gateway @gateway', [
          '@gateway' => $commerce_payment_gateway->id(),
        ]);
        return new Response('Access key mismatch', Response::HTTP_FORBIDDEN);
      }

      $order = $this->orderRepository->findOrderByReference($decodedToken->getMerchantReference());

      if (!$order) {
        $this->montonioLogger->warning('Order not found for merchant reference @ref in webhook', [
          '@ref' => $decodedToken->getMerchantReference(),
        ]);
        return new Response('Order not found', Response::HTTP_NOT_FOUND);
      }

      $this->paymentService->processWebhook($decodedToken, $order, $commerce_payment_gateway);

      $this->montonioLogger->info('Webhook processed successfully for order id @order_id, status: @status', [
        '@order_id' => $order->id(),
        '@status' => $decodedToken->getPaymentStatus(),
      ]);

      return new Response('OK', Response::HTTP_OK);
    }
    catch (\Exception $e) {
      $this->montonioLogger->error('Error processing webhook: @error', [
        '@error' => $e->getMessage(),
      ]);
      return new Response('Internal server error', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
  }

}

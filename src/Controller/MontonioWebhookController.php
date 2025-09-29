<?php

namespace Drupal\commerce_montonio\Controller;

use Drupal\commerce_montonio\Service\MontonioApiClientFactory;
use Drupal\commerce_montonio\Service\MontonioLogger;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles Montonio webhook notifications.
 */
class MontonioWebhookController extends ControllerBase
{

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * Constructs a new MontonioWebhookController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\commerce_montonio\Service\MontonioApiClientFactory $apiClientFactory
   *   The Montonio API client factory.
   * @param \Drupal\commerce_montonio\Service\MontonioLogger $montonioLogger
   *   The Montonio logger.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, MontonioApiClientFactory $apiClientFactory, MontonioLogger $montonioLogger)
  {
    $this->entityTypeManager = $entityTypeManager;
    $this->apiClientFactory = $apiClientFactory;
    $this->montonioLogger = $montonioLogger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('commerce_montonio.api_client_factory'),
      $container->get('commerce_montonio.logger')
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
      if (!isset($decodedToken->accessKey) || $decodedToken->accessKey !== $configuration['access_key']) {
        $this->montonioLogger->error('Access key mismatch in webhook token for gateway @gateway', [
          '@gateway' => $commerce_payment_gateway->id(),
        ]);
        return new Response('Access key mismatch', 403);
      }

      $order = $this->findOrderByReference($decodedToken->merchantReference);

      if (!$order) {
        $this->montonioLogger->warning('Order not found for merchant reference @ref in webhook', [
          '@ref' => $decodedToken->merchantReference,
        ]);
        return new Response('Order not found', 404);
      }

      $this->processPaymentStatus($decodedToken, $order, $commerce_payment_gateway);

      $this->montonioLogger->info('Webhook processed successfully for order id @order_id, status: @status', [
        '@order_id' => $order->id(),
        '@status' => $decodedToken->paymentStatus,
      ]);

      return new Response('OK', 200);
    } catch (\Exception $e) {
      $this->montonioLogger->error('Error processing webhook: @error', [
        '@error' => $e->getMessage(),
      ]);
      return new Response('Internal server error', 500);
    }
  }

  /**
   * Finds an order by its merchant reference (order number).
   *
   * @param string $merchantReference
   *   The merchant reference to search for.
   *
   * @return \Drupal\commerce_order\Entity\OrderInterface|null
   *   The order entity or NULL if not found.
   */
  protected function findOrderByReference($merchantReference): ?OrderInterface {
    $orderStorage = $this->entityTypeManager->getStorage('commerce_order');
    $orders = $orderStorage->loadByProperties([
      'order_number' => $merchantReference,
    ]);

    return !empty($orders) ? reset($orders) : NULL;
  }

  /**
   * Processes the payment status from the webhook.
   *
   * @param object $decodedToken
   *   The decoded JWT token from Montonio.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   The payment gateway entity.
   */
  protected function processPaymentStatus($decodedToken, OrderInterface $order, PaymentGatewayInterface $gateway)
  {
    $paymentStorage = $this->entityTypeManager->getStorage('commerce_payment');

    switch ($decodedToken->paymentStatus) {
      case 'PAID':
        $this->handlePaidStatus($decodedToken, $order, $gateway, $paymentStorage);
        break;

      case 'PARTIALLY_REFUNDED':
      case 'REFUNDED':
        $this->handleRefundStatus($decodedToken, $order, $paymentStorage);
        break;

      case 'VOIDED':
        $this->handleVoidedStatus($decodedToken, $order, $paymentStorage);
        break;

      case 'ABANDONED':
        $this->handleAbandonedStatus($decodedToken, $order, $paymentStorage);
        break;

      case 'AUTHORIZED':
        $this->handleAuthorizedStatus($decodedToken, $order, $gateway, $paymentStorage);
        break;

      default:
        $this->montonioLogger->info('Unhandled payment status @status for order id @order', [
          '@status' => $decodedToken->paymentStatus,
          '@order' => $order->id(),
        ]);
    }
  }

  /**
   * Handles PAID payment status.
   */
  protected function handlePaidStatus($decodedToken, OrderInterface $order, PaymentGatewayInterface $gateway, EntityStorageInterface $paymentStorage)
  {
    $existingPayments = $paymentStorage->loadByProperties([
      'remote_id' => $decodedToken->uuid,
      'order_id' => $order->id(),
    ]);

    if (!empty($existingPayments)) {
      $this->montonioLogger->info('Payment already exists for order id @order, skipping creation', [
        '@order' => $order->id(),
      ]);
    }

    $payment = $paymentStorage->create([
      'state' => 'completed',
      'amount' => new Price((string) $decodedToken->grandTotal, $decodedToken->currency),
      'payment_gateway' => $gateway->id(),
      'order_id' => $order->id(),
      'remote_id' => $decodedToken->uuid,
      'remote_state' => $decodedToken->paymentStatus,
    ]);

    $payment->save();

    $this->montonioLogger->info('Payment created for order id @order, amount: @amount', [
      '@order' => $order->id(),
      '@amount' => $decodedToken->grandTotal . ' ' . $decodedToken->currency,
    ]);

    if ($order->getState()->getId() === 'draft') {
      $order->getState()->applyTransitionById('place');
      $order->unlock();
      $order->save();
    }
  }

  /**
   * Handles PARTIALLY_REFUNDED and REFUNDED payment statuses.
   */
  protected function handleRefundStatus($decodedToken, OrderInterface $order, EntityStorageInterface $paymentStorage)
  {
    $payments = $paymentStorage->loadByProperties([
      'remote_id' => $decodedToken->uuid,
      'order_id' => $order->id(),
    ]);

    if (!empty($payments)) {
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
      $payment = reset($payments);

      if ($decodedToken->paymentStatus === 'PARTIALLY_REFUNDED') {
        $payment->setState('partially_refunded');
      } else {
        $payment->setState('refunded');
      }

      $payment->setRemoteState($decodedToken->paymentStatus);
      $payment->save();

      $this->montonioLogger->info('Payment @payment updated to @status', [
        '@payment' => $payment->id(),
        '@status' => $decodedToken->paymentStatus,
      ]);
    }
  }

  /**
   * Handles VOIDED payment status.
   */
  protected function handleVoidedStatus($decodedToken, OrderInterface $order, EntityStorageInterface $paymentStorage)
  {
    $payments = $paymentStorage->loadByProperties([
      'remote_id' => $decodedToken->uuid,
      'order_id' => $order->id(),
    ]);

    if (!empty($payments)) {
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
      $payment = reset($payments);
      $payment->setState('voided');
      $payment->setRemoteState($decodedToken->paymentStatus);
      $payment->save();

      $this->montonioLogger->warning('Payment @payment was voided by the bank for order id @order', [
        '@payment' => $payment->id(),
        '@order' => $order->id(),
      ]);
    }
  }

  /**
   * Handles ABANDONED payment status.
   */
  protected function handleAbandonedStatus($decodedToken, OrderInterface $order, EntityStorageInterface $paymentStorage)
  {
    $payments = $paymentStorage->loadByProperties([
      'order_id' => $order->id(),
      'state' => 'new',
    ]);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    foreach ($payments as $payment) {
      if ($payment->getRemoteId() === $decodedToken->uuid) {
        $payment->setState('canceled');
        $payment->setRemoteState($decodedToken->paymentStatus);
        $payment->save();

        $this->montonioLogger->info('Payment @payment marked as abandoned for order id @order', [
          '@payment' => $payment->id(),
          '@order' => $order->id(),
        ]);
      }
    }
  }

  /**
   * Handles AUTHORIZED payment status.
   */
  protected function handleAuthorizedStatus($decodedToken, OrderInterface $order, PaymentGatewayInterface $gateway, EntityStorageInterface $paymentStorage)
  {
    $existingPayments = $paymentStorage->loadByProperties([
      'remote_id' => $decodedToken->uuid,
      'order_id' => $order->id(),
    ]);

    if (empty($existingPayments)) {
      $payment = $paymentStorage->create([
        'state' => 'authorization',
        'amount' => new Price((string) $decodedToken->grandTotal, $decodedToken->currency),
        'payment_gateway' => $gateway->id(),
        'order_id' => $order->id(),
        'remote_id' => $decodedToken->uuid,
        'remote_state' => $decodedToken->paymentStatus,
      ]);
      $payment->save();

      $this->montonioLogger->info('Payment authorized for order id @order, amount: @amount', [
        '@order' => $order->id(),
        '@amount' => $decodedToken->grandTotal . ' ' . $decodedToken->currency,
      ]);
    } else {
      /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
      $payment = reset($existingPayments);
      if ($payment->getState()->getId() === 'new') {
        $payment->setState('authorization');
        $payment->setRemoteState($decodedToken->paymentStatus);
        $payment->save();
      }
    }
  }
}

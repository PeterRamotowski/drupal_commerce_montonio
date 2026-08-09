<?php

namespace Drupal\commerce_montonio\Service;

use Drupal\commerce_montonio\Dto\DtoFactory;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentGatewayInterface;
use Drupal\commerce_price\Price;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;

/**
 * Builds the order request payload for the Montonio API.
 */
class OrderRequestBuilder {

  /**
   * Constructs a new OrderRequestBuilder object.
   *
   * @param \Drupal\commerce_montonio\Dto\DtoFactory $dtoFactory
   *   The DTO factory.
   * @param \Drupal\commerce_montonio\Service\OrderNumber $orderNumber
   *   The order number service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    private DtoFactory $dtoFactory,
    private OrderNumber $orderNumber,
    private LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Builds the full order request payload.
   *
   * Assigns an order number if not already set, then assembles the payload.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   The payment gateway entity.
   * @param string $paymentMethod
   *   The selected payment method identifier.
   * @param string|null $preferredBank
   *   The preferred bank code for payment initiation, if applicable.
   * @param int|null $bnplPeriod
   *   The BNPL installment period (1, 2, or 3), if applicable.
   *
   * @return array
   *   The assembled order data array ready for the Montonio API.
   */
  public function build(
    OrderInterface $order,
    PaymentGatewayInterface $gateway,
    string $paymentMethod,
    ?string $preferredBank = NULL,
    ?int $bnplPeriod = NULL,
  ): array {
    $this->orderNumber->setOrderNumber($order);
    $order->save();

    $amount = $order->getTotalPrice();
    $merchantReference = $order->getOrderNumber() ?: (string) $order->id();
    $billingAddress = $this->resolveBillingAddress($order);

    return [
      'merchantReference' => $merchantReference,
      'returnUrl'         => $this->buildReturnUrl($order),
      'notificationUrl'   => $this->buildNotificationUrl($gateway),
      'currency'          => $amount->getCurrencyCode(),
      'grandTotal'        => (float) $amount->getNumber(),
      'locale'            => $this->languageManager->getCurrentLanguage()->getId(),
      'billingAddress'    => $billingAddress,
      'shippingAddress'   => $this->resolveShippingAddress($order, $billingAddress),
      'lineItems'         => $this->buildLineItems($order),
      'payment'           => $this->buildPaymentBlock(
        $paymentMethod,
        $amount,
        $merchantReference,
        $billingAddress,
        $preferredBank,
        $bnplPeriod,
      ),
    ];
  }

  /**
   * Builds the payment block section of the payload.
   *
   * @param string $paymentMethod
   *   The selected payment method identifier.
   * @param \Drupal\commerce_price\Price $amount
   *   The order total price.
   * @param string $merchantReference
   *   The merchant reference (order number).
   * @param array $billingAddress
   *   The resolved billing address array.
   * @param string|null $preferredBank
   *   The preferred bank code for payment initiation.
   * @param int|null $bnplPeriod
   *   The BNPL installment period.
   *
   * @return array
   *   The payment block array.
   */
  private function buildPaymentBlock(
    string $paymentMethod,
    Price $amount,
    string $merchantReference,
    array $billingAddress,
    ?string $preferredBank,
    ?int $bnplPeriod,
  ): array {
    $methodOptions = match ($paymentMethod) {
      PaymentMethodRegistry::PAYMENT_INITIATION => $this->buildPaymentInitiationOptions(
        $billingAddress,
        $merchantReference,
        $preferredBank,
      ),
      PaymentMethodRegistry::BNPL => ['period' => (int) ($bnplPeriod ?? 1)],
      default => [],
    };

    return [
      'method'        => $paymentMethod,
      'methodDisplay' => PaymentMethodRegistry::apiDisplayName($paymentMethod),
      'methodOptions' => $methodOptions,
      'amount'        => (float) $amount->getNumber(),
      'currency'      => $amount->getCurrencyCode(),
    ];
  }

  /**
   * Builds payment initiation method options.
   *
   * @param array $billingAddress
   *   The resolved billing address array.
   * @param string $merchantReference
   *   The merchant reference used as payment description.
   * @param string|null $preferredBank
   *   The preferred bank code.
   *
   * @return array
   *   The payment initiation options array.
   */
  private function buildPaymentInitiationOptions(
    array $billingAddress,
    string $merchantReference,
    ?string $preferredBank,
  ): array {
    $options = [
      'paymentDescription' => 'Payment for order ' . $merchantReference,
      'preferredCountry'   => $billingAddress['country'] ?? 'EE',
    ];

    if ($preferredBank) {
      $options['preferredProvider'] = $preferredBank;
    }

    return $options;
  }

  /**
   * Builds line items from order items.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return array
   *   The line items array.
   */
  private function buildLineItems(OrderInterface $order): array {
    $lineItems = [];
    foreach ($order->getItems() as $orderItem) {
      $lineItems[] = [
        'name'       => $orderItem->getTitle(),
        'quantity'   => (int) $orderItem->getQuantity(),
        'finalPrice' => (float) $orderItem->getTotalPrice()->getNumber(),
      ];
    }
    return $lineItems;
  }

  /**
   * Resolves the billing address DTO array for an order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return array
   *   The billing address array, or an empty array if unavailable.
   */
  private function resolveBillingAddress(OrderInterface $order): array {
    $billingProfile = $order->getBillingProfile();

    /** @var \Drupal\address\AddressInterface|null $billingAddress */
    $billingAddress = $billingProfile ? $billingProfile->get('address')->first() : NULL;

    if (!$billingAddress) {
      return [];
    }

    return $this->dtoFactory
      ->createAddressDto($billingAddress, $order->getEmail())
      ->toArray();
  }

  /**
   * Resolves the shipping address, falling back to billing address.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param array $billingAddress
   *   The already-resolved billing address array.
   *
   * @return array
   *   The shipping address array.
   */
  private function resolveShippingAddress(OrderInterface $order, array $billingAddress): array {
    $profiles = $order->collectProfiles();

    /** @var \Drupal\address\AddressInterface|null $shippingAddress */
    $shippingAddress = isset($profiles['shipping'])
      ? $profiles['shipping']->get('address')->first()
      : NULL;

    if ($shippingAddress) {
      return $this->dtoFactory
        ->createAddressDto($shippingAddress, $order->getEmail())
        ->toArray();
    }

    return $billingAddress;
  }

  /**
   * Builds the return URL for a given order.
   *
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   *
   * @return string
   *   The absolute return URL.
   */
  private function buildReturnUrl(OrderInterface $order): string {
    return Url::fromRoute('commerce_payment.checkout.return', [
      'commerce_order' => $order->id(),
      'step'           => 'payment',
    ], ['absolute' => TRUE])->toString();
  }

  /**
   * Builds the webhook notification URL for a given gateway.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentGatewayInterface $gateway
   *   The payment gateway entity.
   *
   * @return string
   *   The absolute notification URL.
   */
  private function buildNotificationUrl(PaymentGatewayInterface $gateway): string {
    return Url::fromRoute('commerce_montonio.webhook', [
      'commerce_payment_gateway' => $gateway->id(),
    ], ['absolute' => TRUE])->toString();
  }

}

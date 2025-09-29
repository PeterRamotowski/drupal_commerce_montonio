<?php

namespace Drupal\commerce_montonio\PluginForm;

use Drupal\commerce_montonio\Dto\MontonioAddressDto;
use Drupal\commerce_montonio\Service\MontonioApiClient;
use Drupal\commerce_montonio\Service\MontonioApiClientFactory;
use Drupal\commerce_montonio\Service\OrderNumber;
use Drupal\commerce_order\Entity\OrderInterface;
use Drupal\commerce_payment\Entity\PaymentInterface;
use Drupal\commerce_payment\PluginForm\PaymentOffsiteForm;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Off-site payment form for Montonio.
 */
class MontonioOffsiteForm extends PaymentOffsiteForm implements ContainerInjectionInterface
{

  /**
   * Default country code.
   */
  public const DEFAULT_COUNTRY_CODE = 'EE';

  public function __construct(
    protected LanguageManagerInterface $languageManager,
    protected MontonioApiClientFactory $apiClientFactory,
    protected OrderNumber $orderNumber,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static
  {
    return new static(
      $container->get('language_manager'),
      $container->get('commerce_montonio.api_client_factory'),
      $container->get('commerce_montonio.order_number'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildConfigurationForm($form, $form_state);

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $order = $payment->getOrder();

    /** @var \Drupal\commerce_montonio\Plugin\Commerce\PaymentGateway\Montonio $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $configuration = $payment_gateway_plugin->getConfiguration();

    $apiClient = $this->apiClientFactory->createFromPaymentGatewayPlugin($payment_gateway_plugin);

    $enabledMethods = $payment_gateway_plugin->getEnabledPaymentMethods();
    $options = $this->buildPaymentMethodOptions($enabledMethods, $order);

    if (empty($options)) {
      return $this->processDefaultPayment($form, $form_state, $payment, $order, $configuration, $apiClient);
    }

    $defaultMethod = $this->getDefaultPaymentMethod($enabledMethods, $configuration);
    if (!isset($options[$defaultMethod])) {
      $defaultMethod = array_key_first($options);
    }

    $form['payment_method'] = [
      '#type' => 'radios',
      '#title' => $this->t('Choose your payment method'),
      '#options' => $options,
      '#default_value' => $defaultMethod,
      '#required' => TRUE,
      '#weight' => -10,
    ];

    if (isset($enabledMethods['paymentInitiation'])) {
      $form['preferred_bank'] = [
        '#type' => 'radios',
        '#title' => $this->t('Select your bank'),
        '#options' => $this->buildBankOptions($enabledMethods['paymentInitiation'], $order),
        '#weight' => -9,
        '#wrapper_attributes' => [
          'style' => $defaultMethod !== 'paymentInitiation' ? 'display: none;' : '',
        ],
        '#states' => [
          'visible' => [
            ':input[name="payment_process[offsite_payment][payment_method]"]' => ['value' => 'paymentInitiation'],
          ],
          'required' => [
            ':input[name="payment_process[offsite_payment][payment_method]"]' => ['value' => 'paymentInitiation'],
          ],
        ],
      ];
    }

    $form['actions'] = [
      '#type' => 'actions',
      '#weight' => 100,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Proceed to payment'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    if (isset($form['payment_method'])) {
      $this->validatePaymentMethodSelection($form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);

    if (!isset($form['payment_method'])) {
      return;
    }

    /** @var \Drupal\commerce_payment\Entity\PaymentInterface $payment */
    $payment = $this->entity;
    $order = $payment->getOrder();

    /** @var \Drupal\commerce_montonio\Plugin\Commerce\PaymentGateway\Montonio $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $configuration = $payment_gateway_plugin->getConfiguration();
    $enabledMethods = $payment_gateway_plugin->getEnabledPaymentMethods();

    $apiClient = $this->apiClientFactory->createFromPaymentGatewayPlugin($payment_gateway_plugin);

    $this->processPaymentMethodSelection($form, $form_state, $payment, $order, $configuration, $apiClient, $enabledMethods);
  }

  /**
   * Validates the payment method selection.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE if validation passes, FALSE otherwise.
   */
  protected function validatePaymentMethodSelection(array $form, FormStateInterface $form_state): bool {
    $values = $form_state->getValues();

    $selectedMethod = isset($form['payment_method'])
      ? NestedArray::getValue($values, $form['payment_method']['#parents'])
      : NULL;

    if (empty($selectedMethod)) {
      $form_state->setError($form['payment_method'], $this->t('Please choose a payment method.'));
      return FALSE;
    }

    if (
      $selectedMethod === 'paymentInitiation'
      && isset($form['preferred_bank'])
      && empty(NestedArray::getValue($values, $form['preferred_bank']['#parents']))
    ) {
      $form_state->setError($form['preferred_bank'], $this->t('Please select your bank.'));
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Process payment method selection and redirect to Montonio.
   */
  protected function processPaymentMethodSelection(array $form, FormStateInterface $form_state, $payment, $order, array $configuration, MontonioApiClient $apiClient, array $enabledMethods = []): array {
    $values = $form_state->getValues();

    $selectedMethod = isset($form['payment_method'])
      ? NestedArray::getValue($values, $form['payment_method']['#parents'])
      : NULL;

    if (!$selectedMethod && !empty($enabledMethods)) {
      $selectedMethod = $this->getDefaultPaymentMethod($enabledMethods, $configuration);
    }

    $preferredBank = NULL;
    if (isset($form['preferred_bank'])) {
      $preferredBank = NestedArray::getValue($values, $form['preferred_bank']['#parents']);
    }

    $orderData = $this->buildOrderData($payment, $order, $selectedMethod, $preferredBank ?: NULL);

    $response = $apiClient->createOrder($orderData);

    if (!$response || !isset($response['paymentUrl'])) {
      throw new \Exception('Failed to create payment order with Montonio.');
    }

    return $this->buildRedirectForm($form, $form_state, $response['paymentUrl'], [], self::REDIRECT_GET);
  }

  /**
   * Process default payment without selection.
   */
  protected function processDefaultPayment(array $form, FormStateInterface $form_state, $payment, $order, array $configuration, MontonioApiClient $apiClient): array {
    /** @var \Drupal\commerce_montonio\Plugin\Commerce\PaymentGateway\Montonio $payment_gateway_plugin */
    $payment_gateway_plugin = $payment->getPaymentGateway()->getPlugin();
    $enabledMethods = $payment_gateway_plugin->getEnabledPaymentMethods();
    
    $default_method = $configuration['default_payment_method'] ?? 'blik';
    
    // Ensure the default method is enabled, otherwise use the first enabled method
    if (empty($enabledMethods[$default_method]) && !empty($enabledMethods)) {
      $default_method = array_key_first($enabledMethods);
    }

    $order_data = $this->buildOrderData($payment, $order, $default_method);

    $response = $apiClient->createOrder($order_data);

    if (!$response || !isset($response['paymentUrl'])) {
      throw new \Exception('Failed to create payment order with Montonio.');
    }

    return $this->buildRedirectForm($form, $form_state, $response['paymentUrl'], [], self::REDIRECT_GET);
  }

  /**
   * Builds payment method options from available methods.
   */
  protected function buildPaymentMethodOptions(array $availableMethods, OrderInterface $order): array {
    $options = [];
    $currency = $order->getTotalPrice()->getCurrencyCode();

    foreach ($availableMethods as $method_id => $method_data) {
      if ($this->methodSupportsCurrency($method_data, $currency, $order)) {
        $options[$method_id] = $this->getPaymentMethodLabel($method_id);
      }
    }

    return $options;
  }

  /**
   * Builds bank options for payment initiation.
   */
  protected function buildBankOptions(array $paymentInitiationData, OrderInterface $order): array {
    $options = [];
    $currency = $order->getTotalPrice()->getCurrencyCode();
    $countryCode = self::DEFAULT_COUNTRY_CODE;
    $billingProfile = $order->getBillingProfile();

      /** @var \Drupal\address\AddressInterface|null $billingAddress */
      $billingAddress = $billingProfile ? $billingProfile->get('address')->first() : NULL;

    if ($billingAddress && $billingAddress->getCountryCode()) {
      $countryCode = $billingAddress->getCountryCode();
    }

    if (isset($paymentInitiationData['setup'][$countryCode]['paymentMethods'])) {
      foreach ($paymentInitiationData['setup'][$countryCode]['paymentMethods'] as $bank) {
        if (in_array($currency, $bank['supportedCurrencies'])) {
          $options[$bank['code']] = $bank['name'];
        }
      }
    }

    return $options;
  }

  /**
   * Checks if payment method supports the given currency.
   */
  protected function methodSupportsCurrency(array $methodData, string $currency, OrderInterface $order): bool {
    // For payment initiation, check by country and currency.
    if (isset($methodData['setup'])) {
      $countryCode = self::DEFAULT_COUNTRY_CODE;
      $billingProfile = $order->getBillingProfile();

      /** @var \Drupal\address\AddressInterface|null $billingAddress */
      $billingAddress = $billingProfile ? $billingProfile->get('address')->first() : NULL;

      if ($billingAddress && $billingAddress->getCountryCode()) {
        $countryCode = $billingAddress->getCountryCode();
      }

      if (isset($methodData['setup'][$countryCode]['supportedCurrencies'])) {
        return in_array($currency, $methodData['setup'][$countryCode]['supportedCurrencies']);
      }
    }

    // For other methods, assume EUR and PLN support based on typical Montonio setup.
    $supportedCurrencies = ['EUR'];
    if (in_array($currency, ['PLN']) && isset($methodData['processor'])) {
      $supportedCurrencies[] = 'PLN';
    }

    return in_array($currency, $supportedCurrencies);
  }

  /**
   * Gets the default payment method from enabled options.
   */
  protected function getDefaultPaymentMethod(array $enabledMethods, array $configuration): ?string {
    $default = $configuration['default_payment_method'] ?? 'blik';

    if (isset($enabledMethods[$default])) {
      return $default;
    }

    return key($enabledMethods);
  }

  /**
   * Builds the order data for Montonio API.
   *
   * @param \Drupal\commerce_payment\Entity\PaymentInterface $payment
   *   The payment entity.
   * @param \Drupal\commerce_order\Entity\OrderInterface $order
   *   The order entity.
   * @param string $paymentMethod
   *   The selected payment method.
   * @param string|null $preferredBank
   *   The preferred bank code for payment initiation.
   *
   * @return array
   *   The order data array.
   */
  protected function buildOrderData(PaymentInterface $payment, OrderInterface $order, string $paymentMethod = 'cardPayments', ?string $preferredBank = NULL): array {
    $amount = $payment->getAmount();
    $billingProfile = $order->getBillingProfile();

    /** @var \Drupal\address\AddressInterface|null $billingAddress */
    $billingAddress = $billingProfile ? $billingProfile->get('address')->first() : NULL;

    $shippingProfile = NULL;

    /** @var \Drupal\address\AddressInterface|null $shippingAddress */
    $shippingAddress = $shippingProfile ? $shippingProfile->get('address')->first() : NULL;

    $montonioBillingAddress = [];
    if ($billingAddress) {
      $montonioBillingAddress = MontonioAddressDto::fromAddress($billingAddress, $order->getEmail())->toArray();
    }

    $montonioShippingAddress = $montonioBillingAddress;
    if ($shippingAddress) {
      $montonioShippingAddress = MontonioAddressDto::fromAddress($shippingAddress, $order->getEmail())->toArray();
    }

    $lineItems = [];
    foreach ($order->getItems() as $orderItem) {
      $lineItems[] = [
        'name' => $orderItem->getTitle(),
        'quantity' => (int) $orderItem->getQuantity(),
        'finalPrice' => (float) $orderItem->getTotalPrice()->getNumber(),
      ];
    }

    // Build payment method options based on selected method.
    $methodOptions = [];
    if ($paymentMethod === 'paymentInitiation') {
      $methodOptions = [
        'paymentDescription' => 'Payment for order ' . $order->getOrderNumber(),
        'preferredCountry' => $montonioBillingAddress['country'] ?? self::DEFAULT_COUNTRY_CODE,
      ];

      if ($preferredBank) {
        $methodOptions['preferredProvider'] = $preferredBank;
      }
    }

    $this->orderNumber->setOrderNumber($order);
    $order->save();

    $merchantReference = $order->getOrderNumber() ?: $order->id();

    return [
      'merchantReference' => $merchantReference,
      'returnUrl' => Url::fromRoute('commerce_payment.checkout.return', [
        'commerce_order' => $order->id(),
        'step' => 'payment',
      ], ['absolute' => TRUE])->toString(),
      'notificationUrl' => Url::fromRoute('commerce_montonio.webhook', [
        'commerce_payment_gateway' => $payment->getPaymentGatewayId(),
      ], ['absolute' => TRUE])->toString(),
      'currency' => $amount->getCurrencyCode(),
      'grandTotal' => (float) $amount->getNumber(),
      'locale' => $this->languageManager->getCurrentLanguage()->getId(),
      'billingAddress' => $montonioBillingAddress,
      'shippingAddress' => $montonioShippingAddress,
      'lineItems' => $lineItems,
      'payment' => [
        'method' => $paymentMethod,
        'methodDisplay' => $this->getMethodDisplayName($paymentMethod),
        'methodOptions' => $methodOptions,
        'amount' => (float) $amount->getNumber(),
        'currency' => $amount->getCurrencyCode(),
      ],
    ];
  }

  /**
   * Gets the display label for a payment method.
   */
  protected function getPaymentMethodLabel(string $methodId): string {
    $labels = [
      'cardPayments' => $this->t('Pay with card'),
      'paymentInitiation' => $this->t('Pay with your bank'),
      'blik' => $this->t('Pay with BLIK'),
      'bnpl' => $this->t('Buy now, pay later'),
      'hirePurchase' => $this->t('Hire purchase'),
    ];

    return $labels[$methodId] ?? $this->t('Montonio @method', ['@method' => $methodId]);
  }

  /**
   * Gets the display name for a payment method.
   *
   * @param string $method
   *   The payment method identifier.
   *
   * @return string
   *   The display name.
   */
  protected function getMethodDisplayName(string $method): string {
    $names = [
      'cardPayments' => 'Pay with card',
      'paymentInitiation' => 'Pay with your bank',
      'blik' => 'Pay with BLIK',
      'bnpl' => 'Buy now, pay later',
      'hirePurchase' => 'Hire purchase',
    ];

    return $names[$method] ?? 'Montonio';
  }
}

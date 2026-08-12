<?php

namespace Drupal\commerce_montonio\Plugin\Commerce\PaymentGateway;

use Drupal\commerce_montonio\PluginForm\MontonioOffsiteForm;
use Drupal\commerce_montonio\Repository\PaymentRepositoryInterface;
use Drupal\commerce_montonio\Service\MontonioApiClientFactory;
use Drupal\commerce_payment\Attribute\CommercePaymentGateway;
use Drupal\commerce_payment\Plugin\Commerce\PaymentGateway\OffsitePaymentGatewayBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the Montonio offsite Checkout payment gateway.
 */
#[CommercePaymentGateway(
  id: "montonio_redirect_checkout",
  label: new TranslatableMarkup("Montonio (Redirect to Montonio)"),
  display_label: new TranslatableMarkup("Montonio"),
  forms: [
    "offsite-payment" => MontonioOffsiteForm::class,
  ],
  payment_method_types: ["credit_card"],
  credit_card_types: [
    "mastercard",
    "visa",
    "maestro",
    "amex",
  ]
)]
class Montonio extends OffsitePaymentGatewayBase {

  /**
   * The Montonio API client factory.
   *
   * @var \Drupal\commerce_montonio\Service\MontonioApiClientFactory
   */
  protected MontonioApiClientFactory $apiClientFactory;

  /**
   * The payment repository.
   *
   * @var \Drupal\commerce_montonio\Repository\PaymentRepositoryInterface
   */
  protected PaymentRepositoryInterface $paymentRepository;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->apiClientFactory = $container->get('commerce_montonio.api_client_factory');
    $instance->paymentRepository = $container->get('commerce_montonio.payment_repository');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'access_key' => '',
      'secret_key' => '',
      'default_payment_method' => 'blik',
      'enabled_payment_methods' => [],
      'debug' => FALSE,
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::validateConfigurationForm($form, $form_state);

    $values = $form_state->getValue($form['#parents']);
    $enabled_methods = array_filter($values['enabled_payment_methods']);
    $default_method = $values['default_payment_method'];

    if (empty($enabled_methods)) {
      $form_state->setError($form['enabled_payment_methods'], $this->t('You must enable at least one payment method.'));
    }

    if (!empty($enabled_methods) && !in_array($default_method, $enabled_methods)) {
      $form_state->setError($form['default_payment_method'], $this->t('The default payment method must be one of the enabled methods.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);

    $form['access_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Access Key'),
      '#description' => $this->t('Your Montonio Access Key from the Partner System.'),
      '#default_value' => $this->configuration['access_key'],
      '#required' => TRUE,
    ];

    $form['secret_key'] = [
      '#type' => 'password',
      '#title' => $this->t('Secret Key'),
      '#description' => !empty($this->configuration['secret_key'])
        ? $this->t('Your Montonio Secret Key. Leave blank to keep the existing key.')
        : $this->t('Your Montonio Secret Key from the Partner System.'),
      '#required' => empty($this->configuration['secret_key']),
      '#attributes' => ['autocomplete' => 'off'],
    ];

    $payment_method_options = [
      'cardPayments' => $this->t('Card Payments'),
      'paymentInitiation' => $this->t('Bank Payments'),
      'blik' => $this->t('BLIK'),
      'bnpl' => $this->t('Buy Now Pay Later'),
      'hirePurchase' => $this->t('Hire Purchase'),
    ];

    $form['enabled_payment_methods'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Enabled payment methods'),
      '#description' => $this->t('Select which payment methods should be available for customers. Only enabled methods will be shown during checkout.'),
      '#options' => $payment_method_options,
      '#default_value' => $this->configuration['enabled_payment_methods'] ?? [],
      '#required' => TRUE,
    ];

    // Filter default payment method options to only enabled methods.
    $enabled_methods = $this->configuration['enabled_payment_methods'] ?? [];
    $default_method_options = array_intersect_key($payment_method_options, array_flip($enabled_methods));

    // If no methods are enabled, show all options as fallback.
    if (empty($default_method_options)) {
      $default_method_options = $payment_method_options;
    }

    $form['default_payment_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Default payment method'),
      '#options' => $default_method_options,
      '#default_value' => $this->configuration['default_payment_method'],
    ];

    $form['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug'),
      '#description' => $this->t('Enable debugging to display detailed error messages.'),
      '#default_value' => $this->configuration['debug'],
    ];

    $form['#attached']['library'][] = 'commerce_montonio/admin';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $existing_secret_key = $this->configuration['secret_key'] ?? '';
    parent::submitConfigurationForm($form, $form_state);

    if (!$form_state->getErrors()) {
      $values = $form_state->getValue($form['#parents']);
      $enabled_payment_methods = array_filter($values['enabled_payment_methods']);
      $this->configuration['access_key'] = $values['access_key'];
      $this->configuration['secret_key'] = $values['secret_key'] !== ''
        ? $values['secret_key']
        : $existing_secret_key;
      $this->configuration['default_payment_method'] = $values['default_payment_method'];
      $this->configuration['enabled_payment_methods'] = array_values($enabled_payment_methods);
      $this->configuration['debug'] = $values['debug'];
    }
  }

  /**
   * Gets available payment methods from Montonio API.
   *
   * @return array
   *   Available payment methods.
   */
  public function getAvailablePaymentMethods(): array {
    $apiClient = $this->apiClientFactory->createFromPaymentGatewayPlugin($this);
    return $apiClient->getPaymentMethods();
  }

  /**
   * Gets enabled payment methods based on configuration.
   *
   * @return array
   *   Enabled payment methods filtered from available methods.
   */
  public function getEnabledPaymentMethods(): array {
    $availableMethods = $this->getAvailablePaymentMethods();
    $enabledMethods = $this->configuration['enabled_payment_methods'] ?? [];

    if (empty($enabledMethods)) {
      return $availableMethods;
    }

    $filteredMethods = [];
    foreach ($enabledMethods as $methodId) {
      if (isset($availableMethods[$methodId])) {
        $filteredMethods[$methodId] = $availableMethods[$methodId];
      }
    }

    return $filteredMethods;
  }

}

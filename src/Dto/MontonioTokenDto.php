<?php

namespace Drupal\commerce_montonio\Dto;

/**
 * Data Transfer Object for Montonio JWT token payload.
 */
class MontonioTokenDto {

  /**
   * Constructs a new MontonioTokenDto object.
   *
   * @param object $tokenData
   *   The decoded JWT token data.
   */
  public function __construct(protected object $tokenData) {}

  /**
   * Gets the access key.
   *
   * @return string|null
   *   The access key.
   */
  public function getAccessKey(): ?string {
    return $this->tokenData->accessKey ?? NULL;
  }

  /**
   * Gets the merchant reference.
   *
   * @return string|null
   *   The merchant reference.
   */
  public function getMerchantReference(): ?string {
    return $this->tokenData->merchantReference ?? NULL;
  }

  /**
   * Gets the payment status.
   *
   * @return string|null
   *   The payment status.
   */
  public function getPaymentStatus(): ?string {
    return $this->tokenData->paymentStatus ?? NULL;
  }

  /**
   * Gets the order UUID.
   *
   * @return string|null
   *   The order UUID.
   */
  public function getUuid(): ?string {
    return $this->tokenData->uuid ?? NULL;
  }

  /**
   * Gets the grand total.
   *
   * @return string|null
   *   The grand total.
   */
  public function getGrandTotal(): ?string {
    return isset($this->tokenData->grandTotal) ? (string) $this->tokenData->grandTotal : NULL;
  }

  /**
   * Gets the currency.
   *
   * @return string|null
   *   The currency.
   */
  public function getCurrency(): ?string {
    return $this->tokenData->currency ?? NULL;
  }

  /**
   * Gets a raw property value.
   *
   * @param string $property
   *   The property name.
   *
   * @return mixed
   *   The property value.
   */
  public function get(string $property): mixed {
    return $this->tokenData->{$property} ?? NULL;
  }

}

<?php

namespace Drupal\commerce_montonio\Dto;

use Drupal\address\AddressInterface;

/**
 * Data Transfer Object for Montonio address data.
 */
class MontonioAddressDto {

  public function __construct(
    public readonly string $firstName,
    public readonly string $lastName,
    public readonly string $email,
    public readonly string $addressLine1,
    public readonly ?string $addressLine2,
    public readonly string $locality,
    public readonly string $region,
    public readonly string $country,
    public readonly string $postalCode,
  ) {}

  /**
   * Creates a MontonioAddressDto from a Drupal AddressInterface.
   *
   * @param \Drupal\address\AddressInterface $address
   *   The address to convert.
   * @param string $email
   *   The email address.
   *
   * @return static
   *   The DTO instance.
   */
  public static function fromAddress(AddressInterface $address, string $email): static {
    return new static(
      firstName: $address->getGivenName(),
      lastName: $address->getFamilyName(),
      email: $email,
      addressLine1: $address->getAddressLine1(),
      addressLine2: $address->getAddressLine2(),
      locality: $address->getLocality(),
      region: $address->getAdministrativeArea(),
      country: $address->getCountryCode(),
      postalCode: $address->getPostalCode(),
    );
  }

  /**
   * Converts the DTO to an array for API consumption.
   *
   * @return array
   *   The address data as an array.
   */
  public function toArray(): array {
    return [
      'firstName' => $this->firstName,
      'lastName' => $this->lastName,
      'email' => $this->email,
      'addressLine1' => $this->addressLine1,
      'addressLine2' => $this->addressLine2,
      'locality' => $this->locality,
      'region' => $this->region,
      'country' => $this->country,
      'postalCode' => $this->postalCode,
    ];
  }

}

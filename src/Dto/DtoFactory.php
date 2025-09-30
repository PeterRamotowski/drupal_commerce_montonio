<?php

namespace Drupal\commerce_montonio\Dto;

use Drupal\address\AddressInterface;
use Drupal\commerce_montonio\Dto\MontonioAddressDto;
use Drupal\commerce_montonio\Dto\MontonioTokenDto;

/**
 * Factory for creating DTOs.
 */
class DtoFactory {

  /**
   * Creates a MontonioAddressDto from a Drupal AddressInterface.
   *
   * @param AddressInterface $address
   *   The Drupal address.
   * @param string $email
   *   The email address.
   *
   * @return MontonioAddressDto
   *   The Montonio address DTO.
   */
  public function createAddressDto(AddressInterface $address, string $email): MontonioAddressDto {
    return MontonioAddressDto::fromAddress($address, $email);
  }

  /**
   * Creates a MontonioTokenDto from decoded JWT data.
   *
   * @param object $tokenData
   *   The decoded JWT token data.
   *
   * @return MontonioTokenDto
   *   The Montonio token DTO.
   */
  public function createTokenDto(object $tokenData): MontonioTokenDto {
    return new MontonioTokenDto($tokenData);
  }

}
# Commerce Montonio

This module provides Montonio payment gateway integration for
Drupal Commerce 3.

## Features

- Supports multiple payment methods offered by Montonio (e.g., BLIK, card
payments, bank transfers).
- Customers can select their preferred payment method during checkout.
- Automatic handling of payment status updates via webhooks.
- Sandbox and live mode support.

## Requirements

Commerce Montonio module requires Drupal Commerce 3 module enabled.

## Configuration

1. Go to Commerce > Configuration > Payment > Payment gateways.
2. Add a new payment gateway and select 'Montonio' as the plugin.
3. Configure your Montonio API credentials:
- Access Key: Your Montonio Access Key from the Partner System
- Secret Key: Your Montonio Secret Key from the Partner System
- Mode: Test for sandbox, Live for production
- Enabled Payment Methods: Select which payment methods should be available
to customers
- Default Payment Method: Select a default payment method for the checkout page
4. Save the payment gateway configuration.

## Usage

- Customers can select Montonio as a payment method during checkout.
- The module handles Montonio webhook notifications to update payment statuses
automatically.

## Installation

Install as you would normally install a contributed Drupal module.
For further information, see [Installing Drupal Modules](https://www.drupal.org/docs/extending-drupal/installing-drupal-modules).

## Support

Submit bug reports and feature suggestions, or track changes in the
[issue queue](https://www.drupal.org/project/issues/commerce_montonio).

## Maintainers

- Piotr Ramotowski - [ramotowski](https://www.drupal.org/u/ramotowski)

<?php

namespace Drupal\commerce_montonio\Service;

/**
 * Validates Montonio redirect URLs against open redirect and SSRF attacks.
 */
final class MontonioRedirectUrlValidator {

  /**
   * Checks whether a redirect URL is safe to use.
   *
   * A URL is considered safe if it uses HTTPS and its host ends with
   * 'montonio.com', preventing redirects to arbitrary external hosts.
   *
   * @param string $url
   *   The URL to validate.
   *
   * @return bool
   *   TRUE if the URL is safe, FALSE otherwise.
   */
  public static function isSafe(string $url): bool {
    $parts = parse_url($url);

    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
      return FALSE;
    }

    $scheme = strtolower($parts['scheme']);
    $host = strtolower(rtrim($parts['host'], '.'));

    return $scheme === 'https'
      && ($host === 'montonio.com' || str_ends_with($host, '.montonio.com'));
  }

}

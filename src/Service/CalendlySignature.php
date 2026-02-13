<?php

namespace Drupal\calendly_to_civicrm\Service;

/**
 * Verifies Calendly webhook signatures.
 *
 * Header format: 'Calendly-Webhook-Signature' -> t=TIMESTAMP,v1=HMAC_HEX
 * HMAC_HEX = hex(HMAC_SHA256( signingKey, t + '.' + rawBody ))
 */
class CalendlySignature {

  /**
   * Verifies HMAC and timestamp freshness.
   *
   * @param string $signingKey
   *   Webhook signing key.
   * @param string $header
   *   Raw Calendly signature header.
   * @param string $rawBody
   *   Raw request body.
   * @param int|null $now
   *   Current Unix timestamp override (for tests).
   * @param int $tolerance
   *   Allowed clock skew in seconds.
   */
  public function validate(string $signingKey, string $header, string $rawBody, ?int $now = NULL, int $tolerance = 300): bool {
    if (empty($signingKey) || empty($header)) {
      return FALSE;
    }
    $parts = [];
    foreach (explode(',', $header) as $pair) {
      $kv = explode('=', trim($pair), 2);
      if (count($kv) === 2) {
        $parts[$kv[0]] = $kv[1];
      }
    }
    if (empty($parts['t']) || empty($parts['v1'])) {
      return FALSE;
    }
    if (!is_numeric($parts['t'])) {
      return FALSE;
    }
    $timestamp = (int) $parts['t'];
    $current_time = $now ?? time();
    if (abs($current_time - $timestamp) > $tolerance) {
      return FALSE;
    }
    $signedPayload = $parts['t'] . '.' . $rawBody;
    $calc = hash_hmac('sha256', $signedPayload, $signingKey);
    return hash_equals($calc, $parts['v1']);
  }
}

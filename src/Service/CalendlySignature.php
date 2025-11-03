<?php

namespace Drupal\calendly_to_civicrm\Service;

/**
 * Verifies Calendly webhook signatures.
 *
 * Header format: 'Calendly-Webhook-Signature' -> t=TIMESTAMP,v1=HMAC_HEX
 * HMAC_HEX = hex(HMAC_SHA256( signingKey, t + '.' + rawBody ))
 */
class CalendlySignature {

  public function validate(string $signingKey, string $header, string $rawBody): bool {
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
    $signedPayload = $parts['t'] . '.' . $rawBody;
    $calc = hash_hmac('sha256', $signedPayload, $signingKey);
    return hash_equals($calc, $parts['v1']);
  }
}

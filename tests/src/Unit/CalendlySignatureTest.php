<?php

namespace Drupal\Tests\calendly_to_civicrm\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\calendly_to_civicrm\Service\CalendlySignature;

/**
 * @coversDefaultClass \Drupal\calendly_to_civicrm\Service\CalendlySignature
 * @group calendly_to_civicrm
 */
class CalendlySignatureTest extends UnitTestCase {

  /**
   * @covers ::validate
   */
  public function testValidateAcceptsFreshSignature(): void {
    $service = new CalendlySignature();
    $signing_key = 'test-signing-key';
    $body = '{"event":"invitee.created"}';
    $timestamp = 1700000000;
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, $signing_key);
    $header = 't=' . $timestamp . ',v1=' . $signature;

    $this->assertTrue($service->validate($signing_key, $header, $body, $timestamp + 10));
  }

  /**
   * @covers ::validate
   */
  public function testValidateRejectsExpiredSignature(): void {
    $service = new CalendlySignature();
    $signing_key = 'test-signing-key';
    $body = '{"event":"invitee.created"}';
    $timestamp = 1700000000;
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, $signing_key);
    $header = 't=' . $timestamp . ',v1=' . $signature;

    $this->assertFalse($service->validate($signing_key, $header, $body, $timestamp + 1000, 300));
  }

  /**
   * @covers ::validate
   */
  public function testValidateRejectsMalformedHeader(): void {
    $service = new CalendlySignature();
    $this->assertFalse($service->validate('key', 'v1=abc123', '{"x":1}', 1700000000));
  }

  /**
   * @covers ::validate
   */
  public function testValidateRejectsInvalidHmac(): void {
    $service = new CalendlySignature();
    $body = '{"event":"invitee.created"}';
    $header = 't=1700000000,v1=not-a-real-signature';

    $this->assertFalse($service->validate('test-signing-key', $header, $body, 1700000000));
  }

}


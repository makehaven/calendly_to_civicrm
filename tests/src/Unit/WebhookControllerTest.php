<?php

namespace Drupal\Tests\calendly_to_civicrm\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Queue\QueueInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\calendly_to_civicrm\Controller\WebhookController;
use Drupal\calendly_to_civicrm\Service\CalendlySignature;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @coversDefaultClass \Drupal\calendly_to_civicrm\Controller\WebhookController
 * @group calendly_to_civicrm
 */
class WebhookControllerTest extends UnitTestCase {

  /**
   * @covers ::receive
   */
  public function testRejectsWebhookWhenNoAuthConfigured(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->never())->method('createItem');

    $controller = $this->buildController([
      'shared_token' => '',
      'signing_key' => '',
    ], [
      'webhook_signing_key' => '',
    ], $queue, TRUE, new CalendlySignature());

    $response = $controller->receive($this->buildRequest(['event' => 'invitee.created']));
    $this->assertSame(503, $response->getStatusCode());
  }

  /**
   * @covers ::receive
   */
  public function testAcceptsWebhookWithSharedSigningKeyFallback(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->once())->method('createItem');

    $controller = $this->buildController([
      'shared_token' => '',
      'signing_key' => '',
    ], [
      'webhook_signing_key' => 'shared-signing-key',
    ], $queue, TRUE, new CalendlySignature());

    $payload = ['event' => 'invitee.created'];
    $raw = json_encode($payload);
    $timestamp = time();
    $sig = hash_hmac('sha256', $timestamp . '.' . $raw, 'shared-signing-key');
    $request = $this->buildRequest($payload);
    $request->headers->set('Calendly-Webhook-Signature', 't=' . $timestamp . ',v1=' . $sig);

    $response = $controller->receive($request);
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * @covers ::receive
   */
  public function testRejectsExpiredSignature(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->never())->method('createItem');

    $controller = $this->buildController([
      'shared_token' => '',
      'signing_key' => 'signing-key',
    ], [], $queue, TRUE, new CalendlySignature());

    $payload = ['event' => 'invitee.created'];
    $raw = json_encode($payload);
    $timestamp = time() - 1000;
    $sig = hash_hmac('sha256', $timestamp . '.' . $raw, 'signing-key');
    $request = $this->buildRequest($payload);
    $request->headers->set('Calendly-Webhook-Signature', 't=' . $timestamp . ',v1=' . $sig);

    $response = $controller->receive($request);
    $this->assertSame(401, $response->getStatusCode());
  }

  /**
   * @covers ::receive
   */
  public function testDedupedWebhookDoesNotEnqueueAgain(): void {
    $queue = $this->createMock(QueueInterface::class);
    $queue->expects($this->never())->method('createItem');

    $signature = $this->createMock(CalendlySignature::class);
    $signature->expects($this->never())->method('validate');

    $controller = $this->buildController([
      'shared_token' => 'abc123',
      'signing_key' => '',
    ], [], $queue, FALSE, $signature);

    $request = $this->buildRequest(['event' => 'invitee.created'], 'abc123');
    $response = $controller->receive($request);
    $this->assertSame(200, $response->getStatusCode());
  }

  /**
   * Builds a unit-testable controller with mocked dependencies.
   */
  private function buildController(
    array $module_settings,
    array $shared_settings,
    QueueInterface $queue,
    bool $dedupe_set_result,
    CalendlySignature $signature
  ): WebhookController {
    $module_config = $this->createMock(Config::class);
    $module_config->method('get')
      ->willReturnCallback(static fn(string $key) => $module_settings[$key] ?? NULL);

    $shared_config = $this->createMock(Config::class);
    $shared_config->method('get')
      ->willReturnCallback(static fn(string $key) => $shared_settings[$key] ?? NULL);

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->willReturnCallback(static function (string $name) use ($module_config, $shared_config) {
        if ($name === 'calendly_to_civicrm.settings') {
          return $module_config;
        }
        if ($name === 'calendly_availability.settings') {
          return $shared_config;
        }
        return $shared_config;
      });

    $queue_factory = $this->createMock(QueueFactory::class);
    $queue_factory->method('get')
      ->with(WebhookController::QUEUE)
      ->willReturn($queue);

    $dedupe_store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $dedupe_store->method('setWithExpireIfNotExists')
      ->willReturn($dedupe_set_result);

    $keyvalue_expirable = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $keyvalue_expirable->method('get')
      ->with(WebhookController::DEDUPE_COLLECTION)
      ->willReturn($dedupe_store);

    $logger = $this->createMock(LoggerInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')
      ->with('calendly_to_civicrm')
      ->willReturn($logger);

    return new WebhookController(
      $config_factory,
      $queue_factory,
      $signature,
      $keyvalue_expirable,
      $logger_factory
    );
  }

  /**
   * Creates a request with JSON body and optional shared token query string.
   */
  private function buildRequest(array $payload, ?string $token = NULL): Request {
    $request = Request::create('/calendly/webhook', 'POST', [], [], [], [], json_encode($payload));
    if ($token !== NULL) {
      $request->query->set('token', $token);
    }
    return $request;
  }

}


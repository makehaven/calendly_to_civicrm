<?php

namespace Drupal\calendly_to_civicrm\Controller;

use Drupal\calendly_to_civicrm\EventParser;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\calendly_to_civicrm\Service\CalendlySignature;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives Calendly webhooks and enqueues processing.
 */
class WebhookController extends ControllerBase {

  const QUEUE = 'calendly_to_civicrm.queue';
  const DEDUPE_COLLECTION = 'calendly_to_civicrm.webhook_dedupe';
  const DEDUPE_TTL = 86400;

  protected QueueFactory $queueFactory;
  protected CalendlySignature $signature;
  protected KeyValueStoreExpirableInterface $dedupeStore;
  protected $logger;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    QueueFactory $queue_factory,
    CalendlySignature $signature,
    KeyValueExpirableFactoryInterface $keyvalue_expirable_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->configFactory = $config_factory;
    $this->queueFactory = $queue_factory;
    $this->signature = $signature;
    $this->dedupeStore = $keyvalue_expirable_factory->get(self::DEDUPE_COLLECTION);
    $this->logger = $logger_factory->get('calendly_to_civicrm');
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('queue'),
      $container->get('calendly_to_civicrm.signature'),
      $container->get('keyvalue.expirable'),
      $container->get('logger.factory'),
    );
  }

  public function receive(Request $request) {
    $config = $this->configFactory->get('calendly_to_civicrm.settings');

    $tokenConfig = trim((string) $config->get('shared_token'));
    $tokenReq = (string) $request->query->get('token', '');

    $signingKey = $this->getSigningKey($config);
    $sigHeader = (string) $request->headers->get('Calendly-Webhook-Signature', '');

    $raw = $request->getContent() ?? '';
    $payload = json_decode($raw, TRUE);
    if (!is_array($payload)) {
      $this->logger->warning('Invalid JSON payload received.');
      return new Response('Bad Request', 400);
    }

    if ($tokenConfig === '' && $signingKey === '') {
      $this->logger->error('Calendly webhook auth is not configured. Set a shared token and/or signing key.');
      return new Response('Service Unavailable', 503);
    }

    $authOk = FALSE;
    if ($tokenConfig !== '') {
      $authOk = hash_equals($tokenConfig, $tokenReq);
    }
    if (!$authOk && $signingKey !== '') {
      $authOk = $this->signature->validate($signingKey, $sigHeader, $raw);
    }
    if (!$authOk) {
      $this->logger->warning('Unauthorized webhook attempt.');
      return new Response('Unauthorized', 401);
    }

    $dedupeKey = $this->buildDedupeKey($payload, $raw);
    if (!$this->dedupeStore->setWithExpireIfNotExists($dedupeKey, time(), self::DEDUPE_TTL)) {
      $this->logger->notice('Duplicate Calendly webhook ignored for dedupe key @key.', ['@key' => $dedupeKey]);
      return new Response('OK', 200);
    }

    $event = EventParser::parse($payload);
    $queueData = [
      'payload' => $payload,
      'event' => $event,
      'received' => time(),
      'dedupe_key' => $dedupeKey,
    ];

    $queue = $this->queueFactory->get(self::QUEUE);
    $queue->createItem($queueData);

    return new Response('OK', 200);
  }

  /**
   * Gets the effective signing key, falling back to calendly_availability.
   */
  protected function getSigningKey($config): string {
    $signing_key = trim((string) $config->get('signing_key'));
    if ($signing_key !== '') {
      return $signing_key;
    }
    $shared_calendly = $this->configFactory->get('calendly_availability.settings');
    return trim((string) $shared_calendly->get('webhook_signing_key'));
  }

  /**
   * Builds a deterministic dedupe key for webhook idempotency.
   */
  protected function buildDedupeKey(array $payload, string $raw): string {
    $event_uri = (string) ($payload['payload']['event'] ?? $payload['event'] ?? '');
    $invitee_uri = (string) ($payload['payload']['invitee'] ?? $payload['invitee'] ?? '');
    $event_type = (string) ($payload['event'] ?? '');
    $created_at = (string) ($payload['created_at'] ?? '');

    if ($event_uri !== '' || $invitee_uri !== '' || $event_type !== '' || $created_at !== '') {
      $fingerprint = $event_uri . '|' . $invitee_uri . '|' . $event_type . '|' . $created_at;
      return hash('sha256', $fingerprint);
    }

    return hash('sha256', $raw);
  }

}

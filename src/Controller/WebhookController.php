<?php

namespace Drupal\calendly_to_civicrm\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\calendly_to_civicrm\Service\CalendlySignature;
use Drupal\calendly_to_civicrm\EventParser;
use Drupal\Core\Queue\QueueFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Receives Calendly webhooks and enqueues processing.
 */
class WebhookController extends ControllerBase {

  const QUEUE = 'calendly_to_civicrm.queue';

  protected $queueFactory;
  protected $signature;

  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->queueFactory = $container->get('queue');
    $instance->signature = $container->get('calendly_to_civicrm.signature');
    return $instance;
  }

  public function receive(Request $request) {
    $config = $this->config('calendly_to_civicrm.settings');

    $tokenConfig = trim((string) $config->get('shared_token'));
    $tokenReq = (string) $request->query->get('token', '');

    $signingKey = trim((string) $config->get('signing_key'));
    $sigHeader = (string) $request->headers->get('Calendly-Webhook-Signature', '');

    $raw = $request->getContent() ?? '';
    $payload = json_decode($raw, TRUE);
    if (!is_array($payload)) {
      $this->getLogger('calendly_to_civicrm')->warning('Invalid JSON payload received.');
      return new Response('Bad Request', 400);
    }

    $authOk = TRUE;
    if ($tokenConfig !== '') {
      $authOk = hash_equals($tokenConfig, $tokenReq);
    }
    if (!$authOk && $signingKey !== '') {
      /** @var \Drupal\calendly_to_civicrm\Service\CalendlySignature $verifier */
      $verifier = $this->signature;
      $authOk = $verifier->validate($signingKey, $sigHeader, $raw);
    }
    if (!$authOk) {
      $this->getLogger('calendly_to_civicrm')->warning('Unauthorized webhook attempt.');
      return new Response('Unauthorized', 401);
    }

    $event = EventParser::parse($payload);
    $queueData = ['payload' => $payload, 'event' => $event, 'received' => time()];

    $queue = $this->queueFactory->get(self::QUEUE);
    $queue->createItem($queueData);

    return new Response('OK', 200);
  }

}

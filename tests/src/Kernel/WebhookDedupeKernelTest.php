<?php

namespace Drupal\Tests\calendly_to_civicrm\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\calendly_to_civicrm\Controller\WebhookController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies webhook dedupe behavior against real Drupal services.
 *
 * @group calendly_to_civicrm
 */
class WebhookDedupeKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'calendly_to_civicrm',
  ];

  /**
   * Tests that duplicate webhook payloads only enqueue once.
   */
  public function testDuplicateWebhookIsEnqueuedOnlyOnce(): void {
    $config = $this->container->get('config.factory')->getEditable('calendly_to_civicrm.settings');
    $config
      ->set('shared_token', '')
      ->set('signing_key', 'kernel-signing-key')
      ->save();

    $queue = $this->container->get('queue')->get(WebhookController::QUEUE);
    $queue->deleteQueue();
    $queue->createQueue();
    $this->assertSame(0, $queue->numberOfItems());

    $payload = [
      'event' => 'invitee.created',
      'created_at' => '2026-02-13T12:00:00Z',
      'payload' => [
        'event' => 'https://api.calendly.com/scheduled_events/AAA',
        'invitee' => 'https://api.calendly.com/scheduled_events/AAA/invitees/BBB',
      ],
    ];
    $raw = json_encode($payload);
    $timestamp = time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $raw, 'kernel-signing-key');
    $signature_header = 't=' . $timestamp . ',v1=' . $signature;

    $controller = $this->container->get('class_resolver')->getInstanceFromDefinition(WebhookController::class);

    $request_1 = Request::create('/calendly/webhook', 'POST', [], [], [], [], $raw);
    $request_1->headers->set('Calendly-Webhook-Signature', $signature_header);
    $response_1 = $controller->receive($request_1);
    $this->assertSame(200, $response_1->getStatusCode());
    $this->assertSame(1, $queue->numberOfItems());

    $request_2 = Request::create('/calendly/webhook', 'POST', [], [], [], [], $raw);
    $request_2->headers->set('Calendly-Webhook-Signature', $signature_header);
    $response_2 = $controller->receive($request_2);
    $this->assertSame(200, $response_2->getStatusCode());
    $this->assertSame(1, $queue->numberOfItems());
  }

}


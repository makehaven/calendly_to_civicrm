<?php

namespace Drupal\Tests\calendly_to_civicrm\Kernel;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\calendly_to_civicrm\Plugin\QueueWorker\CalendlyProcessor;

/**
 * Verifies queue worker idempotency behavior with real Drupal services.
 *
 * @group calendly_to_civicrm
 */
class CalendlyProcessorDedupeKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'calendly_to_civicrm',
  ];

  public function testWorkerIdempotencyWithRealDedupeStore(): void {
    $logger_factory = $this->container->get('logger.factory');
    $config_factory = $this->container->get('config.factory');
    $keyvalue_expirable = $this->container->get('keyvalue.expirable');

    $worker = new TestCalendlyProcessor(
      [],
      'calendly_to_civicrm.queue',
      [],
      $logger_factory,
      $config_factory,
      $keyvalue_expirable
    );

    $data = [
      'dedupe_key' => 'controller-dedupe-key',
      'payload' => [
        'payload' => [
          'event' => 'https://api.calendly.com/scheduled_events/AAA',
          'invitee' => 'https://api.calendly.com/scheduled_events/AAA/invitees/BBB',
        ],
      ],
      'event' => [
        'title' => 'Tour Session',
        'invitee_email' => 'invitee@example.org',
        'invitee_name' => 'Invitee Name',
        'organizer_email' => NULL,
        'start' => '2026-02-13T12:00:00Z',
        'end' => '2026-02-13T12:30:00Z',
      ],
    ];

    $worker->processItem($data);
    $worker->processItem($data);

    $this->assertSame(1, $worker->createdActivities);
  }

}

/**
 * Kernel-test double that avoids booting CiviCRM.
 */
class TestCalendlyProcessor extends CalendlyProcessor {

  public int $createdActivities = 0;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelFactoryInterface $logger_factory, ConfigFactoryInterface $config_factory, KeyValueExpirableFactoryInterface $keyvalue_expirable_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $keyvalue_expirable_factory);
  }

  protected static function civicrmBoot() {}

  protected function civiFindContactByEmail(string $email): ?int {
    return NULL;
  }

  protected function civiFindOrCreateContact(string $email, ?string $displayName): int {
    return 123;
  }

  protected function civiCreateActivity(array $params): int {
    $this->createdActivities++;
    return 456;
  }

}

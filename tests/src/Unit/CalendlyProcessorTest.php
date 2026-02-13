<?php

namespace Drupal\Tests\calendly_to_civicrm\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\calendly_to_civicrm\Plugin\QueueWorker\CalendlyProcessor;

/**
 * @coversDefaultClass \Drupal\calendly_to_civicrm\Plugin\QueueWorker\CalendlyProcessor
 * @group calendly_to_civicrm
 */
class CalendlyProcessorTest extends UnitTestCase {

  /**
   * @covers ::processItem
   */
  public function testProcessItemSkipsWhenActivityDedupeExists(): void {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->expects($this->once())
      ->method('setWithExpireIfNotExists')
      ->willReturn(FALSE);
    $store->expects($this->never())->method('delete');

    $worker = $this->buildWorker($store, FALSE);
    $worker->processItem($this->buildData());
    $this->assertSame(0, $worker->createdActivities);
  }

  /**
   * @covers ::processItem
   */
  public function testProcessItemClearsDedupeKeyWhenActivityCreateFails(): void {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->expects($this->once())
      ->method('setWithExpireIfNotExists')
      ->willReturn(TRUE);
    $store->expects($this->once())
      ->method('delete');

    $worker = $this->buildWorker($store, TRUE);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('activity-create-failed');
    $worker->processItem($this->buildData());
  }

  /**
   * Builds a worker using a test double for Civi interactions.
   */
  private function buildWorker(KeyValueStoreExpirableInterface $store, bool $throw_on_create): TestableCalendlyProcessor {
    $module_config = $this->createMock(Config::class);
    $module_config->method('get')
      ->willReturnCallback(static function (string $key) {
        return match ($key) {
          'default_activity_type' => 'Meeting',
          'rules_yaml' => '',
          'staff_email_map_yaml' => '',
          'prefer_config_map' => FALSE,
          default => NULL,
        };
      });

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->with('calendly_to_civicrm.settings')
      ->willReturn($module_config);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')
      ->with('calendly_to_civicrm')
      ->willReturn($logger);

    $keyvalue_factory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $keyvalue_factory->method('get')
      ->with(CalendlyProcessor::ACTIVITY_DEDUPE_COLLECTION)
      ->willReturn($store);

    return new TestableCalendlyProcessor([], 'calendly_to_civicrm.queue', [], $logger_factory, $config_factory, $keyvalue_factory, $throw_on_create);
  }

  /**
   * Builds a representative queue item.
   */
  private function buildData(): array {
    return [
      'dedupe_key' => 'controller-key-1',
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
  }

}

/**
 * Test double that stubs Civi interactions.
 */
class TestableCalendlyProcessor extends CalendlyProcessor {

  public int $createdActivities = 0;
  private bool $throwOnCreate;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, $logger_factory, ConfigFactoryInterface $config_factory, KeyValueExpirableFactoryInterface $keyvalue_expirable_factory, bool $throw_on_create) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $keyvalue_expirable_factory);
    $this->throwOnCreate = $throw_on_create;
  }

  protected static function civicrmBoot() {}

  protected function civiFindContactByEmail(string $email): ?int {
    return 101;
  }

  protected function civiFindOrCreateContact(string $email, ?string $displayName): int {
    return 202;
  }

  protected function civiCreateActivity(array $params): int {
    if ($this->throwOnCreate) {
      throw new \RuntimeException('activity-create-failed');
    }
    $this->createdActivities++;
    return 303;
  }

}


<?php

namespace Drupal\Tests\calendly_to_civicrm\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Tests\UnitTestCase;
use Drupal\calendly_to_civicrm\Plugin\QueueWorker\CalendlyProcessor;
use Drupal\civicrm\Civicrm;

/**
 * @coversDefaultClass \Drupal\calendly_to_civicrm\Plugin\QueueWorker\CalendlyProcessor
 * @group calendly_to_civicrm
 */
class CalendlyProcessorTest extends UnitTestCase {

  /**
   * Optional override for the unresolved-attempt store.
   */
  private ?KeyValueStoreExpirableInterface $attemptStore = NULL;

  /**
   * Optional override for the Calendly token the worker sees.
   */
  private string $calendlyToken = 'test-token';

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
   * An unresolved booking must never be written under the default type.
   *
   * @covers ::processItem
   */
  public function testProcessItemSuspendsQueueWhenNoTokenIsConfigured(): void {
    $this->calendlyToken = '';
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    // The guard runs before dedupe, so the dedupe store is never touched.
    $store->expects($this->never())->method('setWithExpireIfNotExists');

    $worker = $this->buildWorker($store, FALSE);

    $this->expectException(SuspendQueueException::class);
    $worker->processItem($this->buildUnresolvedData());
  }

  /**
   * With a token present an unresolved booking is retried, not written.
   *
   * @covers ::processItem
   */
  public function testProcessItemRequeuesUnresolvedBookingWhileAttemptsRemain(): void {
    $attempts = $this->createMock(KeyValueStoreExpirableInterface::class);
    $attempts->method('get')->willReturn(1);
    $attempts->expects($this->once())->method('setWithExpire');
    $this->attemptStore = $attempts;

    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->expects($this->never())->method('setWithExpireIfNotExists');
    $worker = $this->buildWorker($store, FALSE);

    $this->expectException(RequeueException::class);
    $worker->processItem($this->buildUnresolvedData());
  }

  /**
   * After the retry budget the booking is dropped, still without writing.
   *
   * @covers ::processItem
   */
  public function testProcessItemGivesUpAfterMaxAttemptsWithoutCreating(): void {
    $attempts = $this->createMock(KeyValueStoreExpirableInterface::class);
    $attempts->method('get')->willReturn(CalendlyProcessor::UNRESOLVED_MAX_ATTEMPTS);
    $this->attemptStore = $attempts;

    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->expects($this->never())->method('setWithExpireIfNotExists');
    $worker = $this->buildWorker($store, FALSE);

    $worker->processItem($this->buildUnresolvedData());
    $this->assertSame(0, $worker->createdActivities, 'No activity may be created for an unresolved booking.');
  }

  /**
   * A booking with a real title but no start time is unresolved too.
   *
   * This is the half that dated 391 rows to when the webhook fired.
   *
   * @covers ::processItem
   */
  public function testProcessItemRefusesBookingWithNoStartTime(): void {
    $this->calendlyToken = '';
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->expects($this->never())->method('setWithExpireIfNotExists');
    $worker = $this->buildWorker($store, FALSE);

    $data = $this->buildData();
    $data['event']['start'] = '';

    $this->expectException(SuspendQueueException::class);
    $worker->processItem($data);
  }

  /**
   * A fully resolved booking still writes exactly as before.
   *
   * @covers ::processItem
   */
  public function testProcessItemStillCreatesResolvedActivity(): void {
    $store = $this->createMock(KeyValueStoreExpirableInterface::class);
    $store->method('setWithExpireIfNotExists')->willReturn(TRUE);
    $worker = $this->buildWorker($store, FALSE);

    $worker->processItem($this->buildData());
    $this->assertSame(1, $worker->createdActivities);
    $this->assertSame('2026-02-13T12:00:00Z', $worker->lastParams['activity_date_time']);
    $this->assertSame('Tour Session', $worker->lastParams['subject']);
  }

  /**
   * Builds a queue item shaped the way it arrives when enrichment cannot run.
   *
   * URIs only, so the placeholder title survives and there is no start time.
   */
  private function buildUnresolvedData(): array {
    $data = $this->buildData();
    $data['event']['title'] = CalendlyProcessor::UNRESOLVED_TITLE;
    $data['event']['start'] = NULL;
    return $data;
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

    $availability_config = $this->createMock(Config::class);
    $token = $this->calendlyToken;
    $availability_config->method('get')
      ->willReturnCallback(static function (string $key) use ($token) {
        return $key === 'personal_access_token' ? $token : NULL;
      });

    $config_factory = $this->createMock(ConfigFactoryInterface::class);
    $config_factory->method('get')
      ->willReturnCallback(static function (string $name) use ($module_config, $availability_config) {
        return $name === 'calendly_availability.settings' ? $availability_config : $module_config;
      });

    $logger = $this->createMock(LoggerChannelInterface::class);
    $logger_factory = $this->createMock(LoggerChannelFactoryInterface::class);
    $logger_factory->method('get')
      ->with('calendly_to_civicrm')
      ->willReturn($logger);

    $attempt_store = $this->attemptStore ?? $this->createMock(KeyValueStoreExpirableInterface::class);
    $keyvalue_factory = $this->createMock(KeyValueExpirableFactoryInterface::class);
    $keyvalue_factory->method('get')
      ->willReturnCallback(static function (string $collection) use ($store, $attempt_store) {
        return $collection === CalendlyProcessor::ACTIVITY_DEDUPE_COLLECTION ? $store : $attempt_store;
      });

    $civicrm = $this->createMock(Civicrm::class);

    return new TestableCalendlyProcessor([], 'calendly_to_civicrm.queue', [], $logger_factory, $config_factory, $keyvalue_factory, $civicrm, $throw_on_create);
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

  /**
   * Params handed to the last civiCreateActivity() call.
   *
   * @var array
   */
  public array $lastParams = [];

  private bool $throwOnCreate;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, $logger_factory, ConfigFactoryInterface $config_factory, KeyValueExpirableFactoryInterface $keyvalue_expirable_factory, Civicrm $civicrm, bool $throw_on_create) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $logger_factory, $config_factory, $keyvalue_expirable_factory, $civicrm);
    $this->throwOnCreate = $throw_on_create;
  }

  protected function civicrmBoot() {}

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
    $this->lastParams = $params;
    return 303;
  }

}

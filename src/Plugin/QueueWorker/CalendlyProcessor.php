<?php

namespace Drupal\calendly_to_civicrm\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\calendly_to_civicrm\EventParser;

/**
 * @QueueWorker(
 *   id = "calendly_to_civicrm.queue",
 *   title = @Translation("Calendly → CiviCRM queue"),
 *   cron = {"time" = 30}
 * )
 */
class CalendlyProcessor extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  const ACTIVITY_DEDUPE_COLLECTION = 'calendly_to_civicrm.activity_dedupe';
  const ACTIVITY_DEDUPE_TTL = 2592000;

  protected $logger;
  protected ConfigFactoryInterface $configFactory;
  protected KeyValueStoreExpirableInterface $activityDedupeStore;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, $logger_factory, ConfigFactoryInterface $config_factory, KeyValueExpirableFactoryInterface $keyvalue_expirable_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger_factory->get('calendly_to_civicrm');
    $this->configFactory = $config_factory;
    $this->activityDedupeStore = $keyvalue_expirable_factory->get(self::ACTIVITY_DEDUPE_COLLECTION);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('keyvalue.expirable')
    );
  }

  public function processItem($data) {
    $config = $this->configFactory->get('calendly_to_civicrm.settings');
    $rulesYaml = (string) $config->get('rules_yaml');
    $defaultActivityType = (string) ($config->get('default_activity_type') ?? 'Meeting');
    $preferConfigMap = (bool) $config->get('prefer_config_map');
    $staffMapYaml = (string) $config->get('staff_email_map_yaml');

    $rules = ['rules' => [], 'default_activity_type' => $defaultActivityType];
    if ($rulesYaml) {
      try {
        $rules = \Symfony\Component\Yaml\Yaml::parse($rulesYaml) ?: $rules;
        if (!isset($rules['default_activity_type'])) {
          $rules['default_activity_type'] = $defaultActivityType;
        }
      }
      catch (\Throwable $e) {
        $this->logger->warning('Invalid rules YAML: @m', ['@m' => $e->getMessage()]);
      }
    }

    $staffMap = [];
    if ($staffMapYaml) {
      try {
        $staffMap = \Symfony\Component\Yaml\Yaml::parse($staffMapYaml) ?: [];
      }
      catch (\Throwable $e) {
        $this->logger->warning('Invalid staff map YAML: @m', ['@m' => $e->getMessage()]);
      }
    }

    $event = $data['event'] ?? EventParser::parse($data['payload'] ?? []);

    $activityType = EventParser::classifyActivity($rules, $event);

    $inviteeEmail = $event['invitee_email'] ?? NULL;
    $inviteeName  = $event['invitee_name'] ?? NULL;
    $organizerEmail = $event['organizer_email'] ?? NULL;
    $title = $event['title'] ?? 'Calendly Event';
    $start = $event['start'] ?? NULL;

    if (empty($inviteeEmail)) {
      $payload = $data['payload'] ?? [];
      $inviteeEmail = $payload['payload']['email'] ?? $payload['email'] ?? NULL;
    }

    if (empty($inviteeEmail)) {
      $this->logger->error('Missing invitee email; skipping activity creation for @title', ['@title' => $title]);
      return;
    }

    try {
      $inviteeId = $this->civiFindOrCreateContact($inviteeEmail, $inviteeName);
    }
    catch (\Throwable $e) {
      $this->logger->error('Civi error creating invitee: @m', ['@m' => $e->getMessage()]);
      throw $e;
    }

    $staffId = NULL;
    if ($organizerEmail) {
      if ($preferConfigMap && isset($staffMap[$organizerEmail])) {
        $staffId = (int) $staffMap[$organizerEmail];
      }
      if (!$staffId) {
        $staffId = $this->civiFindContactByEmail($organizerEmail);
      }
    }

    $activityDedupeKey = $this->buildActivityDedupeKey($data, $event, $activityType, (string) $inviteeEmail, (string) $start, (string) $title);
    if (!$this->activityDedupeStore->setWithExpireIfNotExists($activityDedupeKey, time(), self::ACTIVITY_DEDUPE_TTL)) {
      $this->logger->notice('Skipping duplicate Calendly activity for dedupe key @key.', ['@key' => $activityDedupeKey]);
      return;
    }

    try {
      $this->civiCreateActivity([
        'activity_type_id' => $activityType,
        'source_contact_id' => $staffId ?: NULL,
        'assignee_contact_id' => $staffId ?: NULL,
        'target_contact_id' => $inviteeId,
        'activity_date_time' => $start ?: date('c'),
        'subject' => $title,
      ]);
      $this->logger->notice('Created activity "@type" for invitee @email.', ['@type' => $activityType, '@email' => $inviteeEmail]);
    }
    catch (\Throwable $e) {
      $this->activityDedupeStore->delete($activityDedupeKey);
      $this->logger->error('Failed to create activity: @m', ['@m' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Builds an idempotency key for activity creation.
   */
  protected function buildActivityDedupeKey(array $data, array $event, string $activityType, string $inviteeEmail, string $start, string $title): string {
    $payload = $data['payload'] ?? [];
    $event_uri = (string) ($payload['payload']['event'] ?? $payload['event'] ?? '');
    $invitee_uri = (string) ($payload['payload']['invitee'] ?? $payload['invitee'] ?? '');
    $controller_key = (string) ($data['dedupe_key'] ?? '');

    $seed = implode('|', [
      $controller_key,
      $event_uri,
      $invitee_uri,
      strtolower($inviteeEmail),
      $start,
      strtolower($title),
      $activityType,
      (string) ($event['end'] ?? ''),
    ]);

    return hash('sha256', $seed);
  }

  protected static function civicrmBoot() {
    if (!function_exists('civicrm_initialize')) {
      throw new \RuntimeException('CiviCRM is not available in this Drupal runtime.');
    }
    civicrm_initialize();
  }

  protected function civiFindContactByEmail(string $email): ?int {
    $this->civicrmBoot();
    try {
      $r = civicrm_api3('Contact', 'get', [
        'sequential' => 1,
        'email' => $email,
        'return' => ['id'],
        'options' => ['limit' => 1],
      ]);
      if (!empty($r['count'])) {
        return (int) $r['values'][0]['id'];
      }
    } catch (\Throwable $e) {
      throw $e;
    }
    return NULL;
  }

  protected function civiFindOrCreateContact(string $email, ?string $displayName): int {
    $existing = $this->civiFindContactByEmail($email);
    if ($existing) {
      return $existing;
    }
    $params = [
      'contact_type' => 'Individual',
      'email' => $email,
    ];
    if ($displayName) {
      $params['display_name'] = $displayName;
    }
    $r = civicrm_api3('Contact', 'create', $params);
    return (int) $r['id'];
  }

  protected function civiCreateActivity(array $params): int {
    $this->civicrmBoot();
    $r = civicrm_api3('Activity', 'create', $params);
    return (int) $r['id'];
  }

}

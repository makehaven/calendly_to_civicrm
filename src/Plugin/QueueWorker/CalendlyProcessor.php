<?php

namespace Drupal\calendly_to_civicrm\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\calendly_to_civicrm\EventParser;
use Drupal\civicrm\Civicrm;

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
  protected Civicrm $civicrm;
  protected ?ClientInterface $httpClient;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, $logger_factory, ConfigFactoryInterface $config_factory, KeyValueExpirableFactoryInterface $keyvalue_expirable_factory, Civicrm $civicrm, ?ClientInterface $http_client = NULL) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger_factory->get('calendly_to_civicrm');
    $this->configFactory = $config_factory;
    $this->activityDedupeStore = $keyvalue_expirable_factory->get(self::ACTIVITY_DEDUPE_COLLECTION);
    $this->civicrm = $civicrm;
    $this->httpClient = $http_client;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory'),
      $container->get('config.factory'),
      $container->get('keyvalue.expirable'),
      $container->get('civicrm'),
      $container->get('http_client')
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
    $event = $this->enrichEventFromCalendly($data, $event);

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
        // Civi requires a source contact. Fall back to invitee when no staff map exists.
        'source_contact_id' => $staffId ?: $inviteeId,
        'assignee_contact_id' => $staffId ?: NULL,
        'target_contact_id' => $inviteeId,
        'activity_date_time' => $start ?: date('c'),
        'subject' => $title,
        'details' => $this->buildActivityDetails($data, $event),
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

  /**
   * Best-effort enrichment for webhook payloads that only include URIs.
   */
  protected function enrichEventFromCalendly(array $data, array $event): array {
    if (!$this->shouldEnrichEvent($data, $event)) {
      return $event;
    }

    $token = $this->resolveCalendlyAccessToken();
    if ($token === '') {
      return $event;
    }

    $payload = $data['payload'] ?? [];
    $event_uri = (string) ($payload['payload']['event'] ?? $payload['event'] ?? '');
    $invitee_uri = (string) ($payload['payload']['invitee'] ?? $payload['invitee'] ?? '');

    $event_resource = [];
    if ($event_uri !== '') {
      $event_resource = $this->fetchCalendlyResource($event_uri, $token);
    }

    $invitee_resource = [];
    if ($invitee_uri !== '') {
      $invitee_resource = $this->fetchCalendlyResource($invitee_uri, $token);
    }

    if (($event['title'] ?? 'Calendly Event') === 'Calendly Event' && !empty($event_resource['name'])) {
      $event['title'] = (string) $event_resource['name'];
    }
    if (empty($event['start']) && !empty($event_resource['start_time'])) {
      $event['start'] = (string) $event_resource['start_time'];
    }
    if (empty($event['end']) && !empty($event_resource['end_time'])) {
      $event['end'] = (string) $event_resource['end_time'];
    }
    if (empty($event['organizer_email'])) {
      $event['organizer_email'] = $event_resource['event_memberships'][0]['user_email']
        ?? $event_resource['event_memberships'][0]['user']['email']
        ?? NULL;
    }
    if (empty($event['invitee_email']) && !empty($invitee_resource['email'])) {
      $event['invitee_email'] = (string) $invitee_resource['email'];
    }
    if (empty($event['invitee_name']) && !empty($invitee_resource['name'])) {
      $event['invitee_name'] = (string) $invitee_resource['name'];
    }

    return $event;
  }

  /**
   * Determines whether Calendly API enrichment is needed.
   */
  protected function shouldEnrichEvent(array $data, array $event): bool {
    if ($this->httpClient === NULL) {
      return FALSE;
    }

    $title = (string) ($event['title'] ?? '');
    $needs_event_fields = ($title === '' || $title === 'Calendly Event' || empty($event['start']) || empty($event['organizer_email']));
    $needs_invitee_fields = empty($event['invitee_email']) || empty($event['invitee_name']);
    if (!$needs_event_fields && !$needs_invitee_fields) {
      return FALSE;
    }

    $payload = $data['payload'] ?? [];
    $event_uri = (string) ($payload['payload']['event'] ?? $payload['event'] ?? '');
    $invitee_uri = (string) ($payload['payload']['invitee'] ?? $payload['invitee'] ?? '');
    return $event_uri !== '' || $invitee_uri !== '';
  }

  /**
   * Reads configured Calendly PAT used for enrichment calls.
   */
  protected function resolveCalendlyAccessToken(): string {
    $token = trim((string) $this->configFactory->get('calendly_availability.settings')->get('personal_access_token'));
    if (str_starts_with(strtolower($token), 'bearer ')) {
      $token = trim(substr($token, 7));
    }
    return $token;
  }

  /**
   * Fetches a single Calendly resource and returns its "resource" object.
   */
  protected function fetchCalendlyResource(string $uri, string $token): array {
    if ($uri === '' || $this->httpClient === NULL) {
      return [];
    }

    try {
      $response = $this->httpClient->request('GET', $uri, [
        'headers' => [
          'Authorization' => 'Bearer ' . $token,
          'Accept' => 'application/json',
        ],
      ]);
      $decoded = json_decode((string) $response->getBody(), TRUE);
      return is_array($decoded['resource'] ?? NULL) ? $decoded['resource'] : [];
    }
    catch (RequestException $e) {
      $this->logger->warning('Calendly enrichment fetch failed for @uri: @error', [
        '@uri' => $uri,
        '@error' => $e->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Stores lightweight Calendly metadata for auditing/reclassification.
   */
  protected function buildActivityDetails(array $data, array $event): string {
    $payload = $data['payload'] ?? [];
    $event_uri = (string) ($payload['payload']['event'] ?? $payload['event'] ?? '');
    $invitee_uri = (string) ($payload['payload']['invitee'] ?? $payload['invitee'] ?? '');
    $created_at = (string) ($payload['created_at'] ?? '');
    $lines = [
      'Calendly metadata',
      'event_uri: ' . ($event_uri !== '' ? $event_uri : '(none)'),
      'invitee_uri: ' . ($invitee_uri !== '' ? $invitee_uri : '(none)'),
      'created_at: ' . ($created_at !== '' ? $created_at : '(none)'),
      'source: ' . (!empty($data['backfill']) ? 'backfill' : 'webhook'),
      'resolved_title: ' . ((string) ($event['title'] ?? 'Calendly Event')),
    ];
    return implode("\n", $lines);
  }

  protected function civicrmBoot() {
    try {
      $this->civicrm->initialize();
    }
    catch (\Throwable $e) {
      throw new \RuntimeException('Failed to initialize CiviCRM: ' . $e->getMessage(), 0, $e);
    }
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

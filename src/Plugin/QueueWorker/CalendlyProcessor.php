<?php

namespace Drupal\calendly_to_civicrm\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
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

  /**
   * Placeholder EventParser falls back to when the payload has no event name.
   *
   * It is not a real title: every classification rule misses it, so an activity
   * carrying it lands under the default type.
   */
  const UNRESOLVED_TITLE = 'Calendly Event';

  const UNRESOLVED_ATTEMPT_COLLECTION = 'calendly_to_civicrm.unresolved_attempts';
  const UNRESOLVED_MAX_ATTEMPTS = 5;
  const UNRESOLVED_ATTEMPT_TTL = 604800;

  /**
   * Campaign tags Calendly exposes on an invitee's `tracking` object.
   */
  const TRACKING_KEYS = [
    'utm_campaign',
    'utm_source',
    'utm_medium',
    'utm_content',
    'utm_term',
  ];

  protected $logger;
  protected ConfigFactoryInterface $configFactory;
  protected KeyValueStoreExpirableInterface $activityDedupeStore;
  /**
   * Retry counter for bookings Calendly would not resolve, keyed by event.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreExpirableInterface
   */
  protected KeyValueStoreExpirableInterface $unresolvedAttemptStore;
  protected Civicrm $civicrm;
  protected ?ClientInterface $httpClient;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, $logger_factory, ConfigFactoryInterface $config_factory, KeyValueExpirableFactoryInterface $keyvalue_expirable_factory, Civicrm $civicrm, ?ClientInterface $http_client = NULL) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger_factory->get('calendly_to_civicrm');
    $this->configFactory = $config_factory;
    $this->activityDedupeStore = $keyvalue_expirable_factory->get(self::ACTIVITY_DEDUPE_COLLECTION);
    $this->unresolvedAttemptStore = $keyvalue_expirable_factory->get(self::UNRESOLVED_ATTEMPT_COLLECTION);
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

    // Refuse to write a half-resolved booking. With no Calendly token the
    // webhook payload carries only URIs, so the title stays the placeholder
    // (every classification rule misses it, and it lands under the default
    // type) and the start is empty (it used to fall back to date('c'), stamping
    // the row with the moment the webhook fired rather than when the tour
    // happened). That combination silently mis-filed 391 activities between
    // Feb and Jul 2026 and went unnoticed for months, because a wrong row looks
    // exactly like a right one. A booking we skip can be recovered from the
    // Calendly API at any time; a booking we corrupt cannot be spotted at all.
    if ($this->isUnresolvedEvent($event)) {
      $this->handleUnresolvedEvent($data, $event);
      return;
    }

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
        // Guaranteed non-empty by the isUnresolvedEvent() guard above. It must
        // never fall back to "now": that is what produced the Feb-Jul 2026 rows
        // dated when the webhook fired instead of when the booking was for.
        'activity_date_time' => $start,
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
   * TRUE when enrichment left the booking without a usable title or start time.
   */
  protected function isUnresolvedEvent(array $event): bool {
    $title = trim((string) ($event['title'] ?? ''));
    return $title === '' || $title === self::UNRESOLVED_TITLE || empty($event['start']);
  }

  /**
   * Decides what to do with a booking that could not be resolved.
   *
   * A missing token fails every queued item identically, so the queue is
   * suspended rather than drained one bad row at a time: the items keep for the
   * next run and a single loud log says what to fix. A token that is present
   * but failed on this item is treated as a transient API problem and retried a
   * bounded number of times before being given up on.
   */
  protected function handleUnresolvedEvent(array $data, array $event): void {
    $payload = $data['payload'] ?? [];
    $event_uri = self::asResourceUri($payload['payload']['event'] ?? $payload['event'] ?? '');
    $context = [
      '@uri' => $event_uri !== '' ? $event_uri : '(none)',
      '@title' => (string) ($event['title'] ?? ''),
    ];

    if ($this->resolveCalendlyAccessToken() === '') {
      $this->logger->critical('No Calendly access token is configured, so bookings cannot be resolved to a real event name or start time. Refusing to create activities that would be filed under the default activity type and dated when the webhook fired. Set the token at /admin/config/services/calendly-availability; queued items are preserved. First unresolved booking: @uri', $context);
      throw new SuspendQueueException('calendly_to_civicrm: no Calendly access token configured');
    }

    $key = hash('sha256', $event_uri . '|' . ((string) ($data['dedupe_key'] ?? '')));
    $attempts = ((int) $this->unresolvedAttemptStore->get($key, 0)) + 1;
    $this->unresolvedAttemptStore->setWithExpire($key, $attempts, self::UNRESOLVED_ATTEMPT_TTL);

    $context['@n'] = $attempts;

    if ($attempts < self::UNRESOLVED_MAX_ATTEMPTS) {
      $context['@max'] = self::UNRESOLVED_MAX_ATTEMPTS;
      $this->logger->warning('Could not resolve Calendly booking @uri (attempt @n of @max); requeueing rather than filing it under the default activity type.', $context);
      throw new RequeueException('calendly_to_civicrm: unresolved booking, will retry');
    }

    $this->logger->error('Giving up on Calendly booking @uri after @n attempts; no activity was created. Recover it with a targeted backfill from the module settings form once the API is reachable.', $context);
  }

  /**
   * Returns the value only when it is a Calendly resource URI, else ''.
   */
  protected static function asResourceUri($value): string {
    $value = is_string($value) ? trim($value) : '';
    return str_starts_with($value, 'https://') ? $value : '';
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
    // Attribution rides along with an invitee fetch we were making anyway. We
    // deliberately do not trigger a fetch just for it — most bookings carry no
    // campaign, and an extra API call per tour is not worth the tag.
    if (empty($event['tracking']) && !empty($invitee_resource['tracking']) && is_array($invitee_resource['tracking'])) {
      $event['tracking'] = $invitee_resource['tracking'];
    }
    if (empty($event['questions_and_answers']) && !empty($invitee_resource['questions_and_answers']) && is_array($invitee_resource['questions_and_answers'])) {
      $event['questions_and_answers'] = $invitee_resource['questions_and_answers'];
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
    // On the flat payload shape `$payload['event']` is the webhook event *name*
    // ("invitee.created"), not a resource URI, so it was being written into the
    // details as `event_uri: invitee.created`. Keep only real URIs — these
    // lines are parsed by reporting now.
    $event_uri = self::asResourceUri($payload['payload']['event'] ?? $payload['event'] ?? '');
    $invitee_uri = self::asResourceUri($payload['payload']['invitee'] ?? $payload['invitee'] ?? '');
    $created_at = (string) ($payload['created_at'] ?? '');
    $lines = [
      'Calendly metadata',
      'event_uri: ' . ($event_uri !== '' ? $event_uri : '(none)'),
      'invitee_uri: ' . ($invitee_uri !== '' ? $invitee_uri : '(none)'),
      'created_at: ' . ($created_at !== '' ? $created_at : '(none)'),
      'source: ' . (!empty($data['backfill']) ? 'backfill' : 'webhook'),
      'resolved_title: ' . ((string) ($event['title'] ?? 'Calendly Event')),
    ];

    // Campaign attribution, one `key: value` line per tag. Kept in this flat
    // format on purpose: it is what makes "how many tours did the flyer
    // campaign produce?" answerable straight from SQL against
    // civicrm_activity.details, with no schema migration.
    foreach (self::TRACKING_KEYS as $key) {
      $value = trim((string) ($event['tracking'][$key] ?? ''));
      if ($value !== '') {
        $lines[] = $key . ': ' . $value;
      }
    }

    // Free text typed by the invitee, so it is escaped — activity details are
    // rendered as HTML to staff in CiviCRM.
    foreach (($event['questions_and_answers'] ?? []) as $qa) {
      if (!is_array($qa)) {
        continue;
      }
      $question = trim((string) ($qa['question'] ?? ''));
      $answer = trim((string) ($qa['answer'] ?? ''));
      if ($question === '' || $answer === '') {
        continue;
      }
      $lines[] = 'answer[' . htmlspecialchars($question, ENT_QUOTES, 'UTF-8') . ']: '
        . htmlspecialchars($answer, ENT_QUOTES, 'UTF-8');
    }

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

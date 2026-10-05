<?php

declare(strict_types=1);

namespace Drupal\calendly_to_civicrm\Drush\Commands;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Repairs activities left behind by Calendly cancellations.
 *
 * Until 2026-10 the webhook queue ignored invitee.canceled: a booking that
 * was cancelled kept its Tour / Orientation activity as if it happened, and
 * sometimes gained a second one. This asks Calendly which invitees were
 * cancelled and marks their activities Cancelled. Lives under
 * src/Drush/Commands with attribute discovery, the only layout Drush 13 reads.
 */
class CalendlyRepairCommands extends DrushCommands {

  /**
   * The civicrm_activity_contact.record_type_id of activity targets.
   */
  const TARGET_RECORD_TYPE = 3;

  public function __construct(
    protected Connection $db,
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $http,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('database'),
      $container->get('config.factory'),
      $container->get('http_client'),
    );
  }

  /**
   * Mark activities for bookings cancelled in Calendly as Cancelled.
   */
  #[CLI\Command(name: 'calendly_to_civicrm:repair-cancellations', aliases: ['calendly-repair-cancellations'])]
  #[CLI\Option(name: 'since', description: 'First booking date to check, YYYY-MM-DD (default: 12 months ago).')]
  #[CLI\Option(name: 'apply', description: 'Write the changes. Without it nothing is changed (dry run).')]
  #[CLI\Usage(name: 'drush calendly_to_civicrm:repair-cancellations', description: 'List the activities that would be marked Cancelled.')]
  #[CLI\Usage(name: 'drush calendly_to_civicrm:repair-cancellations --since=2025-10-01 --apply', description: 'Mark them, once the list is approved.')]
  public function repairCancellations(array $options = ['since' => '', 'apply' => FALSE]): int {
    $apply = (bool) $options['apply'];
    $since = (string) ($options['since'] ?: gmdate('Y-m-d', strtotime('-12 months')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $since)) {
      $this->io()->error('--since must be YYYY-MM-DD.');
      return self::EXIT_FAILURE;
    }
    $token = trim((string) $this->configFactory->get('calendly_availability.settings')->get('personal_access_token'));
    $token = preg_replace('/^bearer\s+/i', '', $token);
    if ($token === '') {
      $this->io()->error('No Calendly access token (calendly_availability.settings:personal_access_token).');
      return self::EXIT_FAILURE;
    }

    $me = $this->get('https://api.calendly.com/users/me', $token);
    $org = (string) ($me['resource']['current_organization'] ?? '');
    if ($org === '') {
      $this->io()->error('Calendly returned no organization for this token.');
      return self::EXIT_FAILURE;
    }

    // Both statuses: a host-cancelled event is "canceled" itself, and an
    // invitee cancelling leaves the event "active".
    $events = $this->collection('https://api.calendly.com/scheduled_events', $token, [
      'organization' => $org,
      'min_start_time' => $since . 'T00:00:00Z',
      'sort' => 'start_time:asc',
      'count' => 100,
    ]);
    $this->io()->writeln(sprintf('%d scheduled events since %s.', count($events), $since));

    $plan = [];
    $cancelledInvitees = 0;
    $skippedRebooked = 0;
    foreach ($events as $ev) {
      $eventUri = (string) ($ev['uri'] ?? '');
      if ($eventUri === '') {
        continue;
      }
      $invitees = $this->collection($eventUri . '/invitees', $token, ['count' => 100]);
      $active = [];
      foreach ($invitees as $inv) {
        if (($inv['status'] ?? '') === 'active') {
          $active[strtolower((string) ($inv['email'] ?? ''))] = TRUE;
        }
      }
      foreach ($invitees as $inv) {
        if (($inv['status'] ?? '') !== 'canceled') {
          continue;
        }
        $email = strtolower(trim((string) ($inv['email'] ?? '')));
        if ($email === '') {
          continue;
        }
        $cancelledInvitees++;
        // Cancelled and booked again on the same session: the activity
        // stands for the booking that is still on.
        if (isset($active[$email])) {
          $skippedRebooked++;
          continue;
        }
        foreach ($this->activitiesFor($eventUri, $email) as $row) {
          $plan[(int) $row->id] = [
            'id' => (int) $row->id,
            'status_id' => (int) $row->status_id,
            'type' => (string) $row->type,
            'date' => (string) $row->activity_date_time,
            'email' => $email,
            'title' => (string) ($ev['name'] ?? ''),
            'by' => (string) ($inv['cancellation']['canceler_type'] ?? ''),
          ];
        }
      }
    }

    $byType = [];
    foreach ($plan as $p) {
      $byType[$p['type']] = ($byType[$p['type']] ?? 0) + 1;
    }
    ksort($byType);
    $this->io()->table(['activity', 'date', 'type', 'email', 'Calendly event', 'cancelled by'], array_map(
      static fn($p) => [$p['id'], $p['date'], $p['type'], $p['email'], mb_substr($p['title'], 0, 40), $p['by']],
      array_values($plan)
    ));
    $this->io()->writeln(sprintf(
      '%d cancelled invitees; %d rebooked the same session (left alone); %d activities to mark Cancelled (%s).',
      $cancelledInvitees,
      $skippedRebooked,
      count($plan),
      implode(', ', array_map(static fn($t, $n) => "$t $n", array_keys($byType), $byType)) ?: 'none'
    ));

    if (!$apply) {
      $this->io()->writeln('Dry run: nothing changed. Re-run with --apply once the list is approved.');
      return self::EXIT_SUCCESS;
    }

    $cancelled = $this->cancelledStatusId();
    $note = "\nrepaired: " . date('Y-m-d') . ' booking was cancelled in Calendly (calendly_to_civicrm:repair-cancellations)';
    $updated = 0;
    foreach ($plan as $p) {
      // Guarded on the status we read, so a row someone changed since is skipped.
      $updated += (int) $this->db->update('civicrm_activity')
        ->fields(['status_id' => $cancelled])
        ->expression('details', 'CONCAT(COALESCE(details, \'\'), :note)', [':note' => $note])
        ->condition('id', $p['id'])
        ->condition('status_id', $p['status_id'])
        ->execute();
    }
    $this->io()->success(sprintf('Marked %d of %d activities Cancelled.', $updated, count($plan)));
    return self::EXIT_SUCCESS;
  }

  /**
   * Calendly activities for a scheduled event whose target has this email.
   */
  protected function activitiesFor(string $eventUri, string $email): array {
    $q = $this->db->select('civicrm_activity', 'a');
    $q->innerJoin('civicrm_activity_contact', 'ac', 'ac.activity_id = a.id AND ac.record_type_id = :rt', [':rt' => self::TARGET_RECORD_TYPE]);
    $q->innerJoin('civicrm_email', 'e', 'e.contact_id = ac.contact_id');
    $q->leftJoin('civicrm_option_value', 'ov', "ov.value = a.activity_type_id AND ov.option_group_id = (SELECT id FROM {civicrm_option_group} WHERE name = 'activity_type')");
    $q->fields('a', ['id', 'status_id', 'activity_date_time']);
    $q->addField('ov', 'label', 'type');
    $q->condition('e.email', $email);
    $q->condition('a.is_deleted', 0);
    $q->condition('a.status_id', $this->cancelledStatusId(), '<>');
    $q->condition('a.details', 'Calendly metadata%', 'LIKE');
    $q->condition('a.details', '%event_uri: ' . $this->db->escapeLike($eventUri) . '%', 'LIKE');
    $q->distinct();
    return $q->execute()->fetchAll();
  }

  /**
   * The "Cancelled" activity status value.
   */
  protected function cancelledStatusId(): int {
    static $id;
    return $id ??= (int) $this->db->query("SELECT ov.value FROM {civicrm_option_value} ov INNER JOIN {civicrm_option_group} g ON g.id = ov.option_group_id WHERE g.name = 'activity_status' AND ov.name = 'Cancelled'")->fetchField();
  }

  /**
   * Every page of a Calendly collection.
   */
  protected function collection(string $url, string $token, array $query): array {
    $out = [];
    $next = $url;
    $params = $query;
    while ($next) {
      $data = $this->get($next, $token, $params);
      $out = array_merge($out, (array) ($data['collection'] ?? []));
      $next = (string) ($data['pagination']['next_page'] ?? '');
      // next_page already carries every query parameter.
      $params = [];
    }
    return $out;
  }

  /**
   * One GET against the Calendly API.
   */
  protected function get(string $url, string $token, array $query = []): array {
    $opts = ['headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json']];
    if ($query) {
      $opts['query'] = $query;
    }
    $response = $this->http->request('GET', $url, $opts);
    return (array) json_decode((string) $response->getBody(), TRUE);
  }

}

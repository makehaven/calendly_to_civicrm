<?php

namespace Drupal\calendly_to_civicrm;

/**
 * Extracts useful fields from a Calendly webhook payload and classifies activity.
 */
class EventParser {

  /**
   * Webhook event names this module acts on.
   */
  const INVITEE_CREATED = 'invitee.created';
  const INVITEE_CANCELED = 'invitee.canceled';

  public static function parse(array $payload): array {
    // Calendly's v2 webhook body is {"event": "invitee.created", "payload":
    // {invitee fields..., "uri": invitee URI, "event": scheduled-event URI,
    // "scheduled_event": {name, start_time, end_time, event_memberships}}}.
    // The older shapes below are still read for queued items and tests.
    $inner = is_array($payload['payload'] ?? NULL) ? $payload['payload'] : [];
    $scheduled = is_array($inner['scheduled_event'] ?? NULL) ? $inner['scheduled_event'] : [];

    $title = $payload['event']['name'] ?? $payload['event_type']['name'] ?? $scheduled['name'] ?? $payload['name'] ?? 'Calendly Event';

    $invitee_email = $inner['invitee']['email'] ?? $payload['invitee']['email'] ?? $payload['email'] ?? (is_string($inner['email'] ?? NULL) ? $inner['email'] : NULL);
    $invitee_name  = $inner['invitee']['name'] ?? $payload['invitee']['name'] ?? ($payload['name'] ?? (is_string($inner['name'] ?? NULL) ? $inner['name'] : NULL));

    $organizer_email = $inner['event']['organizer']['email'] ?? $payload['organizer']['email']
      ?? $scheduled['event_memberships'][0]['user_email'] ?? NULL;

    $start = $inner['event']['start_time'] ?? $payload['event']['start_time'] ?? $scheduled['start_time'] ?? $payload['start_time'] ?? NULL;
    $end   = $inner['event']['end_time'] ?? $payload['event']['end_time'] ?? $scheduled['end_time'] ?? $payload['end_time'] ?? NULL;

    // Which webhook this is. Only a string at the top level is a name; on
    // older shapes `event` is an object or a URI.
    $webhook = is_string($payload['event'] ?? NULL) && !str_starts_with($payload['event'], 'http') ? $payload['event'] : '';

    $event_uri = self::uri($inner['event'] ?? NULL) ?: self::uri($scheduled['uri'] ?? NULL) ?: self::uri($payload['event'] ?? NULL);
    // v2 puts the invitee's own URI at payload.uri; older shapes at
    // payload.invitee. Until 2026-10 only the latter was read, so every
    // activity was written with "invitee_uri: (none)".
    $invitee_uri = self::uri($inner['uri'] ?? NULL) ?: self::uri($inner['invitee'] ?? NULL) ?: self::uri($payload['invitee'] ?? NULL);

    // Campaign attribution. Calendly copies the utm_* params from the booking
    // URL onto the invitee's `tracking` object, which is how a tour booked from
    // a flyer landing page can be told apart from a walk-in. Checked at each of
    // the payload shapes this module already tolerates.
    $tracking = $payload['payload']['invitee']['tracking']
      ?? $payload['payload']['tracking']
      ?? $payload['invitee']['tracking']
      ?? $payload['tracking']
      ?? [];

    // Whatever the event type asks on the booking form ("How did you hear
    // about us?"), so a walk-in who clicked no tagged link still self-reports.
    $answers = $payload['payload']['invitee']['questions_and_answers']
      ?? $payload['payload']['questions_and_answers']
      ?? $payload['invitee']['questions_and_answers']
      ?? $payload['questions_and_answers']
      ?? [];

    return [
      'title' => is_string($title) ? $title : 'Calendly Event',
      'invitee_email' => $invitee_email,
      'invitee_name'  => $invitee_name,
      'organizer_email' => $organizer_email,
      'start' => $start,
      'end'   => $end,
      'tracking' => is_array($tracking) ? $tracking : [],
      'questions_and_answers' => is_array($answers) ? $answers : [],
      'webhook' => $webhook,
      'event_uri' => $event_uri,
      'invitee_uri' => $invitee_uri,
      'first_name' => is_string($inner['first_name'] ?? NULL) ? $inner['first_name'] : NULL,
      'last_name' => is_string($inner['last_name'] ?? NULL) ? $inner['last_name'] : NULL,
      'rescheduled' => !empty($inner['rescheduled']),
      'canceler_type' => (string) ($inner['cancellation']['canceler_type'] ?? ''),
    ];
  }

  /**
   * The value when it is a Calendly resource URI, else ''.
   */
  public static function uri($value): string {
    $value = is_string($value) ? trim($value) : '';
    return str_starts_with($value, 'https://') ? $value : '';
  }

  public static function classifyActivity(array $rules, array $event): string {
    $default = $rules['default_activity_type'] ?? 'Meeting';
    $list = $rules['rules'] ?? [];
    foreach ($list as $rule) {
      $field = $rule['field'] ?? 'title';
      $match = $rule['match'] ?? '';
      $type  = $rule['activity_type'] ?? $default;
      $val = $event[$field] ?? '';
      if (!is_string($val)) {
        continue;
      }
      if ($match !== '' && stripos($val, $match) !== FALSE) {
        return $type;
      }
    }
    return $default;
  }
}

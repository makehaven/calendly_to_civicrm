<?php

namespace Drupal\calendly_to_civicrm;

/**
 * Extracts useful fields from a Calendly webhook payload and classifies activity.
 */
class EventParser {

  public static function parse(array $payload): array {
    $title = $payload['event']['name'] ?? $payload['event_type']['name'] ?? $payload['name'] ?? 'Calendly Event';

    $invitee_email = $payload['payload']['invitee']['email'] ?? $payload['invitee']['email'] ?? $payload['email'] ?? NULL;
    $invitee_name  = $payload['payload']['invitee']['name'] ?? $payload['invitee']['name'] ?? ($payload['name'] ?? NULL);

    $organizer_email = $payload['payload']['event']['organizer']['email'] ?? $payload['organizer']['email'] ?? NULL;

    $start = $payload['payload']['event']['start_time'] ?? $payload['event']['start_time'] ?? $payload['start_time'] ?? NULL;
    $end   = $payload['payload']['event']['end_time'] ?? $payload['event']['end_time'] ?? $payload['end_time'] ?? NULL;

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
    ];
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

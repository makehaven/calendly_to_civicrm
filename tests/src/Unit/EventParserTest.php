<?php

namespace Drupal\Tests\calendly_to_civicrm\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\calendly_to_civicrm\EventParser;

/**
 * @coversDefaultClass \Drupal\calendly_to_civicrm\EventParser
 * @group calendly_to_civicrm
 */
class EventParserTest extends UnitTestCase {

  /**
   * @covers ::parse
   */
  public function testParseExtractsNestedCalendlyPayloadFields(): void {
    $payload = [
      'payload' => [
        'invitee' => [
          'email' => 'invitee@example.org',
          'name' => 'Invitee Name',
        ],
        'event' => [
          'organizer' => ['email' => 'staff@example.org'],
          'start_time' => '2026-01-01T10:00:00Z',
          'end_time' => '2026-01-01T10:30:00Z',
        ],
      ],
      'event' => [
        'name' => 'Tour Intro',
      ],
    ];

    $parsed = EventParser::parse($payload);

    $this->assertSame('Tour Intro', $parsed['title']);
    $this->assertSame('invitee@example.org', $parsed['invitee_email']);
    $this->assertSame('Invitee Name', $parsed['invitee_name']);
    $this->assertSame('staff@example.org', $parsed['organizer_email']);
    $this->assertSame('2026-01-01T10:00:00Z', $parsed['start']);
    $this->assertSame('2026-01-01T10:30:00Z', $parsed['end']);
  }

  /**
   * Campaign tags are what make a flyer-sourced tour distinguishable.
   *
   * @covers ::parse
   */
  public function testParseExtractsTrackingAndAnswersFromFlatPayload(): void {
    $payload = [
      'email' => 'invitee@example.org',
      'name' => 'Invitee Name',
      'tracking' => [
        'utm_campaign' => 'postcard',
        'utm_source' => 'landing_page',
        'utm_medium' => 'website',
      ],
      'questions_and_answers' => [
        ['question' => 'How did you hear about us?', 'answer' => 'A flyer', 'position' => 0],
      ],
    ];

    $parsed = EventParser::parse($payload);

    $this->assertSame('postcard', $parsed['tracking']['utm_campaign']);
    $this->assertSame('landing_page', $parsed['tracking']['utm_source']);
    $this->assertSame('A flyer', $parsed['questions_and_answers'][0]['answer']);
  }

  /**
   * @covers ::parse
   */
  public function testParseExtractsTrackingFromNestedInviteePayload(): void {
    $payload = [
      'payload' => [
        'invitee' => [
          'email' => 'invitee@example.org',
          'tracking' => ['utm_campaign' => 'flyers-winter-hobby'],
        ],
      ],
    ];

    $parsed = EventParser::parse($payload);

    $this->assertSame('flyers-winter-hobby', $parsed['tracking']['utm_campaign']);
  }

  /**
   * An untagged walk-in booking must still parse cleanly.
   *
   * @covers ::parse
   */
  public function testParseDefaultsTrackingAndAnswersToEmptyArrays(): void {
    $parsed = EventParser::parse(['email' => 'invitee@example.org']);

    $this->assertSame([], $parsed['tracking']);
    $this->assertSame([], $parsed['questions_and_answers']);
  }

  /**
   * @covers ::classifyActivity
   */
  public function testClassifyActivityUsesFirstCaseInsensitiveMatch(): void {
    $rules = [
      'rules' => [
        ['field' => 'title', 'match' => 'tour', 'activity_type' => 'Took Tour'],
        ['field' => 'title', 'match' => 'orientation', 'activity_type' => 'Attended Orientation'],
      ],
      'default_activity_type' => 'Meeting',
    ];
    $event = ['title' => 'TOUR with staff'];

    $this->assertSame('Took Tour', EventParser::classifyActivity($rules, $event));
  }

  /**
   * @covers ::classifyActivity
   */
  public function testClassifyActivityFallsBackToDefaultType(): void {
    $rules = [
      'rules' => [
        ['field' => 'title', 'match' => 'tour', 'activity_type' => 'Took Tour'],
      ],
      'default_activity_type' => 'Meeting',
    ];
    $event = ['title' => 'General consultation'];

    $this->assertSame('Meeting', EventParser::classifyActivity($rules, $event));
  }


  /**
   * Calendly's v2 webhook body: names, URIs and times sit under payload.
   */
  public function testParsesV2InviteePayload(): void {
    $payload = [
      'event' => 'invitee.canceled',
      'created_at' => '2026-10-05T18:06:50.000000Z',
      'payload' => [
        'email' => 'visitor@example.org',
        'name' => 'Vi Sitor',
        'first_name' => 'Vi',
        'last_name' => 'Sitor',
        'uri' => 'https://api.calendly.com/scheduled_events/EV1/invitees/INV1',
        'event' => 'https://api.calendly.com/scheduled_events/EV1',
        'rescheduled' => TRUE,
        'cancellation' => ['canceler_type' => 'invitee'],
        'scheduled_event' => [
          'uri' => 'https://api.calendly.com/scheduled_events/EV1',
          'name' => 'Afternoon Tour with Lior',
          'start_time' => '2026-10-06T19:00:00.000000Z',
          'end_time' => '2026-10-06T19:30:00.000000Z',
          'event_memberships' => [['user_email' => 'lior@example.org']],
        ],
      ],
    ];
    $e = EventParser::parse($payload);
    $this->assertSame('invitee.canceled', $e['webhook']);
    $this->assertSame('Afternoon Tour with Lior', $e['title']);
    $this->assertSame('visitor@example.org', $e['invitee_email']);
    $this->assertSame('Vi Sitor', $e['invitee_name']);
    $this->assertSame('lior@example.org', $e['organizer_email']);
    $this->assertSame('2026-10-06T19:00:00.000000Z', $e['start']);
    $this->assertSame('https://api.calendly.com/scheduled_events/EV1', $e['event_uri']);
    $this->assertSame('https://api.calendly.com/scheduled_events/EV1/invitees/INV1', $e['invitee_uri']);
    $this->assertTrue($e['rescheduled']);
    $this->assertSame('invitee', $e['canceler_type']);
  }

  /**
   * A URI in the event slot is not a webhook name.
   */
  public function testWebhookNameOnlyFromTopLevelString(): void {
    $this->assertSame('', EventParser::parse(['event' => 'https://api.calendly.com/scheduled_events/X'])['webhook']);
    $this->assertSame('', EventParser::parse(['event' => ['name' => 'Tour']])['webhook']);
    $this->assertSame('invitee.created', EventParser::parse(['event' => 'invitee.created'])['webhook']);
  }

}


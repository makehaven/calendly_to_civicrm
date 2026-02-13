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

}


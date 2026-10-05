<?php

namespace Drupal\calendly_to_civicrm\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * A Calendly booking was made or cancelled.
 *
 * Dispatched from the webhook queue so other modules can act on bookings
 * without parsing Calendly payloads themselves. event_access_unifi listens
 * to issue and revoke front-door passes for tours and walkthroughs.
 */
class CalendlyBookingEvent extends Event {

  /**
   * An invitee booked (invitee.created).
   */
  const CREATED = 'calendly_to_civicrm.booking_created';

  /**
   * An invitee cancelled or rescheduled (invitee.canceled).
   */
  const CANCELED = 'calendly_to_civicrm.booking_canceled';

  /**
   * Constructs the event.
   *
   * @param array $booking
   *   Keys: ref (stable booking id: the invitee URI), event_uri, invitee_uri,
   *   title, start, end (ISO 8601), email, name, first_name, last_name,
   *   contact_id (CiviCRM, when known), rescheduled, canceler_type.
   */
  public function __construct(private array $booking) {}

  /**
   * The booking.
   */
  public function getBooking(): array {
    return $this->booking;
  }

}

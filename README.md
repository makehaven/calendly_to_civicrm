# Calendly to CiviCRM

Drupal 10/11 module that listens for Calendly webhooks and creates CiviCRM
Activities such as **Took Tour** or **Attended Orientation** for the invitee,
optionally linking the organizing staff member as the **source/assignee**.

## What it does
- POST endpoint at `/calendly/webhook`.
- Validates requests using either:
  - `Calendly-Webhook-Signature` (HMAC-SHA256), or
  - a shared token query param `?token=...`.
- Classifies events as **Tour** or **Orientation** using configurable keyword rules.
- Finds/creates the invitee in CiviCRM by email; matches staff by organizer email.
- Creates a CiviCRM Activity with the appropriate type and datetime.
- Records **campaign attribution** on the activity — see below.
- Uses Drupal Queue API for resilience and retries.

## Campaign attribution

Calendly copies any `utm_*` params from the booking URL onto the invitee's
`tracking` object, and stores whatever the booking form asked in
`questions_and_answers`. Both are written into the activity's **details** field,
one `key: value` line each:

```
Calendly metadata
event_uri: https://api.calendly.com/scheduled_events/...
invitee_uri: https://api.calendly.com/scheduled_events/.../invitees/...
created_at: 2026-08-08T12:00:00Z
source: webhook
resolved_title: Tuesday Evening Tour with J.R.
utm_campaign: postcard
utm_source: landing_page
utm_medium: website
answer[How did you hear about us?]: A flyer
```

The flat `key: value` shape is deliberate: it makes "how many tours did this
flyer campaign produce?" answerable straight from SQL against
`civicrm_activity.details`, with no custom field or schema migration. For
example:

```sql
SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(a.details, 'utm_campaign: ', -1), '\n', 1) AS campaign,
       COUNT(*)
FROM civicrm_activity a
WHERE a.details LIKE '%utm_campaign: %' AND a.is_deleted = 0
GROUP BY 1;
```

**The tag has to reach Calendly first.** That is the job of the two modules
upstream: `makerspace_landing_page` tags the outbound links with the landing
page's tracking code, and `calendly_availability` forwards the `utm_*` params
from the page URL onto the booking URL. If either is missing, `tracking` arrives
empty and no amount of work here recovers it.

Invitee answers are escaped before storage — activity details render as HTML to
staff in CiviCRM.

## Install
1. Copy this module to `web/modules/custom/calendly_to_civicrm/`.
2. Enable the module.
3. Configure at **Configuration → System → Calendly → CiviCRM**:
   - Shared token and/or Webhook signing key.
   - Rules YAML (defaults provided).
   - Optional staff email→Civi contact ID map.
4. In Calendly, log in and open **Integrations & apps → API and webhooks**. Under
   **Webhook subscriptions** click **Add webhook** and paste
   `https://YOUR-SITE/calendly/webhook?token=YOUR_TOKEN` (omit the token if you
   did not set one here). Subscribe to `invitee.created` (and
   `invitee.canceled` if desired). After saving, view the webhook details on the
   same page to copy the Webhook signing key if you plan to use it.

## Queue
Run the worker manually if needed:
```
drush queue:run calendly_to_civicrm.queue
```

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
- Uses Drupal Queue API for resilience and retries.

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

<?php

namespace Drupal\calendly_to_civicrm\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\DependencyInjection\ContainerInterface;

class SettingsForm extends ConfigFormBase {

  /**
   * HTTP client for talking to Calendly.
   */
  protected ClientInterface $httpClient;

  public function __construct(ConfigFactoryInterface $config_factory, ClientInterface $http_client) {
    parent::__construct($config_factory);
    $this->httpClient = $http_client;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('http_client')
    );
  }

  protected function getEditableConfigNames() {
    return ['calendly_to_civicrm.settings'];
  }

  public function getFormId() {
    return 'calendly_to_civicrm_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('calendly_to_civicrm.settings');
    $shared_token_value = $form_state->getValue('shared_token');
    if ($shared_token_value === NULL) {
      $shared_token_value = $config->get('shared_token');
    }
    $webhook_url = $this->buildWebhookUrl($shared_token_value);

    $form['instructions'] = [
      '#type' => 'markup',
      '#markup' => $this->t('<p>The form below configures the inbound webhook at <code>/calendly/webhook</code>. Configure security, classification, and staff settings, then point Calendly at that URL so invitee events become CiviCRM Activities.</p><ol><li>Fill out the Security section (shared token and/or signing key) so anonymous requests cannot spam CiviCRM.</li><li>Describe how incoming events should map to Civi Activity Types.</li><li>Optionally map Calendly organizer emails to specific staff contacts.</li></ol><p><strong>Calendly setup (no developer console required):</strong></p><ol><li>Log in to Calendly.com and use the left sidebar to open <strong>Integrations &amp; apps</strong>.</li><li>Click the tile labeled <strong>API and webhooks</strong>. From here you can (a) generate a Personal Access Token if you need API access, and (b) manage webhook subscriptions.</li><li>Under <strong>Webhook subscriptions</strong> click <strong>Add webhook</strong>. Paste <code>https://YOUR-SITE/calendly/webhook</code> and, if you set a shared token below, append <code>?token=YOUR_TOKEN</code>. Select <code>invitee.created</code> (and optionally <code>invitee.canceled</code>), then save.</li><li>After saving, open the webhook’s details in the same screen and copy the <strong>Webhook signing key</strong>. Paste that key into the Signing Key field below.</li></ol><p>You do NOT need to create an OAuth app or use the developer console/My Apps workflow for this integration.</p>'),
    ];

    $form['security'] = [
      '#type' => 'details',
      '#title' => $this->t('Security'),
      '#open' => TRUE,
      '#description' => $this->t('Use either a shared token or Calendly\'s webhook signing key (or both). Using only the shared token is sufficient if you do not want to manage signing keys. Requests failing these checks are discarded before they reach CiviCRM.'),
    ];
    $form['security']['shared_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shared token (optional)'),
      '#description' => $this->t('Provide any random string. When you add the webhook inside Calendly (Integrations & apps > API and webhooks) include it in the Subscriber URL as ?token=THIS_VALUE (e.g. https://YOUR-SITE/calendly/webhook?token=mysecret). Calendly does not store this token separately; it is only part of the URL. Requests missing or mismatching the token are rejected immediately.'),
      '#default_value' => $config->get('shared_token'),
    ];
    $form['security']['signing_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Calendly webhook signing key (optional)'),
      '#description' => $this->t('After Calendly creates the webhook (Integrations & apps → API and webhooks → Webhook subscriptions) click the webhook entry and copy the Webhook signing key. Paste it here so Drupal can verify the HMAC sent in the Calendly-Webhook-Signature header before queueing the event.'),
      '#default_value' => $config->get('signing_key'),
    ];

    $form['classification'] = [
      '#type' => 'details',
      '#title' => $this->t('Classification rules'),
      '#open' => TRUE,
      '#description' => $this->t('Rules are evaluated top-to-bottom. Each rule describes a Calendly payload field (title, description, location, etc.), a string to match (case-insensitive substring), and the Activity Type name that should be created in CiviCRM. The first matching rule wins; if no rule matches the default activity type below is used.'),
    ];
$default_rules = <<<YAML
rules:
  - field: title
    match: tour
    activity_type: Took Tour
  - field: title
    match: orientation
    activity_type: Attended Orientation
default_activity_type: Meeting
YAML;
    $form['classification']['rules_yaml'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Rules YAML'),
      '#description' => $this->t('Paste YAML with a "rules" array and optional "default_activity_type". Make sure each activity type already exists in CiviCRM. Example provided below.'),
      '#default_value' => $config->get('rules_yaml') ?: $default_rules,
      '#rows' => 12,
    ];
    $form['classification']['default_activity_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default activity type'),
      '#description' => $this->t('Used whenever no keyword rule matches. Set this to a valid Activity Type machine label from CiviCRM.'),
      '#default_value' => $config->get('default_activity_type') ?: 'Meeting',
    ];

    $form['staff'] = [
      '#type' => 'details',
      '#title' => $this->t('Staff matching'),
      '#open' => TRUE,
      '#description' => $this->t('Calendly organizers become the Activity source/assignee in CiviCRM. When a staff member\'s email does not exist in CiviCRM, use the map below to point the email to the correct staff contact ID.'),
    ];
    $form['staff']['prefer_config_map'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Prefer config map (email->contact ID) before Civi lookup'),
      '#description' => $this->t('Enable to always honor the YAML map first (useful if Calendly organizer emails differ from the staff member\'s primary address). Leave unchecked to look up staff in CiviCRM by email before falling back to the map.'),
      '#default_value' => (bool) $config->get('prefer_config_map'),
    ];
    $form['staff']['staff_email_map_yaml'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Staff email->Civi contact ID map (YAML)'),
      '#description' => $this->t('Provide simple YAML pairs (email on the left, numeric Contact ID on the right). The map is used when the organizer email cannot be resolved automatically in CiviCRM.') . '<br><pre>staff1@makehaven.org: 123
staff2@makehaven.org: 456</pre>',
      '#default_value' => $config->get('staff_email_map_yaml'),
      '#rows' => 6,
    ];

    $form['webhook_setup'] = [
      '#type' => 'details',
      '#title' => $this->t('Webhook setup'),
      '#open' => TRUE,
      '#description' => $this->t('Use this helper to register the webhook with Calendly without leaving Drupal. Generate a personal access token under Integrations & apps → API and webhooks → Personal access tokens. The token is only used for this request and will not be stored.'),
    ];
    $form['webhook_setup']['webhook_url_display'] = [
      '#type' => 'item',
      '#title' => $this->t('Current webhook URL'),
      '#markup' => '<code>' . Html::escape($webhook_url) . '</code>',
      '#description' => $this->t('This is the URL that will be registered with Calendly. Update the shared token above if you need a different query parameter value.'),
    ];
    $form['webhook_setup']['calendly_personal_access_token'] = [
      '#type' => 'password',
      '#title' => $this->t('Calendly personal access token'),
      '#description' => $this->t('Paste a Personal Access Token generated from Calendly (Integrations & apps → API and webhooks). Paste only the token string (no "Bearer" prefix). This token is not saved to configuration.'),
      '#maxlength' => 512,
    ];
    $form['webhook_setup']['register_webhook'] = [
      '#type' => 'submit',
      '#value' => $this->t('Register Webhook'),
      '#submit' => ['::registerWebhookSubmit'],
      // The password element lives at the root level, so limit validation must
      // reference its actual parents to ensure the value is preserved.
      '#limit_validation_errors' => [['calendly_personal_access_token'], ['shared_token']],
      '#button_type' => 'secondary',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('calendly_to_civicrm.settings')
      ->set('shared_token', $form_state->getValue('shared_token'))
      ->set('signing_key', $form_state->getValue('signing_key'))
      ->set('rules_yaml', $form_state->getValue('rules_yaml'))
      ->set('default_activity_type', $form_state->getValue('default_activity_type'))
      ->set('prefer_config_map', (bool) $form_state->getValue('prefer_config_map'))
      ->set('staff_email_map_yaml', $form_state->getValue('staff_email_map_yaml'))
      ->save();
    parent::submitForm($form, $form_state);
    $this->messenger()->addStatus($this->t('Settings saved.'));
  }

  /**
   * Registers the webhook with Calendly using a personal access token.
   */
  public function registerWebhookSubmit(array &$form, FormStateInterface $form_state) {
    $user_input = $form_state->getUserInput();
    $access_token = trim((string) ($user_input['calendly_personal_access_token'] ?? $form_state->getValue('calendly_personal_access_token')));
    if (str_starts_with(strtolower($access_token), 'bearer ')) {
      $access_token = trim(substr($access_token, 7));
    }
    if ($access_token === '') {
      $this->messenger()->addError($this->t('Enter a Calendly personal access token to register the webhook.'));
      return;
    }
    $this->logTokenDiagnostics('Attempting Calendly profile fetch', $access_token);

    $shared_token_value = $form_state->getValue('shared_token');
    if ($shared_token_value === NULL) {
      $shared_token_value = $this->config('calendly_to_civicrm.settings')->get('shared_token');
    }
    $webhook_url = $this->buildWebhookUrl($shared_token_value);

    try {
      $user_response = $this->httpClient->request('GET', 'https://api.calendly.com/users/me', [
        'headers' => $this->buildCalendlyHeaders($access_token),
      ]);
    }
    catch (RequestException $e) {
      \Drupal::logger('calendly_to_civicrm')->warning('Calendly profile request failed: @error', ['@error' => $this->formatCalendlyError($e)]);
      $this->messenger()->addError($this->t('Unable to fetch Calendly profile: @error', ['@error' => $this->formatCalendlyError($e)]));
      return;
    }

    $user_data = json_decode((string) $user_response->getBody(), TRUE);
    $organization = $user_data['resource']['current_organization'] ?? NULL;
    if (!$organization) {
      $this->messenger()->addError($this->t('Calendly did not return an organization. Verify the personal access token and try again.'));
      return;
    }

    $payload = [
      'url' => $webhook_url,
      'events' => ['invitee.created', 'invitee.canceled'],
      'organization' => $organization,
      'scope' => 'organization',
    ];

    try {
      $create_response = $this->httpClient->request('POST', 'https://api.calendly.com/webhook_subscriptions', [
        'headers' => $this->buildCalendlyHeaders($access_token),
        'json' => $payload,
      ]);
    }
    catch (RequestException $e) {
      \Drupal::logger('calendly_to_civicrm')->warning('Calendly webhook registration failed: @error', ['@error' => $this->formatCalendlyError($e)]);
      $this->messenger()->addError($this->t('Unable to register webhook with Calendly: @error', ['@error' => $this->formatCalendlyError($e)]));
      return;
    }

    if ($create_response->getStatusCode() === 201) {
      $this->messenger()->addStatus($this->t('Webhook registered successfully for @url.', ['@url' => $webhook_url]));
    }
    else {
      $this->messenger()->addError($this->t('Unexpected Calendly response (HTTP @code). Please check your token and try again.', ['@code' => $create_response->getStatusCode()]));
    }

    $form_state->setValue('calendly_personal_access_token', '');
    $form_state->setRebuild(TRUE);
  }

  /**
   * Logs masked diagnostics for the provided Calendly token.
   */
  protected function logTokenDiagnostics(string $context, string $token): void {
    $length = strlen($token);
    $preview = $length > 8 ? substr($token, 0, 4) . '...' . substr($token, -4) : $token;
    $hash = sha1($token);
    \Drupal::logger('calendly_to_civicrm')->notice('@context (len=@len, preview=@preview, sha1=@hash)', [
      '@context' => $context,
      '@len' => $length,
      '@preview' => $preview,
      '@hash' => $hash,
    ]);
  }

  /**
   * Builds the webhook URL with the optional shared token.
   */
  protected function buildWebhookUrl($shared_token_value): string {
    $request = \Drupal::request();
    $base = '/calendly/webhook';
    if ($request) {
      $base = rtrim($request->getSchemeAndHttpHost(), '/') . '/calendly/webhook';
    }
    $token = trim((string) $shared_token_value);
    if ($token !== '') {
      return $base . '?token=' . rawurlencode($token);
    }
    return $base;
  }

  /**
   * Returns headers needed for Calendly API calls.
   */
  protected function buildCalendlyHeaders(string $access_token): array {
    return [
      'Authorization' => 'Bearer ' . $access_token,
      'Content-Type' => 'application/json',
      'Accept' => 'application/json',
    ];
  }

  /**
   * Formats errors originating from Calendly requests.
   */
  protected function formatCalendlyError(RequestException $exception): string {
    if ($response = $exception->getResponse()) {
      $body = (string) $response->getBody();
      $decoded = json_decode($body, TRUE);
      if (is_array($decoded)) {
        $title = $decoded['title'] ?? '';
        $detail = $decoded['detail'] ?? ($decoded['message'] ?? '');
        $error = trim($title . ' ' . $detail);
        if ($error !== '') {
          return $error;
        }
      }
      return $body ?: $exception->getMessage();
    }
    return $exception->getMessage();
  }

}

<?php

namespace Drupal\calendly_to_civicrm\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class SettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames() {
    return ['calendly_to_civicrm.settings'];
  }

  public function getFormId() {
    return 'calendly_to_civicrm_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('calendly_to_civicrm.settings');

    $form['security'] = [
      '#type' => 'details',
      '#title' => $this->t('Security'),
      '#open' => TRUE,
    ];
    $form['security']['shared_token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shared token (optional)'),
      '#description' => $this->t('If set, requests must include ?token=VALUE'),
      '#default_value' => $config->get('shared_token'),
    ];
    $form['security']['signing_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Calendly webhook signing key (optional)'),
      '#description' => $this->t('If set, HMAC signatures in the header "Calendly-Webhook-Signature" will be verified.'),
      '#default_value' => $config->get('signing_key'),
    ];

    $form['classification'] = [
      '#type' => 'details',
      '#title' => $this->t('Classification rules'),
      '#open' => TRUE,
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
      '#description' => $this->t('YAML defining how to classify events as activity types.'),
      '#default_value' => $config->get('rules_yaml') ?: $default_rules,
      '#rows' => 12,
    ];
    $form['classification']['default_activity_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Default activity type'),
      '#default_value' => $config->get('default_activity_type') ?: 'Meeting',
    ];

    $form['staff'] = [
      '#type' => 'details',
      '#title' => $this->t('Staff matching'),
      '#open' => TRUE,
    ];
    $form['staff']['prefer_config_map'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Prefer config map (email→contact ID) before Civi lookup'),
      '#default_value' => (bool) $config->get('prefer_config_map'),
    ];
    $form['staff']['staff_email_map_yaml'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Staff email→Civi contact ID map (YAML)'),
      '#description' => $this->t('Example:') . '<br><pre>staff1@makehaven.org: 123
staff2@makehaven.org: 456</pre>',
      '#default_value' => $config->get('staff_email_map_yaml'),
      '#rows' => 6,
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

}

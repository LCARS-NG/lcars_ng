<?php

/**
 * @file
 * Theme settings for LCARS-NG.
 */

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_FORM_ID_alter() for system_theme_settings.
 */
function lcars_ng_form_system_theme_settings_alter(array &$form, FormStateInterface $form_state): void {
  $form['lcars_ng'] = [
    '#type' => 'details',
    '#title' => t('LCARS-NG'),
    '#open' => TRUE,
  ];
  $form['lcars_ng']['front_page_login'] = [
    '#type' => 'checkbox',
    '#title' => t('Login panel on the front page'),
    '#description' => t('Anonymous visitors see the login form in the LCARS login panel instead of the front page content, as in the Drupal 6 theme LCNG.'),
    '#config_target' => 'lcars_ng.settings:front_page_login',
  ];
}

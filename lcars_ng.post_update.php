<?php

/**
 * @file
 * Post update functions for LCARS-NG.
 */

use Drupal\block\Entity\Block;
use Drupal\Core\Config\FileStorage;
use Drupal\Core\Datetime\Entity\DateFormat;

/**
 * Adds the date formats of the theme to sites that installed it before.
 */
function lcars_ng_post_update_date_formats(): string {
  $path = \Drupal::service('extension.list.theme')->getPath('lcars_ng');
  $storage = new FileStorage($path . '/config/install');
  $added = [];
  foreach (['lcars_ng_date', 'lcars_ng_date_time'] as $id) {
    if (!DateFormat::load($id) && ($values = $storage->read('core.date_format.' . $id))) {
      DateFormat::create($values)->save();
      $added[] = $id;
    }
  }
  return $added ? 'Added the date formats ' . implode(', ', $added) . '.' : 'The date formats were there.';
}

/**
 * Gives the menu blocks of the theme a label for screen readers.
 *
 * The blocks came without a label. Their template hides the label, but names
 * the nav element after it; without one, screen readers announced two
 * navigation regions without a name and an empty heading. Only blocks that
 * still have an empty label take the label of the theme.
 */
function lcars_ng_post_update_menu_block_labels(): string {
  if (!\Drupal::moduleHandler()->moduleExists('block')) {
    return 'The Block module is not installed.';
  }
  $labels = [
    'lcars_ng_main_menu' => 'Main navigation',
    'lcars_ng_account_menu' => 'User account menu',
  ];
  $set = [];
  foreach ($labels as $id => $label) {
    $block = Block::load($id);
    if ($block && (string) ($block->get('settings')['label'] ?? '') === '') {
      $settings = $block->get('settings');
      $settings['label'] = $label;
      $block->set('settings', $settings)->save();
      $set[] = $id;
    }
  }
  return $set ? 'Labeled the menu blocks ' . implode(', ', $set) . '.' : 'The menu blocks had labels.';
}

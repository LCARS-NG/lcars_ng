<?php

/**
 * @file
 * Post update functions for LCARS-NG.
 */

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

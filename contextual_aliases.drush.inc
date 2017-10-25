<?php

use \Drupal\contextual_aliases\ContextualAliasStorage;

/**
 * Implements hook_drush_command().
 */
function contextual_aliases_drush_command() {
  $commands = [];

  $commands['contextual-aliases-update'] = [
    'description' => 'Update aliases without context.',
  ];

  return $commands;
}

/**
 * Implements hook_drush_command().
 */
function drush_contextual_aliases_update() {
  /** @var \Drupal\Core\Database\Connection $db */
  $db = \Drupal::service('database');
  $query = $db->select(ContextualAliasStorage::TABLE);
  $query->isNull('context');
  $count = $query->countQuery()->execute()->fetchField();
  $batchSize = 50;
  $batchCount = ceil($count / $batchSize);

  drush_print('Updating ' . $count . ' aliases.');

  $batch = [
    'title' => t('Updating aliases.'),
    'operations' => array_map(function ($step) use ($batchSize, $count) {
      return ['drush_contextual_aliases_update_batch', [$step, $batchSize, $count]];
    }, range(0, $batchCount - 1)),
  ];

  batch_set($batch);
  drush_backend_batch_process();
}

/**
 * One batch of alias updates.
 */
function drush_contextual_aliases_update_batch($step, $size, $total) {
  $offset = $step * $size;
  $limit = ($step + 1) * $size;
  drush_print("Updating aliases $offset to $limit of $total.");

  /** @var \Drupal\Core\Entity\EntityStorageInterface $redirectStorage */
  $redirectStorage = NULL;
  if (drush_module_exists('redirect')) {
    $redirectStorage = \Drupal::service('entity_type.manager')->getStorage('redirect');
  }

  /** @var ContextualAliasStorage $storage */
  $storage = \Drupal::service('path.alias_storage');

  /** @var \Drupal\Core\Database\Connection $db */
  $db = \Drupal::service('database');
  $query = $db->select(ContextualAliasStorage::TABLE);
  $query->fields(ContextualAliasStorage::TABLE);
  $query->isNull('context');
  $query->range($offset, $size);

  foreach ($query->execute()->fetchAll() as $row) {
    // If redirect is enabled, also update affected redirects.
    if ($redirectStorage) {
      /** @var \Drupal\redirect\Entity\Redirect[] $redirects */
      $redirects = $redirectStorage->loadByProperties(['redirect_redirect__uri' => 'internal:' . $row->source]);
      foreach ($redirects as $redirect) {
        $redirect->save();
      }
    }
    // Re-save alias to trigger context resolution.
    $storage->save($row->source, $row->alias, $row->langcode, $row->pid);
  }

}
<?php

namespace Drupal\contextual_aliases;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Path\AliasStorage;


/**
 * Alias storage that takes contextual information into account.
 */
class ContextualAliasStorage extends AliasStorage {

  protected $currentContexts = NULL;

  protected $cachedContexts = [];

  /**
   * The list of alias context resolvers.
   *
   * @var AliasContextResolverInterface[]
   */
  protected $contextResolvers = [];

  /**
   * Add an alias context resolver.
   */
  public function addContextResolver(AliasContextResolverInterface $resolver) {
    $this->contextResolvers[] = $resolver;
  }

  /**
   * Retrieve the list of current contexts.
   *
   * @return array
   *   The list of current contexts.
   */
  protected function getCurrentContext() {
    if (is_null($this->currentContexts)) {
      $this->currentContexts = array_filter(array_map(function (AliasContextResolverInterface $resolver) {
        return $resolver->getCurrentContext();
      }, $this->contextResolvers));
    }
    return $this->currentContexts ? $this->currentContexts[0] : NULL;
  }

  /**
   * Retrieve the contexts for a given source path.
   *
   * @return string
   *   The list of source contexts.
   */
  protected function getSourceContext($source) {
    if (!array_key_exists($source, $this->cachedContexts)) {
      $this->cachedContexts[$source] = NULL;
      foreach ($this->contextResolvers as $resolver) {
        if ($context = $resolver->resolveContext($source)) {
          $this->cachedContexts[$source] = $context;
        }
      }
    }
    return $this->cachedContexts[$source];
  }

  /**
   * {@inheritdoc}
   */
  public function save($source, $alias, $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED, $pid = NULL) {

    if ($source[0] !== '/') {
      throw new \InvalidArgumentException(sprintf('Source path %s has to start with a slash.', $source));
    }

    if ($alias[0] !== '/') {
      throw new \InvalidArgumentException(sprintf('Alias path %s has to start with a slash.', $alias));
    }

    $fields = [
      'source' => $source,
      'alias' => $alias,
      'langcode' => $langcode,
      // CHANGE: insert context for a given source
      'context' => $this->getSourceContext($source)
    ];

    // Insert or update the alias.
    if (empty($pid)) {
      $try_again = FALSE;
      try {
        $query = $this->connection->insert(static::TABLE)
          ->fields($fields);
        $pid = $query->execute();
      }
      catch (\Exception $e) {
        // If there was an exception, try to create the table.
        if (!$try_again = $this->ensureTableExists()) {
          // If the exception happened for other reason than the missing table,
          // propagate the exception.
          throw $e;
        }
      }
      // Now that the table has been created, try again if necessary.
      if ($try_again) {
        $query = $this->connection->insert(static::TABLE)
          ->fields($fields);
        $pid = $query->execute();
      }

      $fields['pid'] = $pid;
      $operation = 'insert';
    }
    else {
      // Fetch the current values so that an update hook can identify what
      // exactly changed.
      try {
        // CHANGE: Also featch the context field.
        $original = $this->connection->query('SELECT source, alias, context, langcode FROM {url_alias} WHERE pid = :pid', [':pid' => $pid])
          ->fetchAssoc();
      }
      catch (\Exception $e) {
        $this->catchException($e);
        $original = FALSE;
      }
      $fields['pid'] = $pid;
      $query = $this->connection->update(static::TABLE)
        ->fields($fields)
        ->condition('pid', $pid);
      $pid = $query->execute();
      $fields['original'] = $original;
      $operation = 'update';
    }
    if ($pid) {
      // @todo Switch to using an event for this instead of a hook.
      $this->moduleHandler->invokeAll('path_' . $operation, [$fields]);
      Cache::invalidateTags(['route_match']);
      return $fields;
    }
    return FALSE;
  }

  protected function contextCondition($select, $context) {
    /** @var $select SelectInterface */
    if ($context) {
      $contextCondition = $select->orConditionGroup();
      $contextCondition->isNull('context');
      $contextCondition->condition('context', $context);
      $select->orderBy('context', 'DESC');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function load($conditions) {
    $select = $this->connection->select(static::TABLE);

    // CHANGE: Add context condition.
    $context = $this->getCurrentContext();
    if (isset($conditions['source'])) {
      $context = $this->getSourceContext($conditions['source']);
    }
    $this->contextCondition($select, $context);
    // ENDCHANGE

    foreach ($conditions as $field => $value) {
      if ($field == 'source' || $field == 'alias') {
        // Use LIKE for case-insensitive matching.
        $select->condition($field, $this->connection->escapeLike($value), 'LIKE');
      }
      else {
        $select->condition($field, $value);
      }
    }
    try {
      return $select
        ->fields(static::TABLE)
        ->orderBy('pid', 'DESC')
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();
    }
    catch (\Exception $e) {
      $this->catchException($e);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function preloadPathAlias($preloaded, $langcode) {
    $langcode_list = [$langcode, LanguageInterface::LANGCODE_NOT_SPECIFIED];
    $select = $this->connection->select(static::TABLE)
      ->fields(static::TABLE, ['source', 'alias']);

    if (!empty($preloaded)) {
      $conditions = new Condition('OR');
      foreach ($preloaded as $preloaded_item) {
        $conditions->condition('source', $this->connection->escapeLike($preloaded_item), 'LIKE');
      }
      $select->condition($conditions);
    }

    $this->contextCondition($select, $this->getCurrentContext());

    // Always get the language-specific alias before the language-neutral one.
    // For example 'de' is less than 'und' so the order needs to be ASC, while
    // 'xx-lolspeak' is more than 'und' so the order needs to be DESC. We also
    // order by pid ASC so that fetchAllKeyed() returns the most recently
    // created alias for each source. Subsequent queries using fetchField() must
    // use pid DESC to have the same effect.
    if ($langcode == LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      array_pop($langcode_list);
    }
    elseif ($langcode < LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      $select->orderBy('langcode', 'ASC');
    }
    else {
      $select->orderBy('langcode', 'DESC');
    }

    $select->orderBy('pid', 'ASC');
    $select->condition('langcode', $langcode_list, 'IN');
    try {
      return $select->execute()->fetchAllKeyed();
    }
    catch (\Exception $e) {
      $this->catchException($e);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function lookupPathAlias($path, $langcode) {
    $source = $this->connection->escapeLike($path);
    $langcode_list = [$langcode, LanguageInterface::LANGCODE_NOT_SPECIFIED];

    // See the queries above. Use LIKE for case-insensitive matching.
    $select = $this->connection->select(static::TABLE)
      ->fields(static::TABLE, ['alias'])
      ->condition('source', $source, 'LIKE');
    if ($langcode == LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      array_pop($langcode_list);
    }
    elseif ($langcode > LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      $select->orderBy('langcode', 'DESC');
    }
    else {
      $select->orderBy('langcode', 'ASC');
    }

    $this->contextCondition($select, $this->getSourceContext($path));

    $select->orderBy('pid', 'DESC');
    $select->condition('langcode', $langcode_list, 'IN');
    try {
      return $select->execute()->fetchField();
    }
    catch (\Exception $e) {
      $this->catchException($e);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function lookupPathSource($path, $langcode) {
    $alias = $this->connection->escapeLike($path);
    $langcode_list = [$langcode, LanguageInterface::LANGCODE_NOT_SPECIFIED];

    // See the queries above. Use LIKE for case-insensitive matching.
    $select = $this->connection->select(static::TABLE)
      ->fields(static::TABLE, ['source'])
      ->condition('alias', $alias, 'LIKE');
    if ($langcode == LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      array_pop($langcode_list);
    }
    elseif ($langcode > LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      $select->orderBy('langcode', 'DESC');
    }
    else {
      $select->orderBy('langcode', 'ASC');
    }

    $this->contextCondition($select, $this->getCurrentContext());

    $select->orderBy('pid', 'DESC');
    $select->condition('langcode', $langcode_list, 'IN');
    try {
      return $select->execute()->fetchField();
    }
    catch (\Exception $e) {
      $this->catchException($e);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function aliasExists($alias, $langcode, $source = NULL) {
    // Use LIKE and NOT LIKE for case-insensitive matching.
    $query = $this->connection->select(static::TABLE)
      ->condition('alias', $this->connection->escapeLike($alias), 'LIKE')
      ->condition('langcode', $langcode);
    if (!empty($source)) {
      $query->condition('source', $this->connection->escapeLike($source), 'NOT LIKE');
    }

    // CHANGE: Injected context condtion.
    $context = $source ? $this->getSourceContext($source) : $this->getCurrentContext();
    $this->contextCondition($query, $context);
    // ENDCHANGE

    $query->addExpression('1');
    $query->range(0, 1);
    try {
      return (bool) $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      $this->catchException($e);
      return FALSE;
    }
  }

}
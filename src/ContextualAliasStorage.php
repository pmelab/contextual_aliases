<?php

namespace Drupal\contextual_aliases;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Path\AliasStorage;


/**
 * Alias storage that takes contextual information into account.
 */
class ContextualAliasStorage extends AliasStorage {

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
   * List of possible contexts.
   *
   * @return array
   *   The options array of contexts.
   */
  public function getContextOptions() {
    $return = array_reduce(array_map(function (AliasContextResolverInterface $resolver) {
      return $resolver->getContextOptions();
    }, $this->contextResolvers), 'array_merge', []);
    return $return;
  }

  /**
   * Retrieve the current context.
   *
   * @return string
   *   The identifier for the current context.
   */
  public function getCurrentContext() {
    foreach ($this->contextResolvers as $resolver) {
      if ($context = $resolver->getCurrentContext()) {
        return $context;
      }
    }
    return NULL;
  }

  /**
   * Retrieve the contexts for a given source path.
   *
   * @return string
   *   The list of source contexts.
   */
  public function getSourceContext($source) {
    foreach ($this->contextResolvers as $resolver) {
      if ($context = $resolver->resolveContext($source)) {
        return $context;
      }
    }
    return NULL;
  }

  protected function _contextCondition($select, $context, $prefix = FALSE) {
    /** @var $select SelectInterface */
    if ($context) {
      $contextCondition = $select->orConditionGroup();
      $contextCondition->isNull('context');
      $contextCondition->condition('context', $context);
      $select->orderBy('context', 'DESC');
    }
    else {
      $select->isNull('context');
    }

    if (!$context || $prefix) {
      $select->addExpression("CASE WHEN context = '' OR context IS NULL THEN alias ELSE CONCAT('/', context, alias) END", 'alias');
    }
    else {
      $select->addField(static::TABLE, 'alias', 'alias');
    }
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

    $context = $this->getSourceContext($source);

    $fields = [
      'source' => $source,
      'alias' => $alias,
      'context' => $context,
      'langcode' => $langcode,
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
        $original = $this->connection->query('SELECT source, alias, langcode FROM {url_alias} WHERE pid = :pid', [':pid' => $pid])
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

    if ($context) {
      $contextCondition = $select->orConditionGroup();
      $contextCondition->isNull('context');
      $contextCondition->condition('context', $context);
      $select->orderBy('context', 'DESC');
    }
    else {
      $select->orderBy('context', 'ASC');
    }

    $select->addField(static::TABLE, 'alias', 'alias');

    // ENDCHANGE

    foreach ($conditions as $field => $value) {
      if ($field == 'source') {
        // Use LIKE for case-insensitive matching.
        $select->condition($field, $this->connection->escapeLike($value), 'LIKE');
      }
      // CHANGE: special behavior for alias conditions.
      else if ($field == 'alias') {
        $select->condition($field, $this->connection->escapeLike($value), 'LIKE');
      }
      // ENDCHANGE
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
  public function lookupPathAlias($path, $langcode) {
    $source = $this->connection->escapeLike($path);
    $langcode_list = [$langcode, LanguageInterface::LANGCODE_NOT_SPECIFIED];

    // See the queries above. Use LIKE for case-insensitive matching.
    $select = $this->connection->select(static::TABLE)
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

    $context = $this->getSourceContext($path);
    $currentContext = $this->getCurrentContext();

    /** @var $select SelectInterface */
    if ($context) {
      $contextCondition = $select->orConditionGroup();
      $contextCondition->isNull('context');
      $contextCondition->condition('context', $context);
      $select->orderBy('context', 'DESC');
    }

    $select->addField(static::TABLE, 'alias', 'alias');

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
      ->fields(static::TABLE, ['source', 'context']);
    $context = $this->getCurrentContext();

    $select->condition('alias', $this->connection->escapeLike($alias), 'LIKE');

    if ($langcode == LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      array_pop($langcode_list);
    }
    elseif ($langcode > LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      $select->orderBy('langcode', 'DESC');
    }
    else {
      $select->orderBy('langcode', 'ASC');
    }

    $select->orderBy('pid', 'DESC');
    $select->condition('langcode', $langcode_list, 'IN');
    try {
      $results = $select->execute()->fetchAll();
      if ($context) {
        $matching = array_filter($results, function ($row) use($context) {
          return $row->context == $context;
        });
        if ($matching) {
          $results = array_values($matching);
        }
      }
      if ($results) {
        return $results[0]->source;
      }
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
    if ($context) {
      $query->condition('context', $context);
    }
    else {
      $query->isNull('context');
    }
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
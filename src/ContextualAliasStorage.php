<?php

namespace Drupal\contextual_aliases;

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
    $result = parent::save($source, $alias, $langcode, $pid);
    if ($result) {
      $context = $this->getSourceContext($result['source']);
      if ($context) {
        if (strpos($alias, '/' . $context) === 0) {
          $alias = substr($alias, strlen($context) + 1);
        }
        $this->connection->update('url_alias')
          ->fields([
            'alias' => $alias,
            'context' => $context,
          ])
          ->condition('pid', $result['pid'])
          ->execute();
      }
    }

    return $result;
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

    $select->addExpression("CASE WHEN context = '' OR context IS NULL THEN alias ELSE CONCAT('/', context, alias) END", 'alias');
    // ENDCHANGE

    foreach ($conditions as $field => $value) {
      if ($field == 'source') {
        // Use LIKE for case-insensitive matching.
        $select->condition($field, $this->connection->escapeLike($value), 'LIKE');
      }
      // CHANGE: special behavior for alias conditions.
      else if ($field == 'alias') {
        if (!$context) {
          $aliasGroup = $select->orConditionGroup();
          $aliasGroup->condition($field, $this->connection->escapeLike($value), 'LIKE');
          $contextGroup = $aliasGroup->andConditionGroup();
          $tail = explode('/', $value);
          $head = array_shift($tail);
          $contextGroup
            ->condition('context', $head)
            ->condition('alias', $this->connection->escapeLike('/' . implode('/', $tail)), 'LIKE');
        }
        else {
          $select->condition($field, $this->connection->escapeLike($value), 'LIKE');
        }
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
  public function preloadPathAlias($preloaded, $langcode) {
    $langcode_list = [$langcode, LanguageInterface::LANGCODE_NOT_SPECIFIED];
    $select = $this->connection->select(static::TABLE)
      ->fields(static::TABLE, ['source']);

    if (!empty($preloaded)) {
      $conditions = new Condition('OR');
      foreach ($preloaded as $preloaded_item) {
        $conditions->condition('source', $this->connection->escapeLike($preloaded_item), 'LIKE');
      }
      $select->condition($conditions);
    }

    // CHANGE: Added context condition.
    $context = $this->getCurrentContext();

    if ($context) {
      $contextCondition = $select->orConditionGroup();
      $contextCondition->isNull('context');
      $contextCondition->condition('context', $context);
      $select->orderBy('context', 'DESC');
    }
    else {
      $select->isNull('context');
    }

    $select->addExpression("CASE WHEN context = '' OR context IS NULL THEN alias ELSE CONCAT('/', context, alias) END", 'alias');
    // ENDCHANGE

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
    else {
      $select->isNull('context');
    }

    if ($context != $currentContext) {
      $select->addExpression("CASE WHEN context = '' OR context IS NULL THEN alias ELSE CONCAT('/', context, alias) END", 'alias');
    }
    else {
      $select->addField(static::TABLE, 'alias', 'alias');
    }

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
      ->fields(static::TABLE, ['source']);
    $context = $this->getCurrentContext();

    if (!$context) {
      $aliasGroup = $select->orConditionGroup();
      $nonContextGroup = $aliasGroup->andConditionGroup();
      $nonContextGroup->condition('alias', $this->connection->escapeLike($alias), 'LIKE');
      $nonContextGroup->isNull('context');
      $aliasGroup->condition($nonContextGroup);
      $contextGroup = $aliasGroup->andConditionGroup();
      $tail = explode('/', ltrim($alias, '/'));
      $head = array_shift($tail);
      $contextGroup
        ->condition('context', $head)
        ->condition('alias', $this->connection->escapeLike('/' . implode('/', $tail)), 'LIKE');
      $aliasGroup->condition($contextGroup);
      $select->condition($aliasGroup);
    }
    else {
      $tail = explode('/', ltrim($alias, '/'));
      $head = array_shift($tail);

      $aliasGroup = $select->orConditionGroup();
      $contextGroup = $aliasGroup->andConditionGroup();

      $contextGroup
        ->condition('context', $head)
        ->condition('alias', $this->connection->escapeLike('/' . implode('/', $tail)), 'LIKE');
      $nonContextGroup = $aliasGroup->andConditionGroup();
      $nonContextGroup->condition('alias', $this->connection->escapeLike($alias), 'LIKE');
      $nonContextGroup->condition('context', $context);
      $aliasGroup->condition($contextGroup);
      $aliasGroup->condition($nonContextGroup);
      $select->condition($aliasGroup);
    }


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
    if ($context) {
      $contextCondition = $query->orConditionGroup();
      $contextCondition->isNull('context');
      $contextCondition->condition('context', $context);
      $query->condition($contextCondition);
      $query->orderBy('context', 'DESC');
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
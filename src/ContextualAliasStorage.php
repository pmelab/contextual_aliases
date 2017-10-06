<?php

namespace Drupal\contextual_aliases;

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
   * @return array
   *   The list of source contexts.
   */
  protected function getSourceContexts($source) {
    if (!array_key_exists($source, $this->cachedContexts)) {
      $this->cachedContexts[$source] = array_filter(array_map(function (AliasContextResolverInterface $resolver) use($source) {
        return $resolver->resolveContext($source);
      }, $this->contextResolvers));
    }
    return $this->cachedContexts[$source];
  }

  /**
   * Add a context prefix to a certain path.
   */
  protected function addPrefix($path, $context) {
    return '/' . $context . $path;
  }

  /**
   * Check if a path is prefixed with a certain context.
   */
  protected function hasPrefix($path, $context) {
    $prefix = $this->addPrefix('', $context);
    return strpos($prefix, $path ) === 0;
  }

  /**
   * Remove the context prefix from a path.
   */
  protected function removePrefix($path, $context) {
    $prefix = $this->addPrefix('', $context);
    return substr($path, strlen($prefix));
  }

  /**
   * {@inheritdoc}
   */
  public function save($source, $alias, $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED, $pid = NULL) {
    if ($contexts = $this->getSourceContexts($source)) {
      $result = FALSE;
      foreach ($contexts as $context) {
        $result = parent::save(
          $this->addPrefix($source, $context),
          $this->addPRefix($alias, $context),
          $langcode,
          $pid
        );
      }
      return $result;
    }
    return parent::save($source, $alias, $langcode, $pid);
  }

  /**
   * {@inheritdoc}
   */
  public function load($conditions) {
    if ($context = $this->getCurrentContext()) {
      return array_filter(parent::load($conditions), function ($row) use ($context) {
        return $this->hasPrefix($row['alias'], $context);
      });
    }
    return parent::load($conditions);
  }

  /**
   * {@inheritdoc}
   */
  public function preloadPathAlias($preloaded, $langcode) {
    if ($context = $this->getCurrentContext()) {
      return array_filter(parent::preloadPathAlias($preloaded, $langcode), function ($row) use ($context) {
        return $this->hasPrefix($row['alias'], $context);
      });
    }
    return parent::preloadPathAlias($preloaded, $langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function lookupPathAlias($path, $langcode) {
    if($context = $this->getCurrentContext()) {
      return $this->removePrefix(parent::lookupPathAlias($this->addPrefix($path, $context), $langcode), $context);
    }
    return parent::lookupPathAlias($path, $langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function lookupPathSource($path, $langcode) {
    if($context = $this->getCurrentContext()) {
      return $this->removePrefix(parent::lookupPathSource($this->addPrefix($path, $context), $langcode), $context);
    }
    return parent::lookupPathSource($path, $langcode);
  }

}
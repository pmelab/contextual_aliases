<?php

namespace Drupal\contextual_aliases;

/**
 * Interface for alias context resolvers.
 */
interface AliasContextResolverInterface {

  /**
   * Retrieve the current alias context string.
   *
   * @return string|null
   *   The context identifier or null if the context is not available.
   */
  function getCurrentContext();

  /**
   * List of possible contexts.
   *
   * @return array
   *   The options array of contexts.
   */
  function getContextOptions();

  /**
   * Build the alias context for a given destination path.
   *
   * @param $path
   *   The destination path.
   *
   * @return string|null
   *   The context identifier or NULL if this resolver doesn't apply.
   */
  function resolveContext($path);

}


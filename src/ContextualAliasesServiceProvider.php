<?php

namespace Drupal\contextual_aliases;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Service provider that replaces the default alias storage.
 */
class ContextualAliasesServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $container->getDefinition('path.alias_storage')
      ->setClass(ContextualAliasStorage::class)
      ->addTag('service_collector', [
        'tag' => 'alias_context_resolver',
        'call' => 'addContextResolver',
      ]);
  }

}
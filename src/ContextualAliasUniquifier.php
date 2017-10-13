<?php

namespace Drupal\contextual_aliases;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Path\AliasManagerInterface;
use Drupal\Core\Path\AliasStorageInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\pathauto\AliasStorageHelperInterface;
use Drupal\pathauto\AliasUniquifier;

class ContextualAliasUniquifier extends AliasUniquifier {

  /**
   * The alias storage.
   *
   * @var \Drupal\contextual_aliases\ContextualAliasStorage
   */
  protected $aliasStorage;

  public function __construct(
    ConfigFactoryInterface $config_factory,
    AliasStorageHelperInterface $alias_storage_helper,
    ModuleHandlerInterface $module_handler,
    RouteProviderInterface $route_provider,
    AliasManagerInterface $alias_manager,
    ContextualAliasStorage $aliasStorage
  ) {
    $this->aliasStorage = $aliasStorage;
    parent::__construct(
      $config_factory,
      $alias_storage_helper,
      $module_handler,
      $route_provider,
      $alias_manager
    );
  }


  public function isReserved($alias, $source, $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED
  ) {

    if ($context = $this->aliasStorage->getSourceContext($source)) {
      $alias = '/' . $context . $alias;
    }

    return parent::isReserved(
      $alias,
      $source,
      $langcode
    );
  }


}
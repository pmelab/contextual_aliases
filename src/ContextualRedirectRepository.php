<?php

namespace Drupal\contextual_aliases;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Language\Language;
use Drupal\redirect\RedirectRepository;

class ContextualRedirectRepository extends RedirectRepository {

  /**
   * The alias storage.
   *
   * @var \Drupal\contextual_aliases\ContextualAliasStorage
   */
  protected $aliasStorage;

  public function __construct(
    EntityManagerInterface $manager,
    Connection $connection,
    ConfigFactoryInterface $config_factory,
    ContextualAliasStorage $aliasStorage
  ) {
    $this->aliasStorage = $aliasStorage;
    parent::__construct($manager, $connection, $config_factory);
  }


  public function findMatchingRedirect(
    $source_path,
    array $query = [],
    $language = Language::LANGCODE_NOT_SPECIFIED
  ) {
    $context = $this->aliasStorage->getCurrentContext();
    if ($context) {
      return parent::findMatchingRedirect(
        '/' . $context . '/' . $source_path,
        $query,
        $language
      ) ?: parent::findMatchingRedirect(
        $source_path,
        $query,
        $language
      );
    }
    return parent::findMatchingRedirect(
      $source_path,
      $query,
      $language
    );
  }

}

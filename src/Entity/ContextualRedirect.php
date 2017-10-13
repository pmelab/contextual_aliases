<?php

namespace Drupal\contextual_aliases\Entity;

use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\redirect\Entity\Redirect;

class ContextualRedirect extends Redirect {

  public function preSave(EntityStorageInterface $storage_controller) {
    /** @var \Drupal\contextual_aliases\ContextualAliasStorage $aliasStorage */
    $aliasStorage = \Drupal::service('path.alias_storage');
    $parsed = parse_url($this->redirect_redirect->uri);
    $context = isset($parsed['path']) ? $aliasStorage->getSourceContext($parsed['path']) : NULL;
    $this->set('context', $context);
    $this->set('hash', Redirect::generateHash(
      ($context ? '/' . $context : '') . $this->redirect_source->path,
      (array) $this->redirect_source->query,
      $this->language()->getId()
    ));
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields['context'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Context'))
      ->setSetting('max_length', 32)
      ->setRequired(FALSE)
      ->setDescription(t('The alias context.'));
    return $fields;
  }

}
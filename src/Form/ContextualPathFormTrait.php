<?php

namespace Drupal\contextual_aliases\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;

trait ContextualPathFormTrait {

  public function buildForm(array $form, FormStateInterface $form_state, $pid = NULL) {
    $form = parent::buildForm($form, $form_state, $pid);
    $path = $this->buildPath($pid);
    $form['context'] = [
      '#title' => $this->t('Context'),
      '#description' => $this->t('Choose the context this alias should apply in. <strong>If the existing path is bound to a context, this value will be overridden!</strong>'),
      '#type' => 'select',
      '#options' => contextual_aliases_context_options(),
      '#default_value' => isset($path['context']) ? $path['context'] : NULL,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $source = &$form_state->getValue('source');
    $source = $this->aliasManager->getPathByAlias($source);
    $alias = &$form_state->getValue('alias');

    // Trim the submitted value of whitespace and slashes. Ensure to not trim
    // the slash on the left side.
    $alias = rtrim(trim(trim($alias), ''), "\\/");

    if ($source[0] !== '/') {
      $form_state->setErrorByName('source', 'The source path has to start with a slash.');
    }
    if ($alias[0] !== '/') {
      $form_state->setErrorByName('alias', 'The alias path has to start with a slash.');
    }

    // Language is only set if language.module is enabled, otherwise save for all
    // languages.
    $langcode = $form_state->getValue('langcode', LanguageInterface::LANGCODE_NOT_SPECIFIED);

    if ($this->aliasStorage->aliasExists($alias, $langcode, $this->path['source'], $form_state->getValue('context'))) {
      $stored_alias = $this->aliasStorage->load(['alias' => $alias, 'langcode' => $langcode, 'context' => $form_state->getValue('context')]);
      if ($stored_alias['alias'] !== $alias) {
        // The alias already exists with different capitalization as the default
        // implementation of AliasStorageInterface::aliasExists is
        // case-insensitive.
        $form_state->setErrorByName('alias', t('The alias %alias could not be added because it is already in use in this language with different capitalization: %stored_alias.', [
          '%alias' => $alias,
          '%stored_alias' => $stored_alias['alias'],
        ]));
      }
      else {
        $form_state->setErrorByName('alias', t('The alias %alias is already in use in this language.', ['%alias' => $alias]));
      }
    }

    if (!$this->pathValidator->isValid(trim($source, '/'))) {
      $form_state->setErrorByName('source', t("Either the path '@link_path' is invalid or you do not have access to it.", ['@link_path' => $source]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Remove unnecessary values.
    $form_state->cleanValues();

    $pid = $form_state->getValue('pid', 0);
    $source = $form_state->getValue('source');
    $alias = $form_state->getValue('alias');
    $context = $form_state->getValue('context');

    // Language is only set if language.module is enabled, otherwise save for all
    // languages.
    $langcode = $form_state->getValue('langcode', LanguageInterface::LANGCODE_NOT_SPECIFIED);

    $this->aliasStorage->save($source, $alias, $langcode, $pid, $context);

    drupal_set_message($this->t('The alias has been saved.'));
    $form_state->setRedirect('path.admin_overview');
  }
}

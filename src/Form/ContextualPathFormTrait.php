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
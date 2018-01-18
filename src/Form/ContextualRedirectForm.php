<?php

namespace Drupal\contextual_aliases\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\redirect\Entity\Redirect;
use Drupal\redirect\Form\RedirectForm;

class ContextualRedirectForm extends RedirectForm {

  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $source = $form_state->getValue(array('redirect_source', 0));
    $redirect = $form_state->getValue(array('redirect_redirect', 0));

    $parsed_url = UrlHelper::parse(trim($source['path']));
    $path = isset($parsed_url['path']) ? $parsed_url['path'] : NULL;
    $query = isset($parsed_url['query']) ? $parsed_url['query'] : NULL;

    /** @var \Drupal\contextual_aliases\ContextualAliasStorage $storage */
    $storage = \Drupal::service('path.alias_storage');

    $context = $form_state->getValue(['context', 0])['value'];
    if (!UrlHelper::isExternal($redirect['uri'])) {
      $context = $storage->getSourceContext($redirect['uri']);
    }

    $hash = Redirect::generateHash($path, $query + ['_context' => $context], $form_state->getValue('language')[0]['value']);

    // Search for duplicate.
    $redirects = \Drupal::entityManager()
      ->getStorage('redirect')
      ->loadByProperties(array('hash' => $hash));

    if (!empty($redirects)) {
      $redirect = array_shift($redirects);
      if ($this->entity->isNew() || $redirect->id() != $this->entity->id()) {
        $form_state->setErrorByName('redirect_source', t('The source path %source is already being redirected in context %context. Do you want to <a href="@edit-page">edit the existing redirect</a>?',
          array(
            '%source' => $source['path'],
            '%context' => $context,
            '@edit-page' => $redirect->url('edit-form'))));
      }
    }

  }

}
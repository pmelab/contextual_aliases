<?php

namespace Drupal\contextual_aliases\Controller;

use Drupal\Component\Utility\Unicode;
use Drupal\Core\Url;
use Drupal\path\Controller\PathController;
use Symfony\Component\HttpFoundation\Request;

class ContextualPathController extends PathController {

  public function adminOverview(Request $request) {
    $keys = $request->query->get('search');
    // Add the filter form above the overview table.
    $build['path_admin_filter_form'] = $this->formBuilder()->getForm('Drupal\path\Form\PathFilterForm', $keys);
    // Enable language column if language.module is enabled or if we have any
    // alias with a language.
    $multilanguage = ($this->moduleHandler()->moduleExists('language') || $this->aliasStorage->languageAliasExists());

    $header = [];
    $header[] = ['data' => $this->t('Alias'), 'field' => 'alias', 'sort' => 'asc'];
    $header[] = ['data' => $this->t('System'), 'field' => 'source'];
    $header[] = ['data' => $this->t('Context'), 'field' => 'context'];
    if ($multilanguage) {
      $header[] = ['data' => $this->t('Language'), 'field' => 'langcode'];
    }
    $header[] = $this->t('Operations');

    $rows = [];
    $destination = $this->getDestinationArray();
    foreach ($this->aliasStorage->getAliasesForAdminListing($header, $keys) as $data) {
      $row = [];
      // @todo Should Path module store leading slashes? See
      //   https://www.drupal.org/node/2430593.
      $row['data']['alias'] = $this->l(Unicode::truncate($data->alias, 50, FALSE, TRUE), Url::fromUserInput($data->source, [
        'attributes' => ['title' => $data->alias],
      ]));
      $row['data']['source'] = $this->l(Unicode::truncate($data->source, 50, FALSE, TRUE), Url::fromUserInput($data->source, [
        'alias' => TRUE,
        'attributes' => ['title' => $data->source],
      ]));

      $row['data']['context'] = $data->context;

      if ($multilanguage) {
        $row['data']['language_name'] = $this->languageManager()->getLanguageName($data->langcode);
      }

      $operations = [];
      $operations['edit'] = [
        'title' => $this->t('Edit'),
        'url' => Url::fromRoute('path.admin_edit', ['pid' => $data->pid], ['query' => $destination]),
      ];
      $operations['delete'] = [
        'title' => $this->t('Delete'),
        'url' => Url::fromRoute('path.delete', ['pid' => $data->pid], ['query' => $destination]),
      ];
      $row['data']['operations'] = [
        'data' => [
          '#type' => 'operations',
          '#links' => $operations,
        ],
      ];

      // If the system path maps to a different URL alias, highlight this table
      // row to let the user know of old aliases.
      if ($data->alias != $this->aliasManager->getAliasByPath($data->source, $data->langcode)) {
        $row['class'] = ['warning'];
      }

      $rows[] = $row;
    }

    $build['path_table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No URL aliases available. <a href=":link">Add URL alias</a>.', [':link' => $this->url('path.admin_add')]),
    ];
    $build['path_pager'] = ['#type' => 'pager'];

    return $build;
  }

}
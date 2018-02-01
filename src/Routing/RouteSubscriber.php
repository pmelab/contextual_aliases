<?php

namespace Drupal\contextual_aliases\Routing;

use Drupal\contextual_aliases\Controller\ContextualPathController;
use Drupal\contextual_aliases\Form\ContextualAddForm;
use Drupal\contextual_aliases\Form\ContextualEditForm;
use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

class RouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection) {

    foreach (['path.admin_overview', 'path.admin_overview_filter'] as $id) {
      if ($route = $collection->get($id)) {
        $route->setDefault('_controller', ContextualPathController::class . '::adminOverview');
      }
    }

    if ($route = $collection->get('path.admin_add')) {
      $route->setDefault('_form', ContextualAddForm::class);
    }

    if ($route = $collection->get('path.admin_edit')) {
      $route->setDefault('_form', ContextualEditForm::class);
    }

  }

}
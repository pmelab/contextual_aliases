<?php

namespace Drupal\contextual_aliases\Form;

use Drupal\path\Form\AddForm as PathAddForm;

class ContextualAddForm extends PathAddForm {
  use ContextualPathFormTrait;
}
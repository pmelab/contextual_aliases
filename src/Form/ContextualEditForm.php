<?php

namespace Drupal\contextual_aliases\Form;

use Drupal\path\Form\EditForm as PathEditForm;

class ContextualEditForm extends PathEditForm {
  use ContextualPathFormTrait;
}
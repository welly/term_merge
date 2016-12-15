<?php

namespace Drupal\term_merge\Plugin\Action;

use Drupal\Core\Action\ActionBase;
use Drupal\Core\Session\AccountInterface;

/**
 * @Action(
 *   id = "term_merge_action",
 *   label = @Translation("Merges Terms"),
 *   type = "taxonomy_term"
 * )
 */
class TermMergeAction extends ActionBase {

  /**
   * {@inheritdoc}
   */
  public function execute($object = NULL) {
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, AccountInterface $account = NULL, $return_as_object = FALSE) {

    return true;
  }

}

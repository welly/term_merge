<?php

namespace Drupal\term_merge;

class TermMerge {

  /**
   * Merge terms one into another using batch API.
   *
   * @param array $term_branch
   *   A single term tid or an array of term tids to be merged, aka term branches
   * @param int $term_trunk
   *   The tid of the term to merge term branches into, aka term trunk
   * @param array $merge_settings
   *   Array of settings that control how merging should happen. Currently
   *   supported settings are:
   *     - term_branch_keep: (bool) Whether the term branches should not be
   *       deleted, also known as "merge only occurrences" option
   *     - merge_fields: (array) Array of field names whose values should be
   *       merged into the values of corresponding fields of term trunk (until
   *       each field's cardinality limit is reached)
   *     - keep_only_unique: (bool) Whether after merging within one field only
   *       unique taxonomy term references should be kept in other entities. If
   *       before merging your entity had 2 values in its taxonomy term reference
   *       field and one was pointing to term branch while another was pointing to
   *       term trunk, after merging you will end up having your entity
   *       referencing to the same term trunk twice. If you pass TRUE in this
   *       parameter, only a single reference will be stored in your entity after
   *       merging
   *     - redirect: (int) HTTP code for redirect from $term_branch to
   *       $term_trunk, 0 stands for the default redirect defined in Redirect
   *       module. Use constant TERM_MERGE_NO_REDIRECT to denote not creating any
   *       HTTP redirect. Note: this parameter requires Redirect module enabled,
   *       otherwise it will be disregarded
   *     - synonyms: (array) Array of field names of trunk term into which branch
   *       terms should be added as synonyms (until each field's cardinality limit
   *       is reached). Note: this parameter requires Synonyms module enabled,
   *       otherwise it will be disregarded
   *     - step: (int) How many term branches to merge per script run in batch. If
   *       you are hitting time or memory limits, decrease this parameter
   */
  public static function merge($term_branch, $term_trunk, $merge_settings = array()) {

    // Older versions of this module had another interface of this function,
    // as backward capability we still support the older interface, instead of
    // supplying a $merge_settings array, it was supplying all the settings as
    // additional function arguments.
    // @todo: delete this backward capability at some point.
    if (!is_array($merge_settings)) {
      $merge_settings = ['term_branch_keep' => $merge_settings];
    }

    // Create an array of sources if it isn't yet.
    if (!is_array($term_branch)) {
      $term_branch = [$term_branch];
    }

    // Creating a skeleton for the merging batch.
    $batch = [
      'title' => t('Merging terms'),
      'operations' => [
        [
          'merge_batch_process',
          [
            $term_branch,
            $term_trunk,
            $merge_settings,
          ],
        ],
      ],
      'file' => drupal_get_path('module', 'term_merge') . '/term_merge.batch.inc',
      'finished' => 'term_merge_batch_finished',
    ];

    // Initialize the batch process.
    batch_set($batch);
  }

   /**
   * Batch finished callback.
   */
  public static function batchFinishedCallback($success, $results, $operations) {
    if ($success) {
      drupal_set_message(t('The terms have been successfully merged.'));
    }
    else {
      // An error happened. We have to notify the user.
      drupal_set_message(t('An error occurred. We are sorry, please, report this error to the maintainers of Term Merge module.'), 'error');
    }
  }

}

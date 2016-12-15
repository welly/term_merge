<?php

/**
 * @file
 * Batch process callbacks for Term Merge module.
 */

define('TERM_MERGE_NO_REDIRECT', -1);

/**
 * Process batch function.
 *
 * Trigger action 'term_merge_action' on each pair of term branch:term trunk.
 *
 * @param array $term_branch
 *   An array of term tids to be merged, aka term branches
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
 * @param array $context
 *   Drupal Batch API context array
 */
function merge_batch_process($term_branch, $term_trunk, $merge_settings, &$context) {
  // Initializing sandbox.
  if (!isset($context['sandbox']['current'])) {
    $context['sandbox']['current'] = 0;
  }

  // Populating $merge_settings with defaults.
  $merge_settings += array(
    'term_branch_keep' => FALSE,
    'merge_fields' => array(),
    'keep_only_unique' => TRUE,
    'redirect' => TERM_MERGE_NO_REDIRECT,
    'synonyms' => array(),
    'step' => 40,
  );

  $total = count($term_branch);

  // To speed up the process we take advantage of taxonomy_term_load_multiple()
  // instead of just repeating calls to taxonomy_term_load().
  $till = min($total, $context['sandbox']['current'] + $merge_settings['step']);
  $length = $till - $context['sandbox']['current'];
  $term_branch = array_slice($term_branch, $context['sandbox']['current'], $length);
  $term_branch = array_values(taxonomy_term_load_multiple($term_branch));
  for ($i = 0; $i < $merge_settings['step'] && $context['sandbox']['current'] < $total; $i++) {
    execute_merge($term_branch, $term_trunk);

    //    actions_do('term_merge_action', $term_branch[$i], array(
//      'term_trunk' => $term_trunk,
//      'term_branch_keep' => $merge_settings['term_branch_keep'],
//      'merge_fields' => $merge_settings['merge_fields'],
//      'keep_only_unique' => $merge_settings['keep_only_unique'],
//      'redirect' => $merge_settings['redirect'],
//      'synonyms' => $merge_settings['synonyms'],
//    ));
    $context['sandbox']['current']++;
  }

  if ($context['sandbox']['current'] != $total) {
    $context['finished'] = $context['sandbox']['current'] / $total;
    $term = $term_branch[$i - 1];
    $context['message'] = t('Merged up to @term', array('@term' => $term->name));
  }
}

/**
 * Action function. Perform action "Term Merge".
 */
function execute_merge($term_branch, $term_trunk) {

  $vocabulary = taxonomy_vocabulary_load($term_branch->vid);

//  $term_branch_children = array();
//  foreach (taxonomy_get_tree($term_branch->vid, $term_branch->tid) as $term) {
//    $term_branch_children[] = $term->tid;
//  }
//
//  if ($term_branch->vid != $term_trunk->vid) {
//    \Drupal::logger('term_merge')->warning(
//      'Trying to merge 2 terms (%term_branch, %term_trunk) from different vocabularies', array(
//        '%term_branch' => $term_branch->name,
//        '%term_trunk' => $term_trunk->name,
//      )
//    );
//    return;
//  }
//  if ($term_branch->tid == $term_trunk->tid) {
//    \Drupal::logger('term_merge')->warning(
//      'Trying to merge a term %term into itself.', array('%term' => $term_branch->name)
//    );
//    return;
//  }
//  if (in_array($term_trunk->tid, $term_branch_children)) {
//    \Drupal::logger('term_merge')->warning(
//      'Trying to merge a term %term_branch into its child %term_trunk.', array(
//        '%term_branch' => $term_branch->name,
//        '%term_trunk' => $term_trunk->name,
//      )
//    );
//    return;
//  }
//
//  // Defining some default values.
//  if (!isset($context['term_branch_keep'])) {
//    // It's easier to manually delete the unwanted terms, rather than
//    // search for your DB back up. So by default we keep the term branch.
//    $context['term_branch_keep'] = TRUE;
//  }
//  if (!isset($context['merge_fields'])) {
//    // Initializing it with an empty array if client of this function forgot to
//    // provide info about what fields to merge.
//    $context['merge_fields'] = array();
//  }
//  if (!isset($context['keep_only_unique'])) {
//    // Seems logical that mostly people will prefer to keep only one value in
//    // term reference field per taxonomy term.
//    $context['keep_only_unique'] = TRUE;
//  }
////  if (!isset($context['redirect']) || !module_exists('redirect')) {
////    // This behavior requires Redirect module installed and enabled.
////    $context['redirect'] = TERM_MERGE_NO_REDIRECT;
////  }
////  if (!isset($context['synonyms']) || !module_exists('synonyms')) {
////    // This behavior requires Synonyms module installed and enabled.
////    $context['synonyms'] = array();
////  }
//
//  // Calling a hook, this way we let whoever else to react and do his own extra
//  // logic when merging of terms occurs. We prefer to call it before we handle
//  // our own logic, because our logic might delete $term_branch and maybe a
//  // module that implements this hook needs this term not deleted yet.
////  module_invoke_all('term_merge', $term_trunk, $term_branch, $context);
//
//  if (!empty($context['merge_fields'])) {
//    // "Merging" the fields from $term_branch into $term_trunk where it is
//    // possible.
//    foreach ($context['merge_fields'] as $field_name) {
//      // Getting the list of available languages for this field.
//      $languages = array();
//      if (isset($term_trunk->$field_name) && is_array($term_trunk->$field_name)) {
//        $languages = array_merge($languages, array_keys($term_trunk->$field_name));
//      }
//      if (isset($term_branch->$field_name) && is_array($term_branch->$field_name)) {
//        $languages = array_merge($languages, array_keys($term_branch->$field_name));
//      }
//      $languages = array_unique($languages);
//      // Merging the data of both terms into $term_trunk.
//      foreach ($languages as $language) {
//        if (!isset($term_trunk->{$field_name}[$language])) {
//          $term_trunk->{$field_name}[$language] = array();
//        }
//        if (!isset($term_branch->{$field_name}[$language])) {
//          $term_branch->{$field_name}[$language] = array();
//        }
//        $items = array_merge($term_trunk->{$field_name}[$language], $term_branch->{$field_name}[$language]);
//        $unique_items = array();
//        foreach ($items as $item) {
//          $unique_items[serialize($item)] = $item;
//        }
//        $items = array_values($unique_items);
//
//        $term_trunk->{$field_name}[$language] = $items;
//      }
//    }
//
//    // And now we can save $term_trunk after shifting all the fields from
//    // $term_branch.
////    taxonomy_term_save($term_trunk);
//  }
//
//  $result = array();
//  foreach (term_merge_fields_with_foreign_key('taxonomy_term_data', 'tid') as $field) {
//    $result[$field['field_name']] = array();
//    $query = new EntityFieldQuery();
//    // Making sure we search in the entire scope of entities.
//    $query->addMetaData('account', user_load(1));
//
//    $query->fieldCondition($field['field_name'], $field['term_merge_field_column'], $term_branch->tid);
//    $_result = $query->execute();
//    $result[$field['field_name']]['entities'] = $_result;
//    $result[$field['field_name']]['column'] = $field['term_merge_field_column'];
//  }
//
//  // Now we load all entities that have fields pointing to $term_branch.
//  foreach ($result as $field_name => $field_data) {
//    $column = $field_data['column'];
//    foreach ($field_data['entities'] as $entity_type => $v) {
//      $ids = array_keys($v);
//      $entities = entity_load($entity_type, $ids);
//      // After we have loaded it, we alter the field to point to $term_trunk.
//      foreach ($entities as $entity) {
//        // What is more, we have to do it for every available language.
//        foreach ($entity->$field_name as $language => $items) {
//          // Keeping track of whether term trunk is already present in this
//          // field in this language. This is useful for the option
//          // 'keep_only_unique'.
//          $is_trunk_added = FALSE;
//          foreach ($entity->{$field_name}[$language] as $delta => $item) {
//            if ($context['keep_only_unique'] && $is_trunk_added && in_array($item[$column], array($term_trunk->tid, $term_branch->tid))) {
//              // We are instructed to keep only unique references and we already
//              // have term trunk in this field, so we just unset value for this
//              // delta.
//              unset($entity->{$field_name}[$language][$delta]);
//            }
//            else {
//              // Merging term references if necessary, and keep an eye on
//              // whether we already have term trunk among this field values.
//              switch ($item[$column]) {
//                case $term_trunk->tid:
//                  $is_trunk_added = TRUE;
//                  break;
//
//                case $term_branch->tid:
//                  $is_trunk_added = TRUE;
//                  $entity->{$field_name}[$language][$delta][$column] = $term_trunk->tid;
//                  break;
//              }
//            }
//          }
//          // Above in the code, while looping through all deltas of this field,
//          // we might have unset some of the deltas to keep term references
//          // unique. We should better keep deltas as a series of consecutive
//          // numbers, because it is what it is supposed to be.
//          $entity->{$field_name}[$language] = array_values($entity->{$field_name}[$language]);
//        }
//
//        // Integration with workbench_moderation module. Without this code, if
//        // we save the node for which workbench moderation is enabled, then
//        // it will go from "published" state into "draft". Though in fact we do
//        // not change anything in the node and therefore it should persist in
//        // published state.
//        if (module_exists('workbench_moderation') && $entity_type == 'node') {
//          $entity->workbench_moderation['updating_live_revision'] = TRUE;
//        }
//
//        // After updating all the references, save the entity.
//        entity_save($entity_type, $entity);
//      }
//    }
//  }
//
//  // Adding term branch as synonym (Synonyms module integration).
//  foreach ($context['synonyms'] as $synonym_field) {
//    synonyms_add_entity_as_synonym($term_trunk, 'taxonomy_term', $synonym_field, $term_branch, 'taxonomy_term');
//  }
//
//  // It turned out we gotta go tricky with the Redirect module. If we create
//  // redirection before deleting the branch term (if we are instructed to delete
//  // in this action) redirect module will do its "auto-clean up" in
//  // hook_entity_delete() and will delete our just created redirects. But at the
//  // same time we have to get the path alias of the $term_branch before it gets
//  // deleted. Otherwise the path alias will be deleted along with the term
//  // itself. Similarly would be lost all redirects pointing to branch term
//  // paths. We will redirect normal term path and its RSS feed.
//  $redirect_paths = array();
//  if ($context['redirect'] != TERM_MERGE_NO_REDIRECT) {
//    $redirect_paths['taxonomy/term/' . $term_trunk->tid] = array(
//      'taxonomy/term/' . $term_branch->tid,
//    );
//    $redirect_paths['taxonomy/term/' . $term_trunk->tid . '/feed'] = array(
//      'taxonomy/term/' . $term_branch->tid . '/feed',
//    );
//    foreach ($redirect_paths as $redirect_destination => $redirect_sources) {
//      // We create redirect from Drupal normal path, then we try to fetch its
//      // alias. Lastly we collect a set of redirects that point to either of the
//      // 2 former paths. Everything we were able to fetch will be redirecting to
//      // the trunk term.
//      $alias = drupal_get_path_alias($redirect_sources[0]);
//      if ($alias != $redirect_sources[0]) {
//        $redirect_sources[] = $alias;
//      }
//      $existing_redirects = array();
//      foreach ($redirect_sources as $redirect_source) {
//        foreach (redirect_load_multiple(array(), array('redirect' => $redirect_source)) as $v) {
//          $existing_redirects[] = $v->source;
//        }
//      }
//      $redirect_paths[$redirect_destination] = array_unique(array_merge($redirect_sources, $existing_redirects));
//    }
//  }
//
//  if (!$context['term_branch_keep']) {
//    // If we are going to delete branch term, we need firstly to make sure
//    // all its children now have the parent of term_trunk.
//    foreach (taxonomy_get_children($term_branch->tid, $vocabulary->vid) as $child) {
//      $parents = taxonomy_get_parents($child->tid);
//      // Deleting the parental link to the term that is being merged.
//      unset($parents[$term_branch->tid]);
//      // And putting the parental link to the term that we merge into.
//      $parents[$term_trunk->tid] = $term_trunk;
//      $parents = array_unique(array_keys($parents));
//      $child->parent = $parents;
//      taxonomy_term_save($child);
//    }
//
//    // Views module integration. We update all Views taxonomy filter handlers
//    // configured to filter on term branch to filter on term trunk now, since
//    // the former becomes the latter.
//    if (module_exists('views')) {
//      $views = views_get_all_views();
//      foreach ($views as $view) {
//        // For better efficiency, we keep track of whether we have updated
//        // anything in a view, and thus whether we need to save it.
//        $needs_saving = FALSE;
//        // Even worse, we have to go through each display of each view.
//        foreach ($view->display as $display_id => $display) {
//          $view->set_display($display_id);
//          $filters = $view->display_handler->get_handlers('filter');
//          foreach ($filters as $filter_id => $filter_handler) {
//            // Currently we know how to update filters only of this particular
//            // class.
//            if (get_class($filter_handler) == 'views_handler_filter_term_node_tid') {
//              $filter = $view->get_item($display_id, 'filter', $filter_id);
//              if (isset($filter['value'][$term_branch->tid])) {
//                // Substituting term branch with term trunk.
//                unset($filter['value'][$term_branch->tid]);
//                $filter['value'][$term_trunk->tid] = $term_trunk->tid;
//                $view->set_item($display_id, 'filter', $filter_id, $filter);
//                $needs_saving = TRUE;
//              }
//            }
//          }
//        }
//        if ($needs_saving) {
//          $view->save();
//        }
//      }
//    }
//
//    // We are instructed to delete the term branch after the merge,
//    // and so we do.
//    taxonomy_term_delete($term_branch->tid);
//  }
//
//  // Here we do the 2nd part of integration with the Redirect module. Once the
//  // branch term has been deleted (if deleted), we can add the redirects
//  // without being afraid that the redirect module will delete them in its
//  // hook_entity_delete().
//  foreach ($redirect_paths as $redirect_destination => $redirect_sources) {
//    foreach ($redirect_sources as $redirect_source) {
//      $redirect = redirect_load_by_source($redirect_source);
//      if (!$redirect) {
//        // Seems like redirect from such URI does not exist yet, we will create
//        // it.
//        $redirect = new stdClass();
//        redirect_object_prepare($redirect, array(
//          'source' => $redirect_source,
//        ));
//      }
//      $redirect->redirect = $redirect_destination;
//      $redirect->status_code = $context['redirect'];
//
//      redirect_save($redirect);
//    }
//  }
//
//  watchdog('term_merge', 'Successfully merged term %term_branch into term %term_trunk in vocabulary %vocabulary. Context: @context', array(
//    '%term_branch' => $term_branch->name,
//    '%term_trunk' => $term_trunk->name,
//    '%vocabulary' => $vocabulary->name,
//    '@context' => var_export($context, 1),
//  ));
}


/**
 * Process results of Term Merge batch.
 */
function merge_batch_finished($success, $results, $operations) {
  if ($success) {
    drupal_set_message(t('The terms have been successfully merged.'));
  }
  else {
    // An error happened. We have to notify the user.
    drupal_set_message(t('An error occurred. We are sorry, please, report this error to the maintainers of Term Merge module.'), 'error');
  }
}

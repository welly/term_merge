<?php

namespace Drupal\term_merge\Form;

use Drupal\Core\Entity\EntityFieldManager;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\taxonomy\TermStorageInterface;
use Drupal\taxonomy\VocabularyInterface;
use Drupal\term_merge\TermMerge;
use Symfony\Component\DependencyInjection\ContainerInterface;

define('TERM_MERGE_NO_REDIRECT', -1);

/**
 * Class TermMergeForm.
 *
 * @package Drupal\term_merge\Form
 */
class TermMergeForm extends ConfirmFormBase {

  private $vocabulary;

  /**
   * The term storage.
   *
   * @var \Drupal\taxonomy\TermStorageInterface;
   */
  protected $termStorage;

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface;
   */
  protected $entityFieldManager;

  public function __construct(TermStorageInterface $term_storage, ModuleHandlerInterface $module_handler, EntityFieldManagerInterface $entity_field_manager) {
    $this->termStorage = $term_storage;
    $this->moduleHandler = $module_handler;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')->getStorage('taxonomy_term'),
      $container->get('module_handler'),
      $container->get('entity_field.manager')
    );
  }


  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'term_merge.term_merge_form',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'term_merge_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, VocabularyInterface $taxonomy_vocabulary = NULL, TermInterface $term = NULL) {

    if (is_null($taxonomy_vocabulary)) {
      $taxonomy_vocabulary = Vocabulary::load($term->id());
    }

    $form['#vocabulary'] = $taxonomy_vocabulary;
    $this->vocabulary = $taxonomy_vocabulary;

    $confirm = $form_state->getValue('confirm');

    if (!isset($confirm)) {
      $tree = $this->termStorage->loadTree($taxonomy_vocabulary->id());

      $term_branch_value = is_null($term) ? NULL : array($term->id());

      $options = array();
      foreach ($tree as $v) {
        $options[$v->tid] = str_repeat('-', $v->depth) . $v->name . ' [tid: ' . $v->tid . ']';
      }

      $form['term_branch'] = [
        '#multiple' => TRUE,
        '#options' => $options,
        '#required' => TRUE,
        '#size' => 8,
        '#type' => 'select',
      ];

      $form['term_branch'] = array(
        '#title' => t('Terms to Merge'),
        '#description' => t('Please, choose the terms you want to merge into another term.'),
        '#ajax' => array(
          'callback' => '::formTermTrunk',
          'wrapper' => 'term-merge-form-term-trunk',
          'method' => 'replace',
          'effect' => 'fade',
        ),
        '#default_value' => $term_branch_value,
      ) + $form['term_branch'];

      if (is_null($form['term_branch']['#default_value'])) {
        unset($form['term_branch']['#default_value']);
      }

      $form['term_trunk'] = [
        '#type' => 'fieldset',
        '#title' => t('Merge Into'),
        '#prefix' => '<div id="term-merge-form-term-trunk">',
        '#suffix' => '</div>',
        '#tree' => TRUE,
      ];

      // Array of currently available widgets for choosing term trunk.
      $term_trunk_widget_options = [
        'autocomplete' => 'Autocomplete',
        'select' => 'Select',
      ];

      $term_trunk_widget = 'select';

      // If the vocabulary is too big, by default we want the trunk term widget to
      // be autocomplete instead of select or hs_taxonomy.
      if (count($tree) > 200) {
        $term_trunk_widget = 'autocomplete';
      }

      // Override the term trunk widget if settings are found in $form_state.
      $widget = $form_state->getValue(['term_trunk', 'widget']);
      if (isset($widget) && in_array($widget, array_keys($term_trunk_widget_options))) {
        $term_trunk_widget = $widget;
      }

      $form['term_trunk']['widget'] = [
        '#type' => 'radios',
        '#title' => t('Widget'),
        '#required' => TRUE,
        '#options' => $term_trunk_widget_options,
        '#default_value' => $term_trunk_widget,
        '#description' => t('Choose what widget you prefer for entering the term trunk.'),
        '#ajax' => array(
          'callback' => '::formTermTrunk',
          'wrapper' => 'term-merge-form-term-trunk',
          'method' => 'replace',
          'effect' => 'fade',
        ),
      ];

      $function = 'formTermTrunkWidget' . ucfirst($term_trunk_widget);
      $this->$function($form, $form_state, $taxonomy_vocabulary);

      // Ensuring the Merge Into form element has the same title no matter what
      // widget has been used.
      $form['term_trunk']['tid']['#title'] = t('Merge into');

      // Adding necessary options of merging.
      $form += $this->mergeOptionsElements($taxonomy_vocabulary);

      $form['actions'] = array(
        '#type' => 'actions',
      );

      $form['actions']['submit'] = array(
        '#type' => 'submit',
        '#value' => t('Submit'),
      );
      return $form;
    }
    else {
      // So vocabulary id is available to confirmform methods.
      $this->vocabulary = $form['#vocabulary'];
      return parent::buildForm($form, $form_state);
    }


  }

  /**
   * Validate the term_merge_form(). Make sure term trunk is not among the
   * selected term branches or their children.
   */
  function validateForm(array &$form, FormStateInterface $form_state) {

    if (!isset($confirm)) {

      /* @var \Drupal\taxonomy\Entity\Vocabulary */
      $taxonomy_vocabulary = $form['#vocabulary'];
      $confirm = $form_state->getValue('confirm');
      $term_branches = $form_state->getValue(['term_branch']);

      // We only validate the 1st step of the form.
      $prohibited_trunks = [];
      foreach ($term_branches as $term_branch) {
        $children = $this->termStorage->loadTree($taxonomy_vocabulary->id(), $term_branch);
        $prohibited_trunks[] = $term_branch;
        foreach ($children as $child) {
          $prohibited_trunks[] = $child->tid;
        }
      }
      $tid = $form_state->getValue(['term_trunk', 'tid']);
      if (in_array($tid, $prohibited_trunks)) {
        $form_state->setErrorByName('term_trunk][tid', $this->t('Trunk term cannot be one of the selected branch terms or their children.'));
      }
    }
  }

  /**
   * Submit handler for term_merge_form(). Merge terms one into another.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $taxonomy_vocabulary = $form['#vocabulary'];
    $confirm = $form_state->getValue('confirm');
    $term_branches = $form_state->getValue(['term_branch']);

    if (!isset($confirm)) {
      // Since merging terms is an important operation, we better confirm user
      // really wants to do this.
      $form_state->setValue('confirm', FALSE);

      $storage = [
        'info' => $form_state->getValues(),
        'merge_settings' => $this->mergeOptionsSubmit($form, $form_state, $form),
      ];
      $form_state->setStorage($storage);
    }
    else {

      $storage = $form_state->getStorage();
      // The user has confirmed merging. We pull up the submitted values.
      $form_state->setValues($storage['info']);

      TermMerge::merge(array_values(
        $form_state->getValue(['term_branch'])),
        $form_state->getValue(['term_trunk','tid']),
        $storage['merge_settings']
      );
      $form_state->setValue(['redirect'], array('taxonomy/term/' . $form_state->getValue(['term_trunk','tid'])));
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * Generate form elements for select widget for term trunk element of the
   * term_merge_form().
   *
   * @param \Drupal\taxonomy\Entity\Vocabulary $taxonomy_vocabulary
   *   Fully loaded taxonomy vocabulary object
   */
  function formTermTrunkWidgetSelect(array &$form, FormStateInterface $form_state, VocabularyInterface $taxonomy_vocabulary) {
    $tree = $this->termStorage->loadTree($taxonomy_vocabulary->id());
    $options = [];
    foreach ($tree as $v) {
      $options[$v->tid] = str_repeat('-', $v->depth) . $v->name . ' [tid: ' . $v->tid . ']';
    }

    $term_branch_value = [];
    // Firstly trying to look up selected term branches in the default value of
    // term branch form element.
    if (isset($form['term_branch']['#default_value']) && is_array($form['term_branch']['#default_value'])) {
      $term_branch_value = $form['term_branch']['#default_value'];
    }

    if (isset($term_branch_value) && is_array($term_branch_value)) {
      $term_branch_value = $form_state->getValue(['term_branch']);
    }

    if (!empty($term_branch_value)) {
      // We have to make sure among term_trunk there is no term_branch or any of
      // their children.
      foreach ($term_branch_value as $v) {
        unset($options[$v]);
        foreach ($this->termStorage->loadTree($taxonomy_vocabulary->id(), $v) as $child) {
          unset($options[$child->id()]);
        }
      }
    }
    else {
      // Term branch has not been selected yet.
      $options = [];
    }

    $form['term_trunk']['tid'] = [
      '#type' => 'select',
      '#required' => TRUE,
      '#description' => t('Choose into what term you want to merge.'),
      '#options' => $options,
    ];
  }

  /**
   * Supportive function.
   *
   * Generate form elements for autocomplete widget for term trunk element of the
   * term_merge_form().
   *
   * @param \Drupal\taxonomy\Entity\Vocabulary $taxonomy_vocabulary
   *   Fully loaded taxonomy vocabulary object
   */
  function formTermTrunkWidgetAutocomplete(array &$form, FormStateInterface $form_state, VocabularyInterface $taxonomy_vocabulary) {

    $form['term_trunk']['tid'] = [
      '#type' => 'entity_autocomplete',
      '#target_type' => 'taxonomy_term',
      '#description' => t("Start typing in a term's name in order to get some suggestions."),
      '#required' => TRUE,
      '#selection_settings' => [
        'target_bundles' => array($taxonomy_vocabulary->id()),
      ],
    ];
  }

  /**
   * Supportive function.
   *
   * Validate form element of the autocomplete widget of term trunk element of
   * the form term_merge_form(). Make sure the entered string is a name of one of
   * the existing terms in the vocabulary where the merge occurs. If term is found
   * the function substitutes the name with its {taxonomy_term_data}.tid as it is
   * what is expected from a term trunk widget to provide in its value.
   */
  function formTermTrunkWidgetAutocompleteValidation(&$element, FormStateInterface $form_state, &$form) {
//    $term = taxonomy_get_term_by_name($element['#value'], $form['#vocabulary']->machine_name);
//    if (!is_array($term) || empty($term)) {
//      // Seems like the user has entered a non existing name in the autocomplete
//      // textfield.
//      form_error($element, t('There are no terms with name %name in the %vocabulary vocabulary.', array(
//        '%name' => $element['#value'],
//        '%vocabulary' => $form['#vocabulary']->name,
//      )));
//    }
//    else {
//      // We have to substitute the term's name with its tid in order to make this
//      // widget consistent with the interface.
//      $term = array_pop($term);
//      form_set_value($element, $term->tid, $form_state);
//    }
  }
  /**
   * Ajax callback function.
   *
   * Used in term_merge_term_merge_form() to replace the term_trunk element
   * depending on already selected term_branch values.
   */
  public function formTermTrunk(array &$form, FormStateInterface $form_state) {
    return $form['term_trunk'];
  }

  /**
   * Return merge settings array.
   *
   * Output of this function should be used for supplying into term_merge()
   * function or for triggering actions_do('term_merge_action', ...) action. This
   * function should be invoked in a form submit handler for a form that used
   * mergeOptionsElements() for generating merge settings elements.
   * It will process data and return an array of merge settings, according to the
   * data user has submitted in your form.
   *
   * @param array $merge_settings_element
   *   That part of form that was generated by mergeOptionsElements()
   * @param array $form_state
   *   Form state array of the submitted form
   * @param array $form
   *   Form array of the submitted form
   *
   * @return array
   *   Array of merge settings that can be used for calling term_merge() or
   *   invoking 'term_merge_action' action
   *
   * @see mergeOptionsElements()
   */
  function mergeOptionsSubmit($merge_settings_element, FormStateInterface $form_state, $form) {
    $merge_settings = array(
      'term_branch_keep' => (bool) $merge_settings_element['term_branch_keep']['#value'],
      'merge_fields' => isset($merge_settings_element['merge_fields']['#value']) ? array_values(array_filter($merge_settings_element['merge_fields']['#value'])) : array(),
      'keep_only_unique' => (bool) $merge_settings_element['keep_only_unique']['#value'],
      'redirect' => isset($merge_settings_element['redirect']['#value']) ? $merge_settings_element['redirect']['#value'] : TERM_MERGE_NO_REDIRECT,
      'synonyms' => isset($merge_settings_element['synonyms']['#value']) ? array_values(array_filter($merge_settings_element['synonyms']['#value'])) : array(),
      'step' => (int) $merge_settings_element['step']['#value'],
    );
    return $merge_settings;
  }

    /**
   * Generate and return form elements that control behavior of merge action.
   *
   * Output of this function should be used in any form that merges terms,
   * ensuring unified interface. It should be used in conjunction with
   * mergeOptionsSubmit(), which will process the submitted values
   * for you and return an array of merge settings.
   *
   * @param object $taxonomy_vocabulary
   *   Fully loaded taxonomy vocabulary object in which merging occurs
   *
   * @return array
   *   Array of form elements that allow controlling term merge action
   *
   * @see mergeOptionsSubmit()
   */
  function mergeOptionsElements($taxonomy_vocabulary) {
    // @todo: it would be nice to provide some ability to supply default values
    // for each setting.
    $form = array();

    // Getting bundle name and a list of fields attached to this bundle for
    // further use down below in the code while generating form elements.

    $bundle = $taxonomy_vocabulary->bundle();
    $instances = $this->entityFieldManager->getFieldDefinitions('taxonomy_term', $taxonomy_vocabulary->id());

    $form['term_branch_keep'] = array(
      '#type' => 'checkbox',
      '#title' => t('Only merge occurrences'),
      '#description' => t('Check this if you want to only merge the occurrences of the specified terms, i.e. the terms will not be deleted from your vocabulary.'),
    );

    if (!empty($instances)) {
      $options = array();
      foreach ($instances as $instance) {
        if ($instance->getFieldStorageDefinition()->isBaseField() == FALSE) {
          $options[$instance->getName()] = $instance->getLabel();
        }
      }

      if (!empty($options)) {
        $form['merge_fields'] = array(
          '#type' => 'checkboxes',
          '#title' => t('Merge Term Fields'),
          '#description' => t('Check the fields whose values from branch terms you want to add to the values of corresponding fields of the trunk term. <b>Important note:</b> the values will be added until the cardinality limit for the selected fields is reached and only unique values for each field will be saved.'),
          '#options' => $options,
        );
      }
    }

    $form['keep_only_unique'] = array(
      '#type' => 'checkbox',
      '#title' => t('Keep only unique terms after merging'),
      '#description' => t('Sometimes after merging you may end up having a node (or any other entity) pointing twice to the same taxonomy term, tick this checkbox if want to keep only unique terms in other entities after merging.'),
      '#default_value' => TRUE,
    );

    $form['step'] = array(
      '#type' => 'textfield',
      '#title' => t('Step'),
      '#description' => t('Please, specify how many terms to process per script run in batch. If you are hitting time or memory limits in your PHP, decrease this number.'),
      '#default_value' => 40,
      '#required' => TRUE,
//      '#element_validate' => array('element_validate_integer_positive'),
    );

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return t('Merge terms');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('term_merge.term_merge_form', array('taxonomy_vocabulary' => $this->vocabulary->id()));
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Merge');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelText() {
    return $this->t('Cancel');
  }

}

<?php

namespace Drupal\super_eref\Plugin\Field\FieldFormatter;

use Drupal\entity_reference_revisions\Plugin\Field\FieldFormatter\EntityReferenceRevisionsEntityFormatter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
/**
 * Extension of plugin implementation of the 'entity reference revisions
 * rendered entity' formatter.
 * This extension allows view mode to be chosen per bundle.
 *
 * @FieldFormatter(
 *   id = "super_erref",
 *   label = @Translation("Rendered entity (flexible)"),
 *   description = @Translation("Display the referenced entities rendered by entity_view() with flexible view modes."),
 *   field_types = {
 *     "entity_reference_revisions"
 *   }
 * )
 */
class SuperEntityReferenceRevisionsEntityFormatter extends EntityReferenceRevisionsEntityFormatter {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'view_mode' => array(),
      'default_view_mode' => 'default',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo($this->getFieldSetting('target_type'));
    $allowed_bundles = isset($this->getFieldSettings()['handler_settings']['target_bundles']) ? $this->getFieldSettings()['handler_settings']['target_bundles'] : array();
    $options = array("" => "- Select -");
    $view_modes = $this->entityDisplayRepository->getViewModeOptions($this->getFieldSetting('target_type'));
    $options = array_merge($options, $view_modes);
    $elements['default_view_mode'] = [
      '#title' => t('Default view mode'),
      '#type' => 'select',
      '#options' => $view_modes,
      '#default_value' => $this->getSetting('default_view_mode'),
      '#description' => t('If a view mode is not selected for a given content type, this view mode will be used.'),
    ];
    $elements['view_mode'] = [
      '#title' => t('View mode'),
      '#tree' => TRUE,
      '#type' => 'container',
    ];
    foreach($bundles as $key=>$val){
      $label = $val['label'];
      if(empty($allowed_bundles) || in_array($key, $allowed_bundles)){
        $elements['view_mode'][$key] = [
          '#title' => t($label),
          '#type' => 'select',
          '#options' => $options,
          '#default_value' => $this->getSetting('view_mode')[$key],
        ];
      }
    }
    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];
    $summary[] = t('Rendering with bundle-specific view modes');
    return $summary;
  }

  /**
   * NOTE: I really wanted to override getSetting for the special case of 'view_mode'
   * instead of making changes inside of viewElements. Unfortunately, the return
   * depends on the $entity value each time through the foreach loop in viewElements.
   * As it stands, this viewElements function has two blocks of code that
   * are different from the parent.
   */

  /**
  * {@inheritdoc}
  */
  public function viewElements(FieldItemListInterface $items, $langcode) {
   /* BEGIN DIFFERENCE FROM PARENT */
   // We give this variable a plural name.
   $view_modes = $this->getSetting('view_mode');
   /* END DIFFERENCE FROM PARENT */
   $elements = array();

   foreach ($this->getEntitiesToView($items, $langcode) as $delta => $entity) {
     // Protect ourselves from recursive rendering.
     static $depth = 0;
     $depth++;
     if ($depth > 20) {
       $this->loggerFactory->get('entity')->error('Recursive rendering detected when rendering entity @entity_type @entity_id. Aborting rendering.', array('@entity_type' => $entity->getEntityTypeId(), '@entity_id' => $entity->id()));
       return $elements;
     }
     $view_builder = \Drupal::entityTypeManager()->getViewBuilder($entity->getEntityTypeId());

     /* BEGIN DIFFERENCE FROM PARENT */
     //What view mode should be used?
     if(isset($view_modes[$entity->bundle()]) && $view_modes[$entity->bundle()]){
       $view_mode = $view_modes[$entity->bundle()];
     }else{
       $view_mode = $this->getSetting('default_view_mode');
     }
     /* END DIFFERENCE FROM PARENT */

     $elements[$delta] = $view_builder->view($entity, $view_mode, $entity->language()->getId());

     // Add a resource attribute to set the mapping property's value to the
     // entity's url. Since we don't know what the markup of the entity will
     // be, we shouldn't rely on it for structured data such as RDFa.
     if (!empty($items[$delta]->_attributes) && !$entity->isNew() && $entity->hasLinkTemplate('canonical')) {
       $items[$delta]->_attributes += array('resource' => $entity->toUrl()->toString());
     }
     $depth = 0;
   }

   return $elements;
  }
}

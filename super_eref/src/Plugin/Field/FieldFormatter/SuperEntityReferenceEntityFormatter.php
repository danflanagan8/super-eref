<?php

namespace Drupal\super_eref\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceEntityFormatter;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
/**
 * Extension of plugin implementation of the 'entity reference rendered entity' formatter.
 * This extension allows view mode to be chosen per content type.
 *
 * @FieldFormatter(
 *   id = "super_eref",
 *   label = @Translation("Rendered entity (flexible)"),
 *   description = @Translation("Display the referenced entities rendered by entity_view() with flexible view modes."),
 *   field_types = {
 *     "entity_reference"
 *   }
 * )
 */
class SuperEntityReferenceEntityFormatter extends EntityReferenceEntityFormatter {

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
    $content_types = \Drupal::service('entity.manager')->getStorage('node_type')->loadMultiple();
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
    foreach($content_types as $content_type){
      $elements['view_mode'][$content_type->id()] = [
        '#title' => t($content_type->label()),
        '#type' => 'select',
        '#options' => $options,
        '#default_value' => $this->getSetting('view_mode')[$content_type->id()],
      ];
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
    $elements = [];

    foreach ($this->getEntitiesToView($items, $langcode) as $delta => $entity) {
      // Due to render caching and delayed calls, the viewElements() method
      // will be called later in the rendering process through a '#pre_render'
      // callback, so we need to generate a counter that takes into account
      // all the relevant information about this field and the referenced
      // entity that is being rendered.
      $recursive_render_id = $items->getFieldDefinition()->getTargetEntityTypeId()
        . $items->getFieldDefinition()->getTargetBundle()
        . $items->getName()
        // We include the referencing entity, so we can render default images
        // without hitting recursive protections.
        . $items->getEntity()->id()
        . $entity->getEntityTypeId()
        . $entity->id();

      if (isset(static::$recursiveRenderDepth[$recursive_render_id])) {
        static::$recursiveRenderDepth[$recursive_render_id]++;
      }
      else {
        static::$recursiveRenderDepth[$recursive_render_id] = 1;
      }

      // Protect ourselves from recursive rendering.
      if (static::$recursiveRenderDepth[$recursive_render_id] > static::RECURSIVE_RENDER_LIMIT) {
        $this->loggerFactory->get('entity')->error('Recursive rendering detected when rendering entity %entity_type: %entity_id, using the %field_name field on the %bundle_name bundle. Aborting rendering.', [
          '%entity_type' => $entity->getEntityTypeId(),
          '%entity_id' => $entity->id(),
          '%field_name' => $items->getName(),
          '%bundle_name' => $items->getFieldDefinition()->getTargetBundle(),
        ]);
        return $elements;
      }

      /* BEGIN DIFFERENCE FROM PARENT */
      //What view mode should be used?
      if(isset($view_modes[$entity->getType()]) && $view_modes[$entity->getType()]){
        $view_mode = $view_modes[$entity->getType()];
      }else{
        $view_mode = $this->getSetting('default_view_mode');
      }
      /* END DIFFERENCE FROM PARENT */

      $view_builder = $this->entityTypeManager->getViewBuilder($entity->getEntityTypeId());
      $elements[$delta] = $view_builder->view($entity, $view_mode, $entity->language()->getId());

      // Add a resource attribute to set the mapping property's value to the
      // entity's url. Since we don't know what the markup of the entity will
      // be, we shouldn't rely on it for structured data such as RDFa.
      if (!empty($items[$delta]->_attributes) && !$entity->isNew() && $entity->hasLinkTemplate('canonical')) {
        $items[$delta]->_attributes += ['resource' => $entity->toUrl()->toString()];
      }
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public static function isApplicable(FieldDefinitionInterface $field_definition) {
    // This formatter is only available for nodes.
    return $field_definition->getFieldStorageDefinition()->getSetting('target_type') == 'node';
  }

}

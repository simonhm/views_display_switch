<?php

namespace Drupal\views_display_switch\Plugin\views\area;

use Drupal\Component\Utility\Html;
use Drupal\Core\EventSubscriber\AjaxResponseSubscriber;
use Drupal\Core\EventSubscriber\MainContentViewSubscriber;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\views\Plugin\views\area\AreaPluginBase;
use Drupal\views\Plugin\views\display\Block;
use Drupal\views\Plugin\views\display\PathPluginBase;

/**
 * Views area display_switch handler.
 *
 * @ingroup views_area_handlers
 *
 * @ViewsArea("display_switch")
 */
class DisplaySwitch extends AreaPluginBase {

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['displays'] = ['default' => []];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $allowed_displays = [];
    $displays = $this->view->storage->get('display');

    // Sort the displays.
    uasort($displays, function ($display1, $display2) {
      if ($display1['position'] != $display2['position']) {
          return $display1['position'] < $display2['position'] ? -1 : 1;
      }
      return 0;
    });

    foreach ($displays as $display_id => $display) {
      if (!$this->isAllowedDisplay($display_id)) {
        unset($displays[$display_id]);
        continue;
      }
      $allowed_displays[$display_id] = $display['display_title'];
    }

    $form['description'] = [
      [
        '#markup' => $this->t('To make sure the results are the same when switching to the other display, it is recommended to make sure the display:'),
      ],
      [
        '#theme' => 'item_list',
        '#items' => [
          $this->t('Has a path.'),
          $this->t('Has the same filter criteria.'),
          $this->t('Has the same sort criteria.'),
          $this->t('Has the same pager settings.'),
          $this->t('Has the same contextual filters.'),
        ],
      ],
    ];

    if (!$allowed_displays) {
      $form['empty_message'] = [
        '#markup' => '<p><em>' . $this->t('There are no path-based or block displays available.') . '</em></p>',
      ];
    }
    else {
      $form['displays'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Displays'),
        '#description' => $this->t('Select the displays you want the displays switch to display.'),
      ];
      foreach ($allowed_displays as $key => $allowed_display) {

        $form['displays'][$key] = [
          '#title' => $allowed_display,
          '#type' => 'fieldset',
        ];
        $form['displays'][$key]['enabled'] = [
          '#title' => 'Enable',
          '#type' => 'checkbox',
          '#default_value' => $this->options['displays'][$key]['enabled'],
        ];
        $form['displays'][$key]['label'] = [
          '#title' => $this->t('Label'),
          '#description' => $this->t('The text of the link.'),
          '#type' => 'textfield',
          '#default_value' => $this->options['displays'][$key]['label'],
          '#states' => [
            'visible' => [
              ':input[name="options[displays][' . $key . '][enabled]"]' => ['checked' => TRUE],
            ],
            'required' => [
              ':input[name="options[displays][' . $key . '][enabled]"]' => ['checked' => TRUE],
            ],
          ],
        ];
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validate() {
    $errors = parent::validate();

    // Do not add errors for the default display if it is not displayed in the
    // UI.
    if ($this->displayHandler->isDefaultDisplay() && !\Drupal::config('views.settings')
      ->get('ui.show.master_display')) {
      return $errors;
    }

    foreach ($this->options['displays'] as $display_id => $values) {
      if ($values['enabled'] === 1) {
        $errors += $this->validateDisplay($display_id);
      }
    }
    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function validateDisplay($display_id) {
    $errors = [];

    // Check if the linked display hasn't been removed.
    $display_handler = $this->view->displayHandlers->get($display_id);
    if (!$display_handler) {
      $errors[] = $this->t('%current_display: The link in the %area area points to the %linked_display display which no longer exists.', [
        '%current_display' => $this->displayHandler->display['display_title'],
        '%area' => $this->areaType,
        '%linked_display' => $this->options['display_id'],
      ]);
      return $errors;
    }

    // Check if the linked display is a path-based display.
    if (!$this->isAllowedDisplay($display_id)) {
      $errors[] = $this->t('%current_display: The link in the %area area points to the %linked_display display which does not have a path.', [
        '%current_display' => $this->displayHandler->display['display_title'],
        '%area' => $this->areaType,
        '%linked_display' => $display_handler->display['display_title'],
      ]);
      return $errors;
    }

    // Check if options of the linked display are equal to the options of the
    // current display. We "only" show a warning here, because even though we
    // recommend keeping the display options equal, we do not want to enforce
    // this.
    $unequal_options = [
      'filters' => t('Filter criteria'),
      'sorts' => t('Sort criteria'),
      'pager' => t('Pager'),
      'arguments' => t('Contextual filters'),
    ];
    foreach (array_keys($unequal_options) as $option) {
      if ($this->hasEqualOptions($display_id, $option)) {
        unset($unequal_options[$option]);
      }
    }

    if ($unequal_options) {
      $warning = $this->t('%current_display: The link in the %area area points to the %linked_display display which uses different settings than the %current_display display for: %unequal_options. To make sure users see the exact same result when clicking the link, please check that the settings are the same.', [
        '%current_display' => $this->displayHandler->display['display_title'],
        '%area' => $this->areaType,
        '%linked_display' => $display_handler->display['display_title'],
        '%unequal_options' => implode(', ', $unequal_options),
      ]);
      $this->messenger()->addWarning($warning);
    }
    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function render($empty = FALSE) {
    if (($empty && empty($this->options['empty'])) || empty($this->options['displays'])) {
      return [];
    }

    // Get query parameters from the exposed input and pager.
    $query = $this->view->getExposedInput();
    if ($current_page = $this->view->getCurrentPage()) {
      $query['page'] = $current_page;
    }

    // @todo Remove this parsing once these are removed from the request in
    //   https://www.drupal.org/node/2504709.
    foreach ([
      'mode',
      'view_name',
      'view_display_id',
      'view_args',
      'view_path',
      'view_dom_id',
      'pager_element',
      'view_base_path',
      AjaxResponseSubscriber::AJAX_REQUEST_PARAMETER,
      FormBuilderInterface::AJAX_FORM_REQUEST,
      MainContentViewSubscriber::WRAPPER_FORMAT,
    ] as $key) {
      unset($query[$key]);
    }

    $links = [];

    foreach ($this->options['displays'] as $display_id => $values) {
      if ($values['enabled'] === 1 && $this->isAllowedDisplay($display_id)) {
        $links[$display_id] = $this->getDisplayLink($display_id, $this->t($values['label'], [], ['context' => 'Views display switch']), $query);
      }
    }

    return [
      '#theme' => 'views_display_switch',
      '#links' => $links,
    ];
  }

  /**
   * Generate link to a display.
   *
   * @param string $display_id
   *   The display ID to generate the link for.
   *
   * @param string $label
   *   The label to be used in for the link.
   *
   * @param string $query
   *   The query to be appended to the url to maintain pager and filters.
   *
   * @return array
   *   The render array for the link to the display
   */
  protected function getDisplayLink($display_id, $label, $query) {
    $classes = [
      'views-display-switch__link',
      'views-display-switch__link--' . Html::getClass($display_id),
    ];
    if ($display_id === $this->view->current_display) {
      $classes[] = 'views-display-switch__link--active';
    }

    // Generate Url for page displays.
    if ($this->isPathBasedDisplay($display_id)) {
      $url = $this->view->getUrl($this->view->args, $display_id)
        ->setOptions(['query' => $query]);
    }
    // Generate Url for block displays.
    else {
      if ($this->isBlockDisplay($display_id)) {
        // @TDOD: This is kind of hacky, since Url::fromRoute('<current>') doesn't work.
        $current_path = \Drupal::service('path.current')->getPath();
        $url = Url::fromUserInput($current_path)->setOption('query', [
          'mode' => $display_id,
        ] + $query);
      }
      else {
        return [];
      }
    }

    return [
      '#type' => 'link',
      '#title' => $label,
      '#url' => $url,
      '#options' => [
        'view' => $this->view,
        'target_display_id' => $display_id,
        'attributes' => ['class' => $classes],
      ],
    ];
  }

  /**
   * Check if a views display is a path-based display.
   *
   * @param string $display_id
   *   The display ID to check.
   *
   * @return bool
   *   Whether the display ID is a path based display or not.
   */
  protected function isPathBasedDisplay($display_id) {
    $loaded_display = $this->view->displayHandlers->get($display_id);
    return $loaded_display instanceof PathPluginBase;
  }

  /**
   * Check if a views display is a block display.
   *
   * @param string $display_id
   *   The display ID to check.
   *
   * @return bool
   *   Whether the display ID is a block or not.
   */
  protected function isBlockDisplay($display_id) {
    $loaded_display = $this->view->displayHandlers->get($display_id);
    return $loaded_display instanceof Block;
  }

  /**
   * Check if a views display is a allowed display.
   *
   * @param string $display_id
   *   The display ID to check.
   *
   * @return bool
   *   Whether the display ID is an allowed display or not.
   */
  protected function isAllowedDisplay($display_id) {
    return $this->isPathBasedDisplay($display_id) || $this->isBlockDisplay($display_id);
  }

  /**
   * Check if the options of a views display are equal to the current display.
   *
   * @param string $display_id
   *   The display ID to check.
   * @param string $option
   *   The option to check.
   *
   * @return bool
   *   Whether the option of the view display are equal to the current display
   *   or not.
   */
  protected function hasEqualOptions($display_id, $option) {
    $loaded_display = $this->view->displayHandlers->get($display_id);
    return $loaded_display->getOption($option) === $this->displayHandler->getOption($option);
  }

}

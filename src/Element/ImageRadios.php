<?php

namespace Drupal\commerce_montonio\Element;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element;
use Drupal\Core\Render\Element\Radios;

/**
 * Extends the radios form element with image support.
 */
#[RenderElement('image_radios')]
class ImageRadios extends Radios {

  /**
   * {@inheritdoc}
   */
  public function getInfo() {
    $info = parent::getInfo();
    $info += [
      '#width' => 40,
      '#height' => 60,
      '#stroke_width' => 1,
      '#padding' => 0,
    ];
    $info['#process'][] = [get_class($this), 'processImageRadios'];
    return $info;
  }

  /**
   * Process callback for the image radios element.
   */
  public static function processImageRadios(
    &$element,
    FormStateInterface $form_state,
    &$complete_form,
  ) {
    foreach (Element::children($element) as $key) {
      if (isset($element[$key]['#title']['image'])) {
        $label = $element[$key]['#title']['label'];
        $image = [
          '#theme' => 'image',
          '#uri' => $element[$key]['#title']['image'],
          '#alt' => $label,
          '#title' => $label,
        ];
        $element[$key]['#title'] = \Drupal::service('renderer')
          ->renderInIsolation($image);
      }

      $element[$key]['#wrapper_attributes']['class'][] = 'image-radios__item';
      $element[$key]['#attributes']['class'][] = 'visually-hidden';
    }

    $element['#attributes']['class'][] = 'image-radios-wrapper';
    $element['#attached']['library'][] = 'commerce_montonio/image_radios';

    $element['#wrapper_attributes'] = ['class' => ['image-radios']];
    return $element;
  }

}

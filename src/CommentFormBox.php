<?php

namespace Drupal\lcars_ng;

use Drupal\Core\Render\Element;
use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Puts a comment form on a page of its own into a box.
 *
 * Below a post the form already stands in a box with its heading
 * (field--comment.html.twig). On the pages Edit (/comment/{id}/edit) and
 * Reply (/comment/reply/…) the form is the main content: there its fields and
 * buttons go into a box of the same kind (.comment-form__box, comment.css),
 * without a heading, as the title bar names the page (user wish 25.11.2026).
 * The preview of the comment above and the post below the form, which core
 * puts into the form after Preview, stay outside the box. The callback runs
 * before rendering, when the form is built, so the values of the form keep
 * their places.
 *
 * @see \Drupal\lcars_ng\Hook\LcarsNgHooks::formCommentFormAlter()
 */
final class CommentFormBox implements TrustedCallbackInterface {

  /**
   * Children of the form that stay outside the box (CommentForm::preview()).
   */
  private const OUTSIDE = ['comment_preview', 'comment_output_below'];

  /**
   * Moves the fields and buttons of the form into a box.
   *
   * @param array $form
   *   The built comment form.
   *
   * @return array
   *   The form with its fields and buttons in the child 'lcars_box'.
   */
  public static function preRender(array $form): array {
    $box = [
      '#type' => 'container',
      '#attributes' => ['class' => ['comment-form__box']],
      '#weight' => 0,
    ];
    foreach (Element::children($form) as $key) {
      if (!in_array($key, self::OUTSIDE, TRUE)) {
        $box[$key] = $form[$key];
        unset($form[$key]);
      }
    }
    $form['lcars_box'] = $box;
    $form['#sorted'] = FALSE;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['preRender'];
  }

}

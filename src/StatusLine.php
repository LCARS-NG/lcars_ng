<?php

namespace Drupal\lcars_ng;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lazy builder for the status line of the two-part screen.
 *
 * The status line sits in the curve below the upper panel: the date and time,
 * the signed-in user right beside them. LCNG tried a date and a clock
 * in its h2panel_status region. The line is built for every request
 * (placeholder, max-age 0), so page caches do not freeze the time.
 *
 * Themes cannot define services; the callable resolver instantiates this
 * class through create() for the 'StatusLine::build' callback.
 *
 * @see \Drupal\lcars_ng\Hook\LcarsNgHooks::preprocessPage()
 */
final class StatusLine implements ContainerInjectionInterface, TrustedCallbackInterface {

  public function __construct(
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly AccountInterface $currentUser,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('datetime.time'),
      $container->get('date.formatter'),
      $container->get('entity_type.manager'),
      $container->get('current_user'),
    );
  }

  /**
   * Builds the status line.
   */
  public function build(): array {
    $time = $this->time->getRequestTime();
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['lcars-status']],
      '#cache' => ['max-age' => 0],
      'date' => [
        '#type' => 'html_tag',
        '#tag' => 'time',
        '#value' => $this->dateFormatter->format($time, 'medium'),
        '#attributes' => [
          'class' => ['lcars-status__date'],
          'datetime' => $this->dateFormatter->format($time, 'custom', 'c'),
        ],
      ],
    ];
    if ($user) {
      $build['user'] = $user->toLink()->toRenderable();
      $build['user']['#attributes']['class'][] = 'lcars-status__user';
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['build'];
  }

}

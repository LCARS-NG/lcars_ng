<?php

namespace Drupal\lcars_ng\Hook;

use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lcars_ng\StatusLine;
use Drupal\user\Form\UserLoginForm;

/**
 * Hook implementations for lcars_ng.
 */
class LcarsNgHooks {

  /**
   * Routes rendered in the login layout (page--login.html.twig).
   */
  private const LOGIN_ROUTES = [
    'user.login',
    'user.pass',
    'user.register',
    'user.reset.form',
  ];

  /**
   * Routes rendered in the two-part screen (page--panel.html.twig).
   */
  private const PANEL_ROUTES = [
    'entity.user.canonical',
  ];

  /**
   * Routes rendered in red alert (alert.css).
   */
  private const ALERT_ROUTES = [
    'system.401',
    'system.403',
    'system.404',
  ];

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly PathMatcherInterface $pathMatcher,
    private readonly AccountInterface $currentUser,
    private readonly FormBuilderInterface $formBuilder,
    private readonly ThemeSettingsProvider $themeSettings,
  ) {}

  /**
   * Implements hook_theme_suggestions_page_alter().
   *
   * As in the Drupal 6 theme LCNG, the account pages use the login panel and
   * the front page uses the two-part screen for signed-in users (user profiles
   * share that one). With the theme setting front_page_login, the front page
   * is a login panel for anonymous visitors as well.
   */
  #[Hook('theme_suggestions_page_alter')]
  public function themeSuggestionsPageAlter(array &$suggestions): void {
    if ($this->isLoginPage()) {
      $suggestions[] = 'page__login';
    }
    elseif ($this->isPanelPage()) {
      $suggestions[] = 'page__panel';
    }
  }

  /**
   * Implements hook_preprocess_html().
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables): void {
    if ($this->isLoginPage()) {
      $variables['attributes']['class'][] = 'lcars-login-page';
    }
    if (in_array($this->routeMatch->getRouteName(), self::ALERT_ROUTES, TRUE)) {
      $variables['attributes']['class'][] = 'lcars-alert';
    }
  }

  /**
   * Implements hook_preprocess_page().
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    if ($this->isLoginFrontPage()) {
      $variables['login_form'] = $this->formBuilder->getForm(UserLoginForm::class);
    }
    elseif ($this->isPanelPage()) {
      $variables['status_line'] = [
        '#lazy_builder' => [StatusLine::class . '::build', []],
        '#create_placeholder' => TRUE,
      ];
    }
  }

  /**
   * Implements hook_preprocess_image_widget().
   */
  #[Hook('preprocess_image_widget')]
  public function preprocessImageWidget(array &$variables): void {
    $data = &$variables['data'];
    // This prevents image widget templates from rendering preview container
    // HTML to users that do not have permission to access these previews.
    // @todo revisit in https://drupal.org/node/953034
    // @todo revisit in https://drupal.org/node/3114318
    if (isset($data['preview']['#access']) && $data['preview']['#access'] === FALSE) {
      unset($data['preview']);
    }
  }

  /**
   * Whether the current page uses the login layout.
   */
  private function isLoginPage(): bool {
    return $this->isLoginFrontPage()
      || in_array($this->routeMatch->getRouteName(), self::LOGIN_ROUTES, TRUE);
  }

  /**
   * Whether the current page uses the two-part screen.
   */
  private function isPanelPage(): bool {
    return ($this->currentUser->isAuthenticated() && $this->pathMatcher->isFrontPage())
      || in_array($this->routeMatch->getRouteName(), self::PANEL_ROUTES, TRUE);
  }

  /**
   * Whether the front page shows the login panel to an anonymous visitor.
   */
  private function isLoginFrontPage(): bool {
    return $this->currentUser->isAnonymous()
      && $this->pathMatcher->isFrontPage()
      && $this->themeSettings->getSetting('front_page_login');
  }

}

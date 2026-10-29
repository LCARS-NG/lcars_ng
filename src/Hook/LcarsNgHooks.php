<?php

namespace Drupal\lcars_ng\Hook;

use Drupal\block\BlockInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lcars_ng\StatusLine;
use Drupal\user\Form\UserLoginForm;

/**
 * Hook implementations for lcars_ng.
 */
class LcarsNgHooks {

  use StringTranslationTrait;

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
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Implements hook_theme_suggestions_block_alter().
   *
   * Adds the region of a placed block as its least specific suggestion
   * (block__REGION): blocks in the right sidebar become LCARS panels
   * (block--sidebar-second.html.twig), as block-right.tpl.php in the Drupal 6
   * theme LCNG. Templates for a plugin or a single block still win; for a
   * plugin in a region, block__REGION__PLUGIN comes after them
   * (block--sidebar-second--system-menu-block.html.twig: menus as panels).
   */
  #[Hook('theme_suggestions_block_alter')]
  public function themeSuggestionsBlockAlter(array &$suggestions, array $variables): void {
    $id = $variables['elements']['#id'] ?? NULL;
    $block = $id ? $this->entityTypeManager->getStorage('block')->load($id) : NULL;
    if ($block instanceof BlockInterface) {
      $region = 'block__' . $block->getRegion();
      array_unshift($suggestions, $region);
      $suggestions[] = $region . '__' . $block->getPlugin()->getBaseId();
    }
  }

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
    // Colors of the Navigation module (css/lcars/navigation-scheme.css): dark
    // unless a site chose light; sites from before the setting have none.
    $scheme = $this->themeSettings->getSetting('navigation_scheme') ?? 'dark';
    if ($scheme !== 'light') {
      $variables['html_attributes']->setAttribute('data-lcars-navigation', $scheme);
    }
  }

  /**
   * Implements hook_preprocess_page().
   *
   * The login panel on the front page links to the password reset form only
   * where that form is available; a site may deny its route. The status line
   * of the two-part screen is for signed-in users: anonymous visitors see
   * profiles there only where a site grants them, and the page cache of
   * anonymous visitors would keep its time (user decision 29.10.2026, #26).
   *
   * The type of the page (login panel, two-part screen, plain page) depends on
   * whether the visitor is signed in and on the front page. The page caches
   * vary by permissions only, which anonymous and signed-in users may share:
   * the contexts set here reach the response and the page template
   * suggestions alike (themeSuggestionsPageAlter(), preprocessHtml()).
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    $cacheability = (new CacheableMetadata())
      ->addCacheContexts(['user.roles:authenticated', 'url.path.is_front']);
    if ($this->isLoginFrontPage()) {
      $variables['login_form'] = $this->formBuilder->getForm(UserLoginForm::class);
      $reset = Url::fromRoute('user.pass');
      $access = $reset->access(NULL, TRUE);
      $cacheability->addCacheableDependency($access);
      if ($access->isAllowed()) {
        $variables['reset_password_url'] = $reset->toString();
      }
    }
    elseif ($this->isPanelPage() && $this->currentUser->isAuthenticated()) {
      $variables['status_line'] = [
        '#lazy_builder' => [StatusLine::class . '::build', []],
        '#create_placeholder' => TRUE,
      ];
    }
    // Merged, as applyTo() replaces what other preprocess functions set.
    CacheableMetadata::createFromRenderArray($variables)
      ->merge($cacheability)
      ->applyTo($variables);
  }

  /**
   * Implements hook_preprocess_page_title().
   *
   * The log in page shows no title bar, as the login panel on the front page:
   * the button below the form says the same (user decision 28.10.2026). The
   * heading stays for screen readers (page-title.html.twig).
   */
  #[Hook('preprocess_page_title')]
  public function preprocessPageTitle(array &$variables): void {
    $variables['title_hidden'] = $this->routeMatch->getRouteName() === 'user.login';
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
      return;
    }
    // An SVG drawn in currentColor (the default user picture of Drupal CMS)
    // takes the color of its text where it is rendered inline, as on the
    // profile; as the <img> of the preview it falls back to black. Drawn as a
    // mask over the color instead (forms.css).
    $uri = $data['preview']['#uri'] ?? NULL;
    if (!is_string($uri) || !str_ends_with(strtolower($uri), '.svg') || !is_file($uri) || filesize($uri) > 65536
      || !str_contains((string) file_get_contents($uri), 'currentColor')) {
      return;
    }
    $data['preview'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#weight' => $data['preview']['#weight'] ?? -10,
      '#attributes' => [
        'class' => ['image-preview__svg'],
        'aria-hidden' => 'true',
        'style' => sprintf(
          '--lcars-preview-mask: url("%s"); inline-size: %dpx; block-size: %dpx;',
          $this->fileUrlGenerator->generateString($uri),
          (int) ($data['preview']['#width'] ?? 64),
          (int) ($data['preview']['#height'] ?? 64),
        ),
      ],
    ];
  }

  /**
   * Implements hook_form_FORM_ID_alter() for system_theme_settings.
   *
   * Not in theme-settings.php: core registers a function there as a hook of
   * this theme, but loads the file only on this theme's settings page. Where
   * LCARS-NG is also the administration theme, the settings pages of all other
   * themes would call the missing function.
   */
  #[Hook('form_system_theme_settings_alter')]
  public function formSystemThemeSettingsAlter(array &$form): void {
    if (($form['config_key']['#value'] ?? NULL) !== 'lcars_ng.settings') {
      return;
    }
    $form['lcars_ng'] = [
      '#type' => 'details',
      '#title' => $this->t('LCARS-NG'),
      '#open' => TRUE,
    ];
    $form['lcars_ng']['front_page_login'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Login panel on the front page'),
      '#description' => $this->t('Anonymous visitors see the login form in the LCARS login panel instead of the front page content, as in the Drupal 6 theme LCNG.'),
      '#config_target' => 'lcars_ng.settings:front_page_login',
    ];
    $form['lcars_ng']['navigation_scheme'] = [
      '#type' => 'select',
      '#title' => $this->t('Navigation colors'),
      '#description' => $this->t('Colors of the bars of the Navigation module on the left and at the top: dark in the colors of the theme, light as in core, or after the light or dark setting of the system of each user.'),
      '#options' => [
        'dark' => $this->t('Dark'),
        'light' => $this->t('Light'),
        'auto' => $this->t('As the system'),
      ],
      '#config_target' => 'lcars_ng.settings:navigation_scheme',
    ];
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

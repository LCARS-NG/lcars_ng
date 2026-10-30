<?php

namespace Drupal\lcars_ng\Hook;

use Drupal\block\BlockInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\lcars_ng\StatusLine;
use Drupal\user\Form\UserLoginForm;
use Drupal\user\UserInterface;

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

  /**
   * Colors of the generated user pictures (user-picture.css).
   */
  private const USER_PICTURE_COLORS = [
    'link',
    'frame',
    'button',
    'blue',
    'accent',
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
    // Contrast (css/lcars/tokens.css, #34): Medium and High for all visitors,
    // Standard never more. As the system sets nothing: there High follows
    // the setting for more contrast of each visitor's system.
    $contrast = $this->themeSettings->getSetting('contrast') ?? 'system';
    if ($contrast !== 'system') {
      $variables['html_attributes']->setAttribute('data-lcars-contrast', $contrast);
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
   * Implements hook_preprocess_field().
   *
   * A user without a picture gets the default picture of the field, in Drupal
   * CMS the same avatar for everyone. With the theme setting
   * generated_user_pictures, a pattern made from the UUID of the account takes
   * its place, as the identicons of GitHub: a grid of 5 by 5 cells mirrored at
   * its middle, 15 cells and one of five colors of the theme from a SHA-256
   * hash, on a card (user decision 29.10.2026, variant A). Each account keeps
   * its pattern; pictures that users upload stay as they are.
   */
  #[Hook('preprocess_field')]
  public function preprocessField(array &$variables): void {
    $element = $variables['element'];
    $account = $element['#object'] ?? NULL;
    if ($element['#field_name'] !== 'user_picture' || !$account instanceof UserInterface
      || !$element['#items']->isEmpty() || empty($variables['items'])
      || !($this->themeSettings->getSetting('generated_user_pictures') ?? TRUE)) {
      return;
    }
    foreach ($variables['items'] as &$item) {
      $picture = $this->userPicture($account);
      // The default picture links to the account where its formatter does.
      if (isset($item['content']['#url'])) {
        $picture = [
          '#type' => 'link',
          '#url' => $item['content']['#url'],
          '#title' => $picture,
        ];
      }
      CacheableMetadata::createFromRenderArray($item['content'])->applyTo($picture);
      $item['content'] = $picture;
    }
  }

  /**
   * Implements hook_preprocess_status_report_grouped().
   *
   * Core prints the value of each requirement (Protected, Up to date, …) as
   * bare text before its description. Wrapped here, it stands as a status
   * item in the color of its group (status-report.css).
   */
  #[Hook('preprocess_status_report_grouped')]
  public function preprocessStatusReportGrouped(array &$variables): void {
    foreach ($variables['grouped_requirements'] as &$group) {
      foreach ($group['items'] as &$requirement) {
        $value = $requirement['value'] ?? NULL;
        if ($value === NULL || $value === '' || $value === []) {
          continue;
        }
        $requirement['value'] = [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#attributes' => [
            'class' => ['lcars-status-value', 'lcars-status-value--' . $group['type']],
          ],
          'value' => match (TRUE) {
            is_array($value) => $value,
            $value instanceof MarkupInterface => ['#markup' => $value],
            default => ['#plain_text' => (string) $value],
          },
        ];
      }
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
      return;
    }
    // Without an upload, the widget previews the default picture. With
    // generated user pictures (preprocessField()), the form of an account
    // shows its pattern instead; before there is an account, no picture.
    $element = $variables['element'];
    if (isset($data['preview']) && empty($element['#files'])
      && ($element['#entity_type'] ?? NULL) === 'user' && ($element['#field_name'] ?? NULL) === 'user_picture'
      && ($this->themeSettings->getSetting('generated_user_pictures') ?? TRUE)) {
      $account = $this->routeMatch->getParameter('user');
      if ($account instanceof UserInterface) {
        $data['preview'] = ['#weight' => $data['preview']['#weight'] ?? -10] + $this->userPicture($account);
      }
      else {
        unset($data['preview']);
      }
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
    $form['lcars_ng']['contrast'] = [
      '#type' => 'radios',
      '#title' => $this->t('Contrast'),
      '#description' => $this->t('Contrast of the frame, of the texts in its color and of the lines of boxes and fields: for all visitors, or after the setting of the system of each visitor.'),
      '#options' => [
        'system' => $this->t('As the system'),
        'standard' => $this->t('Standard'),
        'medium' => $this->t('Medium'),
        'high' => $this->t('High'),
      ],
      'system' => ['#description' => $this->t('Standard, or High for visitors whose system asks for more contrast.')],
      'standard' => ['#description' => $this->t('The frame and its texts in a quiet steel blue.')],
      'medium' => ['#description' => $this->t('The frame and its texts in a lighter blue, the lines of boxes and fields lighter.')],
      'high' => ['#description' => $this->t('The frame and its texts in light blue, the buttons lighter still, the title bars in gold.')],
      '#config_target' => 'lcars_ng.settings:contrast',
    ];
    $form['lcars_ng']['generated_user_pictures'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generated user pictures'),
      '#description' => $this->t('Users without a picture get a pattern made from their account, in the colors of the theme, instead of the default picture of the field.'),
      '#config_target' => 'lcars_ng.settings:generated_user_pictures',
    ];
    $form['lcars_ng']['collapse_module_packages'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Collapsed packages on Extend'),
      '#description' => $this->t('The packages of modules on Extend start collapsed instead of open. The filter opens the packages with matching modules while it is in use.'),
      '#config_target' => 'lcars_ng.settings:collapse_module_packages',
    ];
  }

  /**
   * Implements hook_form_FORM_ID_alter() for system_modules.
   *
   * Core opens all packages on Extend (only Testing starts collapsed), a list
   * of several screens. With the theme setting collapse_module_packages they
   * start collapsed; core's filter (system.modules.js) opens the packages with
   * matching modules while it is in use and closes them again when it is
   * emptied. Core leaves this to the themes (#2018871).
   */
  #[Hook('form_system_modules_alter')]
  public function formSystemModulesAlter(array &$form): void {
    if (!isset($form['modules'])
      || !($this->themeSettings->getSetting('collapse_module_packages') ?? TRUE)) {
      return;
    }
    foreach (Element::children($form['modules']) as $package) {
      $form['modules'][$package]['#open'] = FALSE;
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for block_admin_display_form.
   *
   * Core puts "Place block" right after the name of each region, in one cell
   * across all columns, so the buttons stand at a different place in each
   * row. Here the button moves to the column Operations, below the operations
   * of the blocks (user wish 30.10.2026). The name and the message "No blocks
   * in this region" span the first three columns, the column of the weights
   * gets an empty cell of its own: tabledrag widens every cell across that
   * column by one when the weights show at page load, without having
   * narrowed it before, which pushed the last cell into a sixth column.
   */
  #[Hook('form_block_admin_display_form_alter')]
  public function formBlockAdminDisplayFormAlter(array &$form): void {
    if (!isset($form['blocks'])) {
      return;
    }
    foreach (Element::children($form['blocks']) as $key) {
      $row = &$form['blocks'][$key];
      $classes = $row['#attributes']['class'] ?? [];
      if (in_array('region-title', $classes, TRUE) && isset($row['title']['#prefix'])) {
        $button = $row['title'];
        $name = $button['#prefix'];
        unset($button['#prefix'], $button['#wrapper_attributes']);
        $row['title'] = [
          '#markup' => $name,
          '#wrapper_attributes' => ['colspan' => 3],
        ];
        $row['weight'] = ['#markup' => ''];
        $row['operations'] = $button;
      }
      elseif (in_array('region-message', $classes, TRUE) && isset($row['message'])) {
        $row['message']['#wrapper_attributes']['colspan'] = 3;
        $row['weight'] = ['#markup' => ''];
        $row['operations'] = ['#markup' => ''];
      }
    }
    unset($row);
  }

  /**
   * Builds the generated picture of an account (preprocessField()).
   *
   * Nibble i of the hash fills cell i of the left three columns when even,
   * column by column; the right two mirror them. Byte 20 picks the color. The
   * cells form one path, so that adjacent cells show no seam between them.
   */
  private function userPicture(UserInterface $account): array {
    $hash = hash('sha256', $account->uuid(), TRUE);
    $path = '';
    for ($column = 0; $column < 3; $column++) {
      for ($row = 0; $row < 5; $row++) {
        $i = $column * 5 + $row;
        if (((ord($hash[$i >> 1]) >> ($i & 1 ? 0 : 4)) & 15) % 2 === 0) {
          foreach (array_unique([$column, 4 - $column]) as $x) {
            $path .= sprintf('M%.1F %.1Fh11.2v11.2h-11.2z', 8 + $x * 11.2, 8 + $row * 11.2);
          }
        }
      }
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<svg class="lcars-user-picture lcars-user-picture--{{ color }}" xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 72 72" role="img" aria-label="{{ label }}"><rect class="lcars-user-picture__card" width="72" height="72" rx="6"/><path d="{{ path }}"/></svg>',
      '#context' => [
        'color' => self::USER_PICTURE_COLORS[ord($hash[20]) % count(self::USER_PICTURE_COLORS)],
        'label' => $account->getDisplayName(),
        'path' => $path,
      ],
      '#attached' => ['library' => ['lcars_ng/user-picture']],
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

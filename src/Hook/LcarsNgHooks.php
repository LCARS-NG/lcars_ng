<?php

namespace Drupal\lcars_ng\Hook;

use Drupal\block\BlockInterface;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\ConfigTarget;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Routing\RouteProviderInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Url;
use Drupal\lcars_ng\StatusLine;
use Drupal\node\NodeInterface;
use Drupal\user\Form\UserLoginForm;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\RequestStack;

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
   * Routes of the tables of the most frequent log messages (dblog).
   */
  private const LOG_MESSAGE_ROUTES = [
    'dblog.access_denied',
    'dblog.page_not_found',
    'dblog.search',
  ];

  /**
   * Routes of the tables of violation reports (contrib Reporting API).
   */
  private const VIOLATION_REPORT_ROUTES = [
    'reporting.dblog.overview',
  ];

  /**
   * Routes whose tables need more than the golden section of the page.
   */
  private const WIDE_TABLE_ROUTES = [
    'metatag.reports_plugins',
  ];

  /**
   * Routes rendered in the two-part screen (page--panel.html.twig).
   */
  private const PANEL_ROUTES = [
    'entity.user.canonical',
  ];

  /**
   * Routes rendered in an alert (alert.css), with its color.
   *
   * A page not found is a warning, as core logs it, and shows in yellow
   * alert; denied access in red alert (#54).
   */
  private const ALERT_ROUTES = [
    'system.401' => 'red',
    'system.403' => 'red',
    'system.404' => 'yellow',
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
    private readonly ModuleExtensionList $moduleList,
    private readonly RouteProviderInterface $routeProvider,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * Implements hook_theme_registry_alter().
   *
   * The module gin_toolbar (part of Drupal CMS) replaces the template of the
   * top bar of the Navigation module with Gin's, also where Gin is not the
   * administration theme. Gin's template prints the bar on every page and
   * leaves whitespace in its regions, so core's top-bar.css (:empty) shows it
   * empty and moves the page down by its height. Core's template prints the
   * bar only where it has content (title, status and Edit on the page of an
   * entity). Runs after the alter hooks of the modules.
   */
  #[Hook('theme_registry_alter')]
  public function themeRegistryAlter(array &$theme_registry): void {
    if (str_ends_with($theme_registry['top_bar']['path'] ?? '', '/gin_toolbar/templates')) {
      $theme_registry['top_bar']['path'] = $this->moduleList->getPath('navigation') . '/templates';
    }
  }

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
    // The route name can be NULL; PHP 8.5 deprecates NULL as an array key.
    $alert = self::ALERT_ROUTES[(string) $this->routeMatch->getRouteName()] ?? NULL;
    if ($alert !== NULL) {
      $variables['attributes']['class'][] = 'lcars-alert';
      if ($alert === 'yellow') {
        $variables['attributes']['class'][] = 'lcars-alert--yellow';
      }
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
    // Notice for browsers too old for the theme (html.html.twig,
    // css/lcars/browser-notice.css); on unless a site turned it off, also on
    // sites from before the setting.
    $variables['lcars_browser_notice'] = (bool) ($this->themeSettings->getSetting('browser_notice') ?? TRUE);
  }

  /**
   * Implements hook_preprocess_page().
   *
   * The login panel on the front page builds the form of the route user.login,
   * which modules may replace: the core form would skip their steps, such as
   * the second factor of TFA. It links to the password reset form only
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
      $form_class = $this->routeProvider->getRouteByName('user.login')->getDefault('_form') ?: UserLoginForm::class;
      $variables['login_form'] = $this->formBuilder->getForm($form_class);
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
   * the button below the form says the same (user decision 28.10.2026). Nor
   * does the page to reset the password, where the active tab says the same
   * (user decision 31.10.2026). The heading stays for screen readers
   * (page-title.html.twig).
   *
   * On the page of content of a type chosen in the theme setting
   * content_type_in_title_bar, the bar names the type, and the title stands
   * as the heading above the content (preprocessNode(); user decision
   * 31.10.2026, variant D). On the edit form of content of any type the bar
   * says "Edit Blog", "Edit Utility page", without the title, which the field
   * below names, and upright: core gives "<em>Edit @type</em> @title"
   * (NodeForm). The title of the document keeps the one of core (user wish
   * 04.11.2026; for all types, not only those of the setting, user decision
   * 04.11.2026). No other heading repeats the title on the edit form.
   */
  #[Hook('preprocess_page_title')]
  public function preprocessPageTitle(array &$variables): void {
    $route = $this->routeMatch->getRouteName();
    $variables['title_hidden'] = in_array($route, ['user.login', 'user.pass'], TRUE);
    $cacheability = (new CacheableMetadata())
      ->addCacheTags(['config:lcars_ng.settings'])
      ->addCacheContexts(['route']);
    $node = $this->pageNode();
    if ($node && $this->showsTypeInTitleBar($node)) {
      $type = $node->type->entity;
      $variables['title'] = $type->label();
      $variables['title_tag'] = 'div';
      $cacheability->addCacheableDependency($type);
    }
    $edited = $route === 'entity.node.edit_form' ? $this->routeMatch->getParameter('node') : NULL;
    if ($edited instanceof NodeInterface) {
      $type = $edited->type->entity;
      $variables['title'] = $this->t('Edit @type', ['@type' => $type->label()]);
      $cacheability->addCacheableDependency($type);
    }
    CacheableMetadata::createFromRenderArray($variables)
      ->merge($cacheability)
      ->applyTo($variables);
  }

  /**
   * Implements hook_preprocess_node().
   *
   * Content of a type chosen in the theme setting content_type_in_title_bar
   * shows its title as the heading above the content, below the submission
   * information (node.html.twig, node.css): on its own page as the main
   * heading, as its title bar names the type (preprocessPageTitle()).
   *
   * The submission information shows the day of the post only, as
   * "7 June 2011": weekday and time stay in the title of the time element
   * (node.html.twig, node.css; #35, user decision 30.10.2026, variant A). The
   * created field of nodes has no display settings, so its format is set here.
   */
  #[Hook('preprocess_node')]
  public function preprocessNode(array &$variables): void {
    if ($variables['view_mode'] === 'full') {
      $node = $variables['node'];
      $cacheability = (new CacheableMetadata())->addCacheTags(['config:lcars_ng.settings']);
      if ($this->showsTypeInTitleBar($node)) {
        $variables['title_tag'] = $this->pageNode()?->uuid() === $node->uuid() ? 'h1' : 'h2';
        $cacheability->addCacheContexts(['route']);
      }
      CacheableMetadata::createFromRenderArray($variables)
        ->merge($cacheability)
        ->applyTo($variables);
    }
    if (empty($variables['display_submitted']) || empty($variables['date'])) {
      return;
    }
    $variables['date'] = $variables['node']->get('created')->view([
      'label' => 'hidden',
      'type' => 'timestamp',
      'settings' => [
        'date_format' => 'custom',
        'custom_date_format' => 'j F Y',
      ],
    ]);
  }

  /**
   * Implements hook_preprocess_views_view_summary().
   *
   * A summary of a contextual filter in a display with the CSS class
   * lcars-summary-tabs shows as tabs, its counts inside them, as the years
   * above the posts of the blog (views-view-summary.html.twig, archive.css;
   * #35, user decision 30.10.2026, variant D).
   */
  #[Hook('preprocess_views_view_summary')]
  public function preprocessViewsViewSummary(array &$variables): void {
    $classes = explode(' ', (string) $variables['view']->display_handler->getOption('css_class'));
    $variables['tabs'] = in_array('lcars-summary-tabs', $classes, TRUE);
  }

  /**
   * Implements hook_preprocess_table().
   *
   * View modes and form modes (EntityDisplayModeListBuilder): core puts the
   * button "Add … for …" of each type of entity into the last row of its
   * table, so it stood in the box of the table. As the buttons of all other
   * tables in boxes (Save on Roles, Block layout, Permissions, …), it moves
   * below the table (table.html.twig, table.css; user wish 05.11.2026).
   *
   * Each table with its button becomes a collapsible section titled by its
   * type of entity, as the packages on Extend; with the theme setting
   * collapse_module_packages they start collapsed (user wish 05.11.2026).
   * The type comes from the route of the button. The main content is
   * rendered before the blocks, so a theme cannot wrap the tables in a
   * details element of the render array; table.html.twig writes it around
   * the table, and table.css hides core's heading above it (#prefix of the
   * table), which the summary repeats.
   *
   * The tables of the most frequent log messages (Top "access denied" and
   * "page not found" errors, Top search phrases) get the class
   * lcars-log-messages: their messages name paths and classes without a
   * space, which table.css lets break anywhere, so the table fits the golden
   * section of the page (05.11.2026; the longest made it 947px wide). Core
   * gives these tables no class of their own. The violation reports of the
   * Reporting API module (Recent violation reports) get the class
   * lcars-violation-reports: the date in two lines, the day above the time
   * (the " - " of the date format between them drops), the location as a
   * path without scheme and host when it is on this site, breaking anywhere,
   * and columns with the same value in every row left out, so the table
   * fits the golden section (08.11.2026; a URL with a reset token made it
   * 1284px wide, Operations was cut off, and date and type took five and two
   * lines; user wishes: the time in a second line, more room for the
   * location, variant C of four: the path breaking anywhere, not the URL,
   * and not on one line with an ellipsis; preprocessViolationReports()).
   * Tables that need more room than the golden section (the plugins of
   * Metatag, 1299px) get the class lcars-table-wide, which keeps their page
   * at the full width (frame.css).
   */
  #[Hook('preprocess_table')]
  public function preprocessTable(array &$variables): void {
    $classes = $variables['attributes']['class'] ?? [];
    $classes = is_array($classes) ? $classes : explode(' ', (string) $classes);
    $route = $this->routeMatch->getRouteName();
    if (in_array($route, self::LOG_MESSAGE_ROUTES, TRUE)) {
      $classes[] = 'lcars-log-messages';
      $variables['attributes']['class'] = $classes;
    }
    elseif (in_array($route, self::VIOLATION_REPORT_ROUTES, TRUE)) {
      $classes[] = 'lcars-violation-reports';
      $variables['attributes']['class'] = $classes;
      $this->preprocessViolationReports($variables);
    }
    elseif (in_array($route, self::WIDE_TABLE_ROUTES, TRUE)) {
      $classes[] = 'lcars-table-wide';
      $variables['attributes']['class'] = $classes;
    }
    if (!in_array('display-mode-table', $classes, TRUE) || !isset($variables['rows']['_add_new']['cells'][0]['content'])) {
      return;
    }
    $button = $variables['rows']['_add_new']['cells'][0]['content'];
    $variables['after'] = $button;
    unset($variables['rows']['_add_new']);
    $url = $button['#url'] ?? NULL;
    $type = $url instanceof Url ? ($url->getRouteParameters()['entity_type_id'] ?? NULL) : NULL;
    $definition = $type ? $this->entityTypeManager->getDefinition($type, FALSE) : NULL;
    if ($definition) {
      $variables['section'] = [
        'title' => $definition->getLabel(),
        'open' => !$this->collapsesSections(),
      ];
      CacheableMetadata::createFromRenderArray($variables)
        ->addCacheTags(['config:lcars_ng.settings'])
        ->applyTo($variables);
    }
  }

  /**
   * Prepares the cells of the violation reports (Reporting API).
   *
   * The module gives its columns no classes: endpoint, date, type,
   * disposition, location, operations. The date gets the class
   * lcars-violation-reports__date and two spans, day and time (table.css sets
   * them in two lines); the location the class
   * lcars-violation-reports__location and, when it is on this site, only its
   * path. Endpoint, type and disposition are left out when every row of the
   * page has the same value in them (user wish 08.11.2026: with one endpoint
   * and the report-only policy they read csp, csp-violation and report in
   * every row); a column comes back on a page whose rows differ, as enforce
   * beside report once the policy is enforced.
   */
  private function preprocessViolationReports(array &$variables): void {
    $header = $variables['header'] ?? [];
    $rows = $variables['rows'] ?? [];
    // Only the six columns of the module, not the message of an empty table.
    if (count($header) !== 6 || $rows === [] || array_filter($rows, static fn(array $row): bool => count($row['cells'] ?? []) !== 6)) {
      return;
    }
    $host = $this->requestStack->getCurrentRequest()?->getSchemeAndHttpHost() ?? '';
    foreach ($rows as &$row) {
      $date = $row['cells'][1]['content'];
      if (is_string($date) && str_contains($date, ' - ')) {
        [$day, $time] = explode(' - ', $date, 2);
        $row['cells'][1]['content'] = [
          '#markup' => '<span>' . Html::escape($day) . '</span> <span>' . Html::escape($time) . '</span>',
        ];
      }
      $row['cells'][1]['attributes']->addClass('lcars-violation-reports__date');
      $location = $row['cells'][4]['content'];
      if ($host !== '' && is_string($location) && str_starts_with($location, $host . '/')) {
        $row['cells'][4]['content'] = substr($location, strlen($host));
      }
      $row['cells'][4]['attributes']->addClass('lcars-violation-reports__location');
    }
    unset($row);

    foreach ([0, 2, 3] as $column) {
      $values = array_column(array_column(array_column($rows, 'cells'), $column), 'content');
      if (count(array_filter($values, 'is_scalar')) !== count($values) || count(array_unique(array_map('strval', $values))) !== 1) {
        continue;
      }
      unset($header[$column]);
      foreach ($rows as &$row) {
        unset($row['cells'][$column]);
      }
      unset($row);
    }
    $variables['header'] = array_values($header);
    $variables['header_columns'] = count($header);
    $variables['rows'] = array_map(static function (array $row): array {
      $row['cells'] = array_values($row['cells']);
      return $row;
    }, $rows);
  }

  /**
   * Implements hook_preprocess_field().
   *
   * A user without a picture gets the default picture of the field, in Drupal
   * CMS the same avatar for everyone. With the theme setting
   * generated_user_pictures, a pattern made from the UUID of the account takes
   * its place, as the identicons of GitHub: a grid of 5 by 5 cells mirrored at
   * its middle, 15 cells and one of five colors of the theme from a SHA-256
   * hash (user decision 29.10.2026, variant A), on a disc in the same color,
   * darker (user decision 01.11.2026, variant C; was a card in one color for
   * all). Each account keeps its pattern; pictures that users upload stay as
   * they are.
   *
   * Entity references shown as links to their labels (Tags, Related) become
   * pills: referencePills().
   */
  #[Hook('preprocess_field')]
  public function preprocessField(array &$variables): void {
    $element = $variables['element'];
    if ($element['#formatter'] === 'entity_reference_label') {
      $this->referencePills($variables);
      return;
    }
    $account = $element['#object'] ?? NULL;
    if ($element['#field_name'] !== 'user_picture' || !$account instanceof UserInterface
      || !$element['#items']->isEmpty() || empty($variables['items'])
      || !($this->themeSettings->getSetting('generated_user_pictures') ?? TRUE)) {
      return;
    }
    // On the page of the account (view mode full) a square, which profile.css
    // rounds as buttons and cards; elsewhere the disc.
    $square = ($element['#view_mode'] ?? NULL) === 'full';
    foreach ($variables['items'] as &$item) {
      $picture = $this->userPicture($account, $square);
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
   *
   * Core opens every requirement; with the theme setting
   * collapse_module_packages they start collapsed, as the packages on Extend
   * (status-report-grouped.html.twig, user wish 04.11.2026).
   */
  #[Hook('preprocess_status_report_grouped')]
  public function preprocessStatusReportGrouped(array &$variables): void {
    $variables['details_open'] = !$this->collapsesSections();
    CacheableMetadata::createFromRenderArray($variables)
      ->addCacheTags(['config:lcars_ng.settings'])
      ->applyTo($variables);
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
    // shows its pattern instead, square as on the page of the account
    // (user.css); before there is an account, no picture.
    $element = $variables['element'];
    if (isset($data['preview']) && empty($element['#files'])
      && ($element['#entity_type'] ?? NULL) === 'user' && ($element['#field_name'] ?? NULL) === 'user_picture'
      && ($this->themeSettings->getSetting('generated_user_pictures') ?? TRUE)) {
      $account = $this->routeMatch->getParameter('user');
      if ($account instanceof UserInterface) {
        $data['preview'] = ['#weight' => $data['preview']['#weight'] ?? -10] + $this->userPicture($account, TRUE);
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
   * Implements hook_preprocess_field_multiple_value_form().
   *
   * Core names a field with several values in the head of its table. Here
   * the name is the legend of a fieldset around the table
   * (field-multiple-value-form.html.twig): in node forms, where each field is
   * a box, it stands in the upper line like the other titles (forms.css),
   * elsewhere above the table. A title moved there by CSS alone would be cut
   * off on phones, where the table scrolls in a wrapper of its own
   * (table.css). The head keeps the name for screen readers (user decision
   * 04.11.2026).
   */
  #[Hook('preprocess_field_multiple_value_form')]
  public function preprocessFieldMultipleValueForm(array &$variables): void {
    if (empty($variables['multiple']) || ($variables['table']['#header'][0]['data']['#tag'] ?? NULL) !== 'h4') {
      return;
    }
    $label = &$variables['table']['#header'][0]['data'];
    $attributes = $label['#attributes'] instanceof Attribute ? $label['#attributes'] : new Attribute($label['#attributes'] ?? []);
    $required = $attributes->hasClass('form-required') ? ['js-form-required', 'form-required'] : [];
    $variables['legend'] = $label['#value'];
    $variables['legend_attributes'] = new Attribute(['class' => $required]);
    $label['#attributes'] = $attributes->addClass('visually-hidden');
  }

  /**
   * Implements hook_form_FORM_ID_alter() for system_theme_settings.
   *
   * Not in theme-settings.php: core registers a function there as a hook of
   * this theme, but loads the file only on this theme's settings page. Where
   * LCARS-NG is also the administration theme, the settings pages of all other
   * themes would call the missing function.
   *
   * Core opens the sections of the global settings and of the settings of each
   * theme (Page element display, Logo image, Favicon, those of the theme).
   * With the theme setting collapse_module_packages they start collapsed, as
   * the packages on Extend; a section with an error opens (Details).
   */
  #[Hook('form_system_theme_settings_alter')]
  public function formSystemThemeSettingsAlter(array &$form): void {
    $collapse = $this->collapsesSections();
    if ($collapse) {
      $this->collapseDetails($form);
    }
    if (($form['config_key']['#value'] ?? NULL) !== 'lcars_ng.settings') {
      return;
    }
    $form['lcars_ng'] = [
      '#type' => 'details',
      '#title' => $this->t('LCARS-NG'),
      '#open' => !$collapse,
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
      '#title' => $this->t('Collapsed sections'),
      '#description' => $this->t('The packages of modules on Extend, the sections of the theme settings, of the basic site settings and of Mail System, the groups of the defaults of meta tags, the entries of the status report, the groups of permissions and the types on View modes and Form modes start collapsed instead of open. The filters on Extend and Permissions open the packages and groups with matches while they are in use.'),
      '#config_target' => 'lcars_ng.settings:collapse_module_packages',
    ];
    $form['lcars_ng']['browser_notice'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Notice for old browsers'),
      '#description' => $this->t('Browsers without container queries or color-mix(), on which the frame depends (Safari before 16.2, Chrome and Edge before 111, Firefox before 113), show a bar above the page that recommends a current browser. Current browsers never show it.'),
      '#config_target' => 'lcars_ng.settings:browser_notice',
    ];
    if ($this->entityTypeManager->hasDefinition('node_type')) {
      $types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
      $form['lcars_ng']['content_type_in_title_bar'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Content type in the title bar'),
        '#description' => $this->t('On the page of content of these types, the title bar names the type, and the title stands as the heading above the content, below the date and author.'),
        '#options' => array_map(fn ($type) => $type->label(), $types),
        '#config_target' => new ConfigTarget('lcars_ng.settings', 'content_type_in_title_bar', fromConfig: fn (?array $types): array => $types ?? []),
        // Core's submit handler of theme settings stores the values of the
        // form as they are, after the config target: a list of the types.
        '#element_validate' => [[self::class, 'validateCheckedList']],
      ];
    }
  }

  /**
   * Element validation: the checked options of checkboxes as a list.
   */
  public static function validateCheckedList(array $element, FormStateInterface $form_state): void {
    $form_state->setValueForElement($element, array_values(array_filter($element['#value'])));
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
    if (isset($form['modules']) && $this->collapsesSections()) {
      $this->collapseDetails($form['modules']);
    }
  }

  /**
   * Implements hook_form_alter() for forms whose sections start collapsed.
   *
   * Basic site settings (system_site_information_settings): core opens Site
   * details, Front page and Error pages. Defaults of meta tags
   * (metatag_defaults_form, a base form ID): the Metatag module opens each
   * group with a value (Basic tags, Advanced, Open Graph, Twitter Cards), so
   * on the global defaults all of them. Mail System (mailsystem_admin_form):
   * the module opens Default Mail System and Module-specific configuration.
   * With the theme setting collapse_module_packages they start collapsed, as
   * the packages on Extend.
   */
  #[Hook('form_system_site_information_settings_alter')]
  #[Hook('form_metatag_defaults_form_alter')]
  #[Hook('form_mailsystem_admin_form_alter')]
  public function formCollapseSectionsAlter(array &$form): void {
    if ($this->collapsesSections()) {
      $this->collapseDetails($form);
    }
  }

  /**
   * Implements hook_form_FORM_ID_alter() for user_admin_permissions.
   *
   * The groups of the permissions table open and close (permissions.js,
   * library lcars_ng/permissions on user/drupal.user.permissions). With the
   * theme setting collapse_module_packages they start collapsed, as the
   * packages on Extend (user decision 05.11.2026). All pages of permissions
   * share the form ID: all roles, one role, one module, one bundle.
   */
  #[Hook('form_user_admin_permissions_alter')]
  public function formUserAdminPermissionsAlter(array &$form): void {
    $form['#attached']['drupalSettings']['lcarsNg']['collapseSections'] = $this->collapsesSections();
    $form['#cache']['tags'][] = 'config:lcars_ng.settings';
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for user_form.
   *
   * Core keeps the account data (email address, username, password, status,
   * roles) in a container without a title. Here it is a box of its own like
   * Locale settings, open, with the title Account (user decision 03.11.2026,
   * variant D); Roles and Picture are boxes as well (user.css). Also on the
   * registration form, which uses the same base form.
   */
  #[Hook('form_user_form_alter')]
  public function formUserFormAlter(array &$form): void {
    if (isset($form['account'])) {
      $form['account']['#type'] = 'details';
      $form['account']['#title'] = $this->t('Account');
      $form['account']['#open'] = TRUE;
    }
  }

  /**
   * Implements hook_form_BASE_FORM_ID_alter() for node_form.
   *
   * In node forms each field is a box with its title in the upper line
   * (forms.css). The moderation state of Content Moderation is a container
   * without a title; as a fieldset it carries the label of its field,
   * Moderation state, in the line like the other boxes (user decision
   * 04.11.2026, variant C).
   */
  #[Hook('form_node_form_alter')]
  public function formNodeFormAlter(array &$form): void {
    if (($form['moderation_state']['widget'][0]['#type'] ?? NULL) === 'container') {
      $form['moderation_state']['widget'][0]['#type'] = 'fieldset';
      // The fieldset would show the description of the field, which the
      // container never did ("The moderation state of this piece of content").
      unset($form['moderation_state']['widget'][0]['#description']);
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
   * gets an empty cell of its own: a cell that spans that column but not the
   * whole row, tabledrag widens by one when the weights show at page load,
   * without having narrowed it before (core #2902300); a name across four
   * columns pushed the button into a sixth column.
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
   * Implements hook_form_FORM_ID_alter() for node_preview_form_select.
   *
   * The bar above the preview of a node (css/lcars/node-preview.css) begins
   * with a label that says that nothing is saved, and shows the link back to
   * the form as a button (user decision 02.11.2026, variant D, #44).
   */
  #[Hook('form_node_preview_form_select_alter')]
  public function formNodePreviewFormSelectAlter(array &$form): void {
    $form['lcars_label'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#value' => $this->t('Preview – nothing is saved'),
      '#attributes' => ['class' => ['node-preview-form-select__label']],
      '#weight' => -10,
    ];
    if (isset($form['backlink'])) {
      $form['backlink']['#options']['attributes']['class'][] = 'button';
    }
  }

  /**
   * Shows the links of an entity reference field as pills (preprocessField()).
   *
   * The label formatter puts each link on a line of its own. As pills outlined
   * in the sidebar blue, as the tags of a Tagify field, they stand in a row
   * beside the label of the field (pills.css; #35, user decision 30.10.2026,
   * variant B). Only where every item is a link: a pill promises one.
   */
  private function referencePills(array &$variables): void {
    if (empty($variables['items'])) {
      return;
    }
    foreach ($variables['items'] as $item) {
      if (($item['content']['#type'] ?? NULL) !== 'link') {
        return;
      }
    }
    $variables['attributes']['class'][] = 'lcars-pills';
    $variables['#attached']['library'][] = 'lcars_ng/pills';
  }

  /**
   * Builds the generated picture of an account (preprocessField()).
   *
   * Nibble i of the hash fills cell i of the left three columns when even,
   * column by column; the right two mirror them. Byte 20 picks the color. The
   * cells form one path, so that adjacent cells show no seam between them.
   * The grid, 45 of 72 units, sits in the middle of the disc, so that even its
   * corners keep about 4 units to the edge (was 56 units on the card). With
   * $square, the ground fills the whole picture instead of the disc, for the
   * page of the account and the preview in its form (user decision
   * 12.11.2026, variant B).
   */
  private function userPicture(UserInterface $account, bool $square = FALSE): array {
    $hash = hash('sha256', $account->uuid(), TRUE);
    $path = '';
    for ($column = 0; $column < 3; $column++) {
      for ($row = 0; $row < 5; $row++) {
        $i = $column * 5 + $row;
        if (((ord($hash[$i >> 1]) >> ($i & 1 ? 0 : 4)) & 15) % 2 === 0) {
          foreach (array_unique([$column, 4 - $column]) as $x) {
            $path .= sprintf('M%.1F %.1Fh9v9h-9z', 13.5 + $x * 9, 13.5 + $row * 9);
          }
        }
      }
    }
    return [
      '#type' => 'inline_template',
      '#template' => '<svg class="lcars-user-picture lcars-user-picture--{{ color }}" xmlns="http://www.w3.org/2000/svg" width="72" height="72" viewBox="0 0 72 72" role="img" aria-label="{{ label }}">{% if square %}<rect class="lcars-user-picture__disc" width="72" height="72"/>{% else %}<circle class="lcars-user-picture__disc" cx="36" cy="36" r="36"/>{% endif %}<path d="{{ path }}"/></svg>',
      '#context' => [
        'square' => $square,
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

  /**
   * Whether lists of sections start collapsed.
   *
   * The key names the packages on Extend, where the setting began in 1.0.9;
   * as part of the interface it keeps its name until 2.0.0.
   */
  private function collapsesSections(): bool {
    return (bool) ($this->themeSettings->getSetting('collapse_module_packages') ?? TRUE);
  }

  /**
   * The content the current page shows: its page or its preview.
   */
  private function pageNode(): ?NodeInterface {
    $node = match ($this->routeMatch->getRouteName()) {
      'entity.node.canonical' => $this->routeMatch->getParameter('node'),
      'entity.node.preview' => $this->routeMatch->getParameter('node_preview'),
      default => NULL,
    };
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /**
   * Whether the title bar names the type of the content on its page.
   */
  private function showsTypeInTitleBar(NodeInterface $node): bool {
    return in_array($node->bundle(), $this->themeSettings->getSetting('content_type_in_title_bar') ?? [], TRUE);
  }

  /**
   * Collapses the sections (details) among the children of an element.
   */
  private function collapseDetails(array &$element): void {
    foreach (Element::children($element) as $key) {
      if (($element[$key]['#type'] ?? NULL) === 'details') {
        $element[$key]['#open'] = FALSE;
      }
    }
  }

}

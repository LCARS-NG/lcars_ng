# LCARS-NG

LCARS-NG is a theme inspired by Michael Okuda's LCARS interface from
*Star Trek: The Next Generation*: rounded elbows, coloured bars and buttons on
a black screen. It is a rebuild of the Drupal 6 theme
[LCNG](https://github.com/LCARS-NG/LCNG) (2011) by the same author, with the
same design on current techniques: Twig, CSS Grid and custom properties, no
JavaScript and no build step. The CSS is served as it is in the repository.

The theme works as front-end theme and as administration theme.

![LCARS-NG front page for signed-in users: upper panel with site name, slogan
and logo, status line with date and user, menu buttons on the left, content
below](screenshot.png)

- For a full description of the theme, visit the
  [project page](https://www.drupal.org/project/lcars_ng).
- To submit bug reports and feature suggestions, or track changes, use the
  [issue queue](https://www.drupal.org/project/issues/lcars_ng).


## Requirements

This theme requires no modules outside of Drupal core (11.3 or later).


## Recommended modules

- [Canvas](https://www.drupal.org/project/canvas): the theme provides four
  single-directory components for Canvas pages in the group "LCARS": button,
  heading, text and panel.
- The theme also styles the pages of Dashboard and Project Browser when they
  are installed.


## Installation

Install as you would normally install a contributed Drupal theme. For further
information, see
[Installing themes](https://www.drupal.org/docs/extending-drupal/installing-themes).

```bash
composer require drupal/lcars_ng
drush theme:install lcars_ng
drush config:set system.theme default lcars_ng
```

To use it for the administration pages as well, select it as administration
theme under *Appearance*.


## Configuration

*Appearance > Settings > LCARS-NG* offers one setting besides the logo and
favicon:

- **Login panel on the front page** (`front_page_login`, off by default):
  anonymous visitors see the login form in the LCARS login panel instead of
  the front page content, as in the Drupal 6 theme LCNG.

The theme chooses its layout per page:

- **Frame** on all pages: elbows at the top and the bottom, menu buttons on
  the left, the content in between.
- **Login panel** on the account pages (log in, register, reset password).
- **Two-part screen** on the front page for signed-in users and on user
  profiles: an upper panel with site name, logo and slogan, a status line with
  date, time and the signed-in user, the content below.
- **Red alert** on the pages for 401, 403 and 404.

Regions:

- `header`: header bar (site name), in the two-part screen the upper panel
- `panel_sidebar`, `panel`, `panel_status`: two-part screen only; buttons
  beside the upper panel, blocks in it, the curve below it
- `breadcrumb`: in the upper curve
- `sidebar`: menu buttons
- `highlighted`, `help`, `content`, `sidebar_second`
- `footer`: in the lower curve, blocks side by side

Colours and frame sizes are custom properties in `css/lcars/tokens.css`
(`--lcars-*`). A sub-theme can override them in its own stylesheet.


## Troubleshooting & FAQ

**The block layout is ignored on pages built with Canvas.**
If the Canvas setting `default_page_variant` names a page variant (for
example the one of Mercury), that variant fills the regions of every theme
instead of the block layout. Leave the setting empty to use the blocks placed
in LCARS-NG.

**Why does the theme override dialog styles of Gin and Claro?**
The module Gin Toolbar attaches the dialog styles of Gin and Claro to every
dialog in every theme. They rely on Gin's CSS variables and leave dialogs
transparent in other themes, so LCARS-NG removes them (`libraries-override`)
and styles dialogs in `css/components/dialog.css`.

**Can the login panel replace the "Access denied" page?**
Set the default 403 page to `/user/login` under *Configuration > System >
Basic site settings*. Anonymous visitors then get the login panel.


## Credits

- Design after the Drupal 6 theme LCNG (2011) by the same author, source:
  [github.com/LCARS-NG/LCNG](https://github.com/LCARS-NG/LCNG).
- `lcars-ng-logo.svg` is the logo of LCNG, redrawn as SVG: an icosahedron in
  a Schlegel diagram with 12 vertices and 30 edges. The theme logo
  `lcars-ng-logo-glow.svg` is derived from it: edges towards the centre lie
  further back (thinner and more transparent), a soft glow, transparent
  background.
- The icons in `images/icons/` come from the Drupal core Starterkit theme, the
  base styles in `css/components/` and most templates are based on it
  (GPL-2.0-or-later).
- LCARS-NG is a fan work. It is not affiliated with or endorsed by the owners
  of Star Trek.


## Maintainers

- Giang Ng

# LCARS-NG

LCARS-NG is a theme inspired by Michael Okuda's LCARS interface from
*Star Trek: The Next Generation*: rounded elbows, colored bars and buttons on
a dark background. It takes up the idea of the interface, but its colors and
shapes are its own. It is a rebuild of the Drupal 6 theme
[LCNG](https://github.com/LCARS-NG/LCNG) (2011) by the same author, on
current techniques: Twig, CSS Grid and custom properties, with no build step.
The CSS is served as it is in the repository. One small script sets the
corners of boxes by their width; without JavaScript everything stays usable.

The theme works as front-end theme and as administration theme. Its frame
adapts to the width of the screen, from phones to wide desktop screens, for
mouse and touch.

![LCARS-NG front page for signed-in users: upper panel with site name, slogan
and logo, status line with date and user, menu buttons on the left, content
below](screenshot.png)

- To see the theme on a live site, visit the
  [demo](https://demo.lcars-ng.org). The login form shows the credentials of
  a read-only guest account, which also opens the administration pages.
- For a full description of the theme, visit the
  [project page](https://www.drupal.org/project/lcars_ng).
- To submit bug reports and feature suggestions, or track changes, use the
  [issue queue](https://www.drupal.org/project/issues/lcars_ng).


## Requirements

This theme requires no modules outside of Drupal core (11.3 or later).


## Recommended modules

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

*Appearance > Settings > LCARS-NG* offers two settings besides the logo and
favicon:

- **Login panel on the front page** (`front_page_login`, off by default):
  anonymous visitors see the login form in the LCARS login panel instead of
  the front page content, as in the Drupal 6 theme LCNG.
- **Navigation colors** (`navigation_scheme`, dark by default): the bars of
  the Navigation module on the left and at the top in the colors of the
  theme (*Dark*), in the colors of core (*Light*), or after the light or dark
  setting of the system of each user (*As the system*).

The theme chooses its layout per page:

- **Frame** on all pages: elbows at the top and the bottom, menu buttons on
  the left, the content in between. On phones only the content remains, with
  the menus as a grid of buttons above the title.
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
- `footer`: in the lower curve, blocks side by side; in the login panel above
  the bottom bar

With the [Klaro!](https://www.drupal.org/project/klaro) consent manager (part
of Drupal CMS), the consent notice is an LCARS panel floating above the bottom
elbow and its footer, and the consent dialog is a panel of the same kind. Their
titles are cut into the ceiling of the panel (the notice title with Klaro's
setting *Show notice title*); short titles such as "Privacy" and "Privacy
settings" fit best.

Colors and frame sizes are custom properties in `css/lcars/tokens.css`
(`--lcars-*`). A sub-theme can override them in its own stylesheet.

The theme provides four single-directory components to embed in templates:
button, heading, text and panel (`components/`).


## Troubleshooting & FAQ

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
  `lcars-ng-logo-facets.svg` is derived from it: a cut gem seen from above,
  the 19 inner faces as shaded, slightly translucent facets over the outer
  face, outer edge only, transparent background. `lcars-ng-logo-glow.svg`,
  the theme logo up to 1.0.2, shows the edges only: towards the center further
  back (thinner and more transparent), with a soft glow.
- `favicon.ico` (16, 32 and 48 px) shows the faceted logo with a stronger
  outer edge, on a transparent background.
- The icons in `images/icons/` come from the Drupal core Starterkit theme, the
  base styles in `css/components/` and most templates are based on it
  (GPL-2.0-or-later).
- LCARS-NG is a fan work. It is not affiliated with or endorsed by the owners
  of Star Trek.


## License

LCARS-NG is licensed under the GNU General Public License, version 2 or
later (GPL-2.0-or-later), like Drupal core. The full text is in
`LICENSE.txt`; drupal.org also adds it to every release package.


## Maintainers

- Giang Ng

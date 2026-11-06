/**
 * @file
 * The top bar of the Navigation module as an LCARS column beside the sidebar.
 *
 * From 64rem the bar stands as a column of segments between the sidebar of
 * the Navigation module and the frame (css/lcars/top-bar.css): the status at
 * the top, below it "More actions", then Edit; without a status Edit moves up.
 * Beside the round top elbow of the frame (pages without the upper panel) a
 * cap comes first.
 * Each segment ends at a seam of the column of the frame beside it: the
 * status and Edit are at least two buttons of the frame high (and as long as
 * their label needs), "More actions" one button. The rest of the column runs
 * down to the seam above the bottom elbow of the frame; beside the elbow a
 * last segment ends round at its bottom edge (user decision 06.11.2026).
 *
 * The heights of the frame depend on its content (logo, breadcrumb, menus),
 * so the script measures them and sets the sizes as custom properties; it
 * measures again when the frame changes size. The column moves with the
 * page, so it stays beside the seams it follows.
 *
 * Below 64rem, without a frame and with items of other modules in the bar
 * (the tools region, other context), the bar of core stays.
 */

((Drupal, once) => {
  /**
   * Width from which the column replaces the bar (desktop of core).
   *
   * @type {MediaQueryList}
   */
  const desktop = window.matchMedia('(min-width: 64rem)');

  /**
   * Converts a CSS length (a custom property of the theme) into pixels.
   *
   * @param {HTMLElement} parent
   *   An element that inherits the tokens of the theme.
   * @param {string} value
   *   The length, e.g. 'var(--lcars-seam)'.
   *
   * @return {number}
   *   The length in pixels.
   */
  const toPixels = (parent, value) => {
    const probe = document.createElement('div');
    probe.style.position = 'absolute';
    probe.style.height = value;
    parent.append(probe);
    const { height } = probe.getBoundingClientRect();
    probe.remove();
    return height;
  };

  /**
   * Top and bottom of an element, relative to the document.
   *
   * @param {Element} element
   *   The element.
   *
   * @return {{top: number, bottom: number}}
   *   Its edges in pixels from the top of the document.
   */
  const edges = (element) => {
    const rect = element.getBoundingClientRect();
    return {
      top: rect.top + window.scrollY,
      bottom: rect.bottom + window.scrollY,
    };
  };

  /**
   * Whether the bar holds only the items the column knows.
   *
   * @param {HTMLElement} bar
   *   The top bar.
   *
   * @return {boolean}
   *   TRUE for title, status, Edit and "More actions" only.
   */
  const knownItems = (bar) => {
    const tools = bar.querySelector('.top-bar__tools');
    const context = bar.querySelector('.top-bar__context');
    const actions = bar.querySelector('.top-bar__actions');
    return (
      (!tools || tools.children.length === 0) &&
      [...(context?.children || [])].every((item) =>
        item.matches('.top-bar__title, .toolbar-badge'),
      ) &&
      [...(actions?.children || [])].every((item) =>
        item.matches(
          '.toolbar-button--primary, [data-drupal-dropdown], .toolbar-dropdown__menu',
        ),
      )
    );
  };

  /**
   * Sets the gap before each segment, its height and the rest of the column.
   *
   * @param {HTMLElement} bar
   *   The top bar.
   * @param {HTMLElement} frame
   *   The LCARS frame.
   */
  const layout = (bar, frame) => {
    const seam = toPixels(frame, 'var(--lcars-seam)');
    const button = toPixels(frame, 'var(--lcars-button-height)');
    const column = [
      ...frame.querySelectorAll(
        ':scope > :is(.lcars-panel-side, .lcars-foot--split, .lcars-head), :scope > .lcars-sidebar :is(.menu a, .block > h2):not(.visually-hidden)',
      ),
    ]
      .filter((element) => element.getBoundingClientRect().height >= button / 2)
      .map((element) => {
        const { top, bottom } = edges(element);
        // The buttons of the sidebar draw their seam as a border.
        const border = parseFloat(getComputedStyle(element).borderBottomWidth);
        return { element, top, bottom: bottom - border };
      })
      .sort((a, b) => a.top - b.top);
    if (!column.length) {
      return;
    }
    const status = bar.querySelector('.toolbar-badge');
    const edit = [bar.querySelector('.toolbar-button--primary'), true];
    const more = [bar.querySelector('[data-drupal-dropdown]'), false];
    // Without a status Edit moves to the top (order in top-bar.css).
    const segments = (
      status ? [[status, true], more, edit] : [edit, more]
    ).filter(([element]) => element);

    // Where the frame starts with its round top elbow (pages without the
    // upper panel), the column starts with a cap beside it: the empty tools
    // region, round at the top in top-bar.css.
    let previous = 0;
    const tools = bar.querySelector('.top-bar__tools');
    const cap = column[0].element.matches('.lcars-head') ? column[0] : null;
    if (tools) {
      tools.style.setProperty('--lcars-top-bar-gap', `${cap ? cap.top : 0}px`);
      tools.style.setProperty(
        '--lcars-top-bar-size',
        `${cap ? cap.bottom - cap.top : 0}px`,
      );
      previous = cap ? cap.bottom : 0;
    }
    segments.forEach(([element, tall], index) => {
      const top = index || previous ? previous + seam : column[0].top;
      let bottom = top + button;
      if (tall) {
        // At least two buttons and the seam between them, and the label.
        element.style.removeProperty('--lcars-top-bar-size');
        const needed = Math.max(
          2 * button + seam,
          element.getBoundingClientRect().height,
        );
        const end = column.find((part) => part.bottom >= top + needed - 1);
        bottom = end ? end.bottom : top + needed;
      }
      element.style.setProperty('--lcars-top-bar-gap', `${top - previous}px`);
      element.style.setProperty('--lcars-top-bar-size', `${bottom - top}px`);
      previous = bottom;
    });

    // The rest runs down to the seam above the bottom elbow, the last
    // segment beside the elbow to its bottom edge (round in top-bar.css),
    // also where the column of the frame is only an edge and its menus stand
    // above the content.
    const foot = frame.querySelector(
      ':scope > .lcars-foot:not(.lcars-foot--split)',
    );
    const elbow = foot ? edges(foot) : { top: previous, bottom: previous };
    let rest = elbow.top - seam - (previous + seam);
    if (rest > 0) {
      previous += seam + rest;
    } else {
      rest = 0;
    }
    const last = Math.max(elbow.top, previous + seam);
    const sizes = {
      '--lcars-top-bar-rest-gap': rest ? seam : 0,
      '--lcars-top-bar-rest': rest,
      '--lcars-top-bar-last-gap': last - previous,
      '--lcars-top-bar-last': Math.max(0, elbow.bottom - last),
    };
    Object.entries(sizes).forEach(([name, value]) => {
      bar.style.setProperty(name, `${value}px`);
    });
  };

  /**
   * Turns the bar into the column or back.
   *
   * @param {HTMLElement} bar
   *   The top bar.
   * @param {boolean} column
   *   TRUE for the column, FALSE for the bar of core.
   */
  const switchTo = (bar, column) => {
    const root = document.documentElement;
    const more = bar.querySelector('[data-drupal-dropdown]');
    if (column) {
      root.dataset.lcarsTopBar = 'strip';
      bar.removeAttribute('data-offset-top');
      bar.setAttribute(
        `data-offset-${root.dir === 'rtl' ? 'right' : 'left'}`,
        '',
      );
      if (more) {
        more.dataset.drupalDropdownPosition =
          root.dir === 'rtl' ? 'left-start' : 'right-start';
      }
    } else {
      delete root.dataset.lcarsTopBar;
      bar.removeAttribute('data-offset-left');
      bar.removeAttribute('data-offset-right');
      bar.setAttribute('data-offset-top', '');
      if (more) {
        delete more.dataset.drupalDropdownPosition;
      }
      [
        '--lcars-top-bar-rest-gap',
        '--lcars-top-bar-rest',
        '--lcars-top-bar-last-gap',
        '--lcars-top-bar-last',
      ].forEach((name) => bar.style.removeProperty(name));
      bar
        .querySelectorAll('.top-bar__tools, .toolbar-button, .toolbar-badge')
        .forEach((element) => {
          element.style.removeProperty('--lcars-top-bar-gap');
          element.style.removeProperty('--lcars-top-bar-size');
        });
    }
    Drupal.displace(true);
  };

  /**
   * Places the top bar as a column beside the sidebar.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Switches the bar between column and bar and keeps the column beside the
   *   seams of the frame.
   */
  Drupal.behaviors.lcarsTopBar = {
    attach(context) {
      once('lcars-top-bar', '.top-bar', context).forEach((bar) => {
        const frame = document.querySelector('.lcars-frame');
        if (!frame || !knownItems(bar)) {
          return;
        }
        let pending = 0;
        const update = () => {
          pending = 0;
          const column = desktop.matches;
          if (
            column !==
            (document.documentElement.dataset.lcarsTopBar === 'strip')
          ) {
            switchTo(bar, column);
          }
          if (column) {
            layout(bar, frame);
          }
        };
        const schedule = () => {
          pending = pending || requestAnimationFrame(update);
        };
        update();
        desktop.addEventListener('change', schedule);
        const observer = new ResizeObserver(schedule);
        observer.observe(frame);
        frame
          .querySelectorAll(
            ':scope > :is(.lcars-panel-side, .lcars-foot--split, .lcars-head, .lcars-sidebar)',
          )
          .forEach((part) => observer.observe(part));
        document.fonts?.ready.then(schedule);
      });
    },
  };
})(Drupal, once);

/**
 * @file
 * Rounds boxes and cards by their own width.
 *
 * Boxes and cards wider than --lcars-box-wide-from (400px) take the corners
 * of --lcars-box-radius-wide (10px), narrower ones those of
 * --lcars-box-radius-small (6px) (user decision 25.10.2026). The script marks
 * each box with data-lcars-box="wide" or "narrow"; box.css sets the corners.
 * CSS alone cannot round an element by its own width without containing its
 * size, which would change the width of the login panel and the columns of
 * tables. Without the script the width of the window decides (tokens.css).
 */

((Drupal, once) => {
  /**
   * The boxes and cards that take their corners from --lcars-box-radius.
   *
   * @type {string}
   */
  const boxes = [
    '.lcars-box',
    'details',
    '.system-status-general-info__item',
    '.password-suggestions',
    '.lcars-login__panel .block-system-branding-block',
    '.lcars-login__panel .region-content',
    '.lcars-login__form',
    '.entity-moderation-form',
    '.ui-dialog',
    '.search__form-container',
    '.pb-detail-modal__sidebar',
    '.pb-project',
    '.layout-builder',
  ].join(', ');

  /**
   * The width above which a box counts as wide, in pixels.
   *
   * @type {number}
   */
  let wideFrom = 400;

  /**
   * Reads --lcars-box-wide-from (rem or px) in pixels.
   *
   * @return {number}
   *   The width above which a box counts as wide.
   */
  const readWideFrom = () => {
    const style = getComputedStyle(document.documentElement);
    const value = style.getPropertyValue('--lcars-box-wide-from').trim();
    const number = parseFloat(value);
    if (Number.isNaN(number)) {
      return wideFrom;
    }
    return value.endsWith('rem') ? number * parseFloat(style.fontSize) : number;
  };

  // A box hidden or taken out of the page reports 0 and counts as narrow until
  // it is shown again.
  const sizes = new ResizeObserver((entries) => {
    entries.forEach(({ target, borderBoxSize }) => {
      target.dataset.lcarsBox =
        borderBoxSize[0].inlineSize > wideFrom ? 'wide' : 'narrow';
    });
  });

  /**
   * Observes the boxes in an element and the element itself.
   *
   * Observing a box again only repeats its first report, so moved boxes (the
   * content of a dialog) need no bookkeeping.
   *
   * @param {Element} element
   *   An element of the page.
   */
  const watch = (element) => {
    if (element.matches(boxes)) {
      sizes.observe(element);
    }
    element.querySelectorAll(boxes).forEach((box) => sizes.observe(box));
  };

  // Dialogs, Project Browser and other scripts add boxes without attaching
  // behaviors to them: watch everything added to the page.
  const additions = new MutationObserver((records) => {
    records
      .flatMap((record) => [...record.addedNodes])
      .filter((node) => node.nodeType === Node.ELEMENT_NODE)
      .forEach(watch);
  });

  /**
   * Marks boxes and cards as wide or narrow by their width.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Observes the boxes of the page and the boxes added later.
   */
  Drupal.behaviors.lcarsBoxRadius = {
    attach(context) {
      once('lcars-box-radius', 'body', context).forEach((body) => {
        wideFrom = readWideFrom();
        watch(body);
        additions.observe(body, { childList: true, subtree: true });
      });
    },
  };
})(Drupal, once);

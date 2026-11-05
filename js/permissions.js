/**
 * @file
 * Collapsible groups in the table of permissions.
 *
 * Core lists the permissions of all modules one below the other, several
 * hundred rows under a row for each module. Here the row of a module opens
 * and closes its permissions; with the theme setting "Collapsed sections"
 * (collapse_module_packages) the groups start collapsed, as the packages on
 * Extend (user decision 05.11.2026, variant D). Each group row counts, in
 * the column of each role, the permissions of the group the role has, its
 * own and those inherited from Authenticated user, as the checkboxes show
 * them; the counts follow every change. Core's filter opens all groups while
 * it is in use and the groups return to their state when it is emptied. The
 * groups open when the form is saved stay open on the page that follows.
 *
 * Collapsed rows keep their place in the widths of the columns
 * (permissions.css), so the table does not jump.
 */

((Drupal, drupalSettings, once) => {
  /**
   * The key of the open groups kept while the form is saved.
   *
   * @type {string}
   */
  const storeKey = `lcars_ng.permissions.open:${window.location.pathname}`;

  /**
   * Reads the groups kept open while the form was saved, once.
   *
   * @return {string[]|null}
   *   The IDs of the module cells, or NULL.
   */
  function takeStored() {
    try {
      const value = window.sessionStorage.getItem(storeKey);
      window.sessionStorage.removeItem(storeKey);
      return value ? JSON.parse(value) : null;
    } catch (e) {
      return null;
    }
  }

  /**
   * Keeps the open groups for the page that follows the save.
   *
   * @param {string[]} ids
   *   The IDs of the module cells of the open groups.
   */
  function store(ids) {
    try {
      window.sessionStorage.setItem(storeKey, JSON.stringify(ids));
    } catch (e) {
      // Without storage the groups start as set.
    }
  }

  /**
   * Makes the groups of one permissions table collapsible.
   *
   * @param {HTMLTableElement} table
   *   The table#permissions.
   */
  function init(table) {
    const body = table.tBodies[0];
    const head = table.tHead?.rows[0];
    if (!body || !head) {
      return;
    }
    const roles = [...head.cells].slice(1).map((th) => th.textContent.trim());
    const groups = [];
    const groupOf = new WeakMap();
    let group = null;
    [...body.rows].forEach((row) => {
      const cell = row.querySelector(':scope > td.module');
      if (cell) {
        group = { row, cell, rows: [], open: true };
        groups.push(group);
      } else if (group) {
        group.rows.push(row);
        groupOf.set(row, group);
      }
    });
    if (!groups.length) {
      return;
    }

    // The column of Authenticated user, whose permissions the other roles
    // but Anonymous user inherit (core's user.permissions.js shows them as
    // checked fake checkboxes).
    const authCell = body
      .querySelector('input.js-rid-authenticated')
      ?.closest('td');
    const auth = authCell ? authCell.cellIndex : -1;

    /**
     * Whether the role of a column has the permission of a row.
     *
     * @param {HTMLTableRowElement} row
     *   A row of a permission.
     * @param {number} column
     *   The index of the column of the role.
     *
     * @return {boolean}
     *   TRUE if checked, own or inherited.
     */
    function granted(row, column) {
      const box = row.cells[column]?.querySelector(
        'input[type="checkbox"]:not(.js-fake-checkbox)',
      );
      if (!box) {
        return false;
      }
      if (box.checked) {
        return true;
      }
      if (
        auth < 0 ||
        column === auth ||
        box.classList.contains('js-rid-anonymous')
      ) {
        return false;
      }
      return !!row.cells[auth]?.querySelector(
        'input.js-rid-authenticated:checked',
      );
    }

    /**
     * Counts the permissions of a group per role.
     *
     * @param {object} item
     *   The group.
     */
    function count(item) {
      const total = item.rows.length;
      item.counts.forEach((td, k) => {
        const n = item.rows.filter((row) => granted(row, k + 1)).length;
        td.classList.toggle('is-none', n === 0);
        td.classList.toggle('is-all', n > 0 && n === total);
        td.replaceChildren();
        if (n > 0) {
          const shown = document.createElement('span');
          shown.setAttribute('aria-hidden', 'true');
          shown.textContent = `${n}/${total}`;
          td.append(shown);
        }
        const read = document.createElement('span');
        read.className = 'visually-hidden';
        read.textContent = Drupal.t('@role: @count of @total', {
          '@role': roles[k],
          '@count': n,
          '@total': total,
        });
        td.append(read);
      });
    }

    let allButton = null;

    /**
     * Names the button for all groups by what it does next.
     */
    function label() {
      if (allButton) {
        allButton.textContent = groups.every((item) => item.open)
          ? Drupal.t('Collapse all')
          : Drupal.t('Expand all');
      }
    }

    /**
     * Opens or closes a group.
     *
     * @param {object} item
     *   The group.
     * @param {boolean} open
     *   Whether it opens.
     */
    function set(item, open) {
      item.open = open;
      item.button.setAttribute('aria-expanded', String(open));
      item.rows.forEach((row) =>
        row.classList.toggle('lcars-permissions__hidden', !open),
      );
    }

    groups.forEach((item) => {
      const name = item.cell.textContent.trim();
      const total = item.rows.length;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'lcars-permissions__toggle';
      const text = document.createElement('span');
      text.className = 'lcars-permissions__name';
      const amount = document.createElement('span');
      amount.className = 'lcars-permissions__total';
      amount.setAttribute('aria-hidden', 'true');
      amount.textContent = `(${total})`;
      const read = document.createElement('span');
      read.className = 'visually-hidden';
      read.textContent = `, ${Drupal.formatPlural(total, '1 permission', '@count permissions')}`;
      text.append(name, ' ', amount, read);
      button.append(text);
      item.cell.replaceChildren(button);
      item.cell.colSpan = 1;
      item.row.classList.add('lcars-permissions__group');
      item.button = button;
      item.counts = roles.map(() => {
        const td = document.createElement('td');
        td.className = 'lcars-permissions__granted';
        item.row.append(td);
        return td;
      });
      count(item);
      button.addEventListener('click', () => {
        set(item, !item.open);
        label();
      });
      // The whole row toggles, not only the name.
      item.row.addEventListener('click', (event) => {
        if (!event.target.closest('button')) {
          set(item, !item.open);
          label();
        }
      });
    });

    // The counts follow each checkbox; a change for Authenticated user
    // changes what the other roles inherit.
    body.addEventListener('change', (event) => {
      const item = groupOf.get(event.target.closest('tr'));
      if (item) {
        count(item);
      }
    });

    // The first state: kept from the save, else collapsed by the setting,
    // but for a table of one group (the permissions of one module) and the
    // group the address points to (#module-…).
    const stored = takeStored();
    const collapse =
      (drupalSettings.lcarsNg?.collapseSections ?? true) && groups.length > 1;
    const target = window.location.hash.slice(1);
    groups.forEach((item) => {
      set(
        item,
        stored
          ? stored.includes(item.cell.id)
          : !collapse || item.cell.id === target,
      );
    });

    const form = table.closest('form');
    // Expand all, collapse all: beside "Hide descriptions".
    const holder = form?.querySelector('.compact-link');
    allButton = document.createElement('button');
    allButton.type = 'button';
    allButton.className = 'link lcars-permissions__all';
    allButton.addEventListener('click', () => {
      const open = !groups.every((item) => item.open);
      groups.forEach((item) => set(item, open));
      label();
    });
    if (holder) {
      holder.append(allButton);
    } else {
      const wrapper = document.createElement('div');
      wrapper.className = 'compact-link';
      wrapper.append(allButton);
      (form || table).prepend(wrapper);
    }
    label();

    // Core's filter (user.permissions.js) hides the rows without a match
    // and the groups left empty, from two characters on. It must see all
    // groups open: on the form, in the capture phase, this debounced call is
    // set before core's on the field, with the same delay, and runs first.
    // Emptied, the groups return to their state before the filter.
    const input = form?.querySelector('input.table-filter-text');
    let before = null;
    if (input && table.matches(input.dataset.table || '')) {
      const sync = Drupal.debounce(() => {
        const filtering = input.value.length >= 2;
        if (filtering && !before) {
          before = groups.map((item) => item.open);
          groups.forEach((item) => set(item, true));
        } else if (!filtering && before) {
          groups.forEach((item, k) => set(item, before[k]));
          before = null;
        }
        label();
      }, 200);
      ['keyup', 'click'].forEach((type) => {
        form.addEventListener(
          type,
          (event) => {
            if (event.target === input) {
              sync();
            }
          },
          true,
        );
      });
    }

    form?.addEventListener('submit', () => {
      const open = before
        ? groups.filter((item, k) => before[k])
        : groups.filter((item) => item.open);
      store(open.map((item) => item.cell.id));
    });
  }

  /**
   * Makes the groups of the permissions table collapsible.
   *
   * @type {Drupal~behavior}
   *
   * @prop {Drupal~behaviorAttach} attach
   *   Attaches the toggles to table#permissions.
   */
  Drupal.behaviors.lcarsNgPermissions = {
    attach(context) {
      once('lcars-permissions', 'table#permissions', context).forEach(init);
    },
  };
})(Drupal, drupalSettings, once);

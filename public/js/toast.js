/*
 * One toast, used by every feature on a page.
 *
 * The map had two independent implementations writing into the same #toast
 * element - map.js's toast(message, ms) and admin-map.js's
 * showToast(message, isError) - each with its own timer, its own idea of how
 * long a message should live and its own way of clearing it. Whichever fired
 * last won, and a dismissal from one could be cut short by the other.
 *
 * Both are now thin wrappers over this. The element itself is declared in the
 * page markup and kept in the DOM so the aria-live region persists for screen
 * readers; it is emptied and hidden between messages rather than removed.
 *
 * Sizing, position and the pill styling live in the stylesheets
 * (app.css for the public map, admin-map.css for the admin map), which is
 * where the page's own look is defined. This file only decides when a message
 * is shown and when it goes away.
 */
(function (global) {
  'use strict';

  var DEFAULT_MS = 3000;
  var timer = null;

  /**
   * The shared element, created on first use if the page did not declare one.
   *
   * @returns {HTMLElement|null} Null when there is no document to put it in.
   */
  function element() {
    if (!global.document) return null;

    var el = global.document.getElementById('toast');
    if (el) return el;

    el = global.document.createElement('div');
    el.id = 'toast';
    el.className = 'toast';
    el.setAttribute('role', 'status');
    el.setAttribute('aria-live', 'polite');
    el.hidden = true;
    global.document.body.appendChild(el);
    return el;
  }

  /**
   * Empties and hides the toast.
   *
   * Called on every dismissal, so no message text and no empty box is left
   * sitting on the screen. A pending timer is cleared with it: a second message
   * that arrives before the first has expired must not be hidden early by the
   * first one's timer.
   */
  function hide() {
    if (timer) {
      clearTimeout(timer);
      timer = null;
    }
    var el = element();
    if (!el) return;
    el.textContent = '';
    el.hidden = true;
  }

  /**
   * Shows a message, replacing whatever is on screen.
   *
   * @param {string} message Text to display.
   * @param {number|{ms?: number, error?: boolean}} [options] A duration in
   *   milliseconds, or an object. Defaults to 3000ms.
   */
  function show(message, options) {
    var ms = DEFAULT_MS;
    var isError = false;

    // Accepts a bare number so the map's existing toast(msg, 15000) call sites
    // pass straight through.
    if (typeof options === 'number') {
      ms = options;
    } else if (options) {
      if (typeof options.ms === 'number') ms = options.ms;
      isError = !!options.error;
    }

    var el = element();
    if (!el) return;

    el.textContent = message;
    el.className = 'toast' + (isError ? ' toast-error' : '');
    el.hidden = false;

    if (timer) clearTimeout(timer);
    timer = setTimeout(hide, ms);
  }

  global.Toast = { show: show, hide: hide };
})(window);

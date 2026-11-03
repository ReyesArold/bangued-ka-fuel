/* Admin sign-in. */
(function () {
  'use strict';

  var form = document.getElementById('login-form');
  var username = document.getElementById('username');
  var password = document.getElementById('password');
  var submit = document.getElementById('submit');
  var errorBox = document.getElementById('login-error');

  function showError(message) {
    errorBox.textContent = message;
    errorBox.hidden = false;
  }

  function clearError() {
    errorBox.hidden = true;
  }

  /**
   * Where to send a signed-in user.
   *
   * An administrator goes to the dashboard. A station manager goes there too:
   * the table filters to their own stations, so they land on a list of
   * exactly the stations they are answerable for, each with only a Prices
   * action.
   *
   * @param {?Object} user The user record from /api/auth/me or /api/auth/login.
   * @returns {string}
   */
  function landingPageFor(user) {
    // Both roles go to the dashboard, because it filters to the caller's own
    // stations: a station manager lands on a list of exactly the stations they
    // are answerable for, one row each with only a Prices action. The admin map
    // is one click away from there.
    return '/admin';
  }

  // Already signed in? Skip straight to where they belong.
  Api.me()
    .then(function (resp) {
      window.location.replace(landingPageFor(resp && resp.data));
    })
    .catch(function () { /* not signed in - show the form */ });

  form.addEventListener('submit', async function (e) {
    e.preventDefault();
    clearError();

    var user = username.value.trim();
    var pass = password.value;

    if (!user || !pass) {
      showError('Enter both username and password.');
      return;
    }

    submit.disabled = true;
    submit.textContent = 'Signing in…';

    try {
      var signedIn = await Api.login(user, pass);
      // Remember the username only - never the password.
      try { localStorage.setItem('bgm.username', user); } catch (err) { /* ignore */ }
      window.location.replace(landingPageFor(signedIn && signedIn.data));
    } catch (err) {
      showError(err.message || 'Sign in failed.');
      password.value = '';
      password.focus();
      submit.disabled = false;
      submit.textContent = 'Sign in';
    }
  });
})();

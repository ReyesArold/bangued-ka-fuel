/* Admin map for Bangued gas station map - editable view, based on public map approach. */
(function () {
  'use strict';

  /*
   * The map itself belongs to map.js, which is the only thing on this page that
   * creates one - two Leaflet maps cannot share the #map container. `map` is
   * that shared instance, adopted in init(); `mapReady` is the promise that
   * resolves once it exists.
   *
   * Everything below the map entry is owned by this file. The unused fields that
   * used to sit here (boundary, fallbackMap, activeBase, infoWindow, legend,
   * activeId) were part of a second, parallel map implementation here that never
   * ran and that would have written to map.js's map if it had.
   */
  /*
   * Sentinel value for the "create a manager instead" entry in the Station
   * manager dropdown. A real manager id is a positive integer, so a string
   * sentinel can never collide with one.
   */
  var NEW_MANAGER_VALUE = '__new_manager__';

  var state = {
    provider: 'leaflet', // Always use Leaflet for admin map (no Google Maps API key needed)
    map: null,
    mapReady: null,
    bounds: null,        // L.LatLngBounds covering Bangued
    cfg: null,
    stations: [],
    brands: [],
    // stationId -> L.Marker, the draggable pins this file swapped in
    markers: {},
    // Set while the coordinate picker's own map is being built
    picker: null,
    pickerMarker: null,
    pickerPromise: null,
    pickerTarget: null,
    editingId: null,
    // Add mode state
    addMode: false,
    tempMarker: null,
    addModeHint: null,
    // Field values as the station dialog last presented them, used by the
    // header's "Dashboard" link to tell an untouched dialog from an edited one.
    stationFormSnapshot: null,
    // The manager this dialog is meant to show, and whether the dropdown could
    // actually hold it. See trackExpectedManager().
    expectedManagerId: '',
    managerSelectionLoaded: true,
    // The signed-in user, and whether they are a full administrator. A station
    // manager gets a prices-only dialog; the server enforces the same rule and
    // this only decides what the UI offers.
    user: null,
    isAdmin: false,
  };

  var el = {};

  /* ------------------------------------------------------------------ utils */

  function peso(value) {
    return '₱' + Number(value).toLocaleString('en-PH', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  function formatTimestamp(value) {
    return FmtTime.format(value);
  }

  function escapeHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  /*
   * Shows a transient message in the page's toast.
   *
   * A thin wrapper over the Toast component in toast.js, which the map's own
   * toast() also uses - one element, one timer, one dismissal path for both.
   *
   * The second parameter is the error flag, matching every call site
   * (showToast('...', true)) and admin.js's own signature. It used to be a
   * duration before the flag, so `true` arrived as the duration: setTimeout
   * coerced it to 1ms and every message - error or not - vanished before it
   * could be read, while the error styling it was meant to add never applied.
   *
   * @param {string} message
   * @param {boolean} [isError=false]
   */
  function showToast(message, isError) {
    if (window.Toast) window.Toast.show(message, { error: isError });
  }

  /* ------------------------------------------------------------------ add mode */

  /**
   * The single place the add-station button's label, icon and colour are built.
   *
   * Four separate copies of this markup existed across the file, two of them
   * written as a <span class="icon" viewBox=...> rather than an <svg>, so the
   * "plus" rendered as an oversized unstroked box and the cancel state was a
   * differently-sized control from the idle one.
   *
   * @param {boolean} adding Whether add mode is being entered.
   */
  function renderAddButton(adding) {
    var btn = document.getElementById('btn-add-station-map');
    if (!btn) return;

    // aria-pressed is what carries the state for a screen reader; the label
    // alone ("Add station" / "Cancel") is ambiguous without it.
    btn.setAttribute('aria-pressed', adding ? 'true' : 'false');
    btn.classList.toggle('is-adding', adding);
    btn.title = adding ? 'Cancel adding a station' : 'Add a station';

    var label = btn.querySelector('.add-station-btn-label');
    if (label) {
      label.textContent = adding ? 'Cancel' : 'Add station';
    }

    var icon = btn.querySelector('.add-station-btn-icon');
    if (icon) {
      // A cross in add mode, a plus otherwise. Written to innerHTML because the
      // glyph is a path, and it is the only markup this function owns.
      icon.innerHTML = adding
        ? '<path d="M6 18L18 6M6 6l12 12"/>'
        : '<path d="M12 5v14M5 12h14"/>';
    }
  }

  function showAddModeHint() {
    // Remove existing hint if any
    hideAddModeHint();

    // Positioned and styled entirely by admin-map.css. This used to set a dozen
    // inline styles, which put the look of the page in the JS and could not be
    // adjusted from the stylesheet at all.
    var hint = document.createElement('div');
    hint.className = 'add-mode-hint';
    hint.setAttribute('role', 'status');
    hint.textContent = 'Click the map to drop the pin, then drag it to adjust. Press Esc to cancel.';

    document.body.appendChild(hint);
    state.addModeHint = hint;
  }

  function hideAddModeHint() {
    if (state.addModeHint) {
      document.body.removeChild(state.addModeHint);
      state.addModeHint = null;
    }
  }

  /* ------------------------------------------------------------------ markers */

  function updateStationPosition(stationId, lat, lng) {
    // Validate coordinates
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
      showToast('Invalid coordinates', true);
      return;
    }

    // Check Bangued bounds if configured
    if (state.cfg && state.cfg.map && state.cfg.map.maxBounds) {
      var bounds = state.cfg.map.maxBounds;
      if (
        lat < bounds[0][0] ||
        lat > bounds[1][0] ||
        lng < bounds[0][1] ||
        lng > bounds[1][1]
      ) {
        showToast('Coordinates must be within Bangued, Abra', true);
        return;
      }
    }

    // Update via API
    Api.updateStation(stationId, {
      latitude: lat,
      longitude: lng
    }).then(function () {
      showToast('Station position updated');
      // Find and update the station in our local state
      var station = state.stations.find(function (s) { return s.id === stationId; });
      if (station) {
        station.latitude = lat;
        station.longitude = lng;
      }
    }).catch(function (err) {
      showToast('Failed to update station position: ' + err.message, true);
      console.error(err);
      // Reload data to revert to server state
      loadData();
    });
  }

  /* ------------------------------------------------------------------ data loading */

  /*
   * Pins, the legend, the boundary and the base-layer switch all belong to
   * map.js on this page, and this file used to carry a second, parallel
   * implementation of every one of them. None of it ran - initMap() bailed
   * before reaching it - and keeping it was actively misleading: renderStations()
   * and applyBrandFilters() both wrote to state.markers, the very map map.js
   * owns, so either being called would have produced a duplicate pin per
   * station. The map now has one owner and this file edits its output through
   * decorateMarker().
   *
   * What this file still fetches is the admin view of the data: brands, the
   * full station list including pending and hidden records, and the config that
   * fills the dialog's dropdowns and supplies the coordinate bounds.
   */
  function loadData() {
    // No loader here. The one on the page belongs to map.js, which shows it
    // while the map boots; raising it again on every refresh put a full-screen
    // overlay over a map that was already usable.
    return Promise.all([
      Api.adminConfig(),
      Api.brands(),
      Api.adminStations()
    ]).then(function ([configResp, brandsResp, stationsResp]) {
      // Check for auth failures
      var authFailure = [configResp, brandsResp, stationsResp].find(function (resp) {
        return resp && resp.error && resp.error.status === 401;
      });
      if (authFailure) {
        redirectToLogin();
        return;
      }

      // Check for other failures
      var failure = [configResp, brandsResp, stationsResp].find(function (resp) {
        return resp && resp.error;
      });
      if (failure) {
        throw new Error(failure.message || 'Request failed');
      }

      state.cfg = extractApiData(configResp) || {};
      state.brands = extractApiData(brandsResp) || [];
      state.stations = extractApiData(stationsResp) || [];

      // The dropdowns are filled from state.brands and state.cfg, so they are
      // populated here rather than at first render. Without this the brand
      // select was empty, every save failed the "select a brand" check, and the
      // manager and fuel-type lists were bare.
      populateBrandDropdown();
      populateManagerDropdown(state.editingId);
      populateFuelTypeDatalist();
    }).catch(function (err) {
      console.error('Failed to load dashboard data:', err);
      showToast('Failed to load dashboard data. Please try refreshing the page.', true);
    });
  }

  function extractApiData(response) {
    if (!response) return null;

    if (response &&
        typeof response === 'object' &&
        response.error !== undefined &&
        response.error !== null &&
        (typeof response.error === 'string' ||
         (typeof response.error === 'object' && response.error.message !== undefined))) {
      return null; // Indicate error
    }

    // Handle different response formats
    if (response.data !== undefined) {
      return response.data;
    }

    // If no explicit data field, assume the whole response is the data
    return response;
  }

  function redirectToLogin() {
    window.location.replace('/login');
  }

  /* ------------------------------------------------------------------ map init */

  /**
   * Adopts the Leaflet instance that map.js already built on this page.
   *
   * This file used to call L.map() on an element called #admin-map-view, which
   * does not exist in admin-map-ui.html - the map container there is #map, and
   * it is already owned by map.js. So initMap() bailed on its first line every
   * time, state.map stayed null, and every add-station code path behind it
   * (enterAddMode's cursor, the map click handler, the pin) was unreachable.
   *
   * Two Leaflet maps cannot share one container, so there is no "create a second
   * one" alternative: the admin layer has to attach to the existing instance.
   * That instance arrives asynchronously, hence the wait on PublicMapApi.ready.
   *
   * @returns {Promise<void>}
   */
  function initMap() {
    if (!state.mapReady) {
      showToast('Map could not be initialised. Please refresh the page.', true);
      return Promise.resolve(null);
    }

    return state.mapReady.then(function (map) {
      if (!map) {
        // map.js settled ready with no map, which means it failed. Its loader is
        // still up carrying the reason, so there is nothing useful to add.
        console.warn('The map did not become available; add-station is disabled.');
        return null;
      }

      state.bounds = map.getBounds();

      // The map click that drops a pin. Fires for clicks on empty map only; a
      // click on a pin is flagged and ignored (see handleMapClick).
      map.on('click', handleMapClick);

      return map;
    }).catch(function (err) {
      console.error('Error attaching to the map:', err);
      showToast('Could not initialize map. Please try refreshing the page.', true);
      return null;
    });
  }

  /**
   * Turns one of map.js's station pins into an editable one.
   *
   * Called by map.js in place of its own click handler, for every pin it draws.
   * The marker is the same object map.js keeps in its own registry, so the
   * legend filter, the station list and the search results keep operating on
   * the pin that is actually on the map.
   *
   * @param {Object} station The station the marker represents.
   * @param {L.Marker} marker The marker, created draggable for this page.
   */
  function onStationPin(station, marker) {
    if (!station || !marker) return;

    /*
     * Dragging is an administrator-only action: it PATCHes coordinates, which
     * StationPolicy::move refuses for a station manager. map.js creates every
     * pin draggable whenever an editor is present, so the permission is undone
     * here rather than by changing the shared public-map code.
     *
     * Disabling rather than ignoring dragend: with dragging off, Leaflet never
     * fires dragend at all, so there is no request to intercept and no way for
     * the pin to appear to move without being saved.
     */
    var mayMove = state.isAdmin || !state.user; // !state.user: before /auth/me resolves.
    if (!mayMove) {
      if (marker.dragging) marker.dragging.disable();
      // The pin still lifts on hover, which reads as interactive.
      if (marker.setZIndexOffset) marker.setZIndexOffset(0);
    }

    /*
     * A station manager's stations are highlighted: a pulsing ring around each
     * pin they are answerable for, drawn as a Leaflet divIcon so it needs no
     * extra marker and cannot be dragged around by accident. Every other pin
     * stays exactly as it is, which is what makes "mine" legible at a glance
     * without hiding the rest of the map. A manager may have several, so this
     * runs per pin rather than once.
     */
    if (!state.isAdmin && canEditStationPrices(station)) {
      marker.bindTooltip(
        '<span class="mine-ring"></span>',
        {
          permanent: true,
          interactive: false,
          direction: 'center',
          className: 'mine-marker',
          opacity: 1,
        }
      );
      // On top of everything, so the ring is not hidden under an adjacent pin.
      if (marker.setZIndexOffset) marker.setZIndexOffset(1000);
    }

    marker.on('click', function (e) {
      // Flag the originating event so the map-level click handler can tell a pin
      // click from a click on empty map. Leaflet does not stop the map's own
      // 'click' for marker clicks, so the flag is what separates the two -
      // without it, clicking a pin while armed opened the empty add dialog on
      // top of the station being inspected.
      if (e && e.originalEvent) e.originalEvent.__stationPinClick = true;

      if (!canEditStationPrices(station)) {
        // Someone else's station. The pin is not a dead end: it says why,
        // rather than doing nothing on click.
        showToast('You can only edit prices of your assigned stations', true);
        return;
      }

      openEditStationModal(station);
    });

    marker.on('dragend', function () {
      if (!state.isAdmin) {
        showToast('You can only edit prices of your assigned stations', true);
        return;
      }

      var pos = marker.getLatLng();
      updateStationPosition(station.id, pos.lat, pos.lng);
    });
  }

  /* ------------------------------------------------------------------ events */

  function bindEvents() {
    try {
      // The dashboard's own "Add station" button. Only present on admin.html;
      // here it is the header button further down.
      var addBtn = document.getElementById('btn-add');
      if (addBtn) {
        addBtn.addEventListener('click', function () { openAddStationModal(null, null); });
      }

      // Refresh button
      var refreshBtn = document.getElementById('btn-refresh');
      if (refreshBtn) {
        refreshBtn.addEventListener('click', loadData);
      }

      // Station form submission
      var stationForm = document.getElementById('station-form');
      if (stationForm) {
        stationForm.addEventListener('submit', function (e) {
          e.preventDefault();
          saveStation();
        });
      }

      // Add station button, in the header.
      //
      // Bound to a plain named function rather than an inline arrow: the arrow
      // closed over `this`, and a listener that throws is reported by the
      // browser as "Uncaught TypeError: Illegal invocation" with no hint that it
      // came from this handler - which is how a dead button looked identical to
      // a missing one.
      var addStationBtn = document.getElementById('btn-add-station-map');
      if (addStationBtn) {
        addStationBtn.addEventListener('click', toggleAddMode);
      }

      // Station manager dropdown. Choosing "+ New manager" reveals the account
      // fields underneath; leaving it hides them again.
      var managerSelect = document.getElementById('f-manager');
      if (managerSelect) {
        managerSelect.addEventListener('change', toggleNewManagerFields);
      }

      /*
       * The manager list is filtered by the station's brand, so changing the
       * Brand has to re-filter it. Without this the dropdown kept offering
       * managers of the old brand and the save was refused with a message about
       * a brand the administrator had just changed away from.
       */
      var brandSelect = document.getElementById('f-brand');
      if (brandSelect) {
        brandSelect.addEventListener('change', function () {
          populateManagerDropdown(state.editingId);
        });
      }

      // Header "Dashboard" link. Present only on the admin map.
      bindBackDashboard();

      // Price editor: the accordion header and the add-fuel button.
      var pricesToggle = document.getElementById('edit-prices-toggle');
      if (pricesToggle) {
        pricesToggle.addEventListener('click', function () {
          setEditPricesOpen(!isEditPricesOpen());
        });
      }
      var pricesAdd = document.getElementById('edit-prices-add');
      if (pricesAdd) {
        pricesAdd.addEventListener('click', function () {
          addEditPriceRow('', '');
          var last = document.querySelector('#edit-prices-rows .edit-price-row:last-child input[list="fuel-types"]');
          if (last) last.focus();
        });
      }

      // Station form real-time validation
      var latInput = document.getElementById('f-lat');
      var lngInput = document.getElementById('f-lng');
      if (latInput) {
        latInput.addEventListener('change', function () {
          validateCoords();
          syncPickerFromInputs();
        });
      }
      if (lngInput) {
        lngInput.addEventListener('change', function () {
          validateCoords();
          syncPickerFromInputs();
        });
      }

      // Price modal
      var priceAddBtn = document.getElementById('price-add');
      if (priceAddBtn) {
        priceAddBtn.addEventListener('click', function () {
          addPriceRow();
        });
      }

      var priceForm = document.getElementById('price-form');
      if (priceForm) {
        priceForm.addEventListener('submit', function (e) {
          e.preventDefault();
          savePrices();
        });
      }

      // Password modal
      var passwordForm = document.getElementById('password-form');
      if (passwordForm) {
        passwordForm.addEventListener('submit', function (e) {
          e.preventDefault();
          changePassword();
        });
      }

      // Logout button
      var logoutBtn = document.getElementById('btn-logout');
      if (logoutBtn) {
        logoutBtn.addEventListener('click', function () {
          Api.logout().then(function () {
            window.location.replace('/login');
          }).catch(function (err) {
            showToast('Logout failed', true);
            console.error('Logout error:', err);
          });
        });
      }

      // Password change button
      var passwordBtn = document.getElementById('btn-password');
      if (passwordBtn) {
        passwordBtn.addEventListener('click', openPasswordModal);
      }

      // Handle modal close buttons. Routed through requestCloseModals() rather than
      // closeModals() so an edited station dialog asks before being thrown away.
      document.querySelectorAll('[data-close]').forEach(function (btn) {
        btn.addEventListener('click', requestCloseModals);
      });

      // Close modals when clicking backdrop
      document.querySelectorAll('.modal-backdrop').forEach(function (el) {
        el.addEventListener('click', function (e) {
          if (e.target === el) requestCloseModals();
        });
      });

      // Escape. Closes any open dialog, and also stands add mode down on its own -
        // otherwise Escape only worked once a dialog happened to be open, so
        // cancelling a half-armed add mode (button showing "Cancel", crosshair
        // over the map) needed a click on the button instead.
        window.addEventListener('keydown', function (e) {
          if (e.key !== 'Escape') return;

          var anyModalOpen = Array.prototype.some.call(
            document.querySelectorAll('.modal-backdrop'),
            function (el) { return !el.hidden; }
          );

          if (anyModalOpen) {
            requestCloseModals();
          } else if (state.addMode) {
            exitAddMode();
          }
        });
    } catch (e) {
      console.error('Error binding event listeners:', e);
      showToast('Some UI features may not work correctly due to initialization error.', false);
    }
  }

  /* ------------------------------------------------------------------ add mode */

  function toggleAddMode() {
    if (state.addMode) {
      exitAddMode();
    } else {
      enterAddMode();
    }
  }

  function enterAddMode() {
    if (!state.map) {
      // No map means no crosshair and no place to click, so arming add mode
      // would only leave the UI claiming something it cannot do.
      showToast('Map not initialized. Please wait for map to load or refresh the page.', true);
      return;
    }

    state.addMode = true;

    // Clear any existing temp marker
    if (state.tempMarker && state.map) {
      state.map.removeLayer(state.tempMarker);
      state.tempMarker = null;
    }

    renderAddButton(true);

    // Set map cursor to crosshair
    state.map.getContainer().style.cursor = 'crosshair';

    // Show hint
    showAddModeHint();
  }

  function exitAddMode() {
    if (!state.map) {
      // Provide feedback if map is not initialized
      showToast('Map not initialized. Please wait for map to load or refresh the page.', true);
      return;
    }

    state.addMode = false;

    // Remove temporary marker if exists
    if (state.tempMarker) {
      state.map.removeLayer(state.tempMarker);
      state.tempMarker = null;
    }

    renderAddButton(false);

    // Reset map cursor
    state.map.getContainer().style.cursor = '';

    // Take the hint down with the mode. Left behind, it sat over the map
    // telling the admin to click, while the button beside it read "Add station".
    hideAddModeHint();
  }

  function handleMapClick(e) {
    // A click that lands on a pin is a selection, not a placement. Without this
    // guard Leaflet fires the map click after the marker's own click, so
    // clicking a station while armed for add mode opened the empty add form on
    // top of the station that was being inspected.
    if (e.originalEvent && e.originalEvent.__stationPinClick) {
      return;
    }

    if (!state.addMode) return;
    if (!state.map) {
      showToast('Map not initialized. Please wait for map to load or refresh the page.', true);
      return;
    }

    var lat = e.latlng.lat;
    var lng = e.latlng.lng;

    // Check if coordinates are within Bangued bounds
    if (state.cfg && state.cfg.stationBounds) {
      var bounds = state.cfg.stationBounds;
      if (
        lat < bounds.minLat ||
        lat > bounds.maxLat ||
        lng < bounds.minLon ||
        lng > bounds.maxLon
      ) {
        showToast('Coordinates must be within Bangued, Abra', true);
        return;
      }
    }

    // Remove existing temporary marker
    if (state.tempMarker) {
      state.map.removeLayer(state.tempMarker);
    }

    // Create new temporary draggable marker
    state.tempMarker = L.marker([lat, lng], {
      draggable: true,
      icon: L.divIcon({
        className: 'temp-pin',
        html: '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10" stroke-width="2"/><path d="M12 8v4m4-4H8"/></svg>',
        iconSize: [24, 24],
        iconAnchor: [12, 24]
      })
    }).addTo(state.map);

    // Update form fields when marker is dragged
    state.tempMarker.on('drag', function (event) {
      var pos = event.target.getLatLng();
      writeCoords(pos.lat, pos.lng);
    });

    // The coordinates are handed to openAddStationModal rather than written to
    // the fields first: the modal resets the form, so anything written before
    // the call was wiped again a line later and the add form opened with the
    // lat/lng boxes blank - which is exactly what a dropped pin must not do.
    openAddStationModal(lat, lng);

    // Focus on station name field for quick entry
    setTimeout(function () {
      var nameInput = document.getElementById('f-name');
      if (nameInput) nameInput.focus();
    }, 300);
  }

  /* ------------------------------------------------------------------ modals (shared with admin.js) */

  /**
   * Opens the station dialog for a new record.
   *
   * @param {?number} lat Latitude to pre-fill, when the pin was dropped on the
   *   map. Null leaves both coordinate boxes empty, which is how the dialog
   *   behaves when opened without a position.
   * @param {?number} lng Longitude to pre-fill.
   */
  function openAddStationModal(lat, lng) {
    clearErrors();
    state.editingId = null;
    var title = document.getElementById('station-modal-title');
    if (title) title.textContent = 'Add station';
    /*
     * A station being created has no id yet, so there is nothing for the price
     * sync to attach to and saveStation() sends no prices in that case. The
     * panel is hidden rather than shown-and-ignored: an editor that accepts
     * input and then discards it on save is worse than no editor. Prices are
     * added by opening the station again once it exists.
     */
    setEditPricesVisible(false);
    renderEditPrices(null);
    var form = document.getElementById('station-form');
    if (form) {
      form.reset();
      var brandSelect = form.elements['f-brand'];
      if (brandSelect) brandSelect.selectedIndex = 0; // Reset to placeholder
      var activeCheckbox = form.elements['f-active'];
      if (activeCheckbox) activeCheckbox.checked = true;
      writeCoords(lat == null ? '' : lat, lng == null ? '' : lng);

      // A new station has no manager to lose, so the guard is trivially
      // satisfied. Resetting it matters because the previous dialog may have
      // left it false, which would block an unrelated Add station save.
      trackExpectedManager(null);
    }

    // The picker has to be built before the modal is shown: it is created lazily
    // inside a hidden container, and a Leaflet map measured at zero width stays
    // zero width until invalidateSize(), so the dialog has to be opened first and
    // the size corrected on the next frame.
    openModal('station-modal');
    snapshotStationForm();
    ensurePicker().then(function () {
      writeCoords(lat == null ? '' : lat, lng == null ? '' : lng);
      refreshPickerSize();
    });
  }

  function openEditStationModal(station) {
    if (!station) return;
    clearErrors();
    state.editingId = station.id;

    // A station manager gets a prices-only dialog: the panel opens straight
    // away and the form column is greyed out, so the one thing they can change
    // is the thing on screen.
    var pricesOnly = !state.isAdmin;

    var title = document.getElementById('station-modal-title');
    if (title) {
      title.textContent = pricesOnly
        ? 'Edit prices'
        : 'Edit station';
    }

    setEditPricesVisible(true);
    setEditPricesOpen(pricesOnly);
    applyPricesOnlyMode(pricesOnly);
    renderEditPrices(station);
    var form = document.getElementById('station-form');
    if (!form) return;

    // Every field name here matches Station::toApiArray()
    form.elements['f-brand'].value = (station.brand && station.brand.slug) || '';
    form.elements['f-name'].value = station.name || '';
    form.elements['f-address'].value = station.address || '';
    form.elements['f-barangay'].value = station.barangay || '';
    form.elements['f-lat'].value = station.latitude !== null && station.latitude !== undefined ? parseFloat(station.latitude).toFixed(6) : '';
    form.elements['f-lng'].value = station.longitude !== null && station.longitude !== undefined ? parseFloat(station.longitude).toFixed(6) : '';
    form.elements['f-conf'].value = station.locationConfidence || 'approximate';
    form.elements['f-active'].checked = station.isActive !== false;

    // Also records whether the dropdown could hold this station's manager, so a
    // save cannot quietly unassign one that failed to load.
    trackExpectedManager(station.managerId);

    form.elements['f-phone'].value = station.contactPhone || '';
    form.elements['f-email'].value = station.contactEmail || '';
    form.elements['f-hours'].value = station.operatingHours || '';
    form.elements['f-notes'].value = station.notes || '';
    form.elements['f-osm'].value = station.osmRef || '';

    // Centre the picker on the station
    state.pickerTarget = station;
    syncPickerToTarget();
    ensurePicker().then(syncPickerToTarget);

    openModal('station-modal');
    snapshotStationForm();
    refreshPickerSize();
  }

  /**
   * Puts the dialog into (or out of) prices-only mode.
   *
   * The station fields are disabled rather than hidden: a manager can still see
   * what the station is, and a disabled control cannot be typed into or
   * submitted, which is the behaviour the server enforces anyway. The picker
   * pin stops being draggable for the same reason - the mini-map is a
   * reference, not an editor.
   *
   * @param {boolean} pricesOnly
   */
  function applyPricesOnlyMode(pricesOnly) {
    var form = document.getElementById('station-form');
    if (!form) return;

    // Every field in the station form, including the checkbox and the two
    // selects. Walked from the form itself so a field added later is covered.
    //
    // The footer save button is skipped: it carries form="station-form" (it
    // sits in the modal footer, outside the <form>), but HTML still lists
    // form-associated buttons in form.elements, so it would be walked here
    // like any other control and disabled. That leaves a station manager with
    // a Save button that is visible and yet cannot be clicked - exactly the
    // symptom of "change a price and the save button does nothing".
    Array.prototype.forEach.call(form.elements, function (el) {
      if (el.id === 'station-save') {
        return;
      }
      el.disabled = pricesOnly;
    });

    // The save button must always be clickable: for a manager it saves the
    // price payload alone, and for an admin it submits the full station form.
    var saveBtn = document.getElementById('station-save');
    if (saveBtn) {
      saveBtn.disabled = false;
      saveBtn.textContent = pricesOnly ? 'Save prices' : 'Save station';
      saveBtn.dataset.labelFor = saveBtn.textContent;
    }

    var note = document.getElementById('prices-only-note');
    if (note) note.hidden = !pricesOnly;

    if (state.pickerMarker && state.pickerMarker.dragging) {
      if (pricesOnly) {
        state.pickerMarker.dragging.disable();
      } else {
        state.pickerMarker.dragging.enable();
      }
    }
  }

  function openPriceModal(station) {
    if (!station) return;
    clearErrors();
    state.editingId = station.id;
    document.getElementById('price-modal-title').textContent = 'Fuel prices — ' + station.name;
    document.getElementById('price-sub').textContent =
      'Editing prices for ' + station.name + '. Leave effective date blank for current time.';

    var priceRows = document.getElementById('price-rows');
    if (priceRows) {
      priceRows.innerHTML = '';
    }

    var effectiveInput = document.getElementById('p-effective');
    if (effectiveInput) {
      effectiveInput.value = '';
    }

    var existing = Array.isArray(station.prices) ? station.prices : [];
    if (existing.length === 0) {
      addPriceRow();
    } else {
      existing.forEach(function (line) {
        if (!line) return;
        addPriceRow(line.fuelType || '', line.pricePerLiter);
      });
    }

    loadPriceHistory(station.id);
    openModal('price-modal');
  }

  var priceHistoryToken = 0;
  function loadPriceHistory(stationId) {
    var historyEl = document.getElementById('price-history');
    if (!historyEl) return;

    var token = ++priceHistoryToken;
    historyEl.innerHTML = '<p class="empty-note">Loading history…</p>';

    Api.priceHistory(stationId)
      .then(function (response) {
        if (token !== priceHistoryToken) return;
        var rows = response && response.data ? response.data : [];
        if (rows.length === 0) {
          historyEl.innerHTML = '<p class="empty-note">No price changes recorded yet.</p>';
          return;
        }
        var html = '<table class="data"><thead><tr>' +
          '<th>Fuel type</th><th>Previous</th><th>New</th><th>Changed at</th><th>By</th>' +
          '</tr></thead><tbody>';
        rows.forEach(function (row) {
          html += '<tr>' +
            '<td>' + escapeHtml(row.fuel_type) + '</td>' +
            '<td>' + (row.previous_price != null ? '₱' + Number(row.previous_price).toFixed(2) : '—') + '</td>' +
            '<td>₱' + Number(row.new_price).toFixed(2) + '</td>' +
            // changed_at is UTC with no zone marker, so printed raw it read
            // eight hours early for an admin in the Philippines. FmtTime.format
            // applies the UTC-by-convention rule, as the public map already does.
            '<td>' + (FmtTime.format(row.changed_at) || '—') + '</td>' +
            '<td>' + escapeHtml(row.changed_by || '') + '</td>' +
            '</tr>';
        });
        html += '</tbody></table>';
        historyEl.innerHTML = html;
      })
      .catch(function (err) {
        if (token !== priceHistoryToken) return;
        historyEl.innerHTML = '<p class="empty-note">Failed to load price history.</p>';
        console.error('Failed to load price history:', err);
      });
  }

  function openPasswordModal() {
    var form = document.getElementById('password-form');
    if (form) {
      form.reset();
    }
    closeModals();
    openModal('password-modal');
    var current = document.getElementById('pw-current');
    if (current) {
      current.focus();
    }
  }

  function changePassword() {
    var form = document.getElementById('password-form');
    if (!form) return;

    var current = form.elements['pw-current'].value;
    var newPass = form.elements['pw-new'].value;
    var confirm = form.elements['pw-confirm'].value;

    if (!current || !newPass || !confirm) {
      showPasswordError('Please fill in all fields');
      return;
    }

    if (newPass !== confirm) {
      showPasswordError('New passwords do not match');
      return;
    }

    if (newPass.length < 8) {
      showPasswordError('Password must be at least 8 characters');
      return;
    }

    // Disable button during request
    var saveBtn = document.getElementById('password-save');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';
    }

    Api.changePassword(current, newPass)
      .then(function () {
        closeModals();
        form.reset();
        showToast('Password changed successfully');
      })
      .catch(function (err) {
        showPasswordError(err.message || 'Failed to change password');
        console.error(err);
      })
      .finally(function () {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.textContent = 'Update password';
        }
      });
  }

  /**
   * Every interactive way out of a dialog: Cancel, the X, the backdrop and
   * Escape. Asks before discarding an edited station dialog, then closes.
   *
   * Distinct from closeModals(), which is also called on the success path of a
   * save and must never prompt - by then the work is saved, not discarded.
   */
  function requestCloseModals() {
    if (stationFormIsDirty() && !confirm('Discard changes?')) return;
    closeModals();
  }

  function clearErrors() {
    ['station-error', 'price-error', 'password-error'].forEach(function (id) {
      var el = document.getElementById(id);
      if (el) el.hidden = true;
    });
    // Per-row price messages live in the left column, not in any of the three
    // banners above, and they are stale the moment the dialog is reopened.
    clearEditPriceErrors();
  }

  function closeModals() {
    document.querySelectorAll('.modal-backdrop').forEach(function (el) {
      if (el) el.hidden = true;
    });
    clearErrors();

    // Every exit path - Cancel, the X, the backdrop, Escape and a successful
    // save - lands here, so the baseline is dropped here too. Nothing is left
    // to compare against, and stationFormIsDirty() stays false until the next
    // open repopulates it.
    state.stationFormSnapshot = null;

    // Saving a new station happens while add mode is still armed, and the
    // temporary pin must not survive the save. closeModals() is the single
    // teardown point for every exit path - Cancel, the X, the backdrop, Escape
    // and a successful save - so this is where add mode stands down.
    if (state.addMode) {
      exitAddMode();
    }
  }

  /**
   * Repaints everything that shows stations after a create, update or delete.
   *
   * The pins belong to map.js, so they are refreshed through its API rather
   * than by re-rendering locally: re-rendering here would produce a second copy
   * of every marker. The admin-side lists (the station table, the counters) are
   * reloaded from the admin endpoint, which also sees pending and hidden
   * records the public list omits.
   *
   * @returns {Promise<void>}
   */
  function refreshStationViews() {
    var reloadPins = window.PublicMapApi && window.PublicMapApi.reloadStations
      ? window.PublicMapApi.reloadStations()
      : Promise.resolve();

    return reloadPins
      .catch(function (err) {
        // The save itself succeeded; a stale pin is not worth an error state.
        console.error('Failed to refresh map pins:', err);
      })
      .then(function () {
        return loadData();
      });
  }

  function openModal(modalId) {
    var modal = document.getElementById(modalId);
    if (modal) {
      modal.hidden = false;
    }
  }

  /* ------------------------------------------- unsaved-work guard (header back link) */

  /**
   * Every editable value in the station dialog, as one comparable string.
   *
   * Form.elements is walked in document order and includes checkboxes and
   * selects, so a field added to the dialog is covered without touching this.
   * The price rows are appended, so a price edit counts as an unsaved change
   * even when the station fields themselves are untouched.
   *
   * @returns {string}
   */
  function readStationForm() {
    var form = document.getElementById('station-form');
    if (!form) return '';

    var values = Array.prototype.map.call(form.elements, function (el) {
      if (el.type === 'checkbox') return el.checked ? '1' : '0';
      return el.value == null ? '' : String(el.value);
    });

    document.querySelectorAll('#edit-prices-rows .edit-price-row').forEach(function (row) {
      var fuel = row.querySelector('input[list="fuel-types"]');
      var price = row.querySelector('input[type="number"]');
      values.push(fuel ? fuel.value : '');
      values.push(price ? price.value : '');
    });

    return values.join('\u0000');
  }

  /**
   * Records the dialog's current contents as the clean baseline.
   *
   * Called once the dialog has been populated - after openAddStationModal() or
   * openEditStationModal() has filled the fields - so that pre-filled
   * coordinates and the pre-selected brand do not read as edits.
   */
  function snapshotStationForm() {
    state.stationFormSnapshot = readStationForm();
  }

  /**
   * Whether the station dialog is on screen with edits in it.
   *
   * Requires both that the dialog is open and that the fields differ from the
   * snapshot, so cancelling without typing does not prompt.
   *
   * @returns {boolean}
   */
  function stationFormIsDirty() {
    var modal = document.getElementById('station-modal');
    if (!modal || modal.hidden) return false;
    if (state.stationFormSnapshot === null) return false;
    return readStationForm() !== state.stationFormSnapshot;
  }

  /**
   * Warns before the header's "Dashboard" link discards in-progress work.
   *
   * The link itself is a plain <a href>, so middle-click, right-click and
   * "copy link address" keep working; only an unmodified left click is
   * intercepted here. Add mode counts as unsaved work because a pin may already
   * have been dropped onto the map.
   */
  function bindBackDashboard() {
    var link = document.getElementById('back-dashboard');
    if (!link) return;

    link.addEventListener('click', function (e) {
      // Modified clicks (new tab, new window, download) are left to the browser:
      // this page's state would not be discarded by opening the dashboard
      // elsewhere, and cancelling them would break the link's own affordances.
      if (e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

      if (!state.addMode && !stationFormIsDirty()) return;

      if (!confirm('Leave without saving?')) {
        e.preventDefault();
      }
    });
  }

  /* ------------------------------------------------------------------ form helpers */

  function validateCoords() {
    var latInput = document.getElementById('f-lat');
    var lngInput = document.getElementById('f-lng');
    var errorEl = document.getElementById('station-error');

    if (!latInput || !lngInput || !errorEl) return false;

    var lat = parseFloat(latInput.value);
    var lng = parseFloat(lngInput.value);

    // Clear previous error
    errorEl.hidden = true;

    // Check if empty (allowed - placeholder station)
    if (latInput.value === '' && lngInput.value === '') {
      return true;
    }

    // Validate latitude
    if (isNaN(lat) || lat < -90 || lat > 90) {
      showStationError('Latitude must be between -90 and 90');
      latInput.focus();
      return false;
    }

    // Validate longitude
    if (isNaN(lng) || lng < -180 || lng > 180) {
      showStationError('Longitude must be between -180 and 180');
      lngInput.focus();
      return false;
    }

    // Check Bangued bounds if coordinates provided
    if (state.cfg && state.cfg.stationBounds) {
      var bounds = state.cfg.stationBounds;
      if (
        lat < bounds.minLat ||
        lat > bounds.maxLat ||
        lng < bounds.minLon ||
        lng > bounds.maxLon
      ) {
        showStationError('Coordinates must be within Bangued, Abra');
        return false;
      }
    }

    return true;
  }

  function showStationError(message) {
    var errorEl = document.getElementById('station-error');
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.hidden = false;
    }
  }

  function showPriceError(message) {
    var errorEl = document.getElementById('price-error');
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.hidden = false;
    }
  }

  function showPasswordError(message) {
    var errorEl = document.getElementById('password-error');
    if (errorEl) {
      errorEl.textContent = message;
      errorEl.hidden = false;
    }
  }

  function saveStation() {
    var form = document.getElementById('station-form');
    if (!form) return;

    /*
     * A station manager is refused every station field by the server, and the
     * fields are disabled so there is nothing to send. Rather than send the
     * whole form anyway and have the request 403, this short-circuits to the
     * price payload alone - the same body the server would have whittled the
     * request down to anyway.
     */
    if (!state.isAdmin) {
      saveStationPricesOnly();
      return;
    }

    var brandSlug = form.elements['f-brand'].value;
    var stationName = form.elements['f-name'].value.trim();

    // Validate
    if (!brandSlug) {
      showStationError('Please select a brand');
      return;
    }
    if (!stationName) {
      showStationError('Please enter a station name');
      return;
    }

    var lat = form.elements['f-lat'].value.trim();
    var lng = form.elements['f-lng'].value.trim();
    var hasCoords = lat !== '' && lng !== '';

    /*
     * The form is novalidate, so the browser's own type="email" check never runs,
     * and the server only trims and length-limits contactEmail. Left unchecked
     * here, "not-an-email" saved as happily as a real address.
     */
    var email = form.elements['f-email'].value.trim();
    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showStationError('Please enter a valid contact email, or leave it blank');
      return;
    }

    // Validate coordinates if provided
    if (hasCoords) {
      if (!validateCoords()) {
        return;
      }
    }

    /*
     * The body keys are exactly the ones AdminApiController reads. They are
     * camelCase, and the previous snake_case names (brand_id, contact_phone,
     * is_active, ...) were silently dropped by the controller - which meant
     * brandId was always missing and every save came back 400 "Brand is
     * required."
     */
    var data = {
      name: stationName,
      address: form.elements['f-address'].value.trim() || null,
      barangay: form.elements['f-barangay'].value.trim() || null,
      latitude: hasCoords ? parseFloat(lat) : null,
      longitude: hasCoords ? parseFloat(lng) : null,
      contactPhone: form.elements['f-phone'].value.trim() || null,
      contactEmail: email || null,
      operatingHours: form.elements['f-hours'].value.trim() || null,
      notes: form.elements['f-notes'].value.trim() || null,
      isActive: form.elements['f-active'].checked,
      osmRef: form.elements['f-osm'].value.trim() || null,
    };

    // Refuse a save whose manager dropdown never held the station's manager,
    // rather than sending managerId: null and unassigning them silently.
    if (!managerSelectionIsSafe()) {
      return;
    }

    /*
     * The manager choice, which may also CREATE the account. Sent in the same
     * request as the station, so an account is never created without the
     * station it was made for.
     */
    var managerChoice = collectManagerChoice();
    if (managerChoice) {
      if (managerChoice.newManager) {
        // Cheap client-side checks so an obviously incomplete form does not
        // need a round trip; the server repeats both.
        if (!managerChoice.newManager.email) {
          showStationError('Enter an email address for the new manager.');
          return;
        }
        if (!managerChoice.newManager.password || managerChoice.newManager.password.length < 8) {
          showStationError('The new manager needs a temporary password of at least 8 characters.');
          return;
        }
      }

      Object.assign(data, managerChoice);
    }

    // The dropdown carries the brand slug, but the API keys brands by id.
    var brand = state.brands.find(function (b) {
      return b && b.slug === brandSlug;
    });
    if (!brand) {
      showStationError('Please select a brand');
      return;
    }
    data.brandId = brand.id;

    /*
     * locationConfidence is only meaningful alongside coordinates: a station
     * with none is stored as pending regardless of what the select says, so it
     * is left out unless there is a position to describe.
     */
    if (hasCoords) {
      data.locationConfidence = form.elements['f-conf'].value;
    }

    /*
     * Prices ride along in the same request as the station fields, so one Save
     * station button commits both and they cannot half-apply. The server syncs
     * the set inside the same transaction as the station row.
     *
     * Only for an existing station. A station being created has no id yet, so
     * there is nothing for the price sync to attach to - and the panel is hidden
     * in that dialog anyway (see openAddStationModal).
     */
    var collected = { prices: [], errors: {} };
    if (state.editingId) {
      collected = collectEditPrices();
      if (Object.keys(collected.errors).length > 0) {
        clearEditPriceErrors();
        showEditPriceError('Fix the highlighted price rows.', { rows: collected.errors });
        return;
      }
      // An empty array is meaningful here and is not the same as omitting the
      // key: it clears every stored price for this station.
      data.prices = collected.prices;
    }

    var savePromise;
    if (state.editingId) {
      // Update existing
      savePromise = Api.updateStation(state.editingId, data);
    } else {
      // Create new
      savePromise = Api.createStation(data);
    }

    // Disable form during save to prevent duplicate submissions
    var saveBtn = document.getElementById('station-save');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';
    }

    savePromise
      .then(function () {
        closeModals();
        return refreshStationViews();
      })
      .then(function () {
        showToast(state.isAdmin ? 'Station saved successfully' : 'Prices saved successfully');
      })
      .catch(function (err) {
        /*
         * A 403 is the server refusing an action the role may not take. It gets
         * the friendly wording from the requirements rather than the policy's
         * terser one, and it auto-expands the price panel so a manager who hit
         * it while the panel was collapsed can see why.
         */
        if (err.status === 403) {
          setEditPricesOpen(true);
          showToast('You can only edit prices of your assigned stations', true);
          return;
        }

        // A 400 carrying per-row price details belongs in the price panel, not
        // in the station form's banner - and the panel expands itself so the
        // message is visible rather than hidden inside a collapsed column.
        if (state.editingId && err.details && err.details.rows) {
          showEditPriceError(err.message || 'Failed to save prices', err.details);
        } else {
          showStationError(err.message || 'Failed to save station');
        }
        console.error(err);
      })
      .finally(function () {
        // Re-enable form
        if (saveBtn) {
          saveBtn.disabled = false;
          // Restored from the dataset rather than a literal: the button is
          // relabelled for a prices-only dialog, and writing "Save station"
          // back would be a lie for a manager.
          saveBtn.textContent = saveBtn.dataset.labelFor || 'Save station';
        }
      });
  }

  /**
   * Saves only the fuel prices of the station being edited.
   *
   * The station-manager path. It uses PUT /prices rather than the combined
   * PATCH, which is the endpoint a manager is meant to use and which refuses an
   * empty list - so this cannot be used to delete every price row, and the
   * panel's own rule is enforced here instead.
   */
  function saveStationPricesOnly() {
    var stationId = state.editingId;
    if (!stationId) {
      showEditPriceError('No station selected for price update.');
      return;
    }

    var collected = collectEditPrices();
    if (Object.keys(collected.errors).length > 0) {
      clearEditPriceErrors();
      showEditPriceError('Fix the highlighted price rows.', { rows: collected.errors });
      return;
    }

    if (collected.prices.length === 0) {
      showEditPriceError('Enter at least one fuel type and price before saving.');
      return;
    }

    var saveBtn = document.getElementById('station-save');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';
    }

    Api.savePrices(stationId, collected.prices, null)
      .then(function () {
        closeModals();
        return refreshStationViews();
      })
      .then(function () {
        showToast('Prices saved successfully');
      })
      .catch(function (err) {
        if (err.status === 403) {
          setEditPricesOpen(true);
          showToast('You can only edit prices of your assigned stations', true);
          return;
        }

        if (err.details && err.details.rows) {
          showEditPriceError(err.message || 'Failed to save prices', err.details);
        } else {
          showEditPriceError(err.message || 'Failed to save prices');
        }
        console.error(err);
      })
      .finally(function () {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.textContent = saveBtn.dataset.labelFor || 'Save prices';
        }
      });
  }

  function savePrices() {
    var priceRows = document.getElementById('price-rows');
    if (!priceRows) return;

    var stationId = state.editingId;
    if (!stationId) {
      showPriceError('No station selected for price update');
      return;
    }

    var prices = [];
    var rows = priceRows.querySelectorAll('.price-row');
    rows.forEach(function (row) {
      var fuelType = row.querySelector('input[list="fuel-types"]');
      var priceInput = row.querySelector('input[type="number"]');
      if (fuelType && priceInput && fuelType.value.trim() && priceInput.value.trim()) {
        prices.push({
          fuelType: fuelType.value.trim(),
          pricePerLiter: parseFloat(priceInput.value),
        });
      }
    });

    // PUT /prices replaces the whole set, so an empty submission is refused
    // outright rather than being allowed to wipe every stored price.
    if (prices.length === 0) {
      showPriceError('Enter at least one fuel type and price before saving.');
      return;
    }

    var effectiveAtInput = document.getElementById('p-effective');
    var effectiveAt = effectiveAtInput && effectiveAtInput.value
      ? FmtTime.toDbString(FmtTime.fromLocalInput(effectiveAtInput.value))
      : null;

    var saveBtn = document.getElementById('price-save');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.textContent = 'Saving...';
    }

    Api.savePrices(stationId, prices, effectiveAt)
      .then(function () {
        closeModals();
        return refreshStationViews();
      })
      .then(function () {
        showToast('Prices saved successfully');
      })
      .catch(function (err) {
        showPriceError(err.message || 'Failed to save prices');
        console.error(err);
      })
      .finally(function () {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.textContent = 'Save prices';
        }
      });
  }

  function coordinateText(value) {
    if (value === '' || value === null || value === undefined) return '';
    var n = Number(value);
    return isNaN(n) ? '' : n.toFixed(6);
  }

  function writeCoords(lat, lng) {
    var latInput = document.getElementById('f-lat');
    var lngInput = document.getElementById('f-lng');
    if (latInput) latInput.value = coordinateText(lat);
    if (lngInput) lngInput.value = coordinateText(lng);
  }

  function syncPickerFromInputs() {
    if (!state.pickerMarker) return;

    var latInput = document.getElementById('f-lat');
    var lngInput = document.getElementById('f-lng');
    if (!latInput || !lngInput) return;

    var lat = parseFloat(latInput.value);
    var lng = parseFloat(lngInput.value);
    if (isNaN(lat) || isNaN(lng)) return;
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return;

    state.pickerMarker.setLatLng([lat, lng]);
  }

  function syncPickerToTarget() {
    if (!state.picker) return;

    var station = state.pickerTarget;
    var hasCoords = station &&
      station.latitude !== null && station.latitude !== undefined &&
      station.longitude !== null && station.longitude !== undefined;

    var lat = hasCoords ? parseFloat(station.latitude) : 17.5965;
    var lng = hasCoords ? parseFloat(station.longitude) : 120.6167;

    state.picker.setView([lat, lng], hasCoords ? 16 : 14);
    if (state.pickerMarker) {
      state.pickerMarker.setLatLng([lat, lng]);
    }
  }

  /* ---------------------------------------------------------------- price editor (left column) */

  /**
   * Rebuilds the price editor for a station.
   *
   * The station's stored prices become one row each; a station with none starts
   * on the empty state rather than an empty row, so "+ Add fuel" is the only
   * thing an admin has to reach for. Reuses the same #fuel-types datalist the
   * dashboard's Prices modal is fed from, so the suggestions cannot drift.
   *
   * @param {?Object} station The station being edited, or null when creating one.
   */
  function renderEditPrices(station) {
    var rows = document.getElementById('edit-prices-rows');
    if (!rows) return;

    rows.innerHTML = '';
    clearEditPriceErrors();

    var existing = station && Array.isArray(station.prices) ? station.prices : [];
    existing.forEach(function (line) {
      if (!line) return;
      addEditPriceRow(line.fuelType || '', line.pricePerLiter);
    });

    updateEditPricesEmptyState();
  }

  /**
   * Appends one fuel/price row to the editor.
   *
   * The stored fuel type and price are assigned as properties rather than baked
   * into innerHTML, so a value from the database can never be injected as
   * markup.
   *
   * @param {?string} fuelType
   * @param {?number|string} pricePerLiter
   */
  function addEditPriceRow(fuelType, pricePerLiter) {
    var rows = document.getElementById('edit-prices-rows');
    if (!rows) return;

    try {
      var row = document.createElement('div');
      row.className = 'edit-price-row';
      row.innerHTML =
        '<div class="field">' +
        '<label>Fuel type <span class="req">*</span></label>' +
        '<input type="text" list="fuel-types" maxlength="60" required>' +
        '</div>' +
        '<div class="field">' +
        '<label>Price <span class="req">*</span></label>' +
        '<input type="number" step="0.01" min="0" max="10000" inputmode="decimal" required>' +
        '</div>' +
        '<button type="button" class="edit-price-remove" aria-label="Remove this fuel">&times;</button>' +
        // Sits outside the three grid tracks (grid-column below), so it never
        // narrows the inputs on the row above it.
        '<p class="edit-price-error" hidden></p>';

      rows.appendChild(row);

      var fuelInput = row.querySelector('input[list="fuel-types"]');
      var priceInput = row.querySelector('input[type="number"]');
      if (fuelInput && fuelType != null) fuelInput.value = fuelType;
      if (priceInput && pricePerLiter != null) priceInput.value = pricePerLiter;

      row.querySelector('.edit-price-remove').addEventListener('click', function () {
        rows.removeChild(row);
        updateEditPricesEmptyState();
        clearEditPriceErrors();
      });

      // Clearing the message as soon as the admin edits the row it belongs to:
      // an error describing the old value is worse than none.
      row.addEventListener('input', function () {
        var err = row.querySelector('.edit-price-error');
        if (err) err.hidden = true;
      });

      updateEditPricesEmptyState();
    } catch (e) {
      console.error('Error adding edit price row:', e);
    }
  }

  /** Shows the empty state only while the editor holds no rows. */
  function updateEditPricesEmptyState() {
    var rows = document.getElementById('edit-prices-rows');
    var empty = document.getElementById('edit-prices-empty');
    if (!rows || !empty) return;
    empty.hidden = rows.children.length > 0;
  }

  /** Expands or collapses the price panel. */
  function setEditPricesOpen(open) {
    var panel = document.getElementById('edit-prices');
    var toggle = document.getElementById('edit-prices-toggle');
    if (!panel) return;

    panel.classList.toggle('is-open', open);
    // aria-expanded is what carries the state for a screen reader; the chevron
    // rotation alone does not.
    if (toggle) toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function isEditPricesOpen() {
    var panel = document.getElementById('edit-prices');
    return !!(panel && panel.classList.contains('is-open'));
  }

  /**
   * Shows or hides the whole panel.
   *
   * Only used for the create dialog, which has no station to attach prices to
   * yet. Hidden rather than disabled so it does not take a column's width on a
   * dialog whose form is the only thing being edited.
   */
  function setEditPricesVisible(visible) {
    var panel = document.getElementById('edit-prices');
    if (!panel) return;

    panel.hidden = !visible;
    if (!visible) setEditPricesOpen(false);
  }

  /** Clears every per-row message and the panel-level one. */
  function clearEditPriceErrors() {
    document.querySelectorAll('#edit-prices-rows .edit-price-error').forEach(function (el) {
      el.textContent = '';
      el.hidden = true;
    });
    var summary = document.getElementById('edit-prices-error');
    if (summary) {
      summary.textContent = '';
      summary.hidden = true;
    }
  }

  /**
   * Shows a price validation failure, per row where the server said which.
   *
   * The panel is expanded first: a message inside a collapsed panel is an
   * error the admin cannot see, which is how the "Enter at least one fuel type"
   * failure used to read as a save that did nothing.
   *
   * @param {string} message
   * @param {?Object} [details] HttpError details, expected to carry `rows`.
   */
  function showEditPriceError(message, details) {
    setEditPricesOpen(true);

    var rowsByIndex = details && details.rows && typeof details.rows === 'object'
      ? details.rows
      : null;
    var placed = 0;

    if (rowsByIndex) {
      Object.keys(rowsByIndex).forEach(function (index) {
        var row = document.querySelectorAll('#edit-prices-rows .edit-price-row')[Number(index)];
        var slot = row && row.querySelector('.edit-price-error');
        if (!slot) return;
        slot.textContent = rowsByIndex[index];
        slot.hidden = false;
        placed += 1;
      });
    }

    var summary = document.getElementById('edit-prices-error');
    if (summary) {
      // With every message already sitting under its own row, a repeated
      // summary would just be the same text twice.
      summary.textContent = placed > 0 && placed === Object.keys(rowsByIndex || {}).length ? '' : message;
      summary.hidden = !summary.textContent;
    }
  }

  /**
   * Reads the editor into the payload the combined save sends.
   *
   * Client-side checks mirror the server's: a row with nothing in it is skipped
   * (it is an abandoned draft, not a fuel type), while a row with a fuel type
   * but a blank or negative price is an error the admin needs to see. The
   * server re-checks everything regardless, since this is a convenience layer
   * and not a trust boundary.
   *
   * @returns {{prices: Array<Object>, errors: Object<string, string>}}
   */
  function collectEditPrices() {
    var prices = [];
    var errors = {};
    var seen = {};

    var rows = document.querySelectorAll('#edit-prices-rows .edit-price-row');
    rows.forEach(function (row, index) {
      var fuelInput = row.querySelector('input[list="fuel-types"]');
      var priceInput = row.querySelector('input[type="number"]');
      if (!fuelInput || !priceInput) return;

      var fuelType = fuelInput.value.trim();
      var priceText = priceInput.value.trim();

      // An entirely blank row is an abandoned draft; ignore it silently.
      if (!fuelType && !priceText) return;

      if (!fuelType) {
        errors[index] = 'Enter a fuel type.';
        return;
      }

      var key = fuelType.toLowerCase();
      if (seen[key]) {
        errors[index] = 'Duplicate fuel type "' + fuelType + '".';
        return;
      }
      seen[key] = true;

      if (priceText === '') {
        errors[index] = 'Enter a price for "' + fuelType + '".';
        return;
      }

      var price = Number(priceText);
      if (!isFinite(price) || price < 0) {
        errors[index] = 'Price for "' + fuelType + '" must be a number of 0 or more.';
        return;
      }
      if (price > 10000) {
        errors[index] = 'Price for "' + fuelType + '" looks wrong (max 10000).';
        return;
      }

      prices.push({ fuelType: fuelType, pricePerLiter: price });
    });

    return { prices: prices, errors: errors };
  }

  /* ------------------------------------------------------------------ coordinate picker (for modals) */

  /**
   * Builds the OpenStreetMap coordinate picker, once.
   *
   * Called when a station form opens. Safe to call repeatedly: the promise is
   * cached, so opening ten station forms still builds one map.
   *
   * @returns {Promise<void>}
   */
  function ensurePicker() {
    if (state.pickerPromise) return state.pickerPromise;

    state.pickerPromise = new Promise(function (resolve) {
      // Only reachable if leaflet.js failed to load. The lat/lng fields are
      // independent of the map, so the station is still editable.
      if (!window.L) {
        showPickerUnavailable();
        showToast('Could not load the map - type coordinates by hand instead.', true);
        resolve();
        return;
      }

      initOsmPicker();
      resolve();
    });

    return state.pickerPromise;
  }

  /**
   * Replaces the coordinate picker with a notice, for when Leaflet itself could
   * not be loaded. The station form still works - coordinates are typed into the
   * latitude/longitude fields by hand.
   */
  function showPickerUnavailable() {
    var pickerContainer = document.getElementById('picker');
    if (pickerContainer) {
      // The two classes are the ones admin.css already styles for this state
      // (.picker-map.picker-error). The bare "map-placeholder" it used before is
      // not defined in admin.css, so the notice rendered as unstyled text
      // jammed into the corner of the grey box.
      pickerContainer.innerHTML =
        '<div class="picker-map picker-error">Map unavailable - the map library did not load. '
        + 'Type the coordinates below instead.</div>';
    }
  }

  /**
   * Coordinate picker backed by OpenStreetMap via Leaflet.
   *
   * No API key, no billing account and no quota, which is the whole reason the
   * picker runs on Leaflet rather than Google Maps.
   */
  function initOsmPicker() {
    var mapDiv = document.getElementById('picker');
    if (!mapDiv || state.picker) return;

    try {
      state.picker = L.map(mapDiv, {
        center: [17.5965, 120.6167],
        zoom: 14,
        attributionControl: true,
      });

      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 21,
        attribution: '&copy; OpenStreetMap contributors',
      }).addTo(state.picker);

      // The draggable pin carries the icon explicitly. Left to itself Leaflet
      // uses its bundled blue teardrop, which is drawn from an image under
      // public/vendor/leaflet/images/ and renders as a broken image when the
      // admin page is served from a different path.
      state.pickerMarker = L.marker([17.5965, 120.6167], {
        draggable: true,
        icon: pickerPinIcon(),
        keyboard: false,
      }).addTo(state.picker);

      state.pickerMarker.on('dragend', function () {
        var pos = state.pickerMarker.getLatLng();
        writeCoords(pos.lat, pos.lng);
      });

      state.picker.on('click', function (e) {
        writeCoords(e.latlng.lat, e.latlng.lng);
        state.pickerMarker.setLatLng(e.latlng);
      });
    } catch (e) {
      console.error('Error initializing Leaflet picker:', e);
      showPickerUnavailable();
    }
  }

  function pickerPinIcon() {
    var pin = MapIcons.redPin({});
    return L.divIcon({
      className: 'station-pin',
      html: pin.markup,
      iconSize: [pin.width, pin.height],
      iconAnchor: pin.anchor,
    });
  }

  function populateBrandDropdown() {
    var brandSelect = document.getElementById('f-brand');
    if (!brandSelect) return;

    try {
      brandSelect.innerHTML = '<option value="">Select brand</option>';

      // Sort brands by sort_order then name
      var sortedBrands = state.brands.slice().sort(function (a, b) {
        return (a.sortOrder || 0) - (b.sortOrder || 0) ||
               String(a.name || '').localeCompare(String(b.name || ''));
      });

      sortedBrands.forEach(function (brand) {
        if (!brand || !brand.slug) return;
        var option = document.createElement('option');
        option.value = brand.slug;
        option.textContent = brand.name || brand.slug;
        brandSelect.appendChild(option);
      });
    } catch (e) {
      console.error('Error populating brand dropdown:', e);
      brandSelect.innerHTML = '<option value="">Error loading brands</option>';
    }
  }

  function populateFuelTypeDatalist() {
    // fuelTypes, not fuel_types: /api/admin/config answers camelCase, like the
    // rest of the API. Reading the snake_case name left the datalist empty, so
    // every fuel-type input had no autocomplete at all.
    var fuelTypesEl = document.getElementById('fuel-types');
    if (!fuelTypesEl || !state.cfg || !state.cfg.fuelTypes) return;

    try {
      fuelTypesEl.innerHTML = '';
      state.cfg.fuelTypes.forEach(function (type) {
        if (!type) return;
        var option = document.createElement('option');
        option.value = type;
        fuelTypesEl.appendChild(option);
      });
    } catch (e) {
      console.error('Error populating fuel type datalist:', e);
    }
  }

  /**
   * Fills the Station manager dropdown.
   *
   * One manager may handle SEVERAL stations, all of the same brand, so the list
   * is filtered by BRAND rather than by availability: a manager is offered when
   * they have no stations yet (they adopt this brand) or when every station they
   * hold is already this brand.
   *
   * Managers of another brand are left out entirely rather than shown-and-
   * rejected: offering them and refusing on save is what made "Save station"
   * look broken.
   *
   * Mirrors admin.js: the two pages carry separate copies of this dialog, and a
   * manager can reach both.
   *
   * @param {?number} currentStationId The station being edited, for a new station null.
   */
  function populateManagerDropdown(currentStationId) {
    var managerSelect = document.getElementById('f-manager');
    if (!managerSelect) return;

    // Keep the current value across the re-render, so changing the Brand does
    // not silently clear a manager the administrator already picked.
    var previous = managerSelect.value;

    var brandSelect = document.getElementById('f-brand');
    var brandSlug = brandSelect ? brandSelect.value : '';
    var brand = null;
    (state.brands || []).forEach(function (b) {
      if (b && b.slug === brandSlug) brand = b;
    });

    try {
      managerSelect.innerHTML = '';

      var none = document.createElement('option');
      none.value = '';
      none.textContent = 'None (Unassigned)';
      managerSelect.appendChild(none);

      var managers = (state.cfg && state.cfg.managers) ? state.cfg.managers : [];
      managers.forEach(function (manager) {
        if (!manager || !manager.id) return;

        var eligible = eligibleManagersFor(brand, manager);
        // The current manager stays listed even after the brand moves away from
        // theirs: the save refuses with a message naming the mismatch, which is
        // clearer than the selection vanishing mid-edit.
        var isCurrent = currentStationId != null
          && Array.isArray(manager.stationIds)
          && manager.stationIds.indexOf(Number(currentStationId)) !== -1;

        if (!eligible && !isCurrent) return;

        var option = document.createElement('option');
        option.value = String(manager.id);
        option.textContent = managerOptionLabel(manager);
        managerSelect.appendChild(option);
      });

      var newOption = document.createElement('option');
      newOption.value = NEW_MANAGER_VALUE;
      newOption.textContent = '+ New manager…';
      managerSelect.appendChild(newOption);
    } catch (e) {
      console.error('Error populating manager dropdown:', e);
      managerSelect.innerHTML = '<option value="">None (Unassigned)</option>';
    }

    managerSelect.value = (previous && hasOption(managerSelect, previous)) ? previous : '';

    toggleNewManagerFields();
  }

  /**
   * "Juan Dela Cruz (Blu Gas, 3 stations)" - the brand they handle and how many,
   * so an administrator can see they are adding a fourth rather than a first.
   */
  function managerOptionLabel(manager) {
    var name = manager.displayName || manager.username || 'Manager';
    var count = Array.isArray(manager.stationIds) ? manager.stationIds.length : (manager.stationCount || 0);
    var brand = manager.brandName || '';

    var parts = [];
    if (brand) parts.push(brand);
    parts.push(count === 1 ? '1 station' : count + ' stations');

    return name + ' (' + parts.join(', ') + ')';
  }

  /** Whether a manager may be given a station of this brand. */
  function eligibleManagersFor(brand, manager) {
    // No brand chosen yet in the dialog: nothing to filter on.
    if (!brand) return true;
    // A manager with no stations yet may take any brand, and adopts it.
    if (!manager.brandId) return true;

    return Number(manager.brandId) === Number(brand.id);
  }

  function hasOption(select, value) {
    return Array.prototype.some.call(select.options, function (option) {
      return option.value === value;
    });
  }

  /**
   * Shows or hides the "create a manager" fields, following the dropdown.
   */
  function toggleNewManagerFields() {
    var managerSelect = document.getElementById('f-manager');
    var block = document.getElementById('new-manager-fields');
    if (!managerSelect || !block) return;

    var creating = managerSelect.value === NEW_MANAGER_VALUE;
    block.hidden = !creating;

    if (creating) {
      var email = document.getElementById('f-manager-email');
      if (email) email.focus();
    }
  }

  /**
   * Records which manager the dialog is supposed to show, and whether the
   * dropdown could actually hold it.
   *
   * A <select> given a value with no matching option silently becomes EMPTY.
   * That empty value was then read back as "the administrator chose None", so
   * opening a station and pressing Save would remove its manager - no error, no
   * visible cause. This keeps the expected value beside the real one so the
   * save can refuse instead.
   *
   * @param {?number} managerId The station's managerId, or null for a new station.
   */
  function trackExpectedManager(managerId) {
    state.expectedManagerId = managerId == null ? '' : String(managerId);

    var managerSelect = document.getElementById('f-manager');
    if (!managerSelect) {
      state.managerSelectionLoaded = true;
      return;
    }

    managerSelect.value = state.expectedManagerId;

    // An empty expected value always matches: "no manager" is a real choice.
    state.managerSelectionLoaded = managerSelect.value === state.expectedManagerId;

    toggleNewManagerFields();
  }

  /**
   * Refuses a save whose manager dropdown never held the station's manager.
   *
   * @returns {boolean} True when the save may proceed.
   */
  function managerSelectionIsSafe() {
    if (state.managerSelectionLoaded) return true;

    showStationError(
      'The station manager dropdown could not be filled in, so saving now would '
      + 'silently remove the current manager. Reload the page and try again.'
    );

    return false;
  }

  /**
   * The manager half of the save payload, which may also CREATE the account.
   *
   * @returns {?Object} Either { managerId } or { newManager: {...} }, or null.
   */
  function collectManagerChoice() {
    var managerSelect = document.getElementById('f-manager');
    if (!managerSelect) return null;

    var value = managerSelect.value;
    if (!value) {
      return { managerId: null };
    }

    if (value === NEW_MANAGER_VALUE) {
      var name = document.getElementById('f-manager-name');
      var email = document.getElementById('f-manager-email');
      var password = document.getElementById('f-manager-password');

      return {
        newManager: {
          name: name ? name.value.trim() : null,
          email: email ? email.value.trim() : '',
          password: password ? password.value : '',
        },
      };
    }

    return { managerId: parseInt(value, 10) };
  }

  function refreshPickerSize() {
    if (!state.picker) return;
    if (state.picker.invalidateSize) state.picker.invalidateSize();
  }

  /* ------------------------------------------------------------------ table rendering */

  /*
   * Renders the station table, if this page has one.
   *
   * The map page has no table, so the first line returns immediately there and
   * the rest is inert. The table itself lives on admin.html, driven by admin.js;
   * this copy is retained because the price and station dialogs it renders are
   * shared, and loadData() still refreshes it after a save.
   */
  function renderStationsTable() {
    var tbody = document.getElementById('rows');
    if (!tbody) return;

    if (!state.stations) {
      tbody.innerHTML = '<tr><td colspan="5" class="loading-row">Loading stations…</td></tr>';
      return;
    }

    if (state.stations.length === 0) {
      tbody.innerHTML =
        '<tr><td colspan="5">' +
        '<div class="empty-state">' +
        '<h3>No stations yet</h3>' +
        '<p>Add your first gas station to start publishing it on the public map.</p>' +
        '<button class="btn btn-primary" type="button" id="btn-add-empty">Add station</button>' +
        '</div></td></tr>';

      var addEmpty = document.getElementById('btn-add-empty');
      if (addEmpty) addEmpty.addEventListener('click', openAddStationModal);
      return;
    }

    try {
      // Apply table filters (search and status)
      var query = document.getElementById('table-search')?.value?.trim().toLowerCase() || '';
      var status = document.getElementById('table-status')?.value || '';

      var visible = state.stations.filter(function (station) {
        if (!station) return false;
        if (status && getStationStatus(station).key !== status) return false;
        if (!query) return true;

        var haystack = [station.name, station.address, station.barangay];
        if (station.brand) haystack.push(station.brand.name);
        return haystack.some(function (value) {
          return value != null && String(value).toLowerCase().indexOf(query) !== -1;
        });
      });

      // Update result count
      var countEl = document.getElementById('table-count');
      if (countEl) {
        countEl.textContent = visible.length === state.stations.length
          ? state.stations.length + ' total'
          : visible.length + ' of ' + state.stations.length;
      }

      // Handle empty results
      if (visible.length === 0) {
        tbody.innerHTML =
          '<tr><td colspan="5">' +
          '<div class="empty-state">' +
          '<h3>No matching stations</h3>' +
          '<p>No station matches the current search and status filter.</p>' +
          '<button class="btn" type="button" id="btn-clear-filters">Clear filters</button>' +
          '</div></td></tr>';

        var clearBtn = document.getElementById('btn-clear-filters');
        if (clearBtn) {
          clearBtn.addEventListener('click', function () {
            document.getElementById('table-search').value = '';
            document.getElementById('table-status').value = '';
            // Only the table needs redrawing: the pins and the legend belong to
            // map.js, which filters them itself from the same legend checkboxes.
            renderStationsTable();
          });
        }
        return;
      }

      // Render visible rows
      var rows = visible.map(function (station) {
        var status = getStationStatus(station);

        return (
          '<tr class="' + status.rowClass + '">' +
          '<td>' + stationCell(station) + '</td>' +
          '<td class="hide-sm">' + escapeHtml(station.barangay || '—') + '</td>' +
          '<td><span class="status ' + status.className + '">' + status.label + '</span></td>' +
          '<td class="hide-sm">' + priceCell(station.prices) + '</td>' +
          '<td>' +
          '<div class="actions">' +
          // Icon-only buttons carry no visible text, so each one needs an
          // accessible name and a tooltip for sighted mouse users.
          '<button class="btn btn-sm btn-icon btn-edit" type="button" data-id="' + (station.id || '') + '"' +
          ' title="Edit station" aria-label="Edit ' + escapeHtml(station.name || 'station') + '">' +
          '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
          '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2 2v-7"/>' +
          '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4-1 1-4 9.5-9.5z"/>' +
          '</svg>' +
          '</button>' +
          '<button class="btn btn-sm btn-price" type="button" data-id="' + (station.id || '') + '"' +
          ' title="Edit fuel prices">Prices</button>' +
          '<button class="btn btn-sm btn-icon btn-danger" type="button" data-id="' + (station.id || '') + '"' +
          ' title="Delete station" aria-label="Delete ' + escapeHtml(station.name || 'station') + '">' +
          '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" aria-hidden="true">' +
          '<line x1="18" y1="6" x2="6" y2="18"/>' +
          '<line x1="6" y1="6" x2="18" y2="18"/>' +
          '</svg>' +
          '</button>' +
          '</div>' +
          '</td>' +
          '</tr>'
        );
      });

      tbody.innerHTML = rows.join('');

      // Add event listeners to edit/delete buttons
      tbody.querySelectorAll('.btn-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = parseInt(this.dataset.id, 10);
          if (isNaN(id)) return;
          var station = state.stations.find(function (s) {
            return s && s.id === id;
          });
          if (station) {
            openEditStationModal(station);
          }
        });
      });

      tbody.querySelectorAll('.btn-price').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = parseInt(this.dataset.id, 10);
          if (isNaN(id)) return;
          var station = state.stations.find(function (s) {
            return s && s.id === id;
          });
          if (station) {
            openPriceModal(station);
          }
        });
      });

      tbody.querySelectorAll('.btn-danger').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var id = parseInt(this.dataset.id, 10);
          if (isNaN(id)) return;
          if (confirm('Delete this station? This cannot be undone.')) {
            Api.deleteStation(id)
              .then(function () {
                return refreshStationViews();
              })
              .then(function () {
                showToast('Station deleted');
              })
              .catch(function (err) {
                showToast('Failed to delete station: ' + err.message, true);
                console.error(err);
              });
          }
        });
      });
    } catch (e) {
      console.error('Error rendering stations table:', e);
      tbody.innerHTML = '<tr><td colspan="5" class="error-row">Error rendering station list</td></tr>';
    }
  }

  function stationCell(station) {
    var brand = station.brand;
    var logo = brand && brand.slug ? MapIcons.brandLogo(brand) : '';

    // The brand and street address carry the detail that used to be missing here,
    // which left the name alone in a wide column. The address is escaped like the
    // name because it is free text from the API.
    var sub = '';
    if (brand && brand.name) {
      sub += escapeHtml(brand.name);
    }
    var address = (station.address || '').trim();
    if (address) {
      sub += (sub ? '<span class="sep">&middot;</span>' : '') + escapeHtml(address);
    }

    return (
      '<div class="cell-name">' +
      logo +
      '<span class="body">' +
      '<strong>' + escapeHtml(station.name || '') + '</strong>' +
      (sub ? '<span class="sub">' + sub + '</span>' : '') +
      '</span>' +
      '</div>'
    );
  }

  function priceCell(prices) {
    if (!Array.isArray(prices) || prices.length === 0) {
      return '<span class="price-empty">&mdash;</span>';
    }

    var lines = prices
      .filter(function (line) { return line && line.fuelType; })
      .map(function (line) {
        return (
          '<span class="line">' +
          '<span class="fuel">' + escapeHtml(line.fuelType) + '</span>' +
          '<span class="amount">&nbsp;' + Number(line.pricePerLiter || 0).toFixed(2) + '</span>' +
          '</span>'
        );
      });

    if (lines.length === 0) return '<span class="price-empty">&mdash;</span>';

    return '<span class="price-list">' + lines.join('') + '</span>';
  }

  function getStationStatus(station) {
    if (station.isPending === true) {
      return { key: 'pending', label: 'Pending', className: 'status-warning', rowClass: 'row-pending' };
    }
    if (station.isActive === true) {
      return { key: 'active', label: 'Active', className: 'status-success', rowClass: 'row-active' };
    }
    return { key: 'inactive', label: 'Inactive', className: 'status-neutral', rowClass: 'row-inactive' };
  }

  function updateStats() {
    var statsEl = document.getElementById('stats');
    if (!statsEl || !state.stations) {
      if (statsEl) statsEl.innerHTML = '';
      return;
    }

    try {
      var total = state.stations.length;
      var active = state.stations.filter(function (s) {
        return s && s.isActive === true && s.isPending !== true;
      }).length;
      var pending = state.stations.filter(function (s) {
        return s && s.isPending === true;
      }).length;
      var inactive = state.stations.filter(function (s) {
        return s && s.isActive !== true && s.isPending !== true;
      }).length;

      statsEl.innerHTML =
        '<div class="stat"><h3>' + total + '</h3><p>Total</p></div>' +
        '<div class="stat"><h3>' + active + '</h3><p>Active</p></div>' +
        '<div class="stat"><h3>' + inactive + '</h3><p>Inactive</p></div>' +
        '<div class="stat"><h3>' + pending + '</h3><p>Pending</p></div>';
    } catch (e) {
      console.error('Error updating stats:', e);
      if (statsEl) statsEl.innerHTML = '<div class="stat"><h3>Error</h3><p>Stats unavailable</p></div>';
    }
  }

  function addPriceRow(fuelType, pricePerLiter) {
    var priceRows = document.getElementById('price-rows');
    if (!priceRows) return;

    try {
      var row = document.createElement('div');
      row.className = 'price-row grid-2';
      row.innerHTML =
        '<div class="field">' +
        '<label>Fuel type <span class="req">*</span></label>' +
        '<input type="text" list="fuel-types" maxlength="60" required>' +
        '</div>' +
        '<div class="field">' +
        '<label>Price per liter <span class="req">*</span></label>' +
        '<input type="number" step="0.01" min="0" maxlength="10" required>' +
        '</div>' +
        '<button type="button" class="btn btn-sm btn-danger remove-price-row">–</button>';

      priceRows.appendChild(row);

      // Assigned as properties rather than baked into innerHTML so a stored
      // fuel type can never be injected as markup.
      var fuelInput = row.querySelector('input[list="fuel-types"]');
      var priceInput = row.querySelector('input[type="number"]');
      if (fuelInput && fuelType != null) fuelInput.value = fuelType;
      if (priceInput && pricePerLiter != null) priceInput.value = pricePerLiter;

      // Add remove button handler
      var removeBtn = row.querySelector('.remove-price-row');
      if (removeBtn) {
        removeBtn.addEventListener('click', function () {
          priceRows.removeChild(row);
        });
      }
    } catch (e) {
      console.error('Error adding price row:', e);
    }
  }

  /* ------------------------------------------------------------------ initialization */

  function init() {
    // Prevent multiple initialization attempts
    if (state.initAttempted) return;
    state.initAttempted = true;

    // Adopt the map before anything else. map.js creates it on its own
    // DOMContentLoaded handler and only settles `ready` once the map exists (or
    // once it has given up), so this promise - not a fixed timeout - is what
    // makes state.map a real Leaflet map rather than the null that every
    // add-mode path checks for.
    if (window.PublicMapApi) {
      // Registered here, before any pin is drawn, because map.js checks for it
      // as it creates each marker and calls it in place of its own click.
      window.PublicMapApi.onStationPin = onStationPin;

      state.mapReady = window.PublicMapApi.ready.then(function (map) {
        state.map = map || null;
        return state.map;
      });
    } else {
      // map.js absent or failed to parse. Resolved rather than left pending so
      // the rest of the page still loads and reports the problem.
      state.mapReady = Promise.resolve(null);
    }

    // Session is checked up front. Every /api/admin endpoint answers 401
    // without one, so probing first turns a dead dashboard into a bounce to
    // the sign-in page instead of an empty table plus a toast. It also yields
    // the display name for the header.
    Api.me()
      .then(function (resp) {
        var user = resp && resp.data;
        if (!user || (user.role !== 'admin' && user.role !== 'station_manager')) {
          redirectToLogin();
          return null;
        }
        state.isAuthenticated = true;
        applyRole(user);
        renderSignedInAs(user);
        return bootWithConfig();
      })
      .catch(function (err) {
        if (err && err.status === 401) {
          redirectToLogin();
          return;
        }
        console.error('Session check failed:', err);
        showToast('Could not verify your session. Please try again.', true);
      });
  }

  function renderSignedInAs(user) {
    var el = document.getElementById('who-name');
    if (el) {
      el.textContent = user.displayName || user.username || '—';
    }
  }

  /**
   * Applies what the signed-in role may do to this page.
   *
   * A station manager may only change fuel prices, so: no "+ Add station" (the
   * server would 403 a create anyway), no draggable pins, and the Edit station
   * dialog becomes a prices-only dialog. Everything here is presentation - the
   * authority is StationPolicy on the server, and a manager who bypasses this
   * gets a 403 rather than an edit.
   */
  function applyRole(user) {
    state.user = user;
    state.isAdmin = user.role === 'admin';

    if (state.isAdmin) {
      return;
    }

    var addBtn = document.getElementById('btn-add-station-map');
    if (addBtn) addBtn.hidden = true;

    // The form column collapses to nothing: a manager has nothing to edit in it.
    // The card keeps its markup so the price editor stays on the left exactly
    // as an admin sees it.
    document.body.classList.add('is-price-only');
  }

  /**
   * True when the signed-in user may edit this station's prices.
   *
   * Mirrors StationPolicy::updatePrices as closely as the client can: an admin
   * always, a manager only a station assigned to them. managerId comes from the
   * API payload, so it is the same field the server checks - but it is a hint,
   * not the authority.
   */
  function canEditStationPrices(station) {
    if (state.isAdmin) return true;
    if (!state.user || !station) return false;

    return station.managerId != null && Number(station.managerId) === Number(state.user.id);
  }

  function bootWithConfig() {
    // Api.adminConfig, not Api.config: api.js only ever exposed the admin
    // variant, so the old call threw "Api.config is not a function" before any
    // data was loaded and the picker was never built.
    return Api.adminConfig()
      .then(function (configResp) {
        state.cfg = extractApiData(configResp) || {};
      })
      .catch(function (err) {
        console.error('Failed to load dashboard config:', err);
        showToast('Could not load dashboard settings. Some options may be missing.', true);
      })
      .then(function () {
        startDashboard();
      });
  }

  function startDashboard() {
    if (state.dashboardStarted) return;
    state.dashboardStarted = true;

    // Bind the controls first, so the button works the moment it is pressed
    // rather than only after the data round-trip. It is inert until the map
    // arrives: enterAddMode() refuses without one and says so.
    bindEvents();

    loadData();

    /*
     * Always attach to the map, not just for ?add=1.
     *
     * initMap() is what registers the click handler that drops a pin, so
     * skipping it left the map deaf: the button armed, the cursor changed, and
     * clicking the map did nothing at all.
     */
    initMap().then(function () {
      // ?add=1 arms the flow on arrival. Waiting for the map rather than for a
      // fixed delay is what stops this landing before the click handler exists,
      // which is the state the page used to get stuck in.
      if (new URLSearchParams(window.location.search).get('add') === '1' && !state.addMode) {
        enterAddMode();
      }
    });
  }

  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  /*
   * Exposed for debugging from the console.
   *
   * enterAddMode and exitAddMode are here because they are the first thing to
   * try when the button appears dead: AdminMap.enterAddMode() tells you whether
   * the handler is wired up (the button changes to "Cancel") or whether the
   * problem is upstream of it (the "Map not initialized" toast).
   */
  window.AdminMap = {
    state: state,
    loadData: loadData,
    initOsmPicker: initOsmPicker,
    bindEvents: bindEvents,
    isApiErrorResponse: function (response) {
      return response &&
             typeof response === 'object' &&
             response.error !== undefined &&
             response.error !== null &&
             (typeof response.error === 'string' ||
              (typeof response.error === 'object' && response.error.message !== undefined));
    },
    extractApiData: extractApiData,
    renderStationsTable: renderStationsTable,
    stationCell: stationCell,
    priceCell: priceCell,
    stationStatus: getStationStatus,
    updateStats: updateStats,
    // Admin map specific functions
    initMap: initMap,
    onStationPin: onStationPin,
    enterAddMode: enterAddMode,
    exitAddMode: exitAddMode,
    openAddStationModal: openAddStationModal,
    openEditStationModal: openEditStationModal,
    openPriceModal: openPriceModal,
    openPasswordModal: openPasswordModal
  };
})();

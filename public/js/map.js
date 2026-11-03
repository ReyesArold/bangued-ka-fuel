/* Bangued gas station map - public, read-only view, on the Maps JavaScript API. */
(function () {
  'use strict';

  /* The base-map modes, keyed by the value the pill persists in localStorage.
     The map now draws on OpenStreetMap directly (Google is skipped), so each
     mode is a tile set or a stack of them: street is plain OSM, satellite is
     Esri imagery, hybrid adds the CARTO label layer over it, and cyclosm is the
     community cycling map. mapTypeId is kept for the readonly Google branch
     below, which never runs but still reads the field. */
  var MAP_TYPES = [
    { mode: 'street', mapTypeId: 'roadmap', label: 'Default' },
    { mode: 'satellite', mapTypeId: 'satellite', label: 'Satellite' },
    { mode: 'hybrid', mapTypeId: 'hybrid', label: 'Hybrid' },
    { mode: 'cyclosm', mapTypeId: 'roadmap', label: 'CyclOSM' },
  ];

  var state = {
    provider: 'google',
    gmaps: null,
    map: null,
    bounds: null,        // google.maps.LatLngBounds covering Bangued
    boundary: null,
    fallbackMap: null,
    fallbackBounds: null,
    fallbackBoundary: null,
    boundaryOverlay: null,
    fallbackMarkers: {},
    fallbackBases: {},
    activeBase: null,
    infoWindow: null,
    stations: [],
    brands: [],
    cfg: null,
    // stationId -> { marker: google.maps.Marker, ready: boolean }
    markers: {},
    legend: null,
    activeId: null,
    // User location tracking
    userLocationMarker: null,
    userLocationCircle: null,
    locationTracking: false,
    watchId: null,
    followUser: false,
    mapFollowGuard: null,
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
    return MapIcons.escape(s);
  }

  var LAYER_KEY = 'bgm.layer';

  /**
   * The saved base layer, or 'street'.
   *
   * Reading localStorage throws a SecurityError outright wherever storage is
   * unavailable - hardened Firefox, a sandboxed iframe, some private modes -
   * and this file already guards its sessionStorage reads for exactly that
   * reason. The bare getItem in boot() did not: it threw after the legend and
   * the markers were already drawn, the rejection was unhandled, and
   * hideLoader() never ran, so the loader covered the map and the list for good.
   */
  function readLayer() {
    try {
      return localStorage.getItem(LAYER_KEY) || 'street';
    } catch (e) {
      return 'street';
    }
  }

  function writeLayer(mode) {
    try {
      localStorage.setItem(LAYER_KEY, mode);
    } catch (e) {
      /* private mode / quota - the choice simply will not persist */
    }
  }

  /**
   * Shows a message in the page's shared toast.
   *
   * A thin wrapper over the Toast component in toast.js, so this page has one
   * toast with one timer rather than a second implementation writing into the
   * same element from the admin layer.
   */
  function toast(message, ms) {
    if (window.Toast) window.Toast.show(message, ms);
  }

    function hideLoader() {
    if (el.loader) {
      el.loader.classList.add('fade');
      // Hide immediately as well, don't rely solely on timeout + CSS
      el.loader.style.display = 'none';
      // Also set the hidden attribute for good measure
      el.loader.hidden = true;
    }
  }

  /** Fails the loader with a message and a hint, rather than leaving a blank map. */
  function failLoader(message, hint) {
    if (el.loaderText) {
      el.loaderText.textContent = message;
    }
    if (el.loader) {
      el.loader.hidden = false;
      el.loader.classList.remove('fade');
      // Make sure it's visible if we're showing an error
      el.loader.style.display = 'block';
    }
    toast(message + (hint ? ' ' + hint : ''), 15000);
  }

  /**
   * Detects Google Maps refusing to authenticate and reacts.
   *
   * An invalid key, a key without billing, an exhausted quota or a referrer
   * restriction all fail *quietly*: the Maps script still loads, google.maps.Map
   * still constructs, and no exception is thrown. Google just paints its own
   * grey panel over the map saying "Oops! Something went wrong ... see the
   * JavaScript console" - a dead end for whoever has to fix it. The failure is
   * therefore detected from Google's own error overlay, .gm-err-container.
   *
   * A demo key's quota is the case worth retrying: it is shared and comes back
   * intermittently, so the same page that failed a minute ago works now. The
   * auth failure is sticky for the lifetime of the loaded script - there is no
   * way to re-authenticate in place - so recovering means reloading the page
   * and asking again. That is bounded and rate-limited below; after the last
   * attempt the explanatory card stands instead, and everything else on the page
   * (list, search, legend) keeps working.
   *
   * It deliberately does NOT use failLoader(): that overlay covers the whole
   * viewport and would hide the station list and legend, both of which keep
   * working because a key failure has no effect on the data.
   *
   * A MutationObserver is used rather than a poll so a healthy map costs
   * nothing after boot.
   */
  /**
   * Detects Google Maps refusing to authenticate and reacts.
   *
   * Since we're using OpenStreetMap exclusively, this function is kept for
   * backward compatibility but will not execute as we don't load Google Maps.
   */
  function watchForMapAuthFailure() {
    // No-op since we're using OpenStreetMap exclusively
  }

  /** Retry attempts allowed per browser session before giving up. */
  var MAP_RETRY_KEY = 'bgm.mapRetries';
  var MAP_RETRY_LIMIT = 3;

  /**
   * Retries the page a bounded number of times while Google is rejecting the
   * key, then falls back to the explanatory card.
   *
   * Since we're using OpenStreetMap exclusively, this function is kept for
   * backward compatibility but will not execute as we don't load Google Maps.
   */
  function handleMapAuthFailure() {
    // No-op since we're using OpenStreetMap exclusively
  }

  /* ----------------------------------------------------------------- popup */

  /**
   * True when the station name already spells out its brand, e.g. "Blu Gas
   * Station #1" or a station literally named "Petron". In that case the brand
   * is suppressed anywhere it would just repeat the title.
   */
  function nameContainsBrand(station) {
    var name = String(station.name || '').toLowerCase();
    var brand = String(station.brand.name || '').toLowerCase();
    if (!brand) return false;
    return name === brand || name.indexOf(brand) !== -1;
  }

  function buildPopup(station) {
    var brand = station.brand;

    // Option A: the title is the station name only. The brand is never
    // concatenated onto it - it is carried by the brand logo, the header
    // gradient, and a chip in the body when it adds information.
    var head =
      '<div class="pop-head" style="background:linear-gradient(135deg,' + escapeHtml(brand.colorPrimary) + ' 0%,' +
      shade(brand.colorPrimary, -18) + ' 100%)">' +
      '<span class="pop-mark">' +
      MapIcons.brandLogo(brand, {
        lazy: true,
        // The white pin stands in if the brand has no logo file.
        fallback: MapIcons.pinSvg({ colorPrimary: '#ffffff', colorSecondary: 'transparent' }, 20),
      }) +
      '</span>' +
      '<span class="pop-name">' + escapeHtml(station.name) + '</span>' +
      // Our own close button rather than the one Google injects: the InfoWindow
      // content is handed over as a DOM node, so this can be wired up directly
      // and styled to sit on the brand-coloured header.
      '<button class="pop-close" type="button" aria-label="Close">&times;</button>' +
      '</div>';

    var brandChip = nameContainsBrand(station)
      ? ''
      : '<span class="pop-brand-chip" style="background:' + escapeHtml(brand.colorPrimary) +
        ';border-color:' + escapeHtml(brand.colorSecondary || 'rgba(0,0,0,.15)') + '">' +
        escapeHtml(brand.name) + '</span>';

    var rows = '';
    if (station.address) {
      // Escaped like every other admin-editable field here. The address is the
      // one field that was passed through raw, and it lands in innerHTML below,
      // so a station saved with markup in its address ran it for every public
      // visitor who opened the pin.
      rows += row('pin', escapeHtml(station.address));
    }
    if (station.barangay) {
      rows += row('info', 'Barangay: ' + escapeHtml(station.barangay));
    }
    if (station.operatingHours) {
      rows += row('clock', escapeHtml(station.operatingHours));
    }
    // The phone number is deliberately absent from the rows above: it is now the
    // Call button in the action bar, and a visitor who tapped it was left with
    // two controls for one job.

    /*
     * Prices lead. They used to sit at the bottom of the card in a two-column
     * table, below the address and the opening hours, which made the one number
     * people actually came for the last thing they saw. They now render as a
     * grid of chips directly under the header, and the moment the price was
     * confirmed rides beside the heading - a week-old figure reads as current
     * when its timestamp is small grey text underneath it.
     */
    var pricesHtml = '';
    if (station.prices && station.prices.length) {
      var chips = station.prices
        .map(function (p) {
          return '<div class="pop-chip">' +
            '<span class="pop-chip-type">' + escapeHtml(p.fuelType) + '</span>' +
            '<span class="pop-chip-price">' + peso(p.pricePerLiter) + '</span>' +
            '</div>';
        })
        .join('');

      var ago = FmtTime.relative(station.lastPriceUpdate);
      var freshHtml = ago
        ? '<span class="pop-fresh is-' + freshnessTone(station.lastPriceUpdate) + '">' +
          '<span class="pop-fresh-dot" aria-hidden="true"></span>' + escapeHtml(ago) + '</span>'
        : '';

      var updated = formatTimestamp(station.lastPriceUpdate);

      pricesHtml =
        '<div class="pop-prices">' +
        '<div class="pop-prices-head"><h4>Fuel price per liter</h4>' + freshHtml + '</div>' +
        '<div class="pop-chip-grid">' + chips + '</div>' +
        (updated ? '<p class="pop-updated">Confirmed ' + updated + '</p>' : '') +
        '</div>';
    } else {
      pricesHtml =
        '<div class="pop-prices"><h4>Fuel price per liter</h4>' +
        '<p class="price-empty">No price data published yet.</p></div>';
    }

    var flag = '';
    if (station.locationConfidence === 'approximate') {
      flag =
        '<span class="pop-flag">Approximate position &mdash; not yet verified</span>';
    }
    if (station.locationConfidence === 'confirmed') {
      flag = '<span class="pop-flag" style="background:#e8f5e9;color:#2e7d32">Verified location</span>';
    }

    var note = station.notes
      ? '<div class="pop-note">' + escapeHtml(station.notes) + '</div>'
      : '';

    /*
     * The action bar. Reaching a station is the whole point of pinning it on a
     * map, and the card previously offered no way to do it at all - the address
     * was plain text. Directions is a deep link to the station's own
     * coordinates; Call only appears when a number is actually on file, so a
     * station without one does not show a button that cannot work.
     */
    var actions = '';
    if (hasUsableCoords(station)) {
      actions += '<a class="pop-act pop-act-primary" target="_blank" rel="noopener noreferrer" href="' +
        escapeHtml(directionsUrl(station)) + '">' +
        MapIcons.smallIcon('navigate') + '<span>Directions</span></a>';
    }
    if (station.contactPhone) {
      actions += '<a class="pop-act" href="tel:' +
        escapeHtml(String(station.contactPhone).replace(/\s+/g, '')) + '">' +
        MapIcons.smallIcon('phone') + '<span>Call</span></a>';
    }
    var actionsHtml = actions
      ? '<div class="pop-actions">' + actions + '</div>'
      : '';

    return '<div class="pop">' +
      // Purely a visual affordance for the bottom-sheet layout; the card closes
      // on the close button, on Escape and on a tap of the map, never on a drag.
      '<div class="pop-grip" aria-hidden="true"></div>' +
      head +
      '<div class="pop-body">' + brandChip + pricesHtml + rows + flag + actionsHtml + note + '</div>' +
      '</div>';
  }

  /** True when the station has two finite coordinates to navigate to. */
  function hasUsableCoords(station) {
    return station.latitude !== null && station.latitude !== undefined &&
      station.longitude !== null && station.longitude !== undefined &&
      isFinite(Number(station.latitude)) && isFinite(Number(station.longitude));
  }

  /**
   * How far to trust a published price: within a day, within three, or older.
   */
  function freshnessTone(value) {
    var hours = FmtTime.ageHours(value);
    if (hours === null) return 'unknown';
    if (hours < 24) return 'fresh';
    if (hours < 72) return 'recent';
    return 'stale';
  }

  /**
   * A deep link that opens turn-by-turn navigation to the station.
   *
   * Google Maps web, for every platform. The previous form sent iPhones to
   * Apple Maps and everything else to OpenStreetMap's Graphhopper endpoint -
   * two different products, neither of them the one this app is built against
   * (and the OSM form passed a single coordinate to `route=`, which expects a
   * from/to pair, so it did not reliably resolve either).
   *
   * `destination` alone is deliberate: Google takes the traveller's current
   * location as the origin, and if they want to start from somewhere else the
   * web UI still lets them edit it. The key/value form in the query string is
   * the documented Maps URL API contract; the bare path form silently drops
   * waypoints.
   *
   * @param  {Object}  station  A published station carrying latitude/longitude.
   * @return {string}  A Google Maps directions URL for that coordinate.
   */
  function directionsUrl(station) {
    return 'https://www.google.com/maps/dir/?api=1&destination=' +
      Number(station.latitude) + ',' + Number(station.longitude);
  }

  function row(icon, html) {
    return '<div class="pop-row">' + MapIcons.smallIcon(icon) + '<span>' + html + '</span></div>';
  }

  /** Lightens (t>0) or darkens (t<0) a #rrggbb colour. */
  function shade(hex, percent) {
    var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
    if (!m) return hex;
    var num = parseInt(m[1] + m[2] + m[3], 16);
    var amt = Math.round(2.55 * percent);
    var r = Math.min(255, Math.max(0, (num >> 16) + amt));
    var g = Math.min(255, Math.max(0, ((num >> 8) & 0x00ff) + amt));
    var b = Math.min(255, Math.max(0, (num & 0x0000ff) + amt));
    return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
  }

  /**
   * Shows a station's InfoWindow, anchored above its marker.
   *
   * The content is set as a DOM node rather than an HTML string so the close
   * button inside it can be wired up - Google only injects listeners into its
   * own chrome, not into string content.
   */
  function openInfoWindow(station) {
    // Using OpenStreetMap fallback only
    var fentry = state.fallbackMarkers[station.id];
    if (!fentry || !fentry.marker) return;
    var fnode = document.createElement('div');
    fnode.innerHTML = buildPopup(station);
    var fclose = fnode.querySelector('.pop-close');
    if (fclose) {
      fclose.addEventListener('click', function () {
        fentry.marker.closePopup();
      });
    }
    if (!fentry.marker.getPopup()) {
      // closeButton:false - the popup carries its own .pop-close on the
      // brand-coloured header, so Leaflet's own would be a second X.
      //
      // autoPan is off on phones because the stylesheet turns the card into a
      // bottom sheet there: Leaflet would pan the map to fit a box that is no
      // longer anchored to anything, shoving the station under the header.
      fentry.marker.bindPopup(fnode, {
        maxWidth: 340,
        minWidth: 300,
        className: 'station-sheet',
        closeButton: false,
        autoPan: !isNarrowViewport(),
        autoPanPadding: [24, 72],
      });
    } else {
      fentry.marker.setPopupContent(fnode);
    }
    fentry.marker.openPopup();
    // Only bites on phones, where the stylesheet docks the card to the bottom
    // edge; harmless otherwise.
    document.body.classList.add('sheet-open');
  }

  /* ------------------------------------------------------------------ markers */

  /** Phones get the card as a bottom sheet; wider screens keep it on the pin. */
  function isNarrowViewport() {
    return typeof window.matchMedia === 'function'
      ? window.matchMedia('(max-width: 640px)').matches
      : window.innerWidth <= 640;
  }

  /** Builds the Leaflet divIcon for a red station pin. */
  function fallbackIcon(station, selected) {
    var brand = station.brand || { slug: "unknown", name: "Unknown" };
    var approximate = station.locationConfidence === "approximate";

    // Use MapIcons to create a branded icon with fallback
    var logoMarkup = MapIcons.brandLogo(brand, {
      lazy: true,
      fallback: MapIcons.pinSvg({ colorPrimary: "#ffffff", colorSecondary: "transparent" }, 20),
    });

    return L.divIcon({
      // The is-selected hook lets the stylesheet lift the clicked pin above its
      // neighbours, which the darker stroke inside the SVG alone does not do.
      // The approximate class indicates uncertain location.
      className: "station-pin" + (selected ? " is-selected" : "") + (approximate ? " approximate" : ""),
      html: logoMarkup,
      iconSize: [28, 28],
      iconAnchor: [14, 28],
      popupAnchor: [0, -28]
    });
  }

  /**
   * Plots every station that has coordinates.
   *
   * Markers are created detached and only attached to the map once their pin
   * has rasterised, so the map never flashes a half-built icon underneath the
   * loader on the way in.
   *
   * @returns {Promise<void>} Resolves once every pin is ready.
   */
  function renderStations() {
    // Using OpenStreetMap fallback only
    state.stations.forEach(function (station) {
      if (station.latitude == null || station.longitude == null) return;

      /*
       * The admin map (/admin/map) loads this file too and needs the same pins,
       * draggable, opening the edit dialog instead of the public card. Two maps
       * cannot share the #map container, so this file stays the only thing that
       * creates markers - it just asks the admin layer to take over the click.
       *
       * One marker per station either way, deliberately. An earlier version had
       * the admin layer remove this marker and substitute its own, which left
       * state.fallbackMarkers pointing at a detached marker: the legend filter,
       * the station list and the search results all went on showing and hiding
       * a pin that was no longer on the map.
       */
      var editor = window.PublicMapApi && window.PublicMapApi.onStationPin
        ? window.PublicMapApi
        : null;

      var marker = L.marker([station.latitude, station.longitude], {
        icon: fallbackIcon(station, false),
        title: station.name,
        riseOnHover: true,
        // Read by Leaflet at creation, so draggability cannot be switched on
        // later - the option has to be right the first time.
        draggable: !!editor,
      });

      if (editor) {
        try {
          editor.onStationPin(station, marker);
        } catch (err) {
          console.error('Failed to attach station pin handlers:', err);
        }
      } else {
        marker.on('click', function () {
          setActive(station.id);
          openInfoWindow(station);
        });
      }

      state.fallbackMarkers[station.id] = { marker: marker, ready: true };
    });

    applyBrandFilters();
    return Promise.resolve();
  }

  /**
   * Re-reads the station list and repaints the pins, in place.
   *
   * The admin map calls this after saving or deleting a station so the change
   * appears without a reload. renderStations() alone would only append - it
   * keys new markers into state.fallbackMarkers and never removes the old ones -
   * so the existing layers are dropped first.
   *
   * @returns {Promise<void>}
   */
  async function reloadStations() {
    if (!state.fallbackMap) return;

    var resp = await Api.stations();
    var stations = resp && resp.data;
    if (!Array.isArray(stations)) return;

    Object.keys(state.fallbackMarkers).forEach(function (id) {
      var entry = state.fallbackMarkers[id];
      if (entry && entry.marker) state.fallbackMap.removeLayer(entry.marker);
    });
    state.fallbackMarkers = {};
    state.activeId = null;
    state.stations = stations;

    await renderStations();
  }

  /** Shows/hides markers and list rows per the legend checkboxes. */
  function applyBrandFilters() {
    var counts = {};
    state.brands.forEach(function (b) { counts[b.slug] = 0; });

    state.stations.forEach(function (station) {
      // Using OpenStreetMap fallback only
      var fallbackEntry = state.fallbackMarkers[station.id];
      var visible = state.legend ? state.legend.isVisible(station.brand.slug) : true;
      if (visible) {
        counts[station.brand.slug] = (counts[station.brand.slug] || 0) + 1;
        if (fallbackEntry) {
          fallbackEntry.marker.addTo(state.fallbackMap);
        }
      } else {
        if (fallbackEntry) {
          state.fallbackMap.removeLayer(fallbackEntry.marker);
        }
      }
      var row = el.listBody.querySelector('[data-id="' + station.id + '"]');
      if (row) row.hidden = !visible;
    });

    if (state.legend) state.legend.setCounts(counts);
    renderList(counts);
  }

  function setActive(id) {
    var previous = state.activeId;
    state.activeId = id;
    el.listBody.querySelectorAll('.station-row').forEach(function (r) {
      r.classList.toggle('active', Number(r.dataset.id) === id);
    });
    // Ring the newly selected pin and drop the ring from the one before it.
    paintMarker(previous, false);
    paintMarker(id, true);
  }

  /** Repaints one station's pin, adding or removing the selection ring. */
  function paintMarker(id, selected) {
    // Using OpenStreetMap fallback only
    var fbEntry = state.fallbackMarkers[id];
    if (!fbEntry) return;
    var fbStation = state.stations.find(function (s) { return s.id === id; });
    if (!fbStation) return;
    fbEntry.marker.setIcon(fallbackIcon(fbStation, selected));
  }

  /* --------------------------------------------------------------- list panel */

  /** Slides the station list in or out and keeps the toggle in sync. */
  function setListOpen(open) {
    if (el.listPanel) {
      el.listPanel.classList.toggle('open', open);
    }
    if (el.toggleList) {
      el.toggleList.setAttribute('aria-expanded', String(open));
    }
  }

  function renderList(counts) {
    // The list body is rebuilt wholesale below, and replacing its contents
    // resets the scroller to the top. Every brand filter runs this, so without
    // preserving the offset the panel jumps to the top on each tick.
    if (el.listBody) {
      var keepScroll = el.listBody.scrollTop;
    }
    var visibleStations = state.stations.filter(function (s) {
      return !counts || (counts[s.brand.slug] || 0) > 0;
    });

    var shown = 0;
    var html = state.stations
      .map(function (s) {
        var hidden = counts && !(counts[s.brand.slug] > 0);
        if (!hidden) shown++;
        var priceText = s.prices && s.prices.length
          ? s.prices
              .map(function (p) { return escapeHtml(p.fuelType) + ' ' + peso(p.pricePerLiter); })
              .join(' · ')
          : 'No price data yet';
        return (
          '<button type="button" class="station-row' + (state.activeId === s.id ? ' active' : '') +
          '" data-id="' + s.id + '"' + (hidden ? ' hidden' : '') + '>' +
          MapIcons.brandLogo(s.brand, { lazy: true }) +
          '<span><span class="st-name">' + escapeHtml(s.name) + '</span>' +
          '<span class="st-addr">' + escapeHtml(s.address || s.barangay || 'Address not published') + '</span>' +
          (s.locationConfidence === 'approximate' ? '<span class="st-badge">Approximate</span>' : '') +
          '<span class="st-price">' + priceText + '</span></span></button>'
        );
      })
      .join('');

    /*
     * The two empty states are mutually exclusive, and only one of them may own
     * the body. This used to assign the rows, bind a click handler to every one
     * of them, and then - whenever nothing was visible - overwrite the whole body
     * again with a notice, silently discarding those listeners. On a fresh
     * install with no published stations, visibleStations is empty too, so the
     * "All brands are hidden" text replaced "No stations to show." and pointed
     * the visitor at a legend that could never produce a station.
     */
    if (visibleStations.length === 0) {
      if (el.listBody) {
        el.listBody.innerHTML = state.stations.length === 0
          ? '<p class="empty-note">No stations to show.</p>'
          : '<p class="empty-note">All brands are hidden.<br>Use the legend to show them again.</p>';
      }
      if (el.listCount) {
        el.listCount.textContent = '0 / ' + state.stations.length;
      }
    } else {
      if (el.listBody) {
        el.listBody.innerHTML = html;
      }
      if (el.listCount) {
        el.listCount.textContent = shown + ' / ' + state.stations.length;
      }

      if (el.listBody) {
        el.listBody.querySelectorAll('.station-row').forEach(function (row) {
          row.addEventListener('click', function () {
            focusStation(Number(row.dataset.id));
          });
        });
      }
    }
  }

  function focusStation(id) {
    // Using OpenStreetMap fallback only
    var fstation = state.stations.find(function (s) { return s.id === id; });
    var fentry = state.fallbackMarkers[id];
    if (!fstation || !fentry) return;
    if (state.legend) state.legend.showBrand(fstation.brand.slug);
    setActive(id);
    state.fallbackMap.setView([fstation.latitude, fstation.longitude], Math.max(state.fallbackMap.getZoom(), 17));
    openInfoWindow(fstation);
    var frow = el.listBody.querySelector('[data-id="' + id + '"]');
    if (frow && frow.scrollIntoView) frow.scrollIntoView({ block: 'nearest' });
  }

  /* ------------------------------------------------------------------- search */

  function initSearch() {
    function close() {
      el.searchResults.classList.remove('open');
      el.search.setAttribute('aria-expanded', 'false');
    }

    function run() {
      var q = el.search.value.trim().toLowerCase();
      el.searchWrap.classList.toggle('has-value', el.search.value.length > 0);
      if (q.length < 1) return close();

      var matches = state.stations.filter(function (s) {
        return (
          s.name.toLowerCase().indexOf(q) !== -1 ||
          s.brand.name.toLowerCase().indexOf(q) !== -1 ||
          (s.address || '').toLowerCase().indexOf(q) !== -1 ||
          (s.barangay || '').toLowerCase().indexOf(q) !== -1
        );
      });

      if (!matches.length) {
        el.searchResults.innerHTML =
          '<p class="empty-note">No station matches &ldquo;' + escapeHtml(el.search.value.trim()) + '&rdquo;.</p>';
      } else {
        el.searchResults.innerHTML = matches
          .map(function (s) {
            return (
              '<button type="button" role="option" data-id="' + s.id + '">' +
              '<span class="sr-dot" style="background:' + escapeHtml(s.brand.colorPrimary) + '"></span>' +
              '<span><span class="sr-name">' + escapeHtml(s.name) + '</span>' +
              '<span class="sr-sub">' + escapeHtml(s.brand.name) +
              (s.address ? ' · ' + escapeHtml(s.address) : '') + '</span></span></button>'
            );
          })
          .join('');
        el.searchResults.querySelectorAll('button').forEach(function (b) {
          b.addEventListener('click', function () {
            focusStation(Number(b.dataset.id));
            el.search.value = '';
            el.searchWrap.classList.remove('has-value');
            close();
          });
        });
      }
      el.searchResults.classList.add('open');
      el.search.setAttribute('aria-expanded', 'true');
    }

    el.search.addEventListener('input', run);
    el.search.addEventListener('focus', function () { if (el.search.value.trim()) run(); });
    el.searchClear.addEventListener('click', function () {
      el.search.value = '';
      el.searchWrap.classList.remove('has-value');
      close();
      el.search.focus();
    });
    document.addEventListener('click', function (e) {
      if (!el.searchWrap.contains(e.target)) close();
    });
    el.search.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') close();
      if (e.key === 'Enter') {
        var first = el.searchResults.querySelector('button');
        if (first) first.click();
      }
    });
  }

  /* -------------------------------------------------------------- list panel */

  /* ------------------------------------------------------------- base layers */

  /**
   * The click handlers currently bound to the base-layer pills, so a second
   * initLayerSwitch() replaces them instead of stacking another one on each.
   *
   * The Google-to-OSM switch calls this a second time, and it can do so from the
   * auth-failure observer while boot() is still awaiting renderStations() - so
   * both registrations landed on the same buttons and every pill click ran
   * setMode twice.
   */
  var layerHandlers = [];

  function initLayerSwitch() {
    layerHandlers.forEach(function (entry) {
      entry.btn.removeEventListener('click', entry.fn);
    });
    layerHandlers = [];

    function setMode(mode) {
      var picked = MAP_TYPES.filter(function (m) { return m.mode === mode; })[0] || MAP_TYPES[0];

      MAP_TYPES.forEach(function (m) {
        var btn = el.layerButtons[m.mode];
        if (btn) btn.setAttribute('aria-pressed', String(m === picked));
      });

      if (state.provider === 'fallback') {
        var base = state.fallbackBases[picked.mode] || state.fallbackBases.street;
        if (state.activeBase && state.activeBase !== base) {
          state.fallbackMap.removeLayer(state.activeBase);
        }
        if (!state.fallbackMap.hasLayer(base)) base.addTo(state.fallbackMap);
        state.activeBase = base;
      } else {
        state.map.setMapTypeId(picked.mapTypeId);
      }
      writeLayer(picked.mode);
    }

    MAP_TYPES.forEach(function (m) {
      var btn = el.layerButtons[m.mode];
      if (!btn) return;

      var fn = function () { setMode(m.mode); };
      layerHandlers.push({ btn: btn, fn: fn });
      btn.addEventListener('click', fn);
    });

    return setMode;
  }

  /* ----------------------------------------------------- locked to Bangued */

  /**
   * Keeps the view inside Bangued.
   *
   * Google Maps has no maxBounds, so the old mapOptions.restriction is no use
   * here. clampCenter() alone is enough: the centre is pulled back into the
   * boundary's bounding box whenever a drag takes it outside, so the border
   * stays a hard edge.
   *
   * This used to also clamp the zoom, raising it until the whole municipality
   * fitted on screen. That is what stopped the mouse wheel working in either
   * direction. Bangued's box is roughly 29 km north to south, so it does not fit
   * at the default zoom: the clamp ran on every bounds_changed, walked the map
   * from zoom 15 down to minZoom 13 on load, and then undid every scroll to zoom
   * in - while zooming out had nowhere left to go. clampZoom and its
   * viewportCoversBounds helper were removed so the wheel is free; the
   * minZoom/maxZoom in the map options still bound the range, and clampCenter
   * still stops the view being dragged off the municipality.
   */
  function restrictToBangued() {
    if (state.provider === 'fallback') {
      state.fallbackMap.setMaxBounds(state.fallbackBounds);
      return;
    }

    var bounds = state.bounds;

    function clampCenter() {
      var center = state.map.getCenter();
      if (!center) return;
      var sw = bounds.getSouthWest();
      var ne = bounds.getNorthEast();
      var lat = Math.min(Math.max(center.lat(), sw.lat()), ne.lat());
      var lng = Math.min(Math.max(center.lng(), sw.lng()), ne.lng());
      if (lat !== center.lat() || lng !== center.lng()) {
        state.map.setCenter({ lat: lat, lng: lng });
      }
    }

    state.map.addListener('bounds_changed', clampCenter);
  }

  /**
   * Free replacement used when the Google browser key cannot authenticate.
   * It is intentionally self-contained: OSM basemap, Bangued boundary,
   * station pins and the same list/search/legend interactions.
   */
  function initFallbackMap(cfg) {
    state.provider = 'fallback';
    state.fallbackMap = L.map(el.map, {
      center: [cfg.center[0], cfg.center[1]],
      zoom: cfg.defaultZoom,
      minZoom: cfg.minZoom,
      maxZoom: cfg.maxZoom,
      maxBoundsViscosity: 1.0,
      attributionControl: true,
    });

    /*
     * Raising the bottom control row clear of the station card is done with a
     * body class rather than per-element maths, and it has to come back off
     * however the card closes - the close button, a tap of the map, or Escape.
     * 'popupclose' is the one event that covers all three.
     */
    state.fallbackMap.on('popupclose', function () {
      document.body.classList.remove('sheet-open');
    });

    var osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: cfg.maxZoom,
      attribution: '&copy; OpenStreetMap contributors',
    });
    var satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
      maxZoom: cfg.maxZoom,
      attribution: 'Tiles &copy; Esri',
    });
    var labels = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_only_labels/{z}/{x}/{y}{r}.png', {
      maxZoom: cfg.maxZoom,
      pane: 'markerPane',
      attribution: '&copy; OpenStreetMap contributors &copy; CARTO',
    });
    var cyclosm = L.tileLayer('https://{s}.tile-cyclosm.openstreetmap.fr/cyclosm/{z}/{x}/{y}.png', {
      maxZoom: cfg.maxZoom,
      attribution: '&copy; OpenStreetMap contributors, <a href="https://www.cyclosm.org" rel="noopener" target="_blank">CyclOSM</a>',
    });

    /*
     * Google hybrid (satellite imagery + labels). When the browser key is
     * configured, Hybrid mode draws on Google's tile service instead of the
     * Esri/CARTO stack below; the tiles are fetched by the visitor's own
     * browser, so the key goes in the URL and must be restricted by HTTP
     * referrer in the Cloud console. Without a key the hybrid falls back to
     * Esri imagery + CARTO labels, so a missing key can never blank the map.
     */
    var googleHybrid = null;
    if (cfg.google) {
      googleHybrid = L.tileLayer('https://{s}.google.com/vt/lyrs=s,h&x={x}&y={y}&z={z}&key=' + encodeURIComponent(cfg.google), {
        maxZoom: cfg.maxZoom,
        subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
        attribution: 'Tiles &copy; Google',
      });
    }

    state.fallbackBases = {
      street: osm,
      satellite: satellite,
      hybrid: googleHybrid || L.layerGroup([satellite, labels]),
      cyclosm: cyclosm,
    };
    state.activeBase = osm;
    osm.addTo(state.fallbackMap);

    state.fallbackBounds = L.latLngBounds([
      [cfg.maxBounds[0][0], cfg.maxBounds[0][1]],
      [cfg.maxBounds[1][0], cfg.maxBounds[1][1]],
    ]);
    restrictToBangued();

    /*
     * Hard Bangued lock: the town fills the screen and nothing past its box is
     * ever visible. setMaxBounds alone still let you zoom out until the box
     * was smaller than the viewport, which is how the neighbouring area crept
     * in around the borders. getBoundsZoom(bounds, true) returns the smallest
     * zoom at which the whole viewport sits inside the box, so from that zoom
     * inwards the map only ever shows Bangued - the borders are a hard edge.
     *
     * This is not the removed clampZoom. That one set the zoom on every
     * bounds_changed and jammed the scroll wheel. This only moves the minimum
     * zoom, once at boot and again on resize, so the wheel stays free between
     * the floor and maxZoom and the lock always matches the window size.
     */
    function lockViewToBangued() {
      var floor = state.fallbackMap.getBoundsZoom(state.fallbackBounds, true);
      if (!isFinite(floor)) return;
      state.fallbackMap.setMinZoom(floor);
      if (state.fallbackMap.getZoom() < floor) {
        state.fallbackMap.setZoom(floor);
      }
    }

    lockViewToBangued();
    state.fallbackMap.on('resize', lockViewToBangued);
  }

  /**
   * Draws the Bangued boundary on the map as a dashed stroke.
   *
   * /api/meta ships the boundary as a GeoJSON Feature, so the client reads the
   * outer ring from geometry.coordinates[0] - each point is [lon, lat] in
   * GeoJSON order and has to be flipped to Leaflet's [lat, lon]. On a second
   * pass, the raw point lists this function used to accept are still handled,
   * in case anything ever calls it with the coords directly.
   *
   * @param {Object|Array} boundary - GeoJSON Feature, or an array of
   *   [lat, lng] pairs / {lat, lng} / {latitude, longitude} objects.
   */
  function drawBoundary(boundary) {
    // Remove any existing boundary
    if (state.boundaryOverlay) {
      state.fallbackMap.removeLayer(state.boundaryOverlay);
      state.boundaryOverlay = null;
    }

    // Don't draw if no boundary data
    if (!boundary) {
      return;
    }

    try {
      var latLngs = null;

      // GeoJSON Feature: {type:'Feature', geometry:{type:'Polygon', coordinates:[[lon,lat]...]}}
      if (boundary.type === 'Feature' && boundary.geometry &&
          Array.isArray(boundary.geometry.coordinates) &&
          boundary.geometry.coordinates.length) {
        var geoJsonRing = boundary.geometry.coordinates[0];
        if (Array.isArray(geoJsonRing) && geoJsonRing.length) {
          latLngs = geoJsonRing.map(function (point) {
            if (Array.isArray(point) && point.length >= 2) {
              var lon = Number(point[0]);
              var lat = Number(point[1]);
              if (isFinite(lon) && isFinite(lat)) {
                return [lat, lon];
              }
            }
            return null;
          }).filter(function (point) { return point !== null; });
        }
      }

      // Raw point lists: [lat, lng] pairs or {lat, lng} / {latitude, longitude}.
      if ((!latLngs || !latLngs.length) && Array.isArray(boundary)) {
        latLngs = boundary.map(function (point) {
          var lat, lng;

          if (Array.isArray(point) && point.length >= 2) {
            // Format: [lat, lng]
            lat = point[0];
            lng = point[1];
          } else if (typeof point === 'object') {
            // Format: {lat: number, lng: number} or {latitude: number, longitude: number}
            lat = point.lat !== undefined ? point.lat : point.latitude;
            lng = point.lng !== undefined ? point.lng : point.longitude;
          } else {
            // Invalid point format
            return null;
          }

          // Validate coordinates
          if (typeof lat === 'number' && typeof lng === 'number' &&
              isFinite(lat) && isFinite(lng)) {
            return [lat, lng];
          } else {
            return null;
          }
        }).filter(function (point) { return point !== null; });
      }

      // Don't draw if we don't have valid points
      if (!latLngs || latLngs.length < 3) {
        // Need at least 3 points for a polygon
        return;
      }

      // The legend describes it as "the dashed line", so the municipality
      // outline is a dashed stroke, softened by a very faint fill so the
      // extent still reads at the default zoom, where the ring itself is far
      // outside the viewport. Bangued is bigger than the map even at the
      // minimum zoom, so noClip:true stops Leaflet from swapping the ring's
      // stroke for a full-screen box when it clips a path larger than the map.
      state.boundaryOverlay = L.polygon(latLngs, {
        color: '#ff7800',
        weight: 2,
        opacity: 0.9,
        dashArray: '6, 6',
        fill: true,
        fillColor: '#ff7800',
        fillOpacity: 0.05,
        noClip: true,
        interactive: false,
        clickable: false
      }).addTo(state.fallbackMap);
    } catch (e) {
      // Silently fail if boundary drawing fails - don't want to break the map
      console.warn('Failed to draw boundary:', e);
    }
  }

  /* ------------------------------------------------------------------ user location */

  /**
   * Creates or updates the user location marker on the map.
   *
   * The blue dot is paired with an accuracy circle so a visitor can see how
   * trustworthy a fix is without the dot trying to look exact. While the
   * visitor has not touched the map, the view follows them; panTo() honours
   * the Bangued maxBounds, so an out-of-range fix simply cannot fly the
   * visitor past the municipality edge.
   *
   * Both layers are interactive:false - if they took pointer events, a drag
   * started on top of your own dot would be swallowed and "stop following"
   * would never fire.
   *
   * @param {Object} position - Geolocation position object
   */
  function updateUserLocation(position) {
    var lat = position.coords.latitude;
    var lng = position.coords.longitude;

    // If marker doesn't exist, create it
    if (!state.userLocationMarker) {
      // Create user location marker as a tracker icon
      state.userLocationMarker = L.marker([lat, lng], {
        icon: L.divIcon({
          className: 'user-location-tracker',
          html: createTrackerSvg(),
          iconSize: [20, 20],
          iconAnchor: [10, 10]
        }),
        title: 'Your location',
        riseOnHover: true,
        interactive: false
      }).addTo(state.fallbackMap);

      // Accuracy circle under the dot: its radius is the GPS uncertainty in
      // metres, so it shrinks/grows as the fix improves or degrades.
      state.userLocationCircle = L.circle([lat, lng], {
        radius: position.coords.accuracy || 25,
        color: '#1a73e8',
        weight: 1,
        fillColor: '#1a73e8',
        fillOpacity: 0.08,
        interactive: false,
        className: 'user-location-accuracy'
      }).addTo(state.fallbackMap);
    } else {
      // Update existing marker position
      state.userLocationMarker.setLatLng([lat, lng]);
      state.userLocationCircle.setLatLng([lat, lng]);
      state.userLocationCircle.setRadius(position.coords.accuracy || 25);
    }

    // Real-time following: while the visitor has not taken over the map, keep
    // the view centred on them. The separate getCurrentPosition();watchPosition
    // pair this used to run is gone - watchPosition alone delivers an immediate
    // first fix, so centring happens right here on the first callback.
    if (state.followUser) {
      state.fallbackMap.panTo([lat, lng]);
    }
  }

  /**
   * Creates SVG for the tracker icon.
   * @returns {string} SVG markup for tracker icon
   */
  function createTrackerSvg() {
    return (
      '<span class="tracker-ping"></span>' +
      '<svg width="20" height="20" viewBox="0 0 20 20" aria-hidden="true">' +
      '<circle cx="10" cy="10" r="8.5" fill="#1a73e8"/>' +
      '<circle cx="10" cy="10" r="3" fill="#fff"/>' +
      '</svg>'
    );
  }

  /**
   * Starts tracking the user's location.
   */
  function startLocationTracking() {
    if (!navigator.geolocation) {
      toast('Geolocation is not supported by your browser', 5000);
      return;
    }

    state.locationTracking = true;
    state.followUser = true;

    // Watch for position changes. No separate getCurrentPosition() call: the
    // first watchPosition() callback is delivered immediately (and repeatedly
    // after), so the pair that used to fire two fixes back-to-back while
    // clipping the error handling across two call sites is gone.
    state.watchId = navigator.geolocation.watchPosition(
      function(position) {
        updateUserLocation(position);
      },
      function(error) {
        handleLocationError(error);
      },
      {
        enableHighAccuracy: true,
        timeout: 10000,
        maximumAge: 0
      }
    );

    // The moment the visitor drags or zooms, hand them the map and stop
    // following, so the view does not get yanked back under their cursor. The
    // blue dot keeps updating either way. A drag of the map fires 'dragstart';
    // a wheel or pinch fires 'zoomstart'.
    state.mapFollowGuard = function () {
      state.followUser = false;
    };
    state.fallbackMap.on('dragstart zoomstart', state.mapFollowGuard);

    toast('Location tracking enabled', 3000);
  }

  /**
   * Stops tracking the user's location.
   */
  function stopLocationTracking() {
    clearUserLocation();
    toast('Location tracking disabled', 3000);
  }

  /**
   * Tears the tracker down without toasting. Shared by stopLocationTracking()
   * and the permission-denied error path, so a denied fix cannot leave the
   * button pressed with no dot and no watch behind it.
   */
  function clearUserLocation() {
    state.locationTracking = false;
    state.followUser = false;

    if (state.watchId !== null) {
      navigator.geolocation.clearWatch(state.watchId);
      state.watchId = null;
    }

    if (state.mapFollowGuard) {
      state.fallbackMap.off('dragstart zoomstart', state.mapFollowGuard);
      state.mapFollowGuard = null;
    }

    // Remove user location marker and its accuracy circle from map
    if (state.userLocationMarker) {
      state.fallbackMap.removeLayer(state.userLocationMarker);
      state.userLocationMarker = null;
    }
    if (state.userLocationCircle) {
      state.fallbackMap.removeLayer(state.userLocationCircle);
      state.userLocationCircle = null;
    }

    if (el.locationButton) {
      el.locationButton.classList.remove('active');
      el.locationButton.setAttribute('aria-pressed', 'false');
    }
  }

  /**
   * Handles geolocation errors.
   *
   * The numeric codes are used directly rather than error.PERMISSION_DENIED &
   * co, because the named constants are browser-specific legacy surface and a
   * plain number works on every environment the map runs in.
   *
   * @param {Object} error - Geolocation error object
   */
  function handleLocationError(error) {
    var code = error && error.code;

    // 1 = PERMISSION_DENIED. Persistent, so the whole tracker is put away:
    // leaving the button pressed with no watch behind it would be lying.
    if (code === 1) {
      clearUserLocation();
      toast('Location permission denied. Enable location services and try again.', 5000);
      return;
    }

    // 2 = POSITION_UNAVAILABLE, 3 = TIMEOUT. Both are transient: watchPosition
    // keeps trying, so only say so and leave the watch running.
    if (code === 2) {
      toast('Location information is unavailable. Retrying…', 5000);
      return;
    }

    toast('The request to get user location timed out. Retrying…', 5000);
  }

  /* --------------------------------------------------------------------- boot */

  async function boot() {
    el = {
      map: document.getElementById('map'),
      loader: document.getElementById('loader'),
      loaderText: document.getElementById('loader-text'),
      search: document.getElementById('search'),
      searchWrap: document.getElementById('search-wrap'),
      searchClear: document.getElementById('search-clear'),
      searchResults: document.getElementById('search-results'),
      layerButtons: {
        street: document.getElementById('layer-street'),
        satellite: document.getElementById('layer-satellite'),
        hybrid: document.getElementById('layer-hybrid'),
        cyclosm: document.getElementById('layer-cyclosm'),
      },
      toggleList: document.getElementById('toggle-list'),
      listPanel: document.getElementById('list-panel'),
      listCtl: document.querySelector('.list-ctl'),
      listBody: document.getElementById('list-body'),
      listCount: document.getElementById('list-count'),
      legend: document.getElementById('legend'),
      // User location controls
      locationButton: document.getElementById('location-button'),
    };

    let meta, brands, stations;
    try {
      if (el.loaderText) {
        el.loaderText.textContent = 'Loading map data…';
      }
      var results = await Promise.all([Api.meta(), Api.brands(), Api.stations()]);
      meta = results[0];
      brands = results[1] && results[1].data;
      stations = results[2] && results[2].data;
    } catch (err) {
      failLoader('Could not load station data', 'Check that the server is running. ' + err.message);
      settleReady(null);
      return;
    }

    /*
     * api.js resolves with null - it does not throw - for any 2xx whose body is
     * not JSON, which is what a proxy error page or an SPA fallback rule
     * answering index.html for /api/* looks like. Dereferencing that below threw
     * a TypeError *outside* the try above, so nothing caught it, boot()'s
     * rejection went unhandled and the loader sat over the page for good.
     */
    if (!meta || !meta.map || !Array.isArray(brands) || !Array.isArray(stations)) {
      failLoader('Could not load station data', 'The server returned an unexpected response.');
      settleReady(null);
      return;
    }

    var cfg = meta.map;
    state.cfg = cfg;
    state.brands = brands;
    state.stations = stations;

    // Use OpenStreetMap directly, skip Google Maps entirely
    try {
      state.provider = 'fallback';
      state.gmaps = null;
      initFallbackMap(cfg);
      // The map exists from here on, so an embedding page (the admin map) can
      // stop waiting. Settled before the markers are drawn, so its decorator is
      // already registered by the time renderStations() creates them.
      settleReady(state.fallbackMap);
      drawBoundary(cfg.boundary);
      if (el.legend) {
        state.legend = new Legend(el.legend, {
          onToggle: function () { applyBrandFilters(); },
        }).start(brands);
      }
      await renderStations();
      var fallbackSetMode = initLayerSwitch();
      fallbackSetMode(readLayer());
      initSearch();
      setListOpen(false);

      // Initialize user location button if it exists
      if (el.locationButton) {
        el.locationButton.addEventListener('click', function () {
          if (state.locationTracking) {
            stopLocationTracking();
            el.locationButton.classList.remove('active');
            el.locationButton.setAttribute('aria-pressed', 'false');
          } else {
            startLocationTracking();
            el.locationButton.classList.add('active');
            el.locationButton.setAttribute('aria-pressed', 'true');
          }
        });
      }

      setListOpen(false);
      if (el.toggleList && el.listPanel) {
        el.toggleList.addEventListener('click', function () {
          setListOpen(!el.listPanel.classList.contains('open'));
        });
      }
      document.addEventListener('click', function (e) {
        if (!el.listPanel || !el.listPanel.classList.contains('open')) return;
        if (!el.listCtl || el.listCtl.contains(e.target)) return;
        setListOpen(false);
      });
      if (el.listCtl) {
        el.listCtl.addEventListener('keydown', function (e) {
          if (e.key === 'Escape') setListOpen(false);
        });
      }
    } catch (err) {
      console.error('Error initializing map:', err);
      failLoader('Could not initialize map', err.message);
      settleReady(null);
      return;
    }
    hideLoader();
    toast('Using free OpenStreetMap map.', 8000);
  }

  /*
   * The handle admin-map.js builds on.
   *
   * This file owns the only Leaflet instance on /admin/map, so the admin layer
   * cannot create a second map on the same container. Everything it needs is
   * exposed here instead. ready is a promise rather than a flag because boot()
   * is async: admin-map.js binds its button on DOMContentLoaded, which is long
   * before the map exists.
   */
  var readyResolve;
  var readyPromise = new Promise(function (resolve) { readyResolve = resolve; });

  /*
   * Settles ready, with the map or with null.
   *
   * Settling with null matters: a page waiting on this promise would otherwise
   * wait forever on every failure path - a failed data fetch, a missing map
   * container - and never show its own error. boot() settles it on the way out
   * either way, and settleOnce keeps the failure path from settling a second
   * time after the success path already has.
   */
  var readySettled = false;
  function settleReady(map) {
    if (readySettled) return;
    readySettled = true;
    readyResolve(map || null);
  }

  window.PublicMapApi = {
    ready: readyPromise,
    getMap: function () { return state.fallbackMap; },
    getStations: function () { return state.stations; },
    reloadStations: reloadStations,
    onMapReady: settleReady,

    /*
     * Set by the admin map to take over a station pin: its presence is what
     * makes renderStations() create draggable pins and hand over the click, and
     * it is called once per pin as they are created.
     *
     * @param {Object} station
     * @param {L.Marker} marker
     */
    onStationPin: null,
  };

  document.addEventListener('DOMContentLoaded', boot);
})();
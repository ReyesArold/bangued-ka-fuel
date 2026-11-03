<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
<meta name="theme-color" content="#0f766e">
<title>Bangued-Ka-Fuel | Interactive Map</title>
<meta name="description" content="Interactive map of gasoline stations in Bangued, Abra, Philippines, with current fuel prices.">
{{-- Order matters.
     app.css first: it owns the header, the map controls, the legend, the
     station list, the toast and the pins. admin.css second, because the station
     dialog this page opens needs its form, modal, button and table styling,
     which exist nowhere else - the "Add station" flow was unopenable without
     it. admin-map.css last, so the rules specific to this page win. --}}
<link rel="stylesheet" href="/css/app.css">
<link rel="stylesheet" href="/css/admin.css">
<link rel="stylesheet" href="/css/admin-map.css">
{{-- Loaded last: the toast is declared once, here, for every page. --}}
<link rel="stylesheet" href="/css/toast.css">
<link rel="stylesheet" href="/vendor/leaflet/leaflet.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='5' fill='%230f766e'/><path d='M6 2h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-.6v1.8a1 1 0 0 1-1 1H7.6a1 1 0 0 1-1-1V17H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z' fill='%23fff'/><path d='M7 5h3v4H7z' fill='%230f766e'/><path d='M12 5.4h2.2a2.2 2.2 0 0 1 2.2 2.2v3.2' fill='none' stroke='%23fff' stroke-width='1.7' stroke-linecap='round'/><path d='M16.4 9.6 18.4 11.6' fill='none' stroke='%23fff' stroke-width='1.7' stroke-linecap='round'/></svg>">
</head>
<body>

<header class="app-header">
  {{-- Back to the dashboard, in the header's left slot ahead of the title.
       A real link rather than a JS handler, so middle-click, right-click and
       "copy link" all behave the way the browser intends; admin-map.js only
       intercepts a plain left click to warn about unsaved work. --}}
  <a class="back-dashboard" id="back-dashboard" href="{{ route('admin.dashboard') }}"
     aria-label="Back to dashboard" title="Back to dashboard">
    <svg class="back-dashboard-icon" width="16" height="16" viewBox="0 0 24 24" fill="none"
         stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
      <path d="M19 12H5M12 19l-7-7 7-7"></path>
    </svg>
    <span class="back-dashboard-label">Dashboard</span>
  </a>

  <div class="app-title">
    <span class="mark" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="currentColor">
        <path d="M6 2h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-.6v1.8a1 1 0 0 1-1 1H7.6a1 1 0 0 1-1-1V17H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/>
        <path d="M7 5h3v4H7z" fill="#0f766e"/>
        <path d="M12 5.4h2.2a2.2 2.2 0 0 1 2.2 2.2v3.2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        <path d="M16.4 9.6 18.4 11.6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
      </svg>
    </span>
    <span>Bangued-Ka-Fuel</span>
    <small>Bangued, Abra</small>
  </div>

  <div class="header-tools">
    <div class="search-wrap" id="search-wrap">
      <span class="search-icon" aria-hidden="true">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round">
          <circle cx="11" cy="11" r="7"></circle><path d="M20 20l-3.5-3.5"></path>
        </svg>
      </span>
      <label class="visually-hidden" for="search" style="position:absolute;left:-9999px">Search stations</label>
      <input id="search" type="search" autocomplete="off" placeholder="Search station&hellip;"
             role="combobox" aria-expanded="false" aria-controls="search-results" aria-autocomplete="list">
      <button class="search-clear" id="search-clear" type="button" aria-label="Clear search">&times;</button>
      <div class="search-results" id="search-results" role="listbox"></div>
    </div>

    {{-- Same tracker button as public/index.html. map.js wires #location-button
         whenever the element is present, so both map surfaces get the feature;
         without it here, the admin map silently had a location tracker that
         could never be turned on. --}}
    <button class="location-button" id="location-button" type="button" aria-label="Track my location" aria-pressed="false" title="Show and track my location on the map">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
        <circle cx="12" cy="12" r="4"></circle>
        <path d="M12 2v4M12 18v4M2 12h4M18 12h4"></path>
      </svg>
    </button>
  </div>

  {{-- The add-station control sits in the header's right-hand slot, clear of the
       centred search. It carries its own class rather than .btn because the
       button's appearance here - pill, 38px, crosshair state - is the map's,
       not the dashboard's; admin-map.css owns it. --}}
  <div class="header-actions">
    <button class="add-station-btn" type="button" id="btn-add-station-map" aria-pressed="false">
      <svg class="add-station-btn-icon" width="16" height="16" viewBox="0 0 24 24" fill="none"
           stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true">
        <path d="M12 5v14M5 12h14"/>
      </svg>
      <span class="add-station-btn-label">Add station</span>
    </button>
  </div>
</header>

<main id="map" aria-label="Map of gasoline stations in Bangued, Abra"></main>

{{-- Google-style map-type pill, anchored bottom-left like Google Maps. It maps
     onto the API's own map types, so Google's built-in control is switched off
     in map.js. The legend's default anchor stacks above this row. --}}
<div class="mapctl">
  <div class="layer-switch" role="group" aria-label="Base map layer">
    <button type="button" id="layer-street" aria-pressed="true">Default</button>
    <button type="button" id="layer-satellite" aria-pressed="false">Satellite</button>
    <button type="button" id="layer-hybrid" aria-pressed="false">Hybrid</button>
    <button type="button" id="layer-cyclosm" aria-pressed="false">CyclOSM</button>
  </div>
</div>

{{-- Dropdown anchor. The toggle and the panel share one fixed wrapper so the
     panel can position itself with top:100%/right:0 and stay glued to the
     button's own height and right edge at any zoom, header wrap or font size,
     with no magic pixel offsets to keep in sync. --}}
<div class="list-ctl">
  <button class="list-toggle" type="button" id="toggle-list"
          aria-expanded="false" aria-controls="list-panel"
          title="Show or hide the station list">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round">
      <path d="M4 6h16M4 12h16M4 18h16"></path>
    </svg>
    <span>Stations</span>
  </button>

  <aside class="list-panel" id="list-panel" aria-label="Gas station list">
    <div class="list-head">
      <span>Gas Stations</span>
      <span class="list-count" id="list-count">0</span>
    </div>
    <div class="list-body" id="list-body"></div>
  </aside>
</div>

<section class="legend" id="legend" aria-label="Map legend">
  <div class="legend-head" id="legend-head">
    <h2>Legend</h2>
    <button class="legend-collapse" id="legend-collapse" type="button" aria-expanded="true" aria-controls="legend-body" aria-label="Collapse legend">&minus;</button>
  </div>
  <div class="legend-body" id="legend-body">
    <div id="legend-brands"></div>
    <div class="legend-actions">
      <button type="button" id="legend-all">Show all</button>
      <button type="button" id="legend-none">Hide all</button>
    </div>
    <p class="legend-note">
      Untick a brand to hide its stations.
      The dashed line is the Bangued municipal boundary.
    </p>
  </div>
</section>

{{-- Site footer. Sits at the very bottom of the map, outside the legend on
     purpose: the legend body is collapsible and remembers that state, so
     anything inside it disappears for anyone who folds the panel away.
     route() rather than literal paths, because this one is a Blade view. --}}
<footer class="mapfoot">
  <a href="{{ route('about') }}">About</a>
  <span aria-hidden="true">&middot;</span>
  <a href="{{ route('privacy') }}">Privacy</a>
</footer>
{{-- ============================ Station create / edit ============================ --}}
{{-- Copied verbatim from admin.html. The admin map's "Add station" flow calls
     openAddStationModal() / saveStation(), which read #station-form and every
     f-* field by name, so this dialog has to exist on the map page too - it used
     to exist only on the dashboard, where clicking the map button therefore
     threw on a null form and nothing could be saved. --}}
<div class="modal-backdrop" id="station-modal" hidden>
  <div class="modal station-edit-card" role="dialog" aria-modal="true" aria-labelledby="station-modal-title">
    <div class="modal-head">
      <h2 id="station-modal-title">Add station</h2>
      <button class="x" type="button" data-close aria-label="Close">&times;</button>
    </div>

    {{-- Two columns from 700px up: the collapsible price editor on the left,
         the unchanged station form on the right. Both scroll on their own
         inside the 90vh card. Under 700px this stacks and the price editor
         becomes an accordion above the form; the markup is identical either way,
         so only admin-map.css changes between the two. --}}
    <div class="modal-body station-edit-body">
      <aside class="edit-prices" id="edit-prices">
        <button type="button" class="edit-prices-head" id="edit-prices-toggle"
                aria-expanded="false" aria-controls="edit-prices-reveal">
          <svg class="edit-prices-icon" width="16" height="16" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M3 22h12M4 9h10M14 22V4a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v18M14 13h2a2 2 0 0 1 2 2v2a2 2 0 0 0 2 2a2 2 0 0 0 2-2V9.83a2 2 0 0 0-.59-1.42L18 5"></path>
          </svg>
          <span class="edit-prices-title">Edit prices</span>
          <svg class="edit-prices-chevron" width="14" height="14" viewBox="0 0 24 24" fill="none"
               stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6 9l6 6 6-6"></path>
          </svg>
        </button>

        <div class="edit-prices-reveal" id="edit-prices-reveal">
          <div class="edit-prices-body">
            <div class="edit-prices-rows" id="edit-prices-rows"></div>
            <p class="edit-prices-empty" id="edit-prices-empty" hidden>No prices yet. Click + Add fuel.</p>
            <button type="button" class="btn btn-sm edit-prices-add" id="edit-prices-add">+ Add fuel</button>
            {{-- Per-row messages are rendered by admin-map.js into
                 .edit-price-error, keyed by the server's row index. --}}
            <div class="alert alert-error edit-prices-summary" id="edit-prices-error" role="alert" hidden></div>
          </div>
        </div>
      </aside>

      <div class="station-edit-form">
        {{-- Shown only in prices-only mode, i.e. for a station manager. Says why
             the fields below cannot be touched, so greyed-out inputs do not read
             as a broken dialog. --}}
        <p class="prices-only-note" id="prices-only-note" hidden>
          Only prices can be edited by station managers.
        </p>

        <div class="alert alert-error" id="station-error" role="alert" hidden></div>

        <form id="station-form" novalidate>
          <div class="grid-2">
            <div class="field">
              <label for="f-brand">Brand <span class="req">*</span></label>
              <select id="f-brand" required></select>
            </div>
            <div class="field">
              <label for="f-name">Station name <span class="req">*</span></label>
              <input type="text" id="f-name" maxlength="160" required>
            </div>
          </div>

          <div class="field">
            <label for="f-address">Street address</label>
            <input type="text" id="f-address" maxlength="400" placeholder="e.g. Torrijos Street, Zone 5">
          </div>

          <div class="grid-2">
            <div class="field">
              <label for="f-barangay">Barangay</label>
              <input type="text" id="f-barangay" maxlength="120" placeholder="e.g. Lipcan">
            </div>
            <div class="field">
              <label for="f-hours">Operating hours</label>
              <input type="text" id="f-hours" maxlength="200" placeholder="e.g. 24 hours">
            </div>
          </div>

          <fieldset>
            <legend>Location</legend>
            <div class="picker">
              <div class="picker-map" id="picker"></div>
              <p class="picker-help">
                Click the map or drag the pin to set the exact position, or just type the
                coordinates below. Positions outside Bangued are rejected when you save.
                Leave the coordinates empty to keep this station off the public map as a placeholder.
              </p>
              <p class="picker-help">
                OpenStreetMap &mdash; no API key or quota required.
              </p>
            </div>
            <div class="grid-2">
              <div class="field">
                <label for="f-lat">Latitude</label>
                {{-- step="any" rather than 0.000001: a fixed step makes the
                     field :invalid for any value that is not an exact multiple
                     of it, which blocks form submission. Range is still enforced
                     in validateCoords() so a bad pair cannot be saved. --}}
                <input type="number" id="f-lat" step="any" min="-90" max="90"
                       inputmode="decimal" placeholder="17.596500">
              </div>
              <div class="field">
                <label for="f-lng">Longitude</label>
                <input type="number" id="f-lng" step="any" min="-180" max="180"
                       inputmode="decimal" placeholder="120.616700">
              </div>
            </div>
            <div class="grid-2">
              <div class="field">
                <label for="f-conf">Coordinate confidence</label>
                <select id="f-conf">
                  <option value="confirmed">Confirmed &mdash; verified on site</option>
                  <option value="approximate">Approximate &mdash; needs verification</option>
                </select>
              </div>
              <div class="field">
                <label class="checkline" style="margin-top:22px">
                  <input type="checkbox" id="f-active" checked>
                  Show on public map
                </label>
              </div>
            </div>
            <div class="field">
              <label for="f-manager">Station manager</label>
              <select id="f-manager">
                <option value="">None (Unassigned)</option>
              </select>
              {{-- Shown when "+ New manager" is chosen above. The account is
                   created by the SAME request that saves the station, so a
                   manager account never exists without a station to go with it. --}}
              <div class="new-manager-fields" id="new-manager-fields" hidden>
                <label class="sub-label" for="f-manager-name">New manager name</label>
                <input type="text" id="f-manager-name" maxlength="160" placeholder="e.g. Maria Santos">
                <label class="sub-label" for="f-manager-email">Email <span class="req">*</span></label>
                <input type="email" id="f-manager-email" maxlength="160" placeholder="maria@example.com">
                <label class="sub-label" for="f-manager-password">Temporary password <span class="req">*</span></label>
                <input type="text" id="f-manager-password" maxlength="200" autocomplete="off"
                       placeholder="At least 8 characters - share it with them">
              </div>
              <p class="hint">One manager per station. A manager can only edit fuel prices of the station they are assigned to.</p>
            </div>
          </fieldset>

          <fieldset>
            <legend>Contact &amp; notes</legend>
            <div class="grid-2">
              <div class="field">
                <label for="f-phone">Contact phone</label>
                <input type="text" id="f-phone" maxlength="60" placeholder="e.g. 0917 123 4567">
              </div>
              <div class="field">
                <label for="f-email">Contact email</label>
                <input type="email" id="f-email" maxlength="160" placeholder="optional">
              </div>
            </div>
            <div class="field">
              <label for="f-notes">Notes</label>
              <textarea id="f-notes" maxlength="2000" placeholder="Anything worth showing in the station popup."></textarea>
              <p class="hint">Notes are shown to everyone in the station popup.</p>
            </div>
            <div class="field">
              <label for="f-osm">OpenStreetMap reference</label>
              <input type="text" id="f-osm" maxlength="60" placeholder="e.g. node/4525780866">
              <p class="hint">Optional provenance note, e.g. the OSM object the coordinates came from.</p>
            </div>
          </fieldset>
        </form>
      </div>
    </div>
    <div class="modal-foot">
      <button class="btn" type="button" data-close>Cancel</button>
      <button class="btn btn-primary" type="submit" form="station-form" id="station-save">Save station</button>
    </div>
  </div>
</div>

{{-- ============================== Fuel prices modal ============================== --}}
<div class="modal-backdrop" id="price-modal" hidden>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="price-modal-title">
    <div class="modal-head">
      <h2 id="price-modal-title">Fuel prices</h2>
      <button class="x" type="button" data-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-error" id="price-error" role="alert" hidden></div>
      <p class="hint" style="margin:0 0 14px" id="price-sub"></p>

      {{-- The price inputs live in a real form so the footer button can submit
           it via form="price-form" even though the button sits outside the
           form (it has to, for the footer's layout). Without this there was no
           #price-form at all, so nothing could trigger savePrices(). --}}
      <form id="price-form" novalidate>
        <div class="field">
          <label for="p-effective">Effective as of</label>
          <input type="datetime-local" id="p-effective" name="effectiveAt">
          <p class="hint">Shown to the public as &ldquo;last updated&rdquo;.</p>
        </div>

        <div class="price-rows" id="price-rows"></div>
        <button class="btn btn-sm" type="button" id="price-add" style="margin-top:10px">+ Add fuel type</button>
        {{-- Autocomplete hints for the fuel-type inputs rendered into #price-rows.
             Populated from GET /api/admin/config so there is a single source of
             truth; without it every row's list="fuel-types" pointed at nothing. --}}
        <datalist id="fuel-types"></datalist>
      </form>

      <details style="margin-top:20px">
        <summary style="cursor:pointer;font-size:13px;font-weight:600;color:var(--ink-2)">Price change history</summary>
        <div id="price-history" style="margin-top:10px"></div>
      </details>
    </div>
    <div class="modal-foot">
      <button class="btn" type="button" data-close>Cancel</button>
      <button class="btn btn-primary" type="submit" form="price-form" id="price-save">Save prices</button>
    </div>
  </div>
</div>

{{-- ============================== Change password ============================== --}}
<div class="modal-backdrop" id="password-modal" hidden>
  <div class="modal narrow" role="dialog" aria-modal="true" aria-labelledby="password-modal-title">
    <div class="modal-head">
      <h2 id="password-modal-title">Change password</h2>
      <button class="x" type="button" data-close aria-label="Close">&times;</button>
    </div>
    <div class="modal-body">
      <div class="alert alert-error" id="password-error" role="alert" hidden></div>
      <form id="password-form" novalidate>
        <div class="field">
          <label for="pw-current">Current password <span class="req">*</span></label>
          <input type="password" id="pw-current" autocomplete="current-password" required>
        </div>
        <div class="field">
          <label for="pw-new">New password <span class="req">*</span></label>
          {{-- minlength="8" is advisory only: the same rule is enforced server
               side, and the browser would otherwise block submission before our
               own message could explain why. --}}
          <input type="password" id="pw-new" autocomplete="new-password" minlength="8" required>
          <p class="hint">At least 8 characters. The sample password should be changed before going live.</p>
        </div>
        <div class="field">
          <label for="pw-confirm">Confirm new password <span class="req">*</span></label>
          <input type="password" id="pw-confirm" autocomplete="new-password" minlength="8" required>
        </div>
      </form>
    </div>
    <div class="modal-foot">
      <button class="btn" type="button" data-close>Cancel</button>
      <button class="btn btn-primary" type="submit" form="password-form" id="password-save">Update password</button>
    </div>
  </div>
</div>

<div class="toast" id="toast" role="status" aria-live="polite" hidden></div>

<div class="loader" id="loader">
  <div class="loader-box">
    <div class="spinner" role="presentation"></div>
    <p id="loader-text">Loading gas stations&hellip;</p>
  </div>
</div>

<script src="/vendor/leaflet/leaflet.js"></script>
<script src="/js/icons.js"></script>
<script src="/js/api.js"></script>
<script src="/js/time.js"></script>
<script src="/js/legend.js"></script>
<script src="/js/toast.js"></script>
<script src="/js/map.js"></script>
<script src="/js/admin-map.js"></script>
</body>
</html>

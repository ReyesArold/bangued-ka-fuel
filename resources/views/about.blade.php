@extends('layouts.page')

@section('title', 'About')
@section('eyebrow', 'About this map')
@section('heading', 'Gas Stations in Bangued, Abra')

@section('description', 'What the Bangued gas station map is, where its station and price data comes from, and how accurate it is.')

@section('lede', 'An interactive map of the gasoline stations in Bangued, Abra, with the fuel prices recorded for each one. No account is needed to use it.')

@section('body')
@php
    // One place, read from config, so the About page and the Privacy Policy
    // cannot drift apart. See config/bangued.php -> contact.email.
    $contactEmail = config('bangued.contact.email');
@endphp

<div class="page-body">

  <h2>What this is</h2>

  <p>
    This map lists the fuel stations in and around Bangued, Abra, and shows where
    each one is. Tap a marker to see the station's name, brand, address, opening
    hours and the price recorded for each fuel type.
  </p>

  <p>
    It is a community reference. Prices and details are entered by volunteer
    administrators and reviewed by whoever reports an error, not pulled from the
    stations themselves or from any government source. That makes it useful as a
    quick answer to "which brands are here, and roughly what do they cost" &mdash;
    and unreliable as the last word on either.
  </p>

  <h2>Who maintains it</h2>

  <p>
    This map is maintained by a small number of volunteers from Bangued. There is
    no company behind it and no advertising.
  </p>

  {{-- REPLACE: the maintainer's name or group, and how to reach them beyond the
       corrections address below. Delete this comment once the sentence is real. --}}
  <p>
    <strong>Maintained by:</strong> <span class="email">REPLACE &mdash; add your name or group here</span>
  </p>

  <h2>Where the information comes from</h2>

  <p>
    Every station carries a confidence flag, so you can tell how much to trust its
    pin. The flag is part of the station's data and is kept up to date as pins are
    verified.
  </p>

  <div class="table-scroll">
    <table class="data-table">
      <caption>How each station's position was established.</caption>
      <thead>
        <tr>
          <th scope="col">Confidence</th>
          <th scope="col">What it means</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><strong>Confirmed</strong></td>
          <td>The position was supplied and confirmed by someone who knows the station. Trust the pin.</td>
        </tr>
        <tr>
          <td><strong>Approximate</strong></td>
          <td>
            The pin is a reasonable guess &mdash; often the centre of a village or
            a nearby landmark taken from OpenStreetMap rather than the forecourt
            itself. The station is real; the pin may be a short walk away.
          </td>
        </tr>
        <tr>
          <td><strong>Pending</strong></td>
          <td>
            The station is known to exist but its position is not yet confirmed.
            Pending stations are <strong>not shown on the map</strong> until
            somebody verifies them.
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <p>
    Road, barangay and place names come from OpenStreetMap. Where an OpenStreetMap
    entry exists for a station it is referenced on the station's record, so you
    can check a location against an independent source.
  </p>

  <h2>About the prices</h2>

  <div class="notice">
    <p>
      <strong>Check before you travel.</strong> Prices here are typed in by
      volunteers and may be hours, days or weeks out of date &mdash; or simply
      wrong. They are not published by the fuel companies and nothing on this site
      is an official price.
    </p>
    <p>
      Confirm the price at the pump, especially before making a long trip for a
      specific fuel type.
    </p>
  </div>

  <p>
    Each price also carries the date and time it was last changed, so you can see
    how current it is. The station list does not refresh by itself while you have
    the page open &mdash; reload the map to pick up a newer figure.
  </p>

  <p>
    Every change is recorded with the time it was made and the account that made
    it, so a figure can be traced back to whoever entered it. That history is the
    reason to trust a price more than an unsourced one.
  </p>

  {{-- REPLACE: drop this paragraph if you never run the demo-price seed on a
       public server. It exists because `bangued:seed --demo-prices` loads
       clearly-fictional round numbers, and a visitor seeing those has no way to
       tell them from real ones. --}}
  <p>
    <strong>A note on demonstration data:</strong> this site can be loaded with
    made-up example prices for testing, using clearly rounded figures that no
    station would post. If you see a suspiciously round price everywhere, it is
    demonstration data, not a real market rate.
  </p>

  <h2>How to use the map</h2>

  <ul>
    <li><strong>Search</strong> for a station by name, or tap a brand in the legend to hide or show that brand's pins.</li>
    <li><strong>Tap a marker</strong> for that station's details and prices.</li>
    <li><strong>Switch the base map</strong> between street, satellite and hybrid using the Default / Satellite / Hybrid control.</li>
    <li><strong>Find yourself</strong> with the crosshair button in the header. Your browser will ask permission first.</li>
    <li><strong>Your choices are remembered</strong> in your own browser &mdash; your base map and brand filters carry over between visits. Nothing about you is sent to us.</li>
  </ul>

  <p>
    The map covers Bangued town proper. It cannot be panned outside the municipal
    boundary, and stations outside it are not listed.
  </p>

  <h2>Map data and attribution</h2>

  <p>The basemap is not ours to license, so the people who make it get the credit:</p>

  <ul>
    <li>Street map &copy; <a href="https://www.openstreetmap.org/copyright" rel="noopener noreferrer" target="_blank">OpenStreetMap</a> contributors</li>
    <li>Satellite imagery &copy; <a href="https://www.esri.com/" rel="noopener noreferrer" target="_blank">Esri</a></li>
    <li>Labels &copy; <a href="https://carto.com/attributions" rel="noopener noreferrer" target="_blank">CARTO</a>, on OpenStreetMap data</li>
    <li>The municipal boundary is derived from published geographic data</li>
  </ul>

  <p>
    The map itself is drawn with <a href="https://leafletjs.com/" rel="noopener noreferrer" target="_blank">Leaflet</a>.
    OpenStreetMap's data is shared under the
    <a href="https://opendatacommons.org/licenses/odbl/" rel="noopener noreferrer" target="_blank">Open Database License</a>,
    and we ask you to keep the attribution visible if you reuse it.
  </p>

  <h2>Found a mistake?</h2>

  <p>
    Wrong price, moved pin, missing station, a station that has closed &mdash;
    please report it. Corrections are the most useful thing anybody can send, and
    they usually take a few minutes.
  </p>

  <p>
    <a class="email" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
  </p>

  <p>
    Please include the station name and what the correct value should be. If you
    can, say how you know &mdash; "I was standing there this morning" is worth a
    lot more than "a website said so".
  </p>

  <h2>Your privacy</h2>

  <p>
    Viewing this map requires no account and we ask for no personal information.
    Your location, if you use the locate button, stays in your browser and is never
    sent to us. The full details are on the
    <a href="{{ route('privacy') }}">privacy&nbsp;policy</a> page.
  </p>

</div>
@endsection
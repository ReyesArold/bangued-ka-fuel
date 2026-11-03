@extends('layouts.page')

@section('title', 'Privacy Policy')
@section('eyebrow', 'Privacy')
@section('heading', 'Privacy Policy')

@section('description', 'What the Bangued gas station map collects, what it does not, and what happens to your location when you use the map.')

@section('lede', 'The short version: looking at this map requires no account, and we do not want your personal information because we have no use for it. Your location, if you share it, stays in your own browser.')

@section('body')
@php
    // Same single source as the About page.
    $contactEmail = config('bangued.contact.email');
@endphp

<div class="page-body">

  <div class="notice">
    <p>
      <strong>This page describes what the software actually does.</strong> Every
      statement below was checked against the running code rather than written from
      intention, so it also covers the parts that are easy to forget &mdash; tile
      servers, server logs and administrator accounts.
    </p>
  </div>

  <h2>Who this is for</h2>

  <p>
    This policy covers the public parts of this site: the map, the
    <a href="{{ route('about') }}">about page</a>, and these pages. It does not
    cover the private administration area, which is described separately below
    because accounts change what is stored.
  </p>

  <h2>What we collect when you just look at the map</h2>

  <p>
    <strong>Nothing that identifies you.</strong> You can search, filter, view
    prices and turn on your location without an account, and we ask for no name,
    no email address and no sign-up.
  </p>

  <p>
    Loading the map does send the ordinary web request that any website sends.
    As with every site on the internet, the web server may record your IP address,
    the time of the request and your browser's user-agent string in its ordinary
    access logs. That is standard server operation rather than anything this site
    does deliberately, and we do not use those logs to build a profile of you.
  </p>

  <h2>What is saved in your own browser</h2>

  <p>
    Two small preferences are stored locally so the map behaves the way you left
    it. They never leave your device and we cannot read them:
  </p>

  <ul>
    <li><strong>Your base map choice</strong> &mdash; whether you last used street, satellite or hybrid tiles.</li>
    <li><strong>Your brand filter</strong> &mdash; which brands you unticked, and whether the legend was folded away.</li>
  </ul>

  <p>
    This is ordinary browser storage (<code>localStorage</code>), not a cookie,
    and it is not attached to your name in any way. If you want to clear it, use
    your browser's <em>clear site data</em> for this site; the map will simply
    forget your choices and start fresh.
  </p>

  <h2>Your location</h2>

  <p>
    The crosshair button in the header shows you where you are on the map. It is
    entirely optional and off until you press it.
  </p>

  <ul>
    <li>Your browser asks for permission first. If you decline, nothing else changes &mdash; every other feature still works, and you can still use the map by panning and zooming.</li>
    <li><strong>Your position is never sent to us.</strong> It is read by your browser and used to place a marker on the map, in the same way a weather app draws a rain radar for your area.</li>
    <li>It is not stored, not logged, and not associated with anything.</li>
    <li>Press the button again, or close the tab, to stop it.</li>
  </ul>

  <h3>One exception worth knowing about</h3>

  <p>
    If you use a station's <strong>directions</strong> link, it opens OpenStreetMap
    in a new tab with that station's coordinates in the address. From that point
    you are on openstreetmap.org and their privacy policy applies. Nothing about
    you is sent &mdash; only the station's location.
  </p>

  <h2>Cookies</h2>

  <p>
    <strong>Visitors who are not signed in get no cookies.</strong> The map, the
    about page and these pages set none.
  </p>

  <p>
    A single cookie is set if you sign in to the administration area. It is called
    <code>laravel_session</code>, it is not readable by JavaScript, it is marked
    <code>SameSite=Lax</code>, it is marked secure on an encrypted connection, and
    it expires after 12 hours or when you sign out, whichever comes first. It
    holds nothing but a session identifier; your account details stay on the
    server.
  </p>

  <h2>Map tile servers</h2>

  <p>
    This is the one place where looking at the map involves third parties, so it is
    worth being plain about. The map is assembled from tiles fetched from external
    servers, and fetching a tile necessarily tells that server which part of the
    map you are looking at and your IP address.
  </p>

  <ul>
    <li><strong>OpenStreetMap</strong> &mdash; street map tiles and the links to OpenStreetMap</li>
    <li><strong>Esri</strong> &mdash; satellite imagery</li>
    <li><strong>CARTO</strong> &mdash; the labels layer</li>
  </ul>

  <p>
    Whichever of those tiles the map is showing will see your IP address and the
    tile coordinates for the area in view. Each provider has its own privacy
    policy, linked in the map's attribution. They are not sent anything about you
    beyond that request, we do not send them anything extra, and we do not use
    them for advertising or profiling.
  </p>

  <h2>What we do not use</h2>

  <p>Stated explicitly, because "we don't collect much" is easier to believe against a list:</p>

  <ul>
    <li>No analytics, and no page-view or behaviour tracking of any kind</li>
    <li>No advertising, ad networks or retargeting</li>
    <li>No tracking pixels, beacons or fingerprinting</li>
    <li>No social media widgets, share buttons or embedded third-party content</li>
    <li>No third-party fonts, scripts or stylesheets &mdash; everything is served from this site except the map tiles above</li>
    <li>No selling, renting or sharing of your information with anyone, for any purpose</li>
  </ul>

  <h2>Administrator accounts</h2>

  <p>
    Signing in is optional and is only for people who maintain the map. If you do
    sign in, more is stored, so it is set out here:
  </p>

  <ul>
    <li><strong>Your account details</strong> &mdash; a username, an email address, a display name and, for station managers, the brand and stations they are responsible for.</li>
    <li><strong>Each time you sign in</strong> &mdash; the date and time, so an account holder can tell whether someone else has been using it.</li>
    <li><strong>Failed sign-in attempts</strong> &mdash; counted against your IP address and username together, to slow down automated guessing. Eight failures in ten minutes locks sign-in for that pairing.</li>
    <li><strong>Every price change you make</strong> &mdash; the old price, the new price, the time, and your username. This is deliberately kept as a permanent audit trail so a figure can always be traced to whoever entered it.</li>
  </ul>

  <p>
    Your password is stored only as a one-way bcrypt hash and cannot be read back,
    by us or by anyone who obtains the database. It is never included in any
    response from this site.
  </p>

  <h2>Children</h2>

  <p>
    This site is a map of fuel stations and is not directed at children under 13.
    We do not knowingly collect information from children, and since we do not
    collect information from anyone at all when they simply view the map, there is
    nothing here for a child to have disclosed.
  </p>

  <h2>Your choices</h2>

  <ul>
    <li>Use the map without ever turning on your location &mdash; nothing is stored.</li>
    <li>Decline the location permission, or turn tracking off with the same button. The map works fully either way.</li>
    <li>Clear this site's stored data in your browser at any time to forget your base map and brand choices.</li>
    <li>Ask us to delete or correct anything we hold about you &mdash; see below.</li>
  </ul>

  <h2>Changes to this policy</h2>

  <p>
    If this policy changes, the date at the bottom of this page changes with it
    and the new version replaces this one here. We will not quietly weaken it: if a
    change started collecting something new, that would be stated on this page
    before it began.
  </p>

  <h2>About this copy of the site</h2>

  {{-- REPLACE: keep in step with about.blade.php's placeholder. If the demo-price
       seed is never run publicly, this section can go. --}}
  <p>
    <strong>If you are reading this on a demonstration or test copy of the site,</strong>
    the station and price data in it may be made-up example data rather than real
    market prices, and the administrator accounts on it are throwaway accounts.
    Nothing on a test copy should be treated as a real record.
  </p>

  <h2>Contact</h2>

  <p>
    Questions about this policy, or a request to see, correct or delete something
    we hold about you:
  </p>

  <p>
    <a class="email" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
  </p>

  <p class="page-updated">Last updated: 6 October 2026</p>

</div>
@endsection
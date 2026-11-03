<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">
<meta name="theme-color" content="#0f766e">
<title>@yield('title') | Bangued-Ka-Fuel</title>
<meta name="description" content="@yield('description')">
{{-- page.css alone: these pages are a scrolling reading column, and app.css's
     shell assumes a viewport-filling map. See the note at the top of page.css. --}}
<link rel="stylesheet" href="/css/page.css">
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='5' fill='%230f766e'/><path d='M6 2h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-.6v1.8a1 1 0 0 1-1 1H7.6a1 1 0 0 1-1-1V17H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z' fill='%23fff'/><path d='M7 5h3v4H7z' fill='%230f766e'/><path d='M12 5.4h2.2a2.2 2.2 0 0 1 2.2 2.2v3.2' fill='none' stroke='%23fff' stroke-width='1.7' stroke-linecap='round'/><path d='M16.4 9.6 18.4 11.6' fill='none' stroke='%23fff' stroke-width='1.7' stroke-linecap='round'/></svg>">
</head>
<body>

<a class="skip-link" href="#main">Skip to content</a>

<header class="page-header">
  <div class="page-brand">
    <span class="mark" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="currentColor">
        <path d="M6 2h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-.6v1.8a1 1 0 0 1-1 1H7.6a1 1 0 0 1-1-1V17H6a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1z"/>
        <path d="M7 5h3v4H7z" fill="#0f766e"/>
        <path d="M12 5.4h2.2a2.2 2.2 0 0 1 2.2 2.2v3.2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
        <path d="M16.4 9.6 18.4 11.6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/>
      </svg>
    </span>
    <span class="name">Bangued-Ka-Fuel</span>
    <span class="where">Bangued, Abra</span>
  </div>

  {{-- About and Privacy are the only two non-map pages, so they are tabs rather
       than a menu. aria-current="page" marks the open one and is what page.css
       styles the highlight from, so the correct tab highlights itself with no
       per-page "active" class to keep in step.

       The @if hugs the attribute on both sides on purpose: with spaces around it
       Blade emits `aria-current="page" >`, and the stray space ends up in the
       rendered HTML that both the tests and the CSS attribute selector read. --}}
  <nav class="page-nav" aria-label="Site information">
    <a href="{{ route('about') }}"@if(request()->routeIs('about')) aria-current="page"@endif>About</a>
    <a href="{{ route('privacy') }}"@if(request()->routeIs('privacy')) aria-current="page"@endif>Privacy</a>
  </nav>
</header>

<main class="page-wrap" id="main">
  <p class="page-eyebrow">@yield('eyebrow')</p>
  <h1 class="page-title">@yield('heading')</h1>

  @hasSection('lede')
    <p class="page-lede">@yield('lede')</p>
  @endif

  @yield('body')
</main>

<footer class="page-footer">
  <div class="page-footer-inner">
    <a class="back-link" href="{{ route('home') }}">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"
           stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
        <path d="M19 12H5M12 19l-7-7 7-7"></path>
      </svg>
      Back to the map
    </a>
    <p class="foot-note">
      Gas Stations in Bangued, Abra &middot;
      <a href="{{ route('about') }}">About</a> &middot;
      <a href="{{ route('privacy') }}">Privacy</a>
    </p>
  </div>
</footer>

</body>
</html>
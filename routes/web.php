<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->file(public_path('index.html'));
})->name('home');

Route::get('/login', function () {
    return response()->file(public_path('login.html'));
})->name('login');

Route::get('/admin', function () {
    return response()->file(public_path('admin.html'));
})->name('admin.dashboard');

/*
 * The only page rendered through Blade rather than served as a static file, so
 * the views can reach route() - the header's "Dashboard" link is built with
 * route('admin.dashboard') rather than a hard-coded /admin.
 */
Route::get('/admin/map', function () {
    return view('admin.map');
})->name('admin.map');

/*
 * About and Privacy Policy - the public, indexed, prose pages.
 *
 * Blade rather than static files in public/, unlike the four above. They need a
 * shared layout, a mailto: address and a cross-link between the two of them,
 * which is what a layout and route() are for. They read no database and no
 * session, so they render even when the station data does not - deliberately,
 * since these are the pages a reader reaches when something else is broken.
 *
 * Reachable from the legend footer on the map. The links there are hard-coded
 * /about and /privacy for the same reason the static pages hard-code /css/app.css:
 * index.html is served as a file and cannot call route().
 */
Route::view('/about', 'about')->name('about');
Route::view('/privacy', 'privacy')->name('privacy');

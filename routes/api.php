<?php

use App\Http\Controllers\AdminApiController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\PublicApiController;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequireAdminRole;
use App\Models\Station;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| Same paths, methods, auth rules and JSON envelopes as the Node version, so
| public/js/api.js and the rest of the frontend are unchanged:
|   - read endpoints answer { "data": ... }
|   - /api/meta is the one exception and answers unwrapped (map.js reads
|     meta.map directly)
|   - everything under /api/admin requires a signed-in administrator
|
*/

Route::get('/health', function () {
    return response()->json([
        'data' => [
            'ok' => true,
            'stations' => Station::count(),
            'uptimeSeconds' => (int) (microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']),
        ],
    ]);
});

Route::get('/meta', [PublicApiController::class, 'meta']);
Route::get('/brands', [PublicApiController::class, 'brands']);
Route::get('/stations', [PublicApiController::class, 'stations']);
Route::get('/stations/{id}', [PublicApiController::class, 'station']);

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout']);
Route::get('/auth/me', [AuthController::class, 'me']);
Route::post('/auth/change-password', [AuthController::class, 'changePassword']);

Route::middleware(RequireAdmin::class)->prefix('admin')->group(function () {
    /*
     * Open to both roles. GET /stations filters to the caller's own stations in
     * the query, and the two price routes are authorised per station by
     * StationPolicy - a manager may edit prices for the stations assigned to
     * them and gets 403 for any other.
     */
    Route::get('/stations', [AdminApiController::class, 'stations']);
    Route::put('/stations/{id}/prices', [AdminApiController::class, 'savePrices']);
    Route::get('/stations/{id}/price-history', [AdminApiController::class, 'priceHistory']);
    Route::get('/brands', [AdminApiController::class, 'brands']);
    Route::get('/config', [AdminApiController::class, 'config']);

    /*
     * The combined save, open to both roles.
     *
     * PATCH /stations/{id} carries three different intents depending on who
     * sends it: the admin map's Save button (station fields plus prices), a
     * marker drag (latitude and longitude only), and the dashboard's edit form
     * (station fields only). A station manager may only ever use the first, so
     * the route cannot be gated wholesale - the middleware here would 403 the
     * one request a manager is allowed to make. The decision is made in
     * update(), where the station is known: a manager's body must carry prices
     * and nothing else, is rebuilt from those prices alone, and is then
     * authorised against updatePrices.
     */
    Route::patch('/stations/{id}', [AdminApiController::class, 'update']);

    /*
     * Administrator only: creating and deleting stations.
     *
     * Also covered by StationPolicy, but the middleware answers 403 before a
     * body is parsed, so a manager posting a new station never reaches
     * validation.
     */
    Route::middleware(RequireAdminRole::class)->group(function () {
        Route::post('/stations', [AdminApiController::class, 'store']);
        Route::delete('/stations/{id}', [AdminApiController::class, 'destroy']);
        // The "Manage stations" checklist. Administrator only: it hands out
        // station-level price rights, so it sits behind the same gate as
        // deleting a station.
        Route::put('/managers/{id}/stations', [AdminApiController::class, 'assignStations']);
    });
});

/*
| Unknown API endpoints must answer 404 JSON, not Laravel's HTML error page -
| the frontend reads payload.error.message out of the response.
*/
Route::fallback(function () {
    return response()->json(['error' => ['message' => 'Endpoint not found.']], 404);
});

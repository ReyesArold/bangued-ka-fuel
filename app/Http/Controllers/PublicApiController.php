<?php

namespace App\Http\Controllers;

use App\Http\HttpError;
use App\Http\Validate;
use App\Models\Brand;
use App\Models\Station;
use App\Support\Bangued;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only endpoints the public map uses. No authentication required.
 *
 * Port of src/routes/public.js.
 */
class PublicApiController extends Controller
{
    /**
     * Map configuration, so the client never hardcodes Bangued's geometry.
     *
     * Note this is the one response in the whole API that is NOT wrapped in a
     * "data" key - the frontend reads meta.map directly (public/js/map.js).
     */
    public function meta(): JsonResponse
    {
        return response()->json([
            'place' => config('bangued.place'),
            'map' => Bangued::mapConfig(),
            'fuelTypes' => config('bangued.fuel_types'),
        ]);
    }

    /** Brands with colours and station counts. */
    public function brands(): JsonResponse
    {
        return response()->json([
            'data' => Brand::orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (Brand $b) => $b->toApiArray())->all(),
        ]);
    }

    /** Published (active, non-pending) stations with their prices. */
    public function stations(): JsonResponse
    {
        $stations = Station::published()
            ->with(['brand', 'prices'])
            ->get()
            ->sortBy([
                fn ($a, $b) => $a->brand->sort_order <=> $b->brand->sort_order,
                fn ($a, $b) => strcasecmp($a->name, $b->name),
            ])
            ->values();

        // toApiArray(false) omits the nested `manager` object. Nothing on the
        // public map reads it, and for an account created from the "+ New
        // manager" dialog the username IS the manager's email address - so
        // including it here would publish real people's email addresses on an
        // unauthenticated endpoint. It also saves the per-station query that
        // resolving the manager would cost.
        return response()->json([
            'data' => $stations->map(fn (Station $s) => $s->toApiArray(false))->all(),
        ]);
    }

    /** A single published station. Hidden and pending records read as 404. */
    public function station(Request $request, string $id): JsonResponse
    {
        $station = Station::with(['brand', 'prices'])
            ->published()
            ->find(Validate::id($id, 'station id'));

        if (! $station) {
            throw HttpError::notFound('Station not found.');
        }

        return response()->json(['data' => $station->toApiArray(false)]);
    }
}

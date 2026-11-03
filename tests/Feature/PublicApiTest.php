<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Station;
use Tests\TestCase;

/**
 * Pins the public API contract that the Node version served and that the
 * frontend JS depends on.
 */
class PublicApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBanguedData();
    }

    public function test_meta_is_unwrapped_and_exposes_the_map_payload(): void
    {
        $response = $this->getJson('/api/meta');

        $response->assertOk()->assertJsonStructure([
            'map' => [
                'center',
                'defaultZoom',
                'minZoom',
                'maxZoom',
                // [[minLat, minLon], [maxLat, maxLon]] - the two diagonal
                // corners Leaflet's LatLngBounds is built from, not a flat
                // array.
                'maxBounds' => [0 => ['0', '1'], 1 => ['0', '1']],
                'boundary' => ['geometry' => ['coordinates']],
            ],
            'place',
            'fuelTypes',
        ]);

        $this->assertArrayNotHasKey('data', $response->json(), 'meta must stay unwrapped');
        $this->assertGreaterThan(3, count($response->json('map.boundary.geometry.coordinates.0')));
    }

    /**
     * Hybrid mode draws on Google's tile service, and those tiles are requested
     * from the visitor's own browser, so /api/meta has to carry the browser key
     * for the Hybrid layer to work. It is meant to be public, and must be
     * restricted by HTTP referrer in the Google Cloud console.
     */
    public function test_meta_carries_the_google_maps_key_when_one_is_configured(): void
    {
        config(['bangued.google_maps.api_key' => 'test-maps-key']);

        $response = $this->getJson('/api/meta');

        $this->assertNotNull($response->json('map.boundary'));
        $this->assertSame('test-maps-key', $response->json('map.google'));
    }

    /** Without a key configured, Hybrid falls back to the Esri/CARTO tiles. */
    public function test_meta_omits_the_google_maps_key_when_none_is_configured(): void
    {
        config(['bangued.google_maps.api_key' => null]);

        $this->assertNull($this->getJson('/api/meta')->json('map.google'));
    }

    /** The map clamps to Bangued, so the keys never need a zoom beyond 21 - the
        ceiling Leaflet's OSM tiles cap out at. */
    public function test_meta_max_zoom_stays_within_the_map_ceiling(): void
    {
        $maxZoom = $this->getJson('/api/meta')->json('map.maxZoom');

        $this->assertGreaterThanOrEqual(config('bangued.map.defaultZoom'), $maxZoom);
        $this->assertLessThanOrEqual(21, $maxZoom);
    }

    /**
     * maxBounds is the boundary polygon padded by a margin, not a box fitted to
     * the stations: it is the viewport clamp, so stations must sit INSIDE it
     * but it is expected to be roomier than the tightest station extent.
     */
    public function test_meta_max_bounds_enclose_every_station(): void
    {
        $bounds = $this->getJson('/api/meta')->json('map.maxBounds');
        [$minLat, $minLon] = $bounds[0];
        [$maxLat, $maxLon] = $bounds[1];

        $this->assertGreaterThan($minLat, $maxLat);
        $this->assertGreaterThan($minLon, $maxLon);

        foreach (Station::where('is_pending', false)->get() as $station) {
            $this->assertGreaterThan($minLat, $station->latitude, 'station lat at or below maxBounds');
            $this->assertLessThan($maxLat, $station->latitude, 'station lat at or above maxBounds');
            $this->assertGreaterThan($minLon, $station->longitude, 'station lon at or below maxBounds');
            $this->assertLessThan($maxLon, $station->longitude, 'station lon at or above maxBounds');
        }
    }

    public function test_brands_list_is_wrapped_in_data(): void
    {
        $response = $this->getJson('/api/brands');

        $response->assertOk()->assertJsonStructure(['data' => [['id', 'name', 'colorPrimary', 'markerIcon', 'logoPath', 'sortOrder']]]);
        $this->assertCount(Brand::count(), $response->json('data'));
    }

    public function test_stations_list_omits_pending_and_returns_booleans(): void
    {
        $response = $this->getJson('/api/stations');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(Station::where('is_pending', false)->count(), $data);
        $this->assertNotContains(Station::where('is_pending', true)->count(), [0]);

        foreach ($data as $station) {
            $this->assertIsBool($station['isPending'], 'isPending must be a JSON boolean');
            $this->assertIsBool($station['isActive'], 'isActive must be a JSON boolean');
            $this->assertNotNull($station['latitude']);
            $this->assertNotNull($station['longitude']);
        }
    }

    public function test_inactive_stations_are_hidden_from_the_public_list(): void
    {
        $target = Station::where('is_pending', false)->first();
        $target->update(['is_active' => false]);

        $names = array_column($this->getJson('/api/stations')->json('data'), 'name');

        $this->assertNotContains($target->name, $names);
    }

    public function test_stations_are_sorted_by_brand_order_then_name(): void
    {
        $names = array_column($this->getJson('/api/stations')->json('data'), 'name');

        $this->assertSame(
            ['Shell', 'Caltex (Power V Caltex Service Station)', 'Seaoil #1'],
            $names,
        );
    }

    public function test_price_history_is_admin_only(): void
    {
        $station = Station::where('is_pending', false)->first();

        $this->getJson("/api/admin/stations/{$station->id}/price-history")->assertUnauthorized();
    }
}

<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The "dashed line" boundary both map legend notes describe.
 *
 * Source-level pins: drawBoundary() lives in public/js/map.js, which has no JS
 * test runner. The important contract is that /api/meta ships the boundary as
 * a GeoJSON Feature - not an array - and that drawBoundary() finally handles
 * that shape instead of bailing on it, which is exactly what used to leave the
 * legend describing a line no one ever drew.
 */
class BoundaryRenderingTest extends TestCase
{
    public function test_client_meta_still_hands_the_boundary_to_the_map(): void
    {
        $response = $this->getJson('/api/meta');

        $response->assertOk();
        $this->assertSame('Feature', $response->json('map.boundary.type'));
        $this->assertNotEmpty($response->json('map.boundary.geometry.coordinates.0'));
    }

    public function test_draw_boundary_consumes_the_geojson_feature_shape(): void
    {
        $draw = $this->functionBody('drawBoundary');

        $this->assertStringContainsString("boundary.type === 'Feature'", $draw);
        $this->assertStringContainsString('boundary.geometry.coordinates[0]', $draw);
        // GeoJSON rings are [lon, lat]; Leaflet wants [lat, lon].
        $this->assertStringContainsString('return [lat, lon];', $draw);
        // A GeoJSON polygon whose first ring is not an array must not abort
        // the whole function before the raw-array fallback runs.
        $this->assertStringContainsString('Array.isArray(boundary.geometry.coordinates)', $draw);
    }

    public function test_the_boundary_is_drawn_as_the_legend_describes_it(): void
    {
        $draw = $this->functionBody('drawBoundary');

        $this->assertStringContainsString("dashArray: '6, 6'", $draw);
        $this->assertStringContainsString('color: \'#ff7800\'', $draw);
        $this->assertStringContainsString('fillOpacity: 0.05', $draw);
        $this->assertStringContainsString('noClip: true', $draw);
        $this->assertStringContainsString('interactive: false', $draw);
    }

    public function test_the_boundary_stroke_is_replaced_rather_than_duplicated(): void
    {
        $draw = $this->functionBody('drawBoundary');

        $this->assertStringContainsString('removeLayer(state.boundaryOverlay)', $draw);
    }

    public function test_both_map_surfaces_still_call_it_a_dashed_line(): void
    {
        $this->assertStringContainsString(
            'The dashed line is the Bangued municipal boundary.',
            file_get_contents(public_path('index.html'))
        );

        $this->assertStringContainsString(
            'The dashed line is the Bangued municipal boundary.',
            file_get_contents(resource_path('views/admin/map.blade.php'))
        );
    }

    /** Extracts a single function body from map.js. */
    private function functionBody(string $name): string
    {
        $source = file_get_contents(__DIR__.'/../../public/js/map.js');

        preg_match(
            '/function '.preg_quote($name, '/').'\([^)]*\)\s*\{\s*\n(.*?)\n\s{2}\}/s',
            $source,
            $matches
        );

        $this->assertNotEmpty($matches, "function $name() is missing from public/js/map.js.");

        return $matches[1];
    }
}

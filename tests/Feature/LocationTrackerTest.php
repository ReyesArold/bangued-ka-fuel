<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The "Track my location" button on both maps.
 *
 * These are source-level pins, not a browser run: the tracker lives in
 * public/js/map.js, which has no JS test runner around it. Each assertion
 * targets a place in that file where a behaviour settles somewhere other than
 * a unit-testable DOM, so every one of them corresponds to a behaviour that
 * was observed end-to-end with a real geolocation fix:
 *
 *  - the first fix draws the dot and an accuracy circle;
 *  - while the visitor has not touched the map, the view follows them;
 *  - a drag or zoom hands them the map and stops the following, while the
 *    dot keeps updating;
 *  - a denied permission puts the whole tracker away instead of leaving the
 *    button pressed with nothing running;
 *  - both map surfaces render the button (map.js wires it wherever it exists).
 *
 * The one place the bites are latent is that these pages are served as static
 * files, so nothing here runs the browser; the public/index.html and
 * admin/map.blade.php assertions exist to catch a surface losing its button.
 */
class LocationTrackerTest extends TestCase
{
    private const MAP_JS = __DIR__.'/../../public/js/map.js';

    public function test_first_fix_draws_a_dot_and_an_accuracy_circle(): void
    {
        $update = $this->functionBody('updateUserLocation');

        $this->assertStringContainsString("className: 'user-location-tracker'", $update);
        $this->assertStringContainsString('userLocationCircle = L.circle', $update);
        $this->assertStringContainsString('position.coords.accuracy', $update);
        // Both layers sit below the cursor so a drag started on your own dot
        // still reaches the map - otherwise "stop following" never fires.
        $this->assertStringContainsString('interactive: false', $update);
    }

    public function test_the_view_follows_the_user_while_tracking(): void
    {
        $update = $this->functionBody('updateUserLocation');

        $this->assertStringContainsString(
            "if (state.followUser) {\n      state.fallbackMap.panTo([lat, lng]);",
            $update
        );
    }

    public function test_a_manual_drag_stops_the_following_but_keeps_the_dot(): void
    {
        $file = file_get_contents(self::MAP_JS);

        // Registering the guard on drag/zoom is what yields control to the
        // visitor; the off() in clearUserLocation is what stops leaking it.
        $this->assertStringContainsString(
            "state.fallbackMap.on('dragstart zoomstart', state.mapFollowGuard);",
            $file
        );
        $this->assertStringContainsString(
            "state.fallbackMap.off('dragstart zoomstart', state.mapFollowGuard);",
            $file
        );
        $this->assertStringContainsString(
            "state.mapFollowGuard = function () {\n      state.followUser = false;\n    };",
            $file
        );
    }

    public function test_a_single_watch_owns_the_tracking(): void
    {
        $file = file_get_contents(self::MAP_JS);

        // The old pair of getCurrentPosition() + watchPosition() fired two
        // fixes back-to-back and split the error handling across two callbacks.
        $this->assertStringContainsString('navigator.geolocation.watchPosition', $file);
        $this->assertStringNotContainsString('navigator.geolocation.getCurrentPosition', $file);
    }

    public function test_permission_denied_takes_the_tracker_down_completely(): void
    {
        $error = $this->functionBody('handleLocationError');

        $this->assertStringContainsString('code === 1', $error);
        $this->assertStringContainsString('clearUserLocation()', $error);
        $this->assertStringNotContainsString('error.PERMISSION_DENIED', $error);

        $clear = $this->functionBody('clearUserLocation');

        $this->assertStringContainsString("setAttribute('aria-pressed', 'false')", $clear);
        $this->assertStringContainsString('clearWatch', $clear);
        $this->assertStringContainsString('removeLayer', $clear);
    }

    public function test_both_map_surfaces_render_the_tracker_button(): void
    {
        $this->assertStringContainsString(
            'id="location-button" type="button" aria-label="Track my location" aria-pressed="false"',
            file_get_contents(public_path('index.html'))
        );

        $this->assertStringContainsString(
            'id="location-button" type="button" aria-label="Track my location" aria-pressed="false"',
            file_get_contents(resource_path('views/admin/map.blade.php'))
        );
    }

    /**
     * Extracts a single function body from map.js, from its opening brace to
     * the closing brace at the function's own indentation.
     */
    private function functionBody(string $name): string
    {
        $source = file_get_contents(self::MAP_JS);

        preg_match(
            '/function '.preg_quote($name, '/').'\([^)]*\)\s*\{\s*\n(.*?)\n\s{2}\}/s',
            $source,
            $matches
        );

        $this->assertNotEmpty($matches, "function $name() is missing from public/js/map.js.");

        return $matches[1];
    }
}

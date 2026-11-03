<?php

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The action bar on a station card - chiefly the Directions button.
 *
 * directionsUrl() lives in public/js/map.js, which is served as a static file
 * with no JS test runner around it, so the regression it guards against is a
 * silent one: it used to send iPhones to Apple Maps and everything else to
 * OpenStreetMap, neither of which is where this map points. Asserting on the
 * source text alone would pass even if the function were never reachable, so
 * this runs the real function under Node and checks what it actually returns.
 *
 * Node is a devDependency of the build but not a requirement of running the
 * app, so its absence skips rather than fails - a PHP-only host should still
 * have a green suite.
 */
class MapActionsTest extends TestCase
{
    private const MAP_JS = __DIR__.'/../../public/js/map.js';

    /** Representative Bangued coordinates, one integer and one decimal. */
    private const SAMPLE = [['latitude' => 17.5906, 'longitude' => 120.5901]];

    public function test_directions_button_opens_google_maps_web(): void
    {
        $urls = $this->runDirectionsUrl(self::SAMPLE);

        $this->assertSame(
            ['https://www.google.com/maps/dir/?api=1&destination=17.5906,120.5901'],
            $urls
        );
    }

    /**
     * The button must reach Google on every platform, so no UA branch is
     * allowed to reappear inside the function.
     */
    public function test_directions_url_has_no_platform_branch(): void
    {
        $function = $this->directionsUrlSource();

        foreach (['navigator', 'userAgent', 'apple', 'openstreetmap', 'maps.apple.com'] as $needle) {
            $this->assertStringNotContainsStringIgnoringCase(
                $needle,
                $function,
                'directionsUrl() must not branch on platform; the link should always open Google Maps web.'
            );
        }
    }

    /**
     * Renders the button markup itself: the anchor has to open in a new tab
     * with noopener, or the map hands its window reference to a third party.
     */
    public function test_directions_anchor_opens_safely_in_a_new_tab(): void
    {
        $html = file_get_contents(self::MAP_JS);

        $this->assertStringContainsString(
            "'<a class=\"pop-act pop-act-primary\" target=\"_blank\" rel=\"noopener noreferrer\" href=\"' +",
            $html,
            'The Directions anchor should open in a new tab with noopener, then hand off to directionsUrl().'
        );
        $this->assertStringContainsString('escapeHtml(directionsUrl(station))', $html);
    }

    /** Every station the card draws the button for must produce a usable link. */
    public function test_directions_is_available_for_any_station_with_coordinates(): void
    {
        $urls = $this->runDirectionsUrl([
            ['latitude' => 0, 'longitude' => 0],
            ['latitude' => -17.5906, 'longitude' => -120.5901],
            ['latitude' => '17.59', 'longitude' => '120.59'],
        ]);

        $this->assertCount(3, $urls);

        foreach ($urls as $url) {
            $this->assertStringStartsWith('https://www.google.com/maps/dir/?api=1&destination=', $url);
        }

        $this->assertSame('https://www.google.com/maps/dir/?api=1&destination=0,0', $urls[0]);
        $this->assertSame('https://www.google.com/maps/dir/?api=1&destination=-17.5906,-120.5901', $urls[1]);
        $this->assertSame('https://www.google.com/maps/dir/?api=1&destination=17.59,120.59', $urls[2]);
    }

    /**
     * Extracts directionsUrl() from map.js and evaluates it in Node against
     * each station, returning the URLs as JSON.
     *
     * @param  array<int, array{latitude: mixed, longitude: mixed}>  $stations
     * @return array<int, string>
     */
    private function runDirectionsUrl(array $stations): array
    {
        $script = <<<'JS'
const extract = /function directionsUrl\(station\)\s*\{[\s\S]*?\n\s{2}\}/;
const source = require('fs').readFileSync(process.argv[1], 'utf8');
const match = source.match(extract);
if (!match) {
  console.error('directionsUrl() not found in map.js');
  process.exit(1);
}
const directionsUrl = eval('(' + match[0] + ')');
console.log(JSON.stringify(JSON.parse(process.argv[2]).map(directionsUrl)));
JS;

        $process = new Process([
            'node',
            '-e',
            $script,
            self::MAP_JS,
            json_encode(array_values($stations)),
        ]);

        try {
            $process->run();
        } catch (\Throwable $e) {
            // symfony/process throws when the binary cannot be found at all,
            // rather than returning a failed process to inspect.
            $this->markTestSkipped('Node is not installed; skipping evaluation of map.js: '.$e->getMessage());
        }

        if (! $process->isSuccessful()) {
            $this->fail('Could not evaluate directionsUrl(): '.$process->getErrorOutput());
        }

        $urls = json_decode(trim($process->getOutput()), true);

        $this->assertIsArray($urls, 'directionsUrl() did not return a JSON array of URLs.');

        return $urls;
    }

    private function directionsUrlSource(): string
    {
        $source = file_get_contents(self::MAP_JS);

        preg_match('/function directionsUrl\(station\)\s*\{[\s\S]*?\n\s{2}\}/', $source, $matches);

        $this->assertNotEmpty($matches, 'directionsUrl() is missing from public/js/map.js.');

        return $matches[0];
    }
}

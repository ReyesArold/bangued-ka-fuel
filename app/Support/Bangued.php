<?php

namespace App\Support;

use RuntimeException;

/**
 * Reads the Bangued boundary and derives the map limits and the server-side
 * coordinate bounds from it.
 *
 * Single source of truth: everything that depends on the boundary - the drawn
 * polygon, the panning limits, and the coordinate check in the admin API - is
 * computed here from data/bangued-boundary.json, so replacing that file with
 * the official PSA/PhilGIS boundary needs no code changes.
 */
class Bangued
{
    /** Cached per request so the 14 KB ring is parsed at most once. */
    private static ?array $boundary = null;

    /** @var array{0: float, 1: float, 2: float, 3: float}|null */
    private static ?array $bbox = null;

    /**
     * Absolute path to the boundary file. Resolved lazily rather than from
     * config/bangued.php, because config is loaded before the application
     * container exists (composer scripts, package discovery) and base_path()
     * is not reliable there.
     */
    public static function path(): string
    {
        return base_path('data/bangued-boundary.json');
    }

    /** Loads and caches the GeoJSON Feature. */
    public static function boundary(): array
    {
        if (self::$boundary !== null) {
            return self::$boundary;
        }

        $path = self::path();
        if (! is_file($path)) {
            throw new RuntimeException(
                "Boundary file not found at {$path}. Run `npm run fetch-boundary` "
                .'in the Node project, or copy data/bangued-boundary.json across.'
            );
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['geometry']['coordinates'][0])) {
            throw new RuntimeException("Boundary file at {$path} is not a GeoJSON Feature with a linear ring.");
        }

        return self::$boundary = $decoded;
    }

    /**
     * Bounding box of the ring as [west, south, east, north].
     *
     * Computed with a running min/max over the points. This is the same fix the
     * Node version needed: spreading a ~14k-element ring into Math.min() blows
     * the call stack.
     */
    public static function bbox(): array
    {
        if (self::$bbox !== null) {
            return self::$bbox;
        }

        $west = $south = $east = $north = null;
        foreach (self::boundary()['geometry']['coordinates'][0] as $point) {
            [$lon, $lat] = [(float) $point[0], (float) $point[1]];
            $west = $west === null ? $lon : min($west, $lon);
            $south = $south === null ? $lat : min($south, $lat);
            $east = $east === null ? $lon : max($east, $lon);
            $north = $north === null ? $lat : max($north, $lat);
        }

        return self::$bbox = [$west, $south, $east, $north];
    }

    /** The ring's points, as [lon, lat] pairs. */
    public static function ring(): array
    {
        return self::boundary()['geometry']['coordinates'][0];
    }

    /**
     * Coordinate limits accepted by the admin API: the boundary's bounding box
     * padded slightly outward. Read as [southWest, northEast] with each corner
     * a [lat, lng] pair.
     */
    public static function maxBounds(): array
    {
        [$west, $south, $east, $north] = self::bbox();

        return [[$south, $west], [$north, $east]];
    }

    /**
     * Coordinate limits accepted by the admin API, as flat min/max pairs.
     *
     * Reads and caches the derived limits in config, so callers can keep using
     * config('bangued.station_bounds'). Populated here on first use rather than
     * in the config file itself, which is loaded before the application
     * container exists - see the note in config/bangued.php.
     */
    public static function stationBounds(): array
    {
        if (is_array(config('bangued.station_bounds'))) {
            return config('bangued.station_bounds');
        }

        [$west, $south, $east, $north] = self::bbox();

        $bounds = [
            'minLat' => round($south - 0.01, 6),
            'maxLat' => round($north + 0.01, 6),
            'minLon' => round($west - 0.01, 6),
            'maxLon' => round($east + 0.01, 6),
        ];

        // config([...]) with an array key SETS and returns the repository, not
        // the value, so set it and read it back explicitly.
        config(['bangued.station_bounds' => $bounds]);

        return $bounds;
    }

    /** Everything /api/meta needs about the map, in one array. */
    public static function mapConfig(): array
    {
        return [
            'center' => config('bangued.map.center'),
            'defaultZoom' => config('bangued.map.defaultZoom'),
            'minZoom' => config('bangued.map.minZoom'),
            'maxZoom' => config('bangued.map.maxZoom'),
            'maxBounds' => self::maxBounds(),
            // Ships as a GeoJSON Feature; the client reads the polygon from
            // geometry.coordinates[0].
            'boundary' => self::boundary(),
            // The browser key for the Google hybrid base layer (Hybrid mode).
            // The tiles are requested from the visitor's own browser, so the
            // key has to reach the client - restrict it by HTTP referrer in the
            // Cloud console. Null when no key is configured, so the client can
            // fall back to the plain Esri/CARTO hybrid.
            'google' => config('bangued.google_maps.api_key') ?: null,
        ];
    }
}

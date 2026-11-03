<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Station;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seed data for the Bangued gas station map.
 *
 * Provenance of coordinates - read before editing
 * ------------------------------------------------
 * 1. Shell  - coordinates supplied directly by the project owner.
 * 2. Caltex - owner supplied "Brgy. Lipcan, Bangued" but no coordinates, so the
 *             position uses the OpenStreetMap centroid of the Lipcan village
 *             node (node/12949328477) and is flagged location_confidence =
 *             'approximate'. VERIFY AND CORRECT in the admin dashboard.
 * 3-6.      Blu Gas #1, Blu Gas #2, Petron, C-Oil - coordinates not yet supplied
 *             by the owner, so these are seeded as is_pending = 1 placeholders.
 *             They are deliberately NOT plotted on the public map until an admin
 *             fills in the coordinates. Each carries an unverifiedLead note.
 *
 * NOTE FOR THE OWNER: this seeder is the reference dataset, not the live one.
 * Filling in coordinates in the admin dashboard is what actually publishes a
 * station, and re-running `bangued:seed` will overwrite those admin edits with
 * the values below.
 *
 * FUEL PRICES ARE INTENTIONALLY NOT SEEDED. Inventing prices would present
 * fabricated data as real. `php artisan bangued:seed --demo-prices` loads
 * clearly-labelled fictional demo values if you want to see the price UI
 * populated.
 */
class BanguedSeeder extends Seeder
{
    /** @var array<int, array<string, mixed>> */
    private const BRANDS = [
        [
            'slug' => 'shell',
            'name' => 'Shell',
            // Shell's corporate yellow + red.
            'color_primary' => '#FDDA00',
            'color_secondary' => '#EE1C25',
            'marker_icon' => 'shell',
            'logo_path' => '/images/brands/shell.png',
            'sort_order' => 10,
        ],
        [
            'slug' => 'caltex',
            'name' => 'Caltex',
            // Caltex (Chevron) blue + red.
            'color_primary' => '#005EB8',
            'color_secondary' => '#E31837',
            'marker_icon' => 'caltex',
            // One logo serves both Power V Caltex stations.
            'logo_path' => '/images/brands/caltex.png',
            'sort_order' => 20,
        ],
        [
            'slug' => 'blu-gas',
            'name' => 'Blu Gas',
            // Custom palette (not an official brand specification).
            'color_primary' => '#1E88E5',
            'color_secondary' => '#00ACC1',
            'marker_icon' => 'pump',
            // One logo serves both Blu Gas stations.
            'logo_path' => '/images/brands/blu-gas.png',
            'sort_order' => 30,
        ],
        [
            'slug' => 'petron',
            'name' => 'Petron',
            // Petron blue + yellow.
            'color_primary' => '#004E9C',
            'color_secondary' => '#FFD100',
            'marker_icon' => 'petron',
            'logo_path' => '/images/brands/petron.png',
            'sort_order' => 40,
        ],
        [
            'slug' => 'seaoil',
            'name' => 'Seaoil',
            // Sampled from images/gas logo/Seaoil.png (dominant yellow, dark
            // navy lettering) rather than an official brand specification.
            'color_primary' => '#FEF201',
            'color_secondary' => '#030315',
            'marker_icon' => 'seaoil',
            'logo_path' => '/images/brands/seaoil.png',
            'sort_order' => 50,
        ],
        [
            'slug' => 'c-oil',
            'name' => 'C-Oil',
            // Placeholder palette, not an official brand specification. Replace
            // with colours sampled from the owner's logo file once it is
            // supplied; these two only drive the marker chip, the legend swatch
            // and the popup header until then.
            'color_primary' => '#0E7A3C',
            'color_secondary' => '#C8102E',
            'marker_icon' => 'c-oil',
            // Drop the owner's logo at public/images/brands/c-oil.png. Until
            // that file exists every surface falls back to the brand-coloured
            // pump chip rather than a broken image.
            'logo_path' => '/images/brands/c-oil.png',
            'sort_order' => 60,
        ],
        [
            'slug' => 'phoenix',
            'name' => 'Phoenix',
            // Sampled from public/images/brands/phoenix.png (3005x2415 RGBA):
            // red covers 61.4% of opaque pixels, near-black 14.5%, white 11.8%
            // and yellow 4.8%. Red and yellow are the two chromatic colours, so
            // they are the pair used for the pin.
            'color_primary' => '#DC291E',
            'color_secondary' => '#FFFE00',
            'marker_icon' => 'phoenix',
            // Logo already supplied by the owner.
            'logo_path' => '/images/brands/phoenix.png',
            'sort_order' => 70,
        ],
    ];

    /** @var array<int, array<string, mixed>> */
    private const STATIONS = [
        [
            'brand_slug' => 'shell',
            'name' => 'Shell',
            'address' => 'Torrijos Street, Zone 5, Bangued, Abra',
            'barangay' => null,
            'latitude' => 17.592008,
            'longitude' => 120.618551,
            'contact_phone' => null,
            'contact_email' => null,
            'operating_hours' => null,
            'notes' => null,
            'is_pending' => false,
            'location_confidence' => 'confirmed',
            'osm_ref' => 'node/3093489524',
        ],
        [
            'brand_slug' => 'caltex',
            'name' => 'Caltex (Power V Caltex Service Station)',
            'address' => 'Brgy. Lipcan, Bangued, Abra',
            'barangay' => 'Lipcan',
            'latitude' => 17.5798234,
            'longitude' => 120.6178725,
            'contact_phone' => null,
            'contact_email' => null,
            'operating_hours' => null,
            'notes' => 'APPROXIMATE POSITION - please verify. Coordinates are the OSM centroid of the Lipcan '
                .'village node (node/12949328477), not a surveyed station location. Unverified lead: OSM '
                .'also lists a "Caltex" fuel station inside Bangued at 17.5941151, 120.6193520 '
                .'(node/3093476663), roughly 1.6 km north of the Lipcan centroid. Confirm which one is '
                .'Power V Caltex and set the final coordinates here.',
            'is_pending' => false,
            'location_confidence' => 'approximate',
            'osm_ref' => 'node/12949328477',
        ],
        [
            'brand_slug' => 'blu-gas',
            'name' => 'Blu Gas Station #1',
            'address' => null,
            'barangay' => null,
            'latitude' => null,
            'longitude' => null,
            'contact_phone' => null,
            'contact_email' => null,
            'operating_hours' => null,
            'notes' => 'Awaiting confirmation of address and coordinates from the project owner.',
            'is_pending' => true,
            'location_confidence' => 'pending',
            'osm_ref' => null,
        ],
        [
            'brand_slug' => 'blu-gas',
            'name' => 'Blu Gas Station #2',
            'address' => null,
            'barangay' => null,
            'latitude' => null,
            'longitude' => null,
            'contact_phone' => null,
            'contact_email' => null,
            'operating_hours' => null,
            'notes' => 'Awaiting confirmation of address and coordinates from the project owner.',
            'is_pending' => true,
            'location_confidence' => 'pending',
            'osm_ref' => null,
        ],
        [
            'brand_slug' => 'petron',
            'name' => 'Petron',
            'address' => null,
            'barangay' => null,
            'latitude' => null,
            'longitude' => null,
            'contact_phone' => null,
            'contact_email' => null,
            'operating_hours' => null,
            'notes' => 'Awaiting confirmation of address and coordinates from the project owner. '
                .'Unverified lead: OSM lists a "Petron" fuel station inside Bangued at '
                .'17.5836385, 120.6172101 (node/4525780866) - confirm before using.',
            'is_pending' => true,
            'location_confidence' => 'pending',
            'osm_ref' => 'node/4525780866',
        ],
        [
            'brand_slug' => 'seaoil',
            'name' => 'Seaoil #1',
            'address' => 'Rizal St, Zone 7, Bangued, 2800 Abra',
            'barangay' => null,
            'latitude' => 17.60059519068887,
            'longitude' => 120.62286199563492,
            'contact_phone' => null,
            'contact_email' => null,
            'operating_hours' => null,
            'notes' => null,
            'is_pending' => false,
            // Coordinates supplied directly by the project owner, same
            // provenance as the Shell record above.
            'location_confidence' => 'confirmed',
            'osm_ref' => null,
        ],
        [
            'brand_slug' => 'c-oil',
            'name' => 'C-Oil',
            'address' => null,
            'barangay' => null,
            'latitude' => null,
            'longitude' => null,
            'contact_phone' => null,
            'contact_email' => null,
            'operating_hours' => null,
            'notes' => 'Awaiting coordinates from the project owner. Until they are filled in this '
                .'station stays off the public map as a placeholder; the logo file is still to be '
                .'supplied as well.',
            'is_pending' => true,
            'location_confidence' => 'pending',
            'osm_ref' => null,
        ],
        [
            'brand_slug' => 'phoenix',
            'name' => 'Phoenix',
            'address' => null,
            'barangay' => null,
            'latitude' => null,
            'longitude' => null,
            'contact_phone' => null,
            'contact_email' => null,
            'operating_hours' => null,
            'notes' => 'Awaiting coordinates from the project owner. The logo is already in place, so '
                .'only the coordinates are outstanding before this station goes live.',
            'is_pending' => true,
            'location_confidence' => 'pending',
            'osm_ref' => null,
        ],
    ];

    /**
     * Set by `php artisan bangued:seed --demo-prices` through the container,
     * before run() is called.
     *
     * A public property rather than an option read off $this->command: a
     * Seeder has no options of its own, and when the command invokes the class
     * directly $this->command is null, so --demo-prices was silently ignored
     * and no demo prices were ever loaded.
     */
    public bool $demoPrices = false;

    /** Fictional round numbers, clearly not real market data. */
    private const DEMO_PRICES = [
        'Gasoline' => 72.50,
        'Diesel' => 65.75,
        'Kerosene' => 58.00,
        'Premium Gasoline' => 78.25,
    ];

    public function run(): void
    {
        $this->seedBrands();
        $this->seedStations();

        if ($this->demoPrices) {
            $this->seedDemoPrices();
        }

        $this->seedAdmin();
    }

    private function seedBrands(): void
    {
        foreach (self::BRANDS as $brand) {
            Brand::updateOrCreate(['slug' => $brand['slug']], $brand);
        }
        $this->command?->info('  brands: '.Brand::count());
    }

    private function seedStations(): void
    {
        $brandIds = Brand::pluck('id', 'slug');

        foreach (self::STATIONS as $station) {
            $slug = $station['brand_slug'];
            unset($station['brand_slug']);

            // Keyed by name+brand so re-running the seeder updates the existing
            // record instead of creating a duplicate.
            $existing = Station::where('name', $station['name'])
                ->where('brand_id', $brandIds[$slug])
                ->first();

            $station['brand_id'] = $brandIds[$slug];

            if ($existing) {
                $existing->fill($station)->save();
            } else {
                Station::create($station);
            }
        }

        $plottable = Station::where('is_pending', false)->count();
        $pending = Station::where('is_pending', true)->count();
        $this->command?->info('  stations: '.Station::count()." ({$plottable} plottable, {$pending} pending)");
    }

    private function seedAdmin(): void
    {
        $config = config('bangued.admin');

        $user = User::where('username', $config['username'])->first();
        if ($user) {
            $this->command?->warn("  admin user '{$config['username']}' already exists - left untouched.");
        } else {
            $user = new User([
                'username' => $config['username'],
                'display_name' => $config['display_name'],
                'role' => $config['role'],
            ]);
            $user->setPasswordHash($config['password'], $config['bcrypt_rounds']);
            $user->save();

            $this->command?->info("  admin user: {$config['username']} (bcrypt, {$config['bcrypt_rounds']} rounds)");
            $this->command?->info('    password: '.$config['password']);
            $this->command?->warn('    !! Change this before deploying to a public server.');
        }

        /*
         * Sample station manager.
         *
         * Created if absent, and left alone if it already exists - so re-running
         * the seeder never resets a real manager account's password. Either way
         * it is assigned every published Shell station below, because
         * StationPolicy grants a manager access only to stations assigned to
         * them: without an assignment this account signs in successfully and
         * then sees an empty dashboard, which reads as a bug rather than as
         * "nothing assigned to you".
         */
        $manager = User::where('username', 'manager')->first();
        if (! $manager) {
            $manager = new User([
                'username' => 'manager',
                'email' => 'manager@example.test',
                'display_name' => 'Station Manager',
                'role' => 'station_manager',
            ]);
            $manager->setPasswordHash('manager123', $config['bcrypt_rounds']);
            $manager->save();
            $this->command?->info('  station manager: manager (password: manager123)');
        }

        /*
         * Only fills stations that are not already assigned to somebody else, so
         * it cannot steal an assignment an administrator made by hand.
         *
         * One brand on purpose. A manager's brand is derived from their assigned
         * stations (users has no brand_id column), so handing this account
         * stations of several brands would hand it access to all of them - which
         * is correct but makes for a misleading demo of the rule. A real
         * deployment assigns one brand per manager.
         */
        /*
         * The manager is held answerable for the Shell brand BEFORE the
         * assignment, because the stations_manager_brand_matches trigger refuses
         * to pair a manager whose users.brand_id differs from the station's.
         * Setting it afterwards would be too late to matter.
         */
        if ($manager->brand_id === null) {
            $manager->forceFill(['brand_id' => Brand::where('slug', 'shell')->value('id')])->save();
        }

        Station::where('is_pending', false)
            ->whereHas('brand', fn ($b) => $b->where('slug', 'shell'))
            ->whereNull('manager_id')
            ->update(['manager_id' => $manager->id]);

        $this->command?->info(
            '    assigned to manager: '.Station::where('manager_id', $manager->id)->count().' Shell station(s)'
        );
    }

    /**
     * Loads fictional demo prices so the price UI can be seen populated. Never
     * runs unless --demo-prices is passed explicitly.
     */
    private function seedDemoPrices(): void
    {
        foreach (Station::where('is_pending', false)->get() as $station) {
            foreach (self::DEMO_PRICES as $fuelType => $price) {
                DB::table('fuel_prices')->updateOrInsert(
                    ['station_id' => $station->id, 'fuel_type' => $fuelType],
                    [
                        'price_per_liter' => $price,
                        'effective_at' => now(),
                        'updated_at' => now(),
                    ],
                );
            }
        }

        $this->command?->info('  FICTIONAL demo prices loaded ('.count(self::DEMO_PRICES).' per station).');
        $this->command?->warn('  !! These are invented round numbers - delete them before real use.');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\FuelPrice;
use App\Models\Station;
use App\Models\User;
use Tests\TestCase;

/**
 * What a station manager may and may not do.
 *
 * A station manager can change fuel prices on the stations assigned to them and
 * nothing else. These tests drive the HTTP API as that user rather than calling
 * the policy directly, because the point being pinned is that the SERVER refuses
 * the request - not that a policy method returns false when asked nicely.
 *
 * Assignment is stations.manager_id, and users carry no brand_id, so a manager's
 * brand is derived from the stations assigned to them. Two managers are set up:
 *
 *   - "shellmgr" owns two Shell stations
 *   - "othermgr" owns one station of a different brand
 *
 * which is what makes "same brand but not mine" and "different brand" two
 * genuinely different cases.
 */
class StationManagerTest extends TestCase
{
    private User $manager;

    /** A second Shell manager, holding the rival station. */
    private User $rivalShellManager;

    /** A second station of THIS manager's own, to prove prices work on both. */
    private Station $mySecond;

    /** A second manager, holding a station of a different brand. */
    private User $otherManager;

    /** $this->mine's manager before a test changes it, for the "unchanged" assertions. */
    private int $originalManagerId;

    private Station $mine;

    private Station $sameBrandNotMine;

    private Station $otherBrand;

    /** A published station left unassigned, used by the visibility tests. */
    private Station $unused;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedBanguedData();

        $shell = Brand::where('slug', 'shell')->firstOrFail();
        $petron = Brand::where('slug', 'petron')->firstOrFail();

        /*
         * Two Shell managers and one Petron manager.
         *
         * shellmgr and shellmgr2 both hold the SHELL brand but own different
         * stations - that pair is what makes "same brand, assigned to someone
         * else" a real case distinct from "different brand". othermgr holds
         * PETRON. Every pairing below is valid under the brand rule, because the
         * database trigger refuses the setup otherwise.
         */
        $this->manager = $this->makeManager('shellmgr', $shell);
        $this->rivalShellManager = $this->makeManager('shellmgr2', $shell);
        $this->otherManager = $this->makeManager('othermgr', $petron);

        // Stations are created here rather than taken from the seeder, so the
        // fixture does not shift when the seed data does. All are published, so
        // is_pending never complicates an assertion.
        $this->mine = $this->station('Shell Mapped A', $shell, $this->manager);
        $this->mySecond = $this->station('Shell Mapped B', $shell, $this->manager);
        $this->sameBrandNotMine = $this->station('Shell Rival', $shell, $this->rivalShellManager);
        $this->otherBrand = $this->station('Petron Mapped', $petron, $this->otherManager);
        $this->unused = $this->station('Shell Nobody', $shell);

        $this->originalManagerId = (int) $this->mine->manager_id;
    }

    private function station(string $name, Brand $brand, ?User $manager = null): Station
    {
        return Station::create([
            'brand_id' => $brand->id,
            'name' => $name,
            'latitude' => 17.5965,
            'longitude' => 120.6167,
            'is_pending' => false,
            'is_active' => true,
            'location_confidence' => 'confirmed',
            'manager_id' => $manager?->id,
        ]);
    }

    private function makeManager(string $username, Brand $brand): User
    {
        $user = new User([
            'username' => $username,
            'email' => $username.'@example.test',
            'display_name' => ucfirst($username),
            'role' => 'station_manager',
            // The brand is stored on the user, so "a manager's brand must match
            // their station's brand" is a real comparison rather than a
            // tautology over the same row.
            'brand_id' => $brand->id,
        ]);
        $user->setPasswordHash('manager123', 4);
        $user->save();

        return $user;
    }

    /* ------------------------------------------------------------ can do */

    public function test_a_manager_can_update_prices_of_their_own_station(): void
    {
        $response = $this->actingAs($this->manager)
            ->putJson("/api/admin/stations/{$this->mine->id}/prices", [
                'prices' => [
                    ['fuelType' => 'Gasoline', 'pricePerLiter' => 71.5],
                    ['fuelType' => 'Diesel', 'pricePerLiter' => 66],
                ],
            ]);

        $response->assertOk()->assertJsonCount(2, 'data.prices');
        $this->assertDatabaseHas('fuel_prices', [
            'station_id' => $this->mine->id,
            'fuel_type' => 'Gasoline',
            'price_per_liter' => 71.5,
        ]);
    }

    public function test_a_manager_can_remove_every_price_row_from_their_station(): void
    {
        $this->actingAs($this->manager)
            ->putJson("/api/admin/stations/{$this->mine->id}/prices", [
                'prices' => [['fuelType' => 'Gasoline', 'pricePerLiter' => 70]],
            ])
            ->assertOk();

        $this->actingAs($this->manager)
            ->putJson("/api/admin/stations/{$this->mine->id}/prices", ['prices' => []])
            ->assertStatus(400);

        // PUT /prices still refuses an empty list (the dashboard's own guard);
        // the combined PATCH is where "delete them all" is allowed.
        $this->actingAs($this->manager)
            ->patchJson("/api/admin/stations/{$this->mine->id}", ['prices' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.prices');

        $this->assertDatabaseMissing('fuel_prices', ['station_id' => $this->mine->id]);
    }

    public function test_a_manager_sees_only_their_assigned_stations(): void
    {
        $response = $this->actingAs($this->manager)->getJson('/api/admin/stations');

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');

        $this->assertContains($this->mine->id, $ids, 'their first station must appear');
        $this->assertContains($this->mySecond->id, $ids, 'their second station must appear');
        $this->assertNotContains($this->sameBrandNotMine->id, $ids, 'same brand, another manager: must not appear');
        $this->assertNotContains($this->otherBrand->id, $ids, 'another brand\'s station must not appear');
        $this->assertNotContains($this->unused->id, $ids, 'unassigned station must not appear');

        // Exactly their two. Asserting the count as well as the membership is
        // what catches a filter that silently starts leaking stations.
        $this->assertCount(2, $ids);
    }

    public function test_a_manager_can_read_the_price_history_of_their_own_station(): void
    {
        $this->actingAs($this->manager)
            ->getJson("/api/admin/stations/{$this->mine->id}/price-history")
            ->assertOk();
    }

    /**
     * The combined save: prices alongside the form, which is what the admin
     * map's single Save button sends. Only the prices part is honoured.
     */
    public function test_a_manager_can_save_prices_through_the_combined_patch(): void
    {
        $response = $this->actingAs($this->manager)
            ->patchJson("/api/admin/stations/{$this->mine->id}", [
                'prices' => [['fuelType' => 'Gasoline', 'pricePerLiter' => 73]],
            ]);

        $response->assertOk();
        $this->assertDatabaseHas('fuel_prices', [
            'station_id' => $this->mine->id,
            'price_per_liter' => 73,
        ]);
    }

    /* ------------------------------------------------------------ cannot do */

    /** Another brand's station: assigned to a different manager entirely. */
    public function test_a_manager_cannot_update_prices_of_another_brand(): void
    {
        $this->actingAs($this->manager)
            ->putJson("/api/admin/stations/{$this->otherBrand->id}/prices", [
                'prices' => [['fuelType' => 'Gasoline', 'pricePerLiter' => 99]],
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('fuel_prices', [
            'station_id' => $this->otherBrand->id,
            'price_per_liter' => 99,
        ]);
    }

    /**
     * The subtler case: same brand as this manager's station, but not assigned
     * to them. A brand-only rule would let this through, so the assignment check
     * is what refuses it.
     */
    public function test_a_manager_cannot_update_prices_of_an_unassigned_same_brand_station(): void
    {
        $this->actingAs($this->manager)
            ->putJson("/api/admin/stations/{$this->sameBrandNotMine->id}/prices", [
                'prices' => [['fuelType' => 'Gasoline', 'pricePerLiter' => 98]],
            ])
            ->assertStatus(403);
    }

    public function test_a_manager_cannot_read_the_price_history_of_an_unassigned_station(): void
    {
        $this->actingAs($this->manager)
            ->getJson("/api/admin/stations/{$this->otherBrand->id}/price-history")
            ->assertStatus(403);
    }

    public function test_a_manager_cannot_rename_their_own_station(): void
    {
        $original = $this->mine->name;

        $this->actingAs($this->manager)
            ->patchJson("/api/admin/stations/{$this->mine->id}", ['name' => 'Renamed By Manager'])
            ->assertStatus(403);

        $this->assertSame($original, $this->mine->fresh()->name);
    }

    public function test_a_manager_cannot_move_their_own_station(): void
    {
        $latitude = $this->mine->latitude;
        $longitude = $this->mine->longitude;

        $this->actingAs($this->manager)
            ->patchJson("/api/admin/stations/{$this->mine->id}", [
                'latitude' => 17.60,
                'longitude' => 120.62,
            ])
            ->assertStatus(403);

        $fresh = $this->mine->fresh();
        $this->assertEqualsWithDelta($latitude, $fresh->latitude, 0.000001);
        $this->assertEqualsWithDelta($longitude, $fresh->longitude, 0.000001);
    }

    public function test_a_manager_cannot_change_the_brand_of_their_own_station(): void
    {
        $brandId = $this->mine->brand_id;

        $this->actingAs($this->manager)
            ->patchJson("/api/admin/stations/{$this->mine->id}", [
                'brandId' => Brand::where('slug', 'petron')->value('id'),
            ])
            ->assertStatus(403);

        $this->assertSame($brandId, $this->mine->fresh()->brand_id);
    }

    /**
     * The important one: station fields sent alongside prices are REFUSED, not
     * quietly dropped.
     *
     * Silently ignoring them made the refusal invisible - the request succeeded,
     * the toast said saved, and nothing told the manager their rename did
     * nothing. A 403 names the offending keys instead. The dialog disables these
     * fields, so a manager should not be able to produce this body at all.
     */
    public function test_a_manager_price_save_is_refused_when_station_fields_are_sent_alongside(): void
    {
        $original = $this->mine->only(['name', 'address', 'barangay', 'operating_hours']);

        $response = $this->actingAs($this->manager)
            ->patchJson("/api/admin/stations/{$this->mine->id}", [
                'name' => 'Should Be Ignored',
                'address' => 'Should Be Ignored',
                'barangay' => 'Should Be Ignored',
                'operatingHours' => 'Should Be Ignored',
                'notes' => 'Should Be Ignored',
                'isActive' => false,
                'latitude' => 17.60,
                'longitude' => 120.62,
                'managerId' => $this->otherManager->id,
                'prices' => [['fuelType' => 'Gasoline', 'pricePerLiter' => 74]],
            ]);

        $response->assertStatus(403);

        $fresh = $this->mine->fresh();
        foreach ($original as $field => $value) {
            $this->assertSame($value, $fresh->{$field}, "$field must be untouched");
        }
        $this->assertTrue($fresh->is_active, 'status must be untouched');
        $this->assertSame($this->originalManagerId, $fresh->manager_id, 'assignment must be untouched');
        $this->assertDatabaseMissing('fuel_prices', [
            'station_id' => $this->mine->id,
            'price_per_liter' => 74,
        ]);
    }

    /** The 403 message names the offending keys, so the cause is visible. */
    public function test_the_refusal_names_the_fields_it_rejected(): void
    {
        $this->actingAs($this->manager)
            ->patchJson("/api/admin/stations/{$this->mine->id}", [
                'name' => 'Nope',
                'prices' => [['fuelType' => 'Gasoline', 'pricePerLiter' => 74]],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.message', 'Station managers can only edit fuel prices, not station details. Refused: name.');
    }

    public function test_a_manager_price_save_cannot_empty_a_station_they_do_not_own(): void
    {
        $this->actingAs($this->otherManager)
            ->putJson("/api/admin/stations/{$this->otherBrand->id}/prices", [
                'prices' => [['fuelType' => 'Gasoline', 'pricePerLiter' => 70]],
            ])
            ->assertOk();

        $this->actingAs($this->manager)
            ->patchJson("/api/admin/stations/{$this->otherBrand->id}", ['prices' => []])
            ->assertStatus(403);

        $this->assertDatabaseHas('fuel_prices', [
            'station_id' => $this->otherBrand->id,
            'fuel_type' => 'Gasoline',
        ]);
    }

    public function test_a_manager_cannot_create_a_station(): void
    {
        $brand = Brand::where('slug', 'shell')->firstOrFail();

        $this->actingAs($this->manager)
            ->postJson('/api/admin/stations', [
                'name' => 'Smuggled In',
                'brandId' => $brand->id,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('stations', ['name' => 'Smuggled In']);
    }

    public function test_a_manager_cannot_delete_a_station(): void
    {
        $id = $this->mine->id;

        $this->actingAs($this->manager)
            ->deleteJson("/api/admin/stations/{$id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('stations', ['id' => $id]);
    }

    public function test_a_manager_cannot_delete_another_managers_station(): void
    {
        $id = $this->otherBrand->id;

        $this->actingAs($this->manager)
            ->deleteJson("/api/admin/stations/{$id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('stations', ['id' => $id]);
    }

    public function test_a_manager_price_validation_still_applies(): void
    {
        // Refused for being a duplicate, not for being a manager: the policy
        // grants the ability, the validation is what rejects the payload.
        $this->actingAs($this->manager)
            ->putJson("/api/admin/stations/{$this->mine->id}/prices", [
                'prices' => [
                    ['fuelType' => 'Gasoline', 'pricePerLiter' => 70],
                    ['fuelType' => 'GASOLINE', 'pricePerLiter' => 72],
                ],
            ])
            ->assertStatus(400)
            ->assertJsonPath('error.details.rows.1', 'Duplicate fuel type "GASOLINE".');
    }

    public function test_a_manager_cannot_read_the_managers_list_from_config(): void
    {
        // The Station Manager dropdown is admin-only, so it is omitted rather
        // than 403ing the whole config call - the price editor needs fuelTypes
        // from the same response.
        $response = $this->actingAs($this->manager)->getJson('/api/admin/config');

        $response->assertOk();
        $this->assertSame([], $response->json('data.managers'));
        $this->assertNotEmpty($response->json('data.fuelTypes'));
    }

    /* ------------------------------------------------------------ admin keeps all */

    public function test_an_admin_can_update_prices_of_any_station(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)
            ->putJson("/api/admin/stations/{$this->otherBrand->id}/prices", [
                'prices' => [['fuelType' => 'Gasoline', 'pricePerLiter' => 80]],
            ])
            ->assertOk();
    }

    public function test_an_admin_can_rename_and_move_any_station(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/admin/stations/{$this->otherBrand->id}", [
                'name' => 'Admin Renamed This',
                'latitude' => 17.60,
                'longitude' => 120.62,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Admin Renamed This');
    }

    public function test_an_admin_can_still_create_and_delete(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $brand = Brand::where('slug', 'shell')->firstOrFail();

        $created = $this->actingAs($admin)->postJson('/api/admin/stations', [
            'name' => 'Created By Admin',
            'brandId' => $brand->id,
        ]);
        $created->assertStatus(201);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/stations/{$created->json('data.id')}")
            ->assertOk();
    }

    public function test_an_admin_still_sees_every_station(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $response = $this->actingAs($admin)->getJson('/api/admin/stations');

        $response->assertOk();
        $this->assertCount(Station::count(), $response->json('data'));
    }

    /**
     * An unassigned station has no manager, so it is invisible to every manager
     * and fully open to the admin. Without this, "no assignment" would have to
     * be treated as "everyone".
     */
    public function test_an_unassigned_station_is_invisible_to_every_manager(): void
    {
        $this->assertNull($this->unused->manager_id, 'the fixture leaves one station unassigned');

        $this->actingAs($this->manager)
            ->getJson('/api/admin/stations')
            ->assertJsonMissing(['id' => $this->unused->id]);

        $this->actingAs($this->manager)
            ->putJson("/api/admin/stations/{$this->unused->id}/prices", [
                'prices' => [['fuelType' => 'Gasoline', 'pricePerLiter' => 10]],
            ])
            ->assertStatus(403);
    }

    /**
     * A manager's brand must match their station's brand, and the brand is
     * stored on the user (users.brand_id) so the comparison is real rather than
     * circular.
     *
     * Assigning a Petron manager to a Shell station is a 400, not a 403 and not
     * a silent acceptance: the administrator asked for something the data model
     * forbids.
     */
    public function test_assigning_a_manager_of_another_brand_is_rejected(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        // othermgr holds the Petron brand; $this->mine is a Shell station.
        $this->assertSame($this->otherBrand->brand_id, $this->otherManager->brand_id);
        $this->assertNotSame($this->mine->brand_id, $this->otherManager->brand_id);

        $this->actingAs($admin)
            ->patchJson("/api/admin/stations/{$this->mine->id}", [
                'managerId' => $this->otherManager->id,
            ])
            ->assertStatus(400);

        $this->assertSame($this->originalManagerId, (int) $this->mine->fresh()->manager_id);
    }

    /**
     * A station whose BRAND changes under a manager is rejected too, which is
     * the second half of the same rule: the pairing is checked whenever either
     * side moves, not only on assignment.
     */
    public function test_changing_a_station_brand_away_from_its_manager_is_rejected(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $petron = Brand::where('slug', 'petron')->firstOrFail();

        // $this->mine is a Shell station whose manager holds the Shell brand: a
        // matched pair. Moving it to Petron breaks the pairing.
        $this->assertSame($this->mine->brand_id, $this->manager->brand_id);

        $this->actingAs($admin)
            ->patchJson("/api/admin/stations/{$this->mine->id}", [
                'brandId' => $petron->id,
            ])
            ->assertStatus(400);

        $this->assertSame(
            $this->mine->brand_id,
            (int) $this->mine->fresh()->brand_id,
            'the brand must not change under a manager of the old brand'
        );
    }

    /**
     * One station per manager, enforced in the database.
     *
     * The UNIQUE index on stations.manager_id is what makes "their station" a
     * definite phrase. Without it a manager could hold two, and the controller's
     * own "already manages another station" check would be the only thing
     * standing between the two.
     */
    /**
     * The manager list behind the Station manager dropdown must report the
     * stations each manager actually holds.
     *
     * A regression test for a SILENT failure: the controller eager loaded the
     * relation as `with('managedStations:id')`, and a `:id` column list selects
     * only the related primary key - not the foreign key Eloquent matches on.
     * The relation resolved to nothing, every manager looked free, the dropdown
     * offered one who already held a station, and "Save station" was then
     * refused with a message about a rule nobody could see.
     */
    public function test_the_manager_list_reports_stations_and_brand(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $managers = $this->actingAs($admin)->getJson('/api/admin/config')->json('data.managers');
        $mine = collect($managers)->firstWhere('id', $this->manager->id);

        $this->assertNotNull($mine, 'the manager must appear in the dropdown list');
        $this->assertSame(
            [$this->mine->id, $this->mySecond->id],
            collect($mine['stationIds'])->sort()->values()->all(),
            "both of this manager's stations must be listed"
        );
        $this->assertSame(2, $mine['stationCount']);
        $this->assertSame(
            (int) $this->mine->brand_id,
            (int) $mine['brandId'],
            'the dropdown filters by brand, so it needs the brand id'
        );
        $this->assertNotNull($mine['brandName'], 'the option label shows the brand by name');
    }

    /**
     * A manager with no stations reports zero and no brand, which is what makes
     * them eligible for any station.
     */
    public function test_a_manager_with_no_stations_reports_zero(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $brandless = $this->makeManager('brandless', $this->unused->brand);
        $brandless->forceFill(['brand_id' => null])->save();

        $managers = $this->actingAs($admin)->getJson('/api/admin/config')->json('data.managers');
        $entry = collect($managers)->firstWhere('id', $brandless->id);

        $this->assertSame(0, $entry['stationCount']);
        $this->assertSame([], $entry['stationIds']);
        $this->assertNull($entry['brandId'], 'no stations means no brand adopted yet');
    }

    /**
     * The headline behaviour: ONE manager, SEVERAL stations, all one brand.
     *
     * This is the case that used to be a 400 ("That manager already manages another
     * station"), which is what broke "Save station" on the admin map.
     */
    public function test_a_manager_can_be_assigned_a_second_station_of_the_same_brand(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $free = $this->station('Shell Third', $this->unused->brand);

        $this->actingAs($admin)
            ->patchJson("/api/admin/stations/{$free->id}", [
                'managerId' => $this->manager->id,
            ])
            ->assertOk();

        $this->assertSame((int) $this->manager->id, (int) $free->fresh()->manager_id);
        $this->assertSame(
            3,
            $this->manager->managedStations()->count(),
            'the manager now handles three stations'
        );
    }

    /** A manager can change prices on EVERY station assigned to them. */
    public function test_a_manager_updates_prices_on_all_their_stations(): void
    {
        foreach ([$this->mine, $this->mySecond] as $index => $station) {
            $this->actingAs($this->manager)
                ->putJson("/api/admin/stations/{$station->id}/prices", [
                    'prices' => [['fuelType' => 'Gasoline', 'pricePerLiter' => 70 + $index]],
                ])
                ->assertOk();
        }

        $this->assertDatabaseHas('fuel_prices', [
            'station_id' => $this->mine->id,
            'price_per_liter' => 70,
        ]);
        $this->assertDatabaseHas('fuel_prices', [
            'station_id' => $this->mySecond->id,
            'price_per_liter' => 71,
        ]);
    }

    /** And a station they do NOT handle is 403, even of their own brand. */
    public function test_a_manager_cannot_touch_a_same_brand_station_assigned_to_another_manager(): void
    {
        $this->assertSame(
            $this->mine->brand_id,
            $this->sameBrandNotMine->brand_id,
            'fixture: the rival station is the same brand'
        );
        $this->assertNotSame(
            $this->manager->id,
            (int) $this->sameBrandNotMine->manager_id
        );

        $this->actingAs($this->manager)
            ->putJson("/api/admin/stations/{$this->sameBrandNotMine->id}/prices", [
                'prices' => [['fuelType' => 'Gasoline', 'pricePerLiter' => 99]],
            ])
            ->assertStatus(403);
    }

    /** The manager's identity is a list, not a single id. */
    public function test_the_manager_payload_lists_every_station_they_handle(): void
    {
        $response = $this->actingAs($this->manager)
            ->getJson('/api/auth/me');

        $response->assertOk();
        $ids = $response->json('data.stationIds');

        $this->assertContains($this->mine->id, $ids);
        $this->assertContains($this->mySecond->id, $ids);
        $this->assertNotContains($this->sameBrandNotMine->id, $ids);
    }

    /* ------------------------------------------------ Manage stations checklist */

    public function test_assign_stations_replaces_the_whole_set(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        // Drop the second station, keep the first, add the unassigned one.
        $response = $this->actingAs($admin)
            ->putJson("/api/admin/managers/{$this->manager->id}/stations", [
                'stationIds' => [$this->mine->id, $this->unused->id],
            ]);

        $response->assertOk()
            ->assertJsonPath('data.managerId', $this->manager->id);

        $this->assertNull(
            $this->mySecond->fresh()->manager_id,
            'the unticked station is unassigned'
        );
        $this->assertSame((int) $this->manager->id, (int) $this->mine->fresh()->manager_id);
        $this->assertSame((int) $this->manager->id, (int) $this->unused->fresh()->manager_id);
        $this->assertSame(
            2,
            $this->manager->fresh()->managedStations()->count(),
            'the set is replaced, not added to'
        );
    }

    public function test_assign_stations_rejects_another_brand_and_changes_nothing(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $before = $this->manager->managedStations()->pluck('id')->all();

        $this->actingAs($admin)
            ->putJson("/api/admin/managers/{$this->manager->id}/stations", [
                // The Petron station is the only wrong one; the Shell tick must
                // NOT be applied.
                'stationIds' => [$this->unused->id, $this->otherBrand->id],
            ])
            ->assertStatus(400);

        $this->assertSame(
            $before,
            $this->manager->fresh()->managedStations()->pluck('id')->all(),
            'a rejected tick must leave the set untouched'
        );
        $this->assertNull($this->unused->fresh()->manager_id);
    }

    public function test_a_manager_with_no_stations_adopts_the_brand_of_the_first(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $fresh = $this->makeManager('brandless', $this->unused->brand);

        $this->actingAs($admin)
            ->patchJson("/api/admin/stations/{$this->unused->id}", [
                'managerId' => $fresh->id,
            ])
            ->assertOk();

        $this->assertSame(
            (int) $this->unused->brand_id,
            (int) $fresh->fresh()->brand_id,
            'a manager with no stations takes the brand of the first one given to them'
        );
    }

    public function test_a_manager_cannot_manage_stations(): void
    {
        $this->actingAs($this->manager)
            ->putJson("/api/admin/managers/{$this->manager->id}/stations", [
                'stationIds' => [$this->unused->id],
            ])
            ->assertStatus(403);

        $this->assertNull($this->unused->fresh()->manager_id);
    }

    /**
     * Saving a station without touching the manager dropdown must not report the
     * station's own manager as a conflict - the unique index is about to be
     * checked against the very row being written.
     */
    public function test_resaving_a_station_keeps_its_own_manager(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $managerId = (int) $this->otherBrand->manager_id;

        $this->actingAs($admin)
            ->patchJson("/api/admin/stations/{$this->otherBrand->id}", [
                'name' => 'Renamed By Admin',
                'managerId' => $managerId,
            ])
            ->assertOk();

        $this->assertSame($managerId, (int) $this->otherBrand->fresh()->manager_id);
    }

    /**
     * The "+ New manager" option: one request creates the account and assigns
     * it, and the new account is held answerable for the station's brand.
     */
    public function test_an_admin_can_create_a_manager_and_assign_it_in_one_request(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $shell = Brand::where('slug', 'shell')->firstOrFail();

        $response = $this->actingAs($admin)
            ->patchJson("/api/admin/stations/{$this->unused->id}", [
                'newManager' => [
                    'name' => 'Ada Manager',
                    'email' => 'ada@example.test',
                    'password' => 'temp-pass-123',
                ],
            ]);

        $response->assertOk();

        $created = User::where('email', 'ada@example.test')->firstOrFail();
        $this->assertSame('station_manager', $created->role);
        $this->assertSame('Ada Manager', $created->display_name);
        $this->assertSame($shell->id, $created->brand_id);
        $this->assertSame($created->id, $this->unused->fresh()->manager_id);

        // The password is hashed through the model's own path, so the account
        // can actually sign in.
        $this->assertTrue($created->checkPassword('temp-pass-123'));
        $this->assertNotSame('temp-pass-123', $created->password_hash);
    }

    /**
     * A body cannot mint an administrator. The "+ New manager" dialog is an
     * admin-only form, but the role is forced server side regardless of what it
     * asks for.
     */
    public function test_a_new_manager_cannot_be_created_with_the_admin_role(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/admin/stations/{$this->unused->id}", [
                'newManager' => [
                    'email' => 'sneaky@example.test',
                    'password' => 'temp-pass-123',
                    'role' => 'admin',
                ],
            ])
            ->assertOk();

        $this->assertSame(
            'station_manager',
            User::where('email', 'sneaky@example.test')->firstOrFail()->role
        );
    }

    /**
     * The account and the station commit together.
     *
     * If the station is rejected AFTER the account has been written, the account
     * must roll back with it - otherwise it is left holding no station and
     * sitting in the dropdown as a free manager nobody asked for.
     */
    public function test_a_created_manager_rolls_back_when_the_station_is_rejected(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $before = User::where('role', 'station_manager')->count();

        // A duplicate email fails inside createManager, which runs inside the
        // transaction opened by update().
        $this->actingAs($admin)
            ->patchJson("/api/admin/stations/{$this->unused->id}", [
                'newManager' => [
                    'email' => $this->manager->email,
                    'password' => 'temp-pass-123',
                ],
            ])
            ->assertStatus(400);

        $this->assertSame(
            $before,
            User::where('role', 'station_manager')->count(),
            'no half-created account is left behind'
        );
    }

    /** A manager may not reassign a station, including to themselves. */
    public function test_a_manager_cannot_change_the_manager(): void
    {
        $this->actingAs($this->manager)
            ->patchJson("/api/admin/stations/{$this->mine->id}", [
                'managerId' => $this->otherManager->id,
                'prices' => [['fuelType' => 'Gasoline', 'pricePerLiter' => 61]],
            ])
            ->assertStatus(403);

        $this->assertSame($this->originalManagerId, $this->mine->fresh()->manager_id);
    }

    /** The manager's name travels with the station, for the dashboard column. */
    public function test_the_station_payload_carries_the_manager(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $station = $this->actingAs($admin)->getJson('/api/admin/stations')
            ->json('data.'.array_search(
                $this->mine->id,
                array_column($this->actingAs($admin)->getJson('/api/admin/stations')->json('data'), 'id')
            ));

        $this->assertSame($this->manager->id, $station['managerId']);
        $this->assertSame('shellmgr', $station['manager']['username']);
        $this->assertSame('Shellmgr', $station['manager']['displayName']);
    }

    /** A station with no manager reports null rather than omitting the key. */
    public function test_an_unmanaged_station_reports_a_null_manager(): void
    {
        $admin = User::where('username', 'admin')->firstOrFail();

        $ids = array_column($this->actingAs($admin)->getJson('/api/admin/stations')->json('data'), 'id');
        $index = array_search($this->unused->id, $ids);
        $this->assertNotFalse($index);

        $station = $this->actingAs($admin)->getJson('/api/admin/stations')->json("data.$index");

        $this->assertNull($station['managerId']);
        $this->assertArrayNotHasKey('manager', $station);
    }

    public function test_prices_of_another_managers_station_are_untouched_by_a_failed_attempt(): void
    {
        $this->actingAs($this->otherManager)
            ->putJson("/api/admin/stations/{$this->otherBrand->id}/prices", [
                'prices' => [['fuelType' => 'Diesel', 'pricePerLiter' => 61]],
            ])
            ->assertOk();

        $before = FuelPrice::where('station_id', $this->otherBrand->id)->count();

        $this->actingAs($this->manager)
            ->putJson("/api/admin/stations/{$this->otherBrand->id}/prices", [
                'prices' => [['fuelType' => 'Diesel', 'pricePerLiter' => 1]],
            ])
            ->assertStatus(403);

        $this->assertSame($before, FuelPrice::where('station_id', $this->otherBrand->id)->count());
        $this->assertDatabaseHas('fuel_prices', [
            'station_id' => $this->otherBrand->id,
            'fuel_type' => 'Diesel',
            'price_per_liter' => 61,
        ]);
    }
}

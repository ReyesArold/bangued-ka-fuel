<?php

namespace App\Http\Controllers;

use App\Http\HttpError;
use App\Http\Validate;
use App\Models\Brand;
use App\Models\FuelPrice;
use App\Models\FuelPriceHistory;
use App\Models\Station;
use App\Models\User;
use App\Support\Bangued;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Full station CRUD plus the fuel price editor.
 *
 * Port of src/routes/admin.js. Every route in this controller sits behind the
 * RequireAdmin middleware, which admits BOTH roles, so who may change what is
 * decided per action by StationPolicy through $this->authorize(). A station
 * manager reaches exactly one of these actions - savePrices() - and only for
 * the stations assigned to them.
 */
class AdminApiController extends Controller
{
    /**
     * Every request key that maps to a station column.
     *
     * A station manager may not send any of them, so the PATCH handler refuses
     * the whole request when one appears. Listed explicitly rather than derived
     * from the fields below, because the rule is "these are forbidden" and a
     * default-deny list is the shape that fails safe: a field added to the
     * station later and not added here is not silently allowed, it is silently
     * ignored by the body rebuild that follows.
     *
     * `prices` and `priceEffectiveAt` are deliberately absent - they are what a
     * manager IS allowed to send.
     */
    private const STATION_FIELD_KEYS = [
        'name',
        'brandId',
        'managerId',
        'newManager',
        'address',
        'barangay',
        'latitude',
        'longitude',
        'locationConfidence',
        'isActive',
        'operatingHours',
        'contactPhone',
        'contactEmail',
        'notes',
        'osmRef',
    ];

    /**
     * The station update() is currently applying to.
     *
     * Set for the duration of that call so resolveManagerId() can tell "this
     * manager already holds another station" from "this manager already holds
     * THIS station". Without it, saving a station without touching the manager
     * dropdown would report the station's own manager as a conflict - because
     * the unique index on manager_id is about to be checked against the very row
     * being written.
     */
    private ?int $editingStationId = null;

    /**
     * Stations visible to the caller.
     *
     * The station-manager filter is in the QUERY, not in the view: a manager's
     * response body must not contain a station they have no business seeing, so
     * the rows are never fetched and then hidden. Admins get every station,
     * placeholders and hidden ones included.
     */
    public function stations(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');
        $this->authorize('viewAny', Station::class);

        // manager is eager loaded because the dashboard's table shows a name under
        // every station; without it that column costs one query per row.
        $query = Station::with(['brand', 'prices', 'manager']);
        if ($user->isStationManager()) {
            $query->where('manager_id', $user->id);
        }

        $stations = $query->get()
            ->sortBy([
                fn ($a, $b) => $a->brand->sort_order <=> $b->brand->sort_order,
                fn ($a, $b) => strcasecmp($a->name, $b->name),
            ])
            ->values();

        return response()->json([
            'data' => $stations->map(fn (Station $s) => $s->toApiArray())->all(),
        ]);
    }

    /**
     * Brands, with a station count each.
     *
     * Open to station managers: the admin map and the dashboard both need the
     * list to render a station's brand, and it carries nothing a manager cannot
     * already read from the public /api/brands.
     */
    public function brands(Request $request): JsonResponse
    {
        return response()->json([
            'data' => Brand::orderBy('sort_order')->orderBy('name')->get()
                ->map(fn (Brand $b) => $b->toApiArray())->all(),
        ]);
    }

    /**
     * Fuel types, coordinate bounds and map config for the dashboard.
     *
     * Open to station managers, who need it: the price editor's fuel-type
     * datalist comes from cfg.fuelTypes, and the admin map needs the bounds to
     * validate a drag. The `managers` list - which is admin-only information,
     * being who can be assigned a station - is omitted from a manager's
     * response rather than 403ing the whole call, since refusing it would leave
     * the price editor with no suggestions.
     */
    public function config(Request $request): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        return response()->json([
            'data' => [
                'fuelTypes' => config('bangued.fuel_types'),
                'stationBounds' => Bangued::stationBounds(),
                'map' => [
                    'center' => config('bangued.map.center'),
                    'defaultZoom' => config('bangued.map.defaultZoom'),
                    'minZoom' => config('bangued.map.minZoom'),
                    'maxZoom' => config('bangued.map.maxZoom'),
                    'maxBounds' => Bangued::maxBounds(),
                ],
                /*
                 * Who can be assigned a station is administrator-only
                 * information, and it is the key to the Station Manager
                 * dropdown - omitted for managers rather than 403ing the whole
                 * config call, which the price editor needs for fuelTypes.
                 *
                 * Each entry carries `stationCount` so the dropdown can show
                 * "Juan Dela Cruz (Blu Gas, 3 stations)" and the administrator
                 * can see they are adding a fourth.
                 *
                 * NO COLUMN CONSTRAINT on the eager loads. A `:id` column list
                 * selects only the related model's primary key, and Eloquent
                 * matches eager-loaded results using the related model's
                 * FOREIGN key - which that list leaves out. The relation then
                 * resolves to nothing, silently, and every manager looks as
                 * though they handle no stations at all. That is exactly what
                 * happened here, and it is why the dropdown offered managers who
                 * already held a station and the save was then refused.
                 */
                'managers' => $user->isStationManager()
                    ? []
                    : User::where('role', 'station_manager')
                        ->with(['managedStations', 'brand'])
                        ->orderBy('display_name')
                        ->get()
                        ->map(fn ($m) => [
                            'id' => $m->id,
                            'username' => $m->username,
                            'email' => $m->email,
                            'displayName' => $m->display_name,
                            'brandId' => $m->brand_id,
                            'brandName' => $m->brand?->name,
                            'stationCount' => $m->managedStations->count(),
                            'stationIds' => $m->managedStations->pluck('id')->all(),
                        ]),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        // create() is administrator only, so a station manager is refused here
        // with the policy's 403 rather than the old ad-hoc check. The policy is
        // the single place that says who may create a station.
        $this->authorize('create', Station::class);

        $name = Validate::requiredString($request->input('name'), 'Station name');
        $brandId = $this->resolveBrandId($request->input('brandId'));
        if (! $brandId) {
            throw HttpError::badRequest('Brand is required.');
        }

        ['latitude' => $latitude, 'longitude' => $longitude] =
            Validate::coordinates($request->input('latitude'), $request->input('longitude'), required: false);

        $hasCoords = $latitude !== null;

        /*
         * The station and the manager account it may create share one
         * transaction. Without it, a manager created inside a station create
         * whose station then fails validation would be left behind holding no
         * station, and would appear in the dropdown as a free manager nobody
         * asked for.
         */
        [$station, $createdManager] = DB::transaction(function () use ($request, $brandId, $name, $latitude, $longitude, $hasCoords) {
            // The brand is resolved above, so a manager created here is held
            // answerable for it and the brand-match check cannot fail.
            $createdManager = Validate::hasKey($request, 'newManager')
                ? $this->createManager($request->input('newManager'), $brandId)
                : null;

            $managerId = $request->input('managerId');
            if ($managerId !== null && $managerId !== '') {
                // No station id yet, so the "already manages another station"
                // check inside resolveManagerId() has nothing to exclude.
                $managerId = $this->resolveManagerId($managerId, $brandId);
            } else {
                $managerId = $createdManager?->id;
            }

            // A record with no coordinates cannot be plotted, so it starts pending.
            $station = new Station([
                'brand_id' => $brandId,
                'name' => $name,
                'address' => Validate::optionalString($request->input('address'), 'Address', 400),
                'barangay' => Validate::optionalString($request->input('barangay'), 'Barangay', 120),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'contact_phone' => Validate::optionalString($request->input('contactPhone'), 'Contact phone', 60),
                'contact_email' => Validate::optionalString($request->input('contactEmail'), 'Contact email', 160),
                'operating_hours' => Validate::optionalString($request->input('operatingHours'), 'Operating hours', 200),
                'notes' => Validate::optionalString($request->input('notes'), 'Notes', 2000),
                'is_pending' => ! $hasCoords,
                'is_active' => Validate::boolean($request->input('isActive'), true),
                'location_confidence' => $hasCoords
                    ? $this->confidenceOrApproximate($request->input('locationConfidence'))
                    : 'pending',
                'osm_ref' => Validate::optionalString($request->input('osmRef'), 'OSM reference', 60),
                'manager_id' => $managerId,
            ]);
            $station->save();

            return [$station, $createdManager];
        });

        return response()->json(
            ['data' => $station->fresh(['brand', 'prices'])->toApiArray()],
            201,
        );
    }

    /**
     * Updates a station and, when the body carries a `prices` array, its fuel
     * prices in the same transaction.
     *
     * The admin map's Edit station dialog saves the form and the price editor
     * together, so the price sync this method delegates to is the same one
     * savePrices() uses - identical validation, identical case-blind duplicate
     * handling, identical history logging. A `prices` key that is present but
     * empty means "remove them all", which PUT /prices refuses outright; the
     * combined save has no such guard because an admin who deleted every row in
     * the editor meant it.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $request->attributes->get('auth_user');

        $station = Station::find(Validate::id($id, 'station id'));
        if (! $station) {
            throw HttpError::notFound('Station not found.');
        }

        $hasPrices = Validate::hasKey($request, 'prices');

        /*
         * A station manager may send nothing but `prices`. They reach this
         * endpoint because the Edit station dialog's single Save button PATCHes
         * the form and the price set together, and because dragging a marker
         * PATCHes coordinates through the same route.
         *
         * Both of those are authorised separately below. Any OTHER body key is
         * a station field - name, brand, coordinates, isActive - and is dropped
         * rather than rejected: refusing the whole request would mean a manager
         * who typed into a greyed-out field they should not have been able to
         * edit loses their price changes too. The response still carries the
         * prices they did change.
         */
        if ($user->isStationManager()) {
            /*
             * A station manager may send prices and nothing else. Any station
             * field alongside them - name, brand, coordinates, isActive,
             * managerId - is a 403 rather than a silent drop.
             *
             * Silently dropping them was the earlier behaviour, on the reasoning
             * that a manager who typed into a greyed-out field should not lose
             * their price changes. But it makes the refusal invisible: the
             * request succeeds, the toast says saved, and nothing tells them the
             * rename they made did nothing. A 403 says it plainly, and the
             * fields are disabled in the dialog so it should not arise.
             */
            $rejected = array_values(array_intersect(
                array_keys($request->all()),
                self::STATION_FIELD_KEYS
            ));

            if ($rejected !== []) {
                throw HttpError::forbidden(
                    'Station managers can only edit fuel prices, not station details. '
                    .'Refused: '.implode(', ', $rejected).'.'
                );
            }

            if (! $hasPrices) {
                throw HttpError::forbidden(
                    'Station managers can only edit fuel prices, not station details.'
                );
            }

            $this->authorize('updatePrices', $station);

            // Belt and braces: the body is rebuilt from prices alone, so no key
            // added to STATION_FIELD_KEYS in a future release can leak through
            // by being forgotten here.
            $request->replace(['prices' => $request->input('prices')]);
        } else {
            /*
             * Administrators keep the combined save. A body carrying only
             * coordinates is a marker drag, which is a different ability from a
             * details edit, so it is checked as one - otherwise a drag would
             * pass an update() check that a details change would not.
             */
            $onlyCoordinates = $this->keysAreOnlyCoordinates($request);

            $this->authorize($onlyCoordinates ? 'move' : 'update', $station);

            /*
             * Assigning a manager - including creating one - is its own ability,
             * checked separately from the details edit it rides along with. Both
             * are administrator only today, so this authorises nothing new today;
             * it exists so the rule is stated where a future role that may edit
             * details still cannot hand out station-level price rights.
             */
            if (Validate::hasKey($request, 'managerId') || Validate::hasKey($request, 'newManager')) {
                $this->authorize('assignManager', $station);
            }
        }

        $this->editingStationId = $station->id;

        /*
         * The whole body runs inside one transaction, so a manager account
         * created by this request is committed with the station it is being
         * assigned to. If the station then fails validation - a brand mismatch,
         * an out-of-range coordinate - the account rolls back too, rather than
         * being left behind holding no station and sitting in the dropdown as a
         * free manager nobody asked for.
         */
        return DB::transaction(function () use ($request, $station, $hasPrices) {
            $this->applyStationFields($request, $station, $hasPrices);

            return response()->json(['data' => $station->fresh(['brand', 'prices'])->toApiArray()]);
        });
    }

    /**
     * Applies a station PATCH body to the model, then saves it and any prices.
     *
     * Split out of update() so the whole body can run inside one transaction
     * covering the station row, the price rows and any manager account the
     * request creates.
     */
    private function applyStationFields(Request $request, Station $station, bool $hasPrices): void
    {
        // Tracks whether the body contained anything updatable, so an empty
        // PATCH is rejected instead of silently bumping updated_at.
        $touched = false;

        // Only keys actually present in the body are touched, so a PATCH never
        // blanks a field the caller did not mention.
        if (Validate::hasKey($request, 'name')) {
            $touched = true;
            $station->name = Validate::requiredString($request->input('name'), 'Station name');
        }
        if (Validate::hasKey($request, 'brandId')) {
            $touched = true;
            $brandId = $this->resolveBrandId($request->input('brandId'));
            if (! $brandId) {
                throw HttpError::badRequest('Brand is required.');
            }
            $station->brand_id = $brandId;
        }

        /*
         * A "+ New manager" account, created by this same request and assigned
         * to this station. Read before the managerId key below so the dialog
         * only has to send one field to do both.
         */
        $createdManager = Validate::hasKey($request, 'newManager')
            ? $this->createManager($request->input('newManager'), $station->brand_id)
            : null;

        if (Validate::hasKey($request, 'managerId') || $createdManager) {
            $touched = true;
            // Explicit null or "" clears the assignment - unless this same
            // request created the manager, in which case the new account wins.
            $managerId = $request->input('managerId');

            if ($managerId === null || $managerId === '') {
                $station->manager_id = $createdManager?->id;
            } else {
                $station->manager_id = $this->resolveManagerId($managerId, $station->brand_id);
            }
        }

        /*
         * A brand change can invalidate the assignment being kept.
         *
         * Checked once every field has been read, so the brand is already the
         * new one, and BEFORE the save - which turns a mismatch into a readable
         * 400 instead of the abort a database trigger would raise.
         *
         * REJECTS rather than unassigning: clearing the manager silently would
         * discard an assignment the administrator did not ask to change, and
         * they would not find out until the manager reported losing access.
         */
        if ($station->manager_id !== null) {
            $manager = User::find($station->manager_id);
            if ($manager && ! $manager->managesBrand($station->brand)) {
                throw HttpError::badRequest(
                    $this->brandMismatchMessage($manager, $station->brand).' '
                    .'Reassign or clear the station manager first.'
                );
            }
        }

        foreach ([
            'address' => ['Address', 400],
            'barangay' => ['Barangay', 120],
            'contactPhone' => ['Contact phone', 60],
            'contactEmail' => ['Contact email', 160],
            'operatingHours' => ['Operating hours', 200],
            'notes' => ['Notes', 2000],
            'osmRef' => ['OSM reference', 60],
        ] as $key => [$label, $max]) {
            if (Validate::hasKey($request, $key)) {
                $touched = true;
                $column = $this->columnFor($key);
                $station->{$column} = Validate::optionalString($request->input($key), $label, $max);
            }
        }
        if (Validate::hasKey($request, 'isActive')) {
            $touched = true;
            $station->is_active = Validate::boolean($request->input('isActive'), true);
        }

        if (Validate::hasKey($request, 'latitude') || Validate::hasKey($request, 'longitude')) {
            $touched = true;
            ['latitude' => $latitude, 'longitude' => $longitude] = Validate::coordinates(
                Validate::hasKey($request, 'latitude') ? $request->input('latitude') : $station->latitude,
                Validate::hasKey($request, 'longitude') ? $request->input('longitude') : $station->longitude,
                required: false,
            );
            $station->latitude = $latitude;
            $station->longitude = $longitude;

            // Supplying coordinates completes a pending record: publish it.
            if ($latitude !== null) {
                $station->is_pending = false;
                $station->location_confidence = $this->confidenceOrApproximate($request->input('locationConfidence'));
            } else {
                $station->is_pending = true;
                $station->location_confidence = 'pending';
            }
        } elseif (Validate::hasKey($request, 'locationConfidence')) {
            $touched = true;
            $value = (string) $request->input('locationConfidence');
            if (! in_array($value, Station::CONFIDENCE, true)) {
                throw HttpError::badRequest('locationConfidence must be confirmed, approximate or pending.');
            }
            $station->location_confidence = $value;
        }

        if (! $touched && ! $hasPrices) {
            throw HttpError::badRequest('No fields to update.');
        }

        /*
         * Prices are validated BEFORE the save, because a bad row has to surface
         * as a 400 with the dialog still holding what the admin typed rather
         * than rolling a transaction back behind a generic error.
         */
        $priceRows = $hasPrices
            ? $this->validatePriceRows($request->input('prices'), allowEmpty: true)
            : null;

        $effectiveAt = $priceRows !== null
            ? Validate::optionalString($request->input('priceEffectiveAt'), 'priceEffectiveAt', 40)
            : null;
        $username = $request->attributes->get('auth_user')->username;

        // The caller already opened a transaction around this method, so this
        // is a savepoint rather than a second top-level one.
        DB::transaction(function () use ($station, $priceRows, $effectiveAt, $username) {
            $station->save();

            if ($priceRows !== null) {
                $this->syncPrices($station->id, $priceRows, $effectiveAt, $username);
            }
        });
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $stationId = Validate::id($id, 'station id');
        $station = Station::find($stationId);
        if (! $station) {
            throw HttpError::notFound('Station not found.');
        }

        $this->authorize('delete', $station);

        // Prices and their history rows cascade at the database level; the
        // explicit transaction keeps that atomic with the station delete.
        DB::transaction(fn () => Station::whereKey($stationId)->delete());

        return response()->json(['data' => ['ok' => true, 'id' => $stationId]]);
    }

    /**
     * Replaces the price set for a station.
     *
     * The set is authoritative: any fuel type missing from the request is
     * deleted, so removing a row in the editor really removes it. Case-only
     * spelling changes are treated as the same fuel type rather than as a
     * delete plus an insert, which is what the UNIQUE (station_id, fuel_type)
     * key would otherwise turn into a duplicate row.
     */
    public function savePrices(Request $request, string $id): JsonResponse
    {
        $station = Station::find(Validate::id($id, 'station id'));
        if (! $station) {
            throw HttpError::notFound('Station not found.');
        }

        /*
         * updatePrices() covers both halves of "is this my station": the station
         * must be assigned to this manager AND its brand must be one they are
         * assigned across. A station id from the request is never trusted on its
         * own - this is the check that stops a manager naming another manager's
         * station to reach its prices.
         */
        $this->authorize('updatePrices', $station);

        // Only `prices` and `effectiveAt` are read below, so no other body key
        // can reach the station through this endpoint even for an admin.
        $input = $request->input('prices');
        if (! is_array($input) || $input === []) {
            throw HttpError::badRequest(
                'Send a non-empty `prices` array, e.g. [{ "fuelType": "Gasoline", "pricePerLiter": 72.50 }].'
            );
        }

        $effectiveAt = Validate::optionalString($request->input('effectiveAt'), 'effectiveAt', 40)
            ?? now()->utc()->format('Y-m-d H:i:s');

        $rows = $this->validatePriceRows($input, allowEmpty: false);
        $username = $request->attributes->get('auth_user')->username;

        DB::transaction(function () use ($station, $rows, $effectiveAt, $username) {
            $this->syncPrices($station->id, $rows, $effectiveAt, $username);
        });

        return response()->json(['data' => $station->fresh(['brand', 'prices'])->toApiArray()]);
    }

    /**
     * Validates a submitted price set into the row shape syncPrices() consumes.
     *
     * Shared by savePrices() and update() so both entry points reject exactly
     * the same inputs with exactly the same wording. Every problem in the list
     * is collected before throwing, with the first one as the message: an editor
     * showing one error per row should not have to be reloaded to discover the
     * second mistake.
     *
     * @param  array<int, mixed>  $input
     * @return list<array{fuelType: string, key: string, price: float}>
     *
     * @throws HttpError 400 with details keyed by row index.
     */
    private function validatePriceRows(array $input, bool $allowEmpty): array
    {
        if (! $allowEmpty && $input === []) {
            throw HttpError::badRequest(
                'Send a non-empty `prices` array, e.g. [{ "fuelType": "Gasoline", "pricePerLiter": 72.50 }].'
            );
        }

        $rows = [];
        $seen = [];
        $errors = [];

        foreach (array_values($input) as $index => $p) {
            try {
                $fuelType = Validate::requiredString(is_array($p) ? ($p['fuelType'] ?? null) : null, 'fuelType', 80);

                $raw = is_array($p) ? ($p['pricePerLiter'] ?? null) : null;
                if (! is_numeric($raw)) {
                    throw HttpError::badRequest("Price for \"{$fuelType}\" must be a number of 0 or more.");
                }
                $price = (float) $raw;
                if ($price < 0) {
                    throw HttpError::badRequest("Price for \"{$fuelType}\" must be a number of 0 or more.");
                }
                if ($price > 10000) {
                    throw HttpError::badRequest("Price for \"{$fuelType}\" looks wrong (max 10000).");
                }

                $key = mb_strtolower($fuelType);
                if (isset($seen[$key])) {
                    throw HttpError::badRequest("Duplicate fuel type \"{$fuelType}\".");
                }
                $seen[$key] = true;
            } catch (HttpError $e) {
                $errors[$index] = $e->getMessage();

                continue;
            }

            $rows[] = ['fuelType' => $fuelType, 'key' => $key, 'price' => $price];
        }

        if ($errors !== []) {
            throw HttpError::badRequest(reset($errors), ['rows' => $errors]);
        }

        return $rows;
    }

    /**
     * Writes a validated price set: removes the fuel types absent from it,
     * collapses case-only spelling variants and upserts the rest, logging a
     * history row wherever a price actually moved.
     *
     * @param  list<array{fuelType: string, key: string, price: float}>  $rows
     */
    private function syncPrices(int $stationId, array $rows, ?string $effectiveAt, string $username): void
    {
        $effectiveAt ??= now()->utc()->format('Y-m-d H:i:s');

        /*
         * Snapshot the current prices BEFORE anything is deleted.
         *
         * The stale-spelling cleanup below removes the row holding the
         * previous value when only the capitalisation changed, so reading
         * it afterwards found nothing and every case-only edit was logged
         * as a jump from NULL. Keyed by the lower-cased fuel type to match
         * the case-blind comparisons used here.
         */
        $previous = [];
        foreach (FuelPrice::where('station_id', $stationId)->get() as $existing) {
            $previous[mb_strtolower($existing->fuel_type)] = (float) $existing->price_per_liter;
        }

        $kept = array_column($rows, 'key');

        // (a) Removals: fuel types the admin deleted in the editor. An empty
        // $kept means "delete them all", which is why the empty-list guard
        // lives in validatePriceRows() rather than here.
        FuelPrice::where('station_id', $stationId)
            ->whereNotIn(DB::raw('lower(fuel_type)'), $kept ?: ['\0'])
            ->delete();

        // (b) Stale spellings of a fuel type that is still present. The
        // unique key is on the raw text, so without this "Gasoline" and a
        // later save spelling it "GASOLINE" would become two rows for one
        // fuel type. Clearing the variant first lets the upsert hit it.
        foreach ($rows as $row) {
            FuelPrice::where('station_id', $stationId)
                ->whereRaw('lower(fuel_type) = ?', [$row['key']])
                ->where('fuel_type', '!=', $row['fuelType'])
                ->delete();
        }

        foreach ($rows as $row) {
            FuelPrice::updateOrCreate(
                ['station_id' => $stationId, 'fuel_type' => $row['fuelType']],
                [
                    'price_per_liter' => $row['price'],
                    'effective_at' => $effectiveAt,
                    'updated_at' => now(),
                ],
            );

            $prevPrice = $previous[$row['key']] ?? null;
            if ($prevPrice === null || $prevPrice !== $row['price']) {
                FuelPriceHistory::create([
                    'station_id' => $stationId,
                    'fuel_type' => $row['fuelType'],
                    'previous_price' => $prevPrice,
                    'new_price' => $row['price'],
                    'changed_at' => now(),
                    'changed_by' => $username,
                ]);
            }
        }
    }

    /**
     * Replaces the set of stations a manager handles.
     *
     * The "Manage stations" checklist: the body is the complete list of station
     * ids this manager should have, so a station that is unticked is unassigned.
     * A partial update would need the client to work out the difference and
     * would leave a stale assignment behind whenever a tick was removed.
     *
     * Every station named must carry the manager's brand. All of it is validated
     * BEFORE the transaction opens, so a rejected tick changes nothing at all
     * rather than leaving the manager half-reassigned.
     *
     * Administrator only, enforced by RequireAdminRole on the route.
     */
    public function assignStations(Request $request, string $id): JsonResponse
    {
        $managerId = Validate::id($id, 'manager');
        $manager = User::find($managerId);

        if (! $manager || ! $manager->isStationManager()) {
            throw HttpError::badRequest('Invalid station manager.');
        }

        $input = $request->input('stationIds');
        if (! is_array($input)) {
            throw HttpError::badRequest('Send stationIds as an array of station ids.');
        }

        // De-duplicated so the same id ticked twice is not a conflict with itself.
        $ids = array_values(array_unique(array_map(
            fn ($v) => Validate::id($v, 'station id'),
            $input
        )));

        $stations = $ids === [] ? collect() : Station::whereIn('id', $ids)->get();

        if ($stations->count() !== count($ids)) {
            throw HttpError::badRequest('One or more of those stations does not exist.');
        }

        // One error for the whole set, naming the first offender, rather than
        // applying the valid ticks and refusing the rest.
        foreach ($stations as $station) {
            if (! $manager->managesBrand($station->brand)) {
                throw HttpError::badRequest(
                    $this->brandMismatchMessage($manager, $station->brand)
                    .' Tick only '.$station->brand->name.' stations.'
                );
            }
        }

        $username = $request->attributes->get('auth_user')->username;

        DB::transaction(function () use ($manager, $ids, $stations) {
            // Unassign first, then assign. Doing it in this order means the
            // manager never transiently holds two brands, which the
            // stations_manager_brand_matches trigger would reject even inside a
            // transaction that is about to succeed.
            Station::where('manager_id', $manager->id)
                ->when($ids !== [], fn ($q) => $q->whereNotIn('id', $ids))
                ->update(['manager_id' => null]);

            foreach ($stations as $station) {
                $station->forceFill(['manager_id' => $manager->id])->save();
            }

            // A manager ticked their first station adopts that station's brand,
            // so the rule has an anchor from then on.
            if ($stations->isNotEmpty()) {
                $manager->adoptBrand($stations->first()->brand);
            }
        });

        return response()->json([
            'data' => [
                'ok' => true,
                'managerId' => $manager->id,
                'stationIds' => Station::where('manager_id', $manager->id)->pluck('id')->all(),
                'brandId' => $manager->fresh()->brand_id,
                'changedBy' => $username,
            ],
        ]);
    }

    /** Last 100 price changes for a station, newest first. */
    public function priceHistory(Request $request, string $id): JsonResponse
    {
        $station = Station::find(Validate::id($id, 'station id'));
        if (! $station) {
            throw HttpError::notFound('Station not found.');
        }

        // The same ability as editing the prices: a manager sees the history of
        // the stations whose prices they can change, and nothing else.
        $this->authorize('viewPriceHistory', $station);

        $rows = FuelPriceHistory::where('station_id', $station->id)
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (FuelPriceHistory $h) => $h->toApiArray())
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * True when the body carries coordinates and nothing but coordinates.
     *
     * This is the shape a marker drag sends (admin-map.js updateStationPosition
     * sends exactly latitude and longitude), which is how update() tells a
     * drag from a details edit and checks the `move` ability instead of
     * `update`. `prices` deliberately does not count: the admin map's combined
     * save sends prices alongside the form fields, and that is an edit, not a
     * drag.
     */
    private function keysAreOnlyCoordinates(Request $request): bool
    {
        $keys = array_keys($request->all());

        if ($keys === []) {
            return false;
        }

        sort($keys);

        return $keys === ['latitude', 'longitude'];
    }

    /**
     * Validates a manager assignment for a station and returns the manager id.
     *
     * One manager may handle SEVERAL stations, all of the same brand. So the
     * only thing that can be wrong is the brand:
     *
     *   - the id must resolve to a station_manager, so an admin account cannot
     *     be assigned as a manager and then inherit station-level rights
     *     through a policy that keys off the assignment;
     *   - the manager's brand must match the station's. A manager with no brand
     *     yet may take any brand, and adopts it.
     *
     * How many stations they already hold is NOT an error. It used to be - a
     * UNIQUE index on stations.manager_id made it one - and that rule was the
     * direct cause of "Save station" failing on the admin map: the dropdown
     * offered a manager who already held a station (because the HasOne eager
     * load silently returned null, so the "busy" filter never excluded anyone)
     * and the save was rejected with a message about a rule the administrator
     * could not see or work around.
     */
    private function resolveManagerId(mixed $input, int $stationBrandId): int
    {
        $managerId = Validate::id($input, 'manager');
        $manager = User::find($managerId);

        if (! $manager || ! $manager->isStationManager()) {
            throw HttpError::badRequest('Invalid station manager.');
        }

        $brand = Brand::findOrFail($stationBrandId);

        if (! $manager->managesBrand($brand)) {
            throw HttpError::badRequest($this->brandMismatchMessage($manager, $brand));
        }

        // First station for this manager: the brand becomes theirs, derived from
        // a real station rather than typed into the account form.
        $manager->adoptBrand($brand);

        return $managerId;
    }

    /**
     * The message for a brand mismatch, naming the manager and the brand they
     * actually handle.
     *
     * Both names are in the message because the administrator is looking at a
     * dropdown that offered this manager for a station of the wrong brand, and
     * "invalid manager" would tell them nothing they can act on.
     */
    private function brandMismatchMessage(User $manager, Brand $wanted): string
    {
        $name = $manager->display_name ?: $manager->username;
        $theirs = $manager->brand?->name;

        return $theirs
            ? "\"{$name}\" handles {$theirs} stations only."
            : "\"{$name}\" handles {$wanted->name} stations only, but is assigned to this station.";
    }

    /**
     * Creates a station manager account from the Edit station dialog.
     *
     * Uses the same hashing path as every other account in the app
     * (User::setPasswordHash) rather than a raw password_hash write, and forces
     * the role to station_manager regardless of what the body asked for - a
     * body that says role=admin must not be able to mint an administrator.
     *
     * @param  mixed  $input  The `newManager` object from the request body.
     * @param  int  $stationBrandId  The brand to hold the new manager answerable for.
     */
    private function createManager(mixed $input, int $stationBrandId): User
    {
        if (! is_array($input)) {
            throw HttpError::badRequest('newManager must be an object.');
        }

        $email = Validate::requiredString($input['email'] ?? null, 'Manager email', 160);

        // Deliberately stricter than the station contact email, which is only
        // trimmed server side: this is a login identity.
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw HttpError::badRequest('Manager email must be a valid email address.');
        }

        if (User::where('email', $email)->exists() || User::where('username', $email)->exists()) {
            throw HttpError::badRequest('A user with that email already exists.');
        }

        $name = Validate::optionalString($input['name'] ?? null, 'Manager name', 160);
        $password = Validate::requiredString($input['password'] ?? null, 'Temporary password', 200);

        if (mb_strlen($password) < 8) {
            throw HttpError::badRequest('Temporary password must be at least 8 characters.');
        }

        $manager = new User([
            // The email doubles as the login username: users.username is the
            // sign-in identifier and an address is a valid one.
            'username' => $email,
            'email' => $email,
            'display_name' => $name ?? $email,
            'role' => 'station_manager',
            'brand_id' => $stationBrandId,
        ]);
        $manager->setPasswordHash($password, (int) config('bangued.admin.bcrypt_rounds'));
        $manager->save();

        return $manager;
    }

    /** Validates a brand id and returns it, or null when absent. */
    private function resolveBrandId(mixed $input): ?int
    {
        if ($input === null || $input === '') {
            return null;
        }
        $id = Validate::id($input, 'brand');
        if (! Brand::whereKey($id)->exists()) {
            throw HttpError::badRequest('Unknown brand.');
        }

        return $id;
    }

    /** Confirmed/approximate, defaulting to approximate for unknown values. */
    private function confidenceOrApproximate(mixed $value): string
    {
        return in_array($value, ['confirmed', 'approximate'], true) ? $value : 'approximate';
    }

    /** camelCase body key -> snake_case column. */
    private function columnFor(string $key): string
    {
        return match ($key) {
            'contactPhone' => 'contact_phone',
            'contactEmail' => 'contact_email',
            'operatingHours' => 'operating_hours',
            'osmRef' => 'osm_ref',
            default => $key,
        };
    }
}

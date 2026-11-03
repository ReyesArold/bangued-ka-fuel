<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Station extends Model
{
    use HasFactory;

    /** Confidence values, in the order the admin UI offers them. */
    public const CONFIDENCE = ['confirmed', 'approximate', 'pending'];

    protected $fillable = [
        'brand_id',
        'manager_id',
        'name',
        'address',
        'barangay',
        'latitude',
        'longitude',
        'contact_phone',
        'contact_email',
        'operating_hours',
        'notes',
        'is_pending',
        'is_active',
        'location_confidence',
        'osm_ref',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            // Real booleans in JSON. The Node app relied on SQLite's 0/1 with a
            // !! cast in the query layer; the admin UI tests these directly, so
            // they must not serialise as 0/1 here.
            'is_pending' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(FuelPrice::class);
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(FuelPriceHistory::class);
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    /**
     * Whether this station is assigned to the given user.
     *
     * The single source of truth for "is this manager's station", used by
     * StationPolicy and mirrored by the admin map's marker click handler. Lives
     * here rather than in the policy so the answer to the question has one
     * place: a station with no manager belongs to no one.
     */
    public function isManagedBy(User $user): bool
    {
        return $this->manager_id !== null && (int) $this->manager_id === (int) $user->id;
    }

    /** Plottable and published: the exact set the public map shows. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_pending', false);
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Identical JSON contract to the Node version's mapStation(), including the
     * camelCase keys and the nested brand object. The frontend depends on every
     * one of these names.
     *
     * @param  bool  $includeManager  Whether to append the nested `manager`
     *                                object. TRUE for the admin endpoints, which
     *                                render the manager's name in the station
     *                                table. FALSE for the public endpoints: no
     *                                public page reads it (map.js, legend.js and
     *                                icons.js never touch station.manager), and it
     *                                carries the manager's username - which is
     *                                their email address for an account created
     *                                from the "+ New manager" dialog - so
     *                                publishing it would put a real person's
     *                                email address on an unauthenticated endpoint.
     */
    public function toApiArray(bool $includeManager = true): array
    {
        // The prices relation is used when the caller eager-loaded it, so
        // serialising a whole list costs one query instead of one per station.
        // Callers that only have a single station still get the lazy query.
        $prices = $this->relationLoaded('prices')
            ? $this->getRelation('prices')->sortBy('fuel_type')->values()
            : $this->prices()->orderBy('fuel_type')->get();

        // Newest price row wins. This deliberately does NOT start the reduce at
        // null and compare `updated_at > null`: in PHP `>` against null coerces
        // both sides, and in the Node original this silently pinned
        // lastPriceUpdate to null forever. Seeded from the first row instead.
        $lastPriceUpdate = $prices->reduce(
            fn ($max, $p) => $max === null || $p->updated_at > $max ? $p->updated_at : $max,
            null,
        );

        $result = [
            'id' => $this->id,
            'name' => $this->name,
            'address' => $this->address,
            'barangay' => $this->barangay,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'contactPhone' => $this->contact_phone,
            'contactEmail' => $this->contact_email,
            'operatingHours' => $this->operating_hours,
            'notes' => $this->notes,
            'isPending' => (bool) $this->is_pending,
            'isActive' => (bool) $this->is_active,
            'locationConfidence' => $this->location_confidence,
            'osmRef' => $this->osm_ref,
            'createdAt' => $this->created_at?->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updated_at?->format('Y-m-d H:i:s'),
            'managerId' => $this->manager_id,
            'brand' => [
                'id' => $this->brand->id,
                'slug' => $this->brand->slug,
                'name' => $this->brand->name,
                'colorPrimary' => $this->brand->color_primary,
                'colorSecondary' => $this->brand->color_secondary,
                'markerIcon' => $this->brand->marker_icon,
                'logoPath' => $this->brand->logo_path,
                'sortOrder' => $this->brand->sort_order,
            ],
            'prices' => $prices->map(fn ($p) => [
                'fuelType' => $p->fuel_type,
                'pricePerLiter' => (float) $p->price_per_liter,
                // public/js/time.js parses these as UTC, matching the Node app,
                // which stored datetime('now') i.e. UTC.
                'effectiveAt' => $p->effective_at?->format('Y-m-d H:i:s'),
                'updatedAt' => $p->updated_at?->format('Y-m-d H:i:s'),
            ])->all(),
            'lastPriceUpdate' => $lastPriceUpdate instanceof \DateTimeInterface
                ? $lastPriceUpdate->format('Y-m-d H:i:s')
                : $lastPriceUpdate,
        ];
        if ($includeManager) {
            /*
             * Resolve the manager ONCE.
             *
             * The earlier form of this was a condition rather than an
             * assignment:
             *     if ($this->relationLoaded('manager') ? $this->manager : $this->manager()->first()) {
             * which looked like it was avoiding a query and did the opposite of
             * what it read as. `->first()` runs a query WITHOUT populating the
             * relation cache, so the `$this->manager` read inside the block
             * issued a second one - two queries per station, on any caller that
             * had not eager-loaded manager.
             *
             * relationLoaded() is a property check with no query behind it, and
             * an eager-loaded list resolves here with no query at all.
             */
            $manager = $this->relationLoaded('manager') ? $this->manager : $this->manager()->first();

            if ($manager) {
                $result['manager'] = [
                    'id' => $manager->id,
                    'username' => $manager->username,
                    'displayName' => $manager->display_name,
                    'brandId' => $manager->brand_id,
                ];
            }
        }

        return $result;
    }
}

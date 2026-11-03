<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An admin account. The Node version authenticated with a JWT cookie
 * (bgm_session); here Laravel's encrypted session cookie replaces it, so the
 * token plumbing disappears while the observable behaviour stays the same:
 * httpOnly cookie, SameSite=Lax, 12 h lifetime, cleared on logout.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    // Matches the SQLite schema: created_at + last_login_at, no updated_at.
    // Without this Eloquent writes an updated_at column that does not exist.
    public $timestamps = false;

    protected $fillable = [
        'username',
        'email',
        'password_hash',
        'display_name',
        'role',
        'brand_id',
        'last_login_at',
    ];

    // The bcrypt hash must never leave the model by accident, and the password
    // is never set on this model: hashing happens through setPasswordHash().
    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'last_login_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The stations this user manages.
     *
     * A HasMany: one manager may handle several stations, and the constraint is
     * that they all share a brand - not that there is only one of them. See
     * managesBrand().
     */
    public function managedStations(): HasMany
    {
        return $this->hasMany(Station::class, 'manager_id');
    }

    /**
     * The brand this user is answerable for.
     *
     * Only meaningful for a station_manager; an administrator is answerable for
     * every brand and has none set. NULL on a manager means "no brand chosen
     * yet" - unconstrained, so an account created before the column existed
     * keeps working, and the first station assigned to it adopts its brand.
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * Whether this manager may handle a station of the given brand.
     *
     * The whole of the brand rule. A manager with no brand yet may take any
     * brand - and that brand becomes theirs (see adoptBrand) - because there is
     * nothing yet to contradict.
     *
     * A positive test rather than comparing against a nullable column:
     * `null !== $brand->id` is true, so the naive comparison would read as a
     * mismatch and lock every pre-existing manager out of their own stations.
     */
    public function managesBrand(Brand $brand): bool
    {
        return $this->brand_id === null || (int) $this->brand_id === (int) $brand->id;
    }

    /**
     * Pins this manager to a brand, the first time they are given a station.
     *
     * Called on assignment rather than on account creation so the brand is
     * always derived from a real station instead of being typed in and then
     * having to be kept in step.
     */
    public function adoptBrand(Brand $brand): void
    {
        if ($this->brand_id === null) {
            $this->forceFill(['brand_id' => $brand->id])->save();
        }
    }

    /**
     * How many stations this manager currently handles.
     *
     * Shown next to their name in the Station manager dropdown, so an
     * administrator can see at a glance that a manager already handles two
     * stations and is being added a third.
     */
    public function managedStationCount(): int
    {
        return $this->managedStations()->count();
    }

    /**
     * Passwords are stored as a bcrypt hash (cost 12, matching the Node app).
     * Written through an explicit method rather than the usual
     * $user->password = ... so a plain hash column can never be filled
     * directly from request input.
     */
    public function setPasswordHash(string $plain, int $rounds = 12): void
    {
        $this->attributes['password_hash'] = password_hash($plain, PASSWORD_BCRYPT, [
            'cost' => $rounds,
        ]);
    }

    public function checkPassword(string $plain): bool
    {
        return password_verify($plain, (string) $this->attributes['password_hash']);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStationManager(): bool
    {
        return $this->role === 'station_manager';
    }

    public function hasAdminAccess(): bool
    {
        return $this->role === 'admin' || $this->role === 'station_manager';
    }

    /** Shape sent to the client by /api/auth/me and /api/auth/login. */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'email' => $this->email,
            'displayName' => $this->display_name,
            'role' => $this->role,
            'brandId' => $this->brand_id,
            // Every station this manager is answerable for, so the admin map can
            // highlight them all and the dashboard can link straight to them
            // without a second request. Empty for an admin and for a manager
            // with nothing assigned yet.
            'stationIds' => $this->managedStations()->pluck('id')->all(),
        ];
    }

    /**
     * Dummy hash used to keep login timing uniform for unknown usernames.
     * A precomputed bcrypt hash is verified against when the username does not
     * exist, so a missing account and a wrong password take the same time and
     * cannot be told apart by response latency.
     */
    public static function dummyHash(): string
    {
        return '$2y$12$C6UzMDM.H6dfI/f/IKcEe.yrOZf6ZBqMKjEHWfPPQlL9Bp/aSpaRy';
    }
}

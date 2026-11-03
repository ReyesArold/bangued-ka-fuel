<?php

namespace App\Policies;

use App\Models\Station;
use App\Models\User;

/**
 * Who may change what on a station.
 *
 * Two roles reach the /api/admin routes (RequireAdmin admits both):
 *
 *   - admin (Map Administrator) may do everything.
 *   - station_manager may change the fuel prices of the stations assigned to
 *     them - one or many, all of one brand - and nothing else: no creating,
 *     deleting, renaming, re-branding, re-banding, moving, re-pointing or
 *     reassigning.
 *
 * Assignment is stations.manager_id, a single nullable foreign key. One manager
 * may handle SEVERAL stations - the constraint is that they all share a brand,
 * not that there is only one of them. A station with no manager belongs to no
 * one and is invisible to every manager.
 *
 * A manager also carries a brand (users.brand_id), enforced at assignment time
 * by AdminApiController::resolveManagerId() and again by a database trigger, so
 * a station can never end up paired with a manager of a different brand. It is
 * deliberately NOT re-checked in updatePrices(): the brand and the assignment are
 * kept in step by those two gates, so re-deriving a check from them here would be
 * a comparison against data that cannot disagree.
 *
 * Every ability below is a deny by default. An unlisted ability - a new one
 * added to a controller without a matching method here - returns false rather
 * than falling through to some other rule.
 */
class StationPolicy
{
    /** View a station's record. Admins see all; a manager sees their own. */
    public function viewAny(User $user): bool
    {
        return $user->hasAdminAccess();
    }

    public function view(User $user, Station $station): bool
    {
        return $user->isAdmin() || $this->manages($user, $station);
    }

    /** Creating stations is an administrator action. */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Editing the station's own fields: name, brand, address, barangay, hours,
     * status, notes and coordinates.
     *
     * Administrator only. A station manager reaches the Edit station dialog to
     * change prices, and every one of those columns is closed to them - the
     * server refuses the request even if the UI is bypassed.
     */
    public function update(User $user, Station $station): bool
    {
        return $user->isAdmin();
    }

    /**
     * Assigning, changing or clearing a station's manager, including creating a
     * manager account from the Edit station dialog.
     *
     * A separate ability from update() because it is a different user action
     * with its own blast radius: it decides who can change this station's prices
     * from now on, and it creates accounts. Kept readable at the call site
     * rather than folded into update().
     */
    public function assignManager(User $user, Station $station): bool
    {
        return $user->isAdmin();
    }

    /** Deleting a station, and with it its prices, is administrator only. */
    public function delete(User $user, Station $station): bool
    {
        return $user->isAdmin();
    }

    /**
     * Moving a station's marker - the PATCH a drag sends with only latitude and
     * longitude.
     *
     * Separate from update() because it is a distinct user action ("drag the
     * pin to a new spot") that must be readable at the call site, and because
     * it stays closed to managers even though the same endpoint serves both.
     */
    public function move(User $user, Station $station): bool
    {
        return $user->isAdmin();
    }

    /**
     * Editing a station's fuel prices: adding, changing and removing price rows.
     *
     * The one station-level ability a manager has, and it is exactly one test:
     * is this my station? A station of another manager fails it, and so does a
     * station of the same brand that is not theirs - which is the case a
     * brand-only rule would have let through.
     */
    public function updatePrices(User $user, Station $station): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isStationManager()) {
            return false;
        }

        return $this->manages($user, $station);
    }

    /** Reading a station's price history is part of managing its prices. */
    public function viewPriceHistory(User $user, Station $station): bool
    {
        return $this->updatePrices($user, $station);
    }

    /** True when the station is assigned to this manager. */
    private function manages(User $user, Station $station): bool
    {
        return $station->isManagedBy($user);
    }
}

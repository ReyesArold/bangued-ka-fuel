<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One manager per station, one station per manager, brands in step.
 *
 * stations.manager_id already exists as a plain nullable foreign key, added by
 * 2026_10_05_000520. This migration adds what was missing for the rule to be
 * enforceable rather than conventional:
 *
 *   1. users.email - where the "+ New manager" dialog stores an address.
 *      Nullable and unique.
 *   2. users.brand_id - the brand a station manager is answerable for.
 *
 *      This is the piece that makes "a manager's brand must match their
 *      station's brand" mean anything. users had no brand column, so a
 *      manager's brand could only be derived from the station they manage -
 *      which for a one-to-one relationship is circular: the rule would compare
 *      a station's brand against itself and could never fail. Storing the brand
 *      on the user makes the comparison real, and it is what
 *      AdminApiController::resolveManagerId() validates on assignment.
 *
 *      Nullable, and NULL means "any brand" - so an existing manager account
 *      with no brand keeps working and is simply unconstrained.
 *
 *   3. A UNIQUE index on stations.manager_id, so two stations cannot claim the
 *      same manager. A manager with two stations would make "their station"
 *      ambiguous in StationPolicy.
 *
 *   4. Triggers that refuse a mismatch between users.brand_id and
 *      stations.brand_id. The controller validates first and answers with a
 *      readable 400; the trigger is the backstop for a write that arrives some
 *      other way (tinker, a console command, a future seeder).
 */
return new class extends Migration
{
    /** Named once so both trigger definitions and down() agree on it. */
    private const TRIGGER = 'stations_manager_brand_matches';

    public function up(): void
    {
        /*
         * Every step below is guarded on whether it has already happened.
         *
         * SQLite does not wrap ALTER TABLE in the migration's transaction, so a
         * migration that fails part-way leaves the earlier DDL committed while
         * the migrations table still says it has not run. Re-running then hits
         * "duplicate column name" and can never complete. The guards make the
         * migration resumable after a partial failure, which for SQLite is the
         * normal case rather than an edge case.
         */

        if (! Schema::hasColumn('users', 'email') || ! Schema::hasColumn('users', 'brand_id')) {
            $this->rebuildUsersTable();
        }

        /*
         * Backfill email from username where the username already looks like an
         * address, so an account that signs in with an email gains a matching
         * email rather than a NULL that later reads as missing data.
         */
        DB::table('users')
            ->where('username', 'like', '%@%')
            ->update(['email' => DB::raw('username')]);

        /*
         * Collapse managers who currently hold more than one station, before the
         * unique index goes on.
         *
         * DATA LOSS, and deliberately so: the previous setup allowed one manager
         * to hold many stations (it was a plain hasMany), so this database may
         * contain such a manager and the index below cannot be created until it
         * does not. The LOWEST station id is kept - the one a manager was
         * assigned first - and every later one is unassigned to NULL, which
         * leaves the station intact and simply leaves it without a manager.
         *
         * NULL is the safe direction: an unassigned station is invisible to
         * managers and fully editable by an administrator, so the station is not
         * stranded and nobody gains access they should not have. The reverse -
         * keeping the newest and dropping the oldest - would silently revoke
         * access someone already had.
         *
         * Administrators reassign the unassigned stations through the Edit
         * station dialog afterwards.
         */
        // manager_id => the one station id that keeps the assignment.
        $keepers = DB::table('stations')
            ->whereNotNull('manager_id')
            ->select('manager_id', DB::raw('MIN(id) as keeper'))
            ->groupBy('manager_id')
            ->pluck('keeper', 'manager_id');

        $demotedIds = DB::table('stations')
            ->whereNotNull('manager_id')
            ->get(['id', 'manager_id'])
            ->reject(fn ($s) => (int) $keepers->get($s->manager_id) === (int) $s->id)
            ->pluck('id');

        if ($demotedIds->isNotEmpty()) {
            DB::table('stations')->whereIn('id', $demotedIds)->update(['manager_id' => null]);
        }

        if (! $this->hasIndex('stations', 'stations_manager_id_unique')) {
            Schema::table('stations', function (Blueprint $table) {
                $table->unique('manager_id');
            });
        }

        $this->dropTriggers();

        /*
         * The same rule on both INSERT and UPDATE. UPDATE covers the case in the
         * requirement that a station's BRAND changes under a manager who was
         * assigned when the brands still matched.
         *
         * Written as a WHEN clause with two subqueries rather than a body SELECT:
         * a trigger body cannot contain the RAISE it needs to be guarded by a
         * condition, and a bare RAISE would abort every write.
         *
         * `m.brand_id IS NOT NULL` is what makes a brand-less manager
         * unconstrained rather than always mismatched.
         */
        foreach (['INSERT', 'UPDATE OF brand_id, manager_id'] as $event) {
            DB::statement(<<<SQL
                CREATE TRIGGER {$this->triggerName($event)}
                BEFORE {$event} ON stations
                FOR EACH ROW
                WHEN NEW.manager_id IS NOT NULL
                     AND EXISTS (
                         SELECT 1 FROM users m
                         WHERE m.id = NEW.manager_id
                           AND m.role = 'station_manager'
                           AND m.brand_id IS NOT NULL
                           AND m.brand_id <> NEW.brand_id
                     )
                BEGIN
                    SELECT RAISE(ABORT, 'That station manager does not manage this brand.');
                END
            SQL);
        }
    }

    public function down(): void
    {
        $this->dropTriggers();

        if ($this->hasIndex('stations', 'stations_manager_id_unique')) {
            Schema::table('stations', fn (Blueprint $t) => $t->dropIndex('stations_manager_id_unique'));
        }

        // A rebuild rather than dropColumn: SQLite refuses to drop a column an inline
        // foreign key still mentions ("unknown column brand_id in foreign key
        // definition"), so the FK and the columns have to go together.
        //
        // The role default returns to 'admin', which is what it was before
        // 2026_10_05_000520 changed it to 'station_manager'.
        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement(<<<'SQL'
            CREATE TABLE users_old (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                username VARCHAR(60) NOT NULL,
                password_hash VARCHAR NOT NULL,
                display_name VARCHAR NULL,
                role VARCHAR NOT NULL DEFAULT 'admin' CHECK (role IN ('admin')),
                created_at DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP),
                last_login_at DATETIME NULL
            )
        SQL);

        DB::statement(
            'INSERT INTO users_old (id, username, password_hash, display_name, role, created_at, last_login_at)'
            .' SELECT id, username, password_hash, display_name, role, created_at, last_login_at FROM users'
        );

        DB::statement('DROP TABLE users');
        DB::statement('ALTER TABLE users_old RENAME TO users');
        DB::statement('CREATE UNIQUE INDEX users_username_unique ON users (username)');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Rebuilds `users` with the two new columns.
     *
     * Written by hand rather than through Schema::table() on purpose.
     *
     * SQLite cannot ALTER TABLE ADD CONSTRAINT, so adding the brand_id FOREIGN
     * KEY makes Laravel rebuild the table from its own schema definition. That
     * rebuild SILENTLY DROPS the CHECK constraint on `role` - the one thing
     * stopping an arbitrary role string from being inserted - and leaves `role`
     * with the default the previous migration set, 'station_manager'. Both are
     * privilege problems: a user inserted without a role would become a station
     * manager, and the constraint the tests assert would quietly no longer
     * exist. This was caught by
     * AuthenticationTest::test_schema_only_permits_the_admin_role failing after
     * the first attempt at this migration.
     *
     * So the table is rebuilt here from explicit DDL that states the CHECK, the
     * correct default, and both new columns. Deliberate and complete beats
     * incidental.
     */
    private function rebuildUsersTable(): void
    {
        $hasEmail = Schema::hasColumn('users', 'email');
        $hasBrand = Schema::hasColumn('users', 'brand_id');

        DB::statement('PRAGMA foreign_keys = OFF');

        DB::statement(<<<'SQL'
            CREATE TABLE users_new (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                username VARCHAR(60) NOT NULL,
                email VARCHAR(160) NULL,
                brand_id INTEGER NULL,
                password_hash VARCHAR NOT NULL,
                display_name VARCHAR NULL,
                -- CHECK kept deliberately: it is the database's own guarantee
                -- that only the two known roles exist. Do not drop it.
                role VARCHAR NOT NULL DEFAULT 'admin' CHECK (role IN ('admin', 'station_manager')),
                created_at DATETIME NOT NULL DEFAULT (CURRENT_TIMESTAMP),
                last_login_at DATETIME NULL,
                FOREIGN KEY (brand_id) REFERENCES brands (id) ON DELETE SET NULL
            )
        SQL);

        DB::statement(
            'INSERT INTO users_new (id, username, email, brand_id, password_hash, display_name, role, created_at, last_login_at)'
            .' SELECT id, username, '.($hasEmail ? 'email' : 'NULL').', '.($hasBrand ? 'brand_id' : 'NULL').', password_hash,'
            .' display_name, role, created_at, last_login_at FROM users'
        );

        DB::statement('DROP TABLE users');
        DB::statement('ALTER TABLE users_new RENAME TO users');

        DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email)');
        DB::statement('CREATE UNIQUE INDEX users_username_unique ON users (username)');

        DB::statement('PRAGMA foreign_keys = ON');
    }

    /**
     * Whether a named index already exists on a table.
     *
     * Used instead of an exception check so the migration is safe to re-run
     * after a partial failure on SQLite. Also accepts the SQLite auto-index names
     * for UNIQUE constraints declared inline.
     */
    private function hasIndex(string $table, string $index): bool
    {
        foreach (DB::select("PRAGMA index_list('{$table}')") as $row) {
            $name = is_array($row) ? ($row['name'] ?? '') : ($row->name ?? '');

            if ($name === $index) {
                return true;
            }
        }

        return false;
    }

    /** Distinct name per event, since SQLite keeps one trigger per name. */
    private function triggerName(string $event): string
    {
        return self::TRIGGER.'_'.strtolower(explode(' ', $event)[0]);
    }

    private function dropTriggers(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS '.self::TRIGGER.'_insert');
        DB::statement('DROP TRIGGER IF EXISTS '.self::TRIGGER.'_update');
    }
};

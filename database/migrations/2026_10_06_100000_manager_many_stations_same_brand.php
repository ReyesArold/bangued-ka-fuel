<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One manager may handle several stations, all of the same brand.
 *
 * 2026_10_06_000000 put a UNIQUE index on stations.manager_id, making the
 * relationship one-to-one. That is dropped here, and the assignment is
 * constrained by BRAND instead: every station a manager handles must carry the
 * same brand as the others.
 *
 * The users.brand_id column and the stations_manager_brand_matches triggers from
 * that migration are deliberately KEPT. They already express the rule this
 * migration moves to, and they express it in the one place that cannot be
 * bypassed: a manager whose users.brand_id is Blu Gas cannot be paired with a
 * Petron station no matter which code path writes the row.
 *
 * No data is changed. Dropping an index that forbade something cannot orphan
 * anything, and the dedupe that migration performed is not undone - reassigning
 * a demoted station is the administrator's decision, not this migration's.
 */
return new class extends Migration
{
    /** SQLite reports unique constraints as auto-indexes with these names. */
    private const UNIQUE_INDEX = 'stations_manager_id_unique';

    public function up(): void
    {
        if ($this->hasIndex('stations', self::UNIQUE_INDEX)) {
            Schema::table('stations', function (Blueprint $table) {
                $table->dropUnique(['manager_id']);
            });
        }
    }

    public function down(): void
    {
        // Restoring the index can fail if a manager now holds more than one
        // station, which is the point of the change. Deliberately not guarded:
        // a rollback that quietly skips would leave the schema in a state that
        // matches neither version.
        Schema::table('stations', function (Blueprint $table) {
            $table->unique('manager_id');
        });
    }

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
};

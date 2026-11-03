<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'station_manager'])->default('station_manager')->change();
        });

        Schema::table('stations', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('brand_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stations', function (Blueprint $table) {
            $table->dropForeign(['manager_id']);
            $table->dropColumn('manager_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin'])->default('admin')->change();
        });
    }
};

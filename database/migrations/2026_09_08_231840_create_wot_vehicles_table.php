<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Local copy of Wargaming's vehicle encyclopedia. Cached in a table
        // rather than the cache store because it is ~700 rows that change only
        // on game patches, and every per-tank stat row has to be joined against
        // it to become readable — a cache miss mid-render would otherwise mean
        // re-fetching the entire encyclopedia. Refreshed by `wot:sync-vehicles`.
        Schema::create('wot_vehicles', function (Blueprint $table) {
            // Wargaming's tank_id is the natural key here: it's stable, it's
            // what tanks/stats joins on, and there is no local identity to
            // preserve because rows are wholly owned by the upstream API.
            $table->unsignedBigInteger('tank_id')->primary();

            $table->string('name');
            $table->string('short_name')->nullable();
            $table->unsignedTinyInteger('tier');
            $table->string('nation');
            $table->string('type');
            $table->boolean('is_premium')->default(false);
            $table->string('image_url')->nullable();

            $table->timestamps();

            // The garage table is filtered and sorted on these three constantly,
            // and they're the columns a "tier 10 heavies" style query hits.
            $table->index(['tier', 'nation', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_vehicles');
    }
};

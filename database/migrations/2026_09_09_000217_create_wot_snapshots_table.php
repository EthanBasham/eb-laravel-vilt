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
        // Account-level lifetime totals, captured periodically.
        //
        // This table exists because the Wargaming API only ever returns
        // *lifetime* figures — there is no endpoint for "what did I do last
        // week". Every period statistic is a difference between two rows here,
        // which means the history has to be accumulated going forward and
        // cannot be backfilled. A 30-day column is meaningful 30 days after the
        // first capture, not before.
        Schema::create('wot_snapshots', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->constrained()->cascadeOnDelete();

            $table->timestamp('captured_at');

            // Promoted out of the JSON because both period lookups filter on
            // them: by date for 7d/30d/60d, and by battle count for the
            // "last 1000 battles" window.
            $table->unsignedInteger('battles');

            // The whole statistics.all block, ~39 fields. Kept as JSON rather
            // than 39 columns: the shape is Wargaming's to change, nothing
            // queries individual metrics (deltas are computed in PHP over two
            // rows), and a new field upstream shouldn't need a migration.
            $table->json('statistics');

            $table->timestamps();

            // Every read is "this account's snapshots, newest first" or "…at or
            // before a cutoff", both of which this serves. Also the FK index
            // Postgres won't create on its own.
            $table->index(['wot_account_id', 'captured_at']);
            $table->index(['wot_account_id', 'battles']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_snapshots');
    }
};

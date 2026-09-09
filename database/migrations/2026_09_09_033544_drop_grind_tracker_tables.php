<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the grind tracker.
 *
 * The feature was built and didn't fit how this player actually plays, so it is
 * stripped rather than left switched off. The create migrations stay in history
 * — they have already run in production — and this drops what they made.
 *
 * The three wot_vehicles columns go too: `next_tanks`, `modules_tree` and
 * `is_gift` existed only to resolve grind targets, nothing else ever read them,
 * and modules_tree alone is several megabytes of JSON across ~1,000 rows plus
 * the payload cost on every encyclopedia sync.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('wot_grinds');

        Schema::table('wot_vehicles', function (Blueprint $table) {
            $table->dropColumn(['next_tanks', 'modules_tree', 'is_gift']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * Restores the columns but not their contents — `wot:sync-vehicles` would
     * need its field list widened again to refill them. The grinds table is not
     * recreated here; its own migration does that if this is ever rolled back
     * far enough.
     */
    public function down(): void
    {
        Schema::table('wot_vehicles', function (Blueprint $table) {
            $table->json('next_tanks')->nullable();
            $table->json('modules_tree')->nullable();
            $table->boolean('is_gift')->default(false);
        });
    }
};

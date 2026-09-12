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
        // Which events a user has told the calendar to stop showing.
        //
        // Per user for the same reason pins are: events are extracted from
        // shared articles and owned by nobody, so one person hiding a campaign
        // they don't play must not hide it for everybody. A column on
        // wot_events would do exactly that — and would be wiped on the next
        // re-parse, which rewrites those rows.
        Schema::create('wot_event_ignores', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wot_event_id')->constrained()->cascadeOnDelete();

            $table->timestamp('ignored_at');

            $table->timestamps();

            // Ignoring twice is idempotent, not a second row.
            $table->unique(['user_id', 'wot_event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_event_ignores');
    }
};

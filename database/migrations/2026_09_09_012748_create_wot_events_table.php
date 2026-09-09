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
        // Dated events extracted from article bodies.
        //
        // Two extraction tiers produce rows here, and `source` records which,
        // because they carry very different confidence:
        //
        //   'calendar' — an <article data-date> inside the site's own
        //                event-calendar component. Exact start and end times,
        //                a title, and reward metadata. Rare but excellent.
        //   'window'   — a pair of data-timestamp attributes giving one overall
        //                start/end for the whole article. Common, coarse.
        //
        // Nothing is inferred from prose. Date phrasing in article text varies
        // constantly, and a calendar that is silently wrong is worse than one
        // that is merely sparse.
        Schema::create('wot_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_article_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            // The site's own classification from data-accent ('stream', etc.),
            // used to colour the calendar. Null for window events, which have
            // no such marker.
            $table->string('event_type')->nullable();

            $table->string('source', 16);

            // Whatever else the day carried — token counts, reward vehicles.
            // JSON because it is presentational detail that varies per campaign
            // and nothing queries it.
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Re-parsing an article must update its events, not duplicate them.
            // Title is part of the key because a single day can legitimately
            // hold two differently-named sessions.
            $table->unique(['wot_article_id', 'starts_at', 'title'], 'wot_events_unique_occurrence');

            // The calendar query: everything in a date range.
            $table->index('starts_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_events');
    }
};

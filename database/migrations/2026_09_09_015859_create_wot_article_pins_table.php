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
        // Which articles a user has pinned to the top of their feed.
        //
        // Per user rather than a flag on wot_articles: articles are shared rows
        // synced from Wargaming's feed and owned by nobody, so one person
        // pinning one must not rearrange anybody else's news. A boolean column
        // on the article would do exactly that.
        Schema::create('wot_article_pins', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wot_article_id')->constrained()->cascadeOnDelete();

            // When this row was pinned. Not used for ordering pinned articles
            // against each other — see WotArticle::scopePinnedFirstFor() — but
            // still read for "pinned" state and to refresh on re-pin.
            $table->timestamp('pinned_at');

            $table->timestamps();

            // Pinning twice is idempotent, not a second row.
            $table->unique(['user_id', 'wot_article_id']);

            // The listing joins on user_id; pinned_at itself is no longer part
            // of the sort, but the composite index still serves the join.
            $table->index(['user_id', 'pinned_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_article_pins');
    }
};

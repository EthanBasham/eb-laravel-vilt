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
        // Which articles a user has seen.
        //
        // Per user for the same reason pins are: articles are shared rows
        // synced from Wargaming's feed, so one person reading something must
        // not mark it read for everybody.
        //
        // Presence is the whole meaning — a row exists iff the article has been
        // seen. There is deliberately no "unseen" row, so an article that
        // arrives in the feed is new to every user without anything being
        // written for them.
        Schema::create('wot_article_views', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wot_article_id')->constrained()->cascadeOnDelete();

            $table->timestamp('seen_at');

            $table->timestamps();

            // Seeing something twice is not a second row, and this index is
            // what the per-article lookup rides on.
            $table->unique(['user_id', 'wot_article_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_article_views');
    }
};

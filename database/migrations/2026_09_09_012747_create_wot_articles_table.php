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
        // Articles from worldoftanks.com's official RSS feeds.
        //
        // The feed is a published interface meant to be consumed, unlike the
        // news index HTML — so the list never breaks when the site is
        // redesigned. Article *bodies* are only fetched when an article might
        // contain event dates; see body_fetched_at.
        Schema::create('wot_articles', function (Blueprint $table) {
            $table->id();

            // The feed's own identifier. Unique so a re-sync updates rather
            // than duplicates, and preferred over the URL because a guid is
            // guaranteed stable by the RSS spec while a URL can be rewritten.
            $table->string('guid')->unique();

            $table->string('url', 1024);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->timestamp('published_at');

            // When the article HTML was last fetched for event extraction, and
            // a hash of what came back. Together they mean an unchanged article
            // is never re-parsed and never re-requested unnecessarily — this is
            // someone else's server.
            $table->timestamp('body_fetched_at')->nullable();
            $table->string('body_hash', 64)->nullable();

            $table->timestamps();

            // The listing is always "newest first".
            $table->index('published_at');
            $table->index(['category', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_articles');
    }
};

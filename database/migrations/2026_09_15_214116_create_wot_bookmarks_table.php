<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The links in the strip under the World of Tanks header, per user.
 *
 * Keyed on the user rather than on the linked Wargaming account, which is what
 * every other table here hangs off. The bar renders on the Connect screen too —
 * before there is an account to hang anything off — and none of what it holds
 * is about a player's game data, so the user is the right owner.
 *
 * `users.wot_bookmarks_seeded_at` is what separates "has never been given the
 * defaults" from "has deliberately emptied the bar". Without it an empty list
 * is indistinguishable from a new account, and deleting the last bookmark would
 * hand all ten defaults back on the next page load.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wot_bookmarks', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('label');
            $table->string('url', 2048);

            // The hover tooltip — what the site is, in a line. Optional: a
            // bookmark someone adds themselves usually needs no explaining.
            $table->string('title')->nullable();

            /*
             * Position in the bar, written from the order the editor posts. It
             * is not unique per user: the whole list is rewritten in one go, so
             * there is never a moment where two rows are fighting over a slot,
             * and a unique index would only make the rewrite harder.
             */
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'position']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('wot_bookmarks_seeded_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wot_bookmarks_seeded_at');
        });

        Schema::dropIfExists('wot_bookmarks');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One Wargaming account may now be linked to more than one user.
 *
 * The guest account (`guest:refresh`) shows the owner's real Wargaming account
 * through a link of its own, without the access token, so the dashboard reads
 * the account's public statistics live and none of its private ones. A user is
 * still linked to at most one account: `user_id` stays unique.
 *
 * Rolling back fails while two users share an account; delete the guest's
 * link first.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('wot_accounts', function (Blueprint $table) {
            $table->dropUnique(['account_id']);
            $table->index('account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wot_accounts', function (Blueprint $table) {
            $table->dropIndex(['account_id']);
            $table->unique('account_id');
        });
    }
};

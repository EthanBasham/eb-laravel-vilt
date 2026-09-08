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
        Schema::create('wot_accounts', function (Blueprint $table) {
            $table->id();

            // One Wargaming account per local user. Unique rather than a plain
            // index so a second link can't be created silently.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Wargaming's own account id. Not the primary key: it's assigned by
            // them, and keeping our own id means the link row survives being
            // re-pointed at a different account. Unsigned big int because WG
            // ids are large and always positive.
            $table->unsignedBigInteger('account_id')->unique();

            $table->string('nickname');

            // Issued by the OpenID flow and valid for two weeks. Encrypted at
            // rest: it grants access to this player's private account data, so
            // it is a credential, not an identifier.
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();

            // When stats were last pulled, so the UI can say how fresh it is.
            $table->timestamp('last_synced_at')->nullable();

            $table->timestamps();

            // Drives the "which links need their token renewing" query.
            $table->index('access_token_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_accounts');
    }
};

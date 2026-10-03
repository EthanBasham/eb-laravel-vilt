<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * State and local income tax, per profile.
 *
 * The brackets are stored on the profile rather than read from config the way
 * the federal ones are. Federal tax is one table for everyone; state and local
 * tax is fifty-odd tables plus every city and county that levies its own, and
 * the app only knows a couple of them. So config holds presets that fill the
 * form in, and what is saved here is whatever the user left in it — theirs to
 * correct when a preset is stale or their state is not one of the presets.
 *
 * Each `*_brackets` column is a list of `{rate, up_to}`: a percentage, and the
 * taxable income it runs up to, with null on the open top bracket. Null for
 * the whole column means no tax of that kind is modelled.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fin_profiles', function (Blueprint $table) {
            // A preset's key ("TN", "NY") or "other". Only a label and a
            // record of which preset was used — the figures are the columns
            // below, not a lookup on this.
            $table->string('state', 20)->nullable();
            $table->decimal('state_deduction', 15, 2)->default(0);
            $table->json('state_brackets')->nullable();

            $table->string('local_name', 60)->nullable();
            $table->decimal('local_deduction', 15, 2)->default(0);
            $table->json('local_brackets')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fin_profiles', function (Blueprint $table) {
            $table->dropColumn(['state', 'state_deduction', 'state_brackets', 'local_name', 'local_deduction', 'local_brackets']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The named tankers handed out by Battle Pass seasons.
 *
 * Unlike every other crew fact here these are people rather than counts: they
 * arrive one at a time, with a name and a season attached, and the question
 * worth answering is where each of them ended up. So this is a list of rows
 * entered by hand, not a tally.
 *
 * Nothing is seeded. The roster is not something the API publishes, and
 * inventing one would put figures on the page that no one recorded.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wot_battle_pass_crew', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            /*
             * Assumed rather than declared: a Battle Pass tanker can be trained
             * into any nation's vehicle, and what this records is the nation
             * the character reads as. Nullable for one there is no guess for.
             */
            $table->string('nation')->nullable();

            $table->unsignedSmallInteger('season')->nullable();

            $table->string('gender')->nullable();

            // 'uncollected', 'in_barracks' or 'in_tank' — see
            // config('wargaming.crew_statuses').
            $table->string('status')->default('uncollected');

            /*
             * Where they are serving, and as what. Both only mean anything
             * under 'in_tank', and both are cleared when the status moves off
             * it — a crew member in the barracks who still named a vehicle
             * would show up as that tank's crew on a page that reads this
             * later.
             */
            $table->unsignedBigInteger('tank_id')->nullable();
            $table->string('crew_role')->nullable();

            $table->timestamps();

            $table->index(['wot_account_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_battle_pass_crew');
    }
};

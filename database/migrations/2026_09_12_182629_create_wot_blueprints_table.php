<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blueprints held, before they become fragments of any particular vehicle.
 *
 * Distinct from `wot_tank_purchases.blueprint_fragments`, which counts fragments
 * already built towards one tank. These are the raw material: national
 * blueprints, tied to a nation, and universal ones that spend anywhere, which
 * are combined to build those fragments. So they are held per nation rather
 * than per vehicle.
 *
 * None of it comes from the API — the public endpoints publish no blueprint
 * inventory — so every count is typed in by hand. A missing row is a zero.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wot_blueprints', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->constrained()->cascadeOnDelete();

            /*
             * A nation slug, or the literal 'universal'. A sentinel rather than
             * a null for the same reason wot_crew_books uses one: Postgres
             * treats nulls as distinct in a unique index, so a nullable column
             * would let a second universal row through the index below.
             */
            $table->string('nation');

            $table->unsignedInteger('quantity')->default(0);

            $table->timestamps();

            $table->unique(['wot_account_id', 'nation']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_blueprints');
    }
};

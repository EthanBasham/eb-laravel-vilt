<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two stockpiles behind a crew: who is waiting in the barracks, and what
 * there is to train them with.
 *
 * Both are counts held against a key from config rather than columns per kind,
 * so a new book or a new sort of recruit is a line in `config/wargaming.php`
 * and not a migration. The cost is that nothing in the schema constrains the
 * key — the form requests do, against the same config the page renders from.
 *
 * A missing row is a zero. Rows are written on first edit, so an untouched
 * account holds none at all.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wot_crew_recruits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->constrained()->cascadeOnDelete();

            // A key from config('wargaming.crew_recruits') — including the six
            // boosted tiers, which are separate kinds rather than one kind with
            // a level, because that is how they are counted in the barracks.
            $table->string('recruit_key');

            $table->unsignedInteger('quantity')->default(0);

            $table->timestamps();

            $table->unique(['wot_account_id', 'recruit_key']);
        });

        Schema::create('wot_crew_books', function (Blueprint $table) {
            $table->id();

            $table->foreignId('wot_account_id')->constrained()->cascadeOnDelete();

            // A key from config('wargaming.crew_books') or, for the two items
            // that are not books, config('wargaming.crew_book_specials').
            $table->string('book_type');

            /*
             * A nation slug, or the literal 'universal' for a stack that spends
             * anywhere. A sentinel rather than a null: the unique index below
             * is what stops a second universal row, and Postgres treats nulls
             * as distinct from each other in a unique index, so a nullable
             * column would let duplicates through on the one database that
             * matters here.
             */
            $table->string('nation');

            $table->unsignedInteger('quantity')->default(0);

            $table->timestamps();

            $table->unique(['wot_account_id', 'book_type', 'nation']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wot_crew_books');
        Schema::dropIfExists('wot_crew_recruits');
    }
};

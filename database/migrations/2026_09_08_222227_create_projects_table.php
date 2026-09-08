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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();

            // Nullable so a project outlives the account that created it —
            // deleting a user shouldn't cascade away the learning log.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->string('summary', 500);
            $table->text('description')->nullable();

            // Comma-separated technologies ("Vue, Inertia, Tailwind"). A join
            // table would be the right call once these need filtering or
            // counting; for a display-only label it isn't worth one yet.
            $table->string('stack')->nullable();

            $table->string('repo_url')->nullable();
            $table->string('demo_url')->nullable();

            $table->boolean('is_featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            // Null means draft. A future date means scheduled — see
            // Project::scopeOnlyPublished().
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            // Postgres does not index foreign key columns automatically the way
            // InnoDB does, so every FK here gets an explicit index.
            $table->index('user_id');

            // Covers Project::onlyPublished()->inDefaultOrder(), the query behind
            // both the listing and the home page.
            $table->index(['sort_order', 'published_at']);

            // The homepage filters on both, and is_featured alone is too
            // low-cardinality to be worth its own index.
            $table->index(['is_featured', 'published_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};

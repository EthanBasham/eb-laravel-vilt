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
        Schema::create('milestones', function (Blueprint $table) {
            $table->id();

            // Milestones have no meaning apart from their project, so they go
            // with it.
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('notes')->nullable();
            $table->unsignedInteger('sort_order')->default(0);

            // Null means not yet done. A timestamp rather than a boolean so the
            // learning log can show *when* something was finished.
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            // Milestones are only ever read one project at a time, in sort
            // order. This also serves as the project_id index Postgres won't
            // create for the foreign key on its own, since project_id is the
            // leftmost column.
            $table->index(['project_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestones');
    }
};

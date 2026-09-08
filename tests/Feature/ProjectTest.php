<?php

use App\Models\Milestone;
use App\Models\Project;

it('resolves a project by its slug', function () {
    $project = Project::factory()->create(['slug' => 'inertia-fundamentals']);

    $this->get(route('projects.show', $project))
        ->assertOk()
        ->assertSee($project->title);
});

it('hides unpublished projects behind a 404', function (string $state) {
    $project = Project::factory()->{$state}()->create();

    $this->get(route('projects.show', $project))->assertNotFound();
})->with(['draft', 'scheduled']);

it('excludes drafts and future-dated projects from the published scope', function () {
    Project::factory()->create();
    Project::factory()->draft()->create();
    Project::factory()->scheduled()->create();

    expect(Project::onlyPublished()->count())->toBe(1);
});

it('derives is_published from published_at', function () {
    expect(Project::factory()->make()->is_published)->toBeTrue()
        ->and(Project::factory()->draft()->make()->is_published)->toBeFalse()
        ->and(Project::factory()->scheduled()->make()->is_published)->toBeFalse();
});

it('splits the stack column into trimmed items', function () {
    $project = Project::factory()->make(['stack' => 'Vue,  Inertia , Laravel, ']);

    expect($project->stack_items)->toBe(['Vue', 'Inertia', 'Laravel']);
});

it('orders milestones by sort_order', function () {
    $project = Project::factory()->create();
    Milestone::factory()->for($project)->create(['title' => 'Second', 'sort_order' => 2]);
    Milestone::factory()->for($project)->create(['title' => 'First', 'sort_order' => 1]);

    expect($project->milestones->pluck('title')->all())->toBe(['First', 'Second']);
});

it('reports milestone progress', function () {
    $project = Project::factory()->create();

    expect($project->progressLabel())->toBe('No milestones yet');

    Milestone::factory()->for($project)->count(2)->create();
    Milestone::factory()->for($project)->complete()->create();

    expect($project->load('milestones')->progressLabel())->toBe('1 of 3 complete');
});

it('deletes a project\'s milestones along with it', function () {
    $project = Project::factory()->create();
    Milestone::factory()->for($project)->count(3)->create();

    $project->delete();

    expect(Milestone::count())->toBe(0);
});

it('keeps projects when their owner is deleted', function () {
    $project = Project::factory()->create();

    $project->user->delete();

    expect($project->fresh())->not->toBeNull()
        ->and($project->fresh()->user_id)->toBeNull();
});

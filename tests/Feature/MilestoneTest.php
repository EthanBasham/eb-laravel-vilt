<?php

use App\Models\Milestone;
use App\Models\Project;
use App\Models\User;

it('requires authentication to toggle a milestone', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->for($project)->create();

    $this->patch(route('projects.milestones.update', [$project, $milestone]), ['is_complete' => 1])
        ->assertRedirect(route('login'));

    expect($milestone->fresh()->is_complete)->toBeFalse();
});

it('marks a milestone complete over AJAX and returns the new progress', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->for($project)->create();
    Milestone::factory()->for($project)->create();

    $this->actingAs(User::factory()->create())
        ->patchJson(route('projects.milestones.update', [$project, $milestone]), ['is_complete' => 1])
        ->assertOk()
        ->assertJson(['is_complete' => true, 'progress_label' => '1 of 2 complete']);

    expect($milestone->fresh()->is_complete)->toBeTrue();
});

it('redirects back when the toggle is posted as a plain form', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->for($project)->complete()->create();

    $this->actingAs(User::factory()->create())
        ->from(route('projects.show', $project))
        ->patch(route('projects.milestones.update', [$project, $milestone]), ['is_complete' => 0])
        ->assertRedirect(route('projects.show', $project));

    expect($milestone->fresh()->is_complete)->toBeFalse();
});

it('rejects a milestone that belongs to a different project', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->for(Project::factory())->create();

    $this->actingAs(User::factory()->create())
        ->patchJson(route('projects.milestones.update', [$project, $milestone]), ['is_complete' => 1])
        ->assertNotFound();
});

it('validates that is_complete is present and boolean', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->for($project)->create();

    $this->actingAs(User::factory()->create())
        ->patchJson(route('projects.milestones.update', [$project, $milestone]), ['is_complete' => 'maybe'])
        ->assertJsonValidationErrorFor('is_complete');
});

it('keeps the original completion timestamp when re-completing', function () {
    $milestone = Milestone::factory()->complete()->create();
    $completedAt = $milestone->completed_at;

    $this->travel(1)->days();
    $milestone->markComplete(true);

    expect($milestone->fresh()->completed_at->timestamp)->toBe($completedAt->timestamp);
});

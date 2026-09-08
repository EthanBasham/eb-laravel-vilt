<?php

use App\Models\Project;

it('renders the home page', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('A workshop for the VILT stack.');
});

it('shows only published, featured projects on the home page', function () {
    $featured = Project::factory()->featured()->create(['title' => 'Featured And Live']);
    Project::factory()->create(['title' => 'Published But Not Featured']);
    Project::factory()->featured()->draft()->create(['title' => 'Featured But Draft']);
    Project::factory()->featured()->scheduled()->create(['title' => 'Featured But Scheduled']);

    $this->get(route('home'))
        ->assertOk()
        ->assertSee($featured->title)
        ->assertDontSee('Published But Not Featured')
        ->assertDontSee('Featured But Draft')
        ->assertDontSee('Featured But Scheduled');
});

it('lists published projects on the index', function () {
    $published = Project::factory()->create(['title' => 'A Published Project']);
    Project::factory()->draft()->create(['title' => 'A Draft Project']);

    $this->get(route('projects.index'))
        ->assertOk()
        ->assertSee($published->title)
        ->assertDontSee('A Draft Project');
});

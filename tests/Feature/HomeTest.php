<?php

use App\Models\User;

it('renders the home page', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('A workshop for the VILT stack.')
        ->assertSee('Pipeline check');
});

it('offers registration to guests and the dashboard to signed-in users', function () {
    $this->get(route('home'))->assertSee('Create an account');

    $this->actingAs(User::factory()->create())
        ->get(route('home'))
        ->assertSee('World of Tanks dashboard')
        ->assertDontSee('Create an account');
});

it('protects the dashboard and profile behind auth', function (string $route) {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['dashboard', 'profile.edit']);

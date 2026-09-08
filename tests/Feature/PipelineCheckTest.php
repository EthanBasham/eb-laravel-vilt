<?php

/**
 * The pipeline check is the scaffold's one server round-trip, and it exists to
 * prove the front-end stack is connected. These cover both of its paths, since
 * the whole point is that the JS one is an enhancement rather than a
 * requirement.
 */
it('answers JSON to an AJAX request', function () {
    $this->postJson(route('pipeline-check.store'), ['message' => 'Hello from Blade'])
        ->assertOk()
        ->assertJson([
            'received' => 'Hello from Blade',
            'reversed' => 'edalB morf olleH',
        ])
        ->assertJsonStructure(['received', 'reversed', 'handled_by', 'at']);
});

it('redirects back with the result when posted as a plain form', function () {
    $this->from(route('home'))
        ->post(route('pipeline-check.store'), ['message' => 'no javascript here'])
        ->assertRedirect(route('home'))
        ->assertSessionHas('pipeline_check.reversed', 'ereh tpircsavaj on');
});

it('renders the result on the page after a non-AJAX post', function () {
    $this->followingRedirects()
        ->from(route('home'))
        ->post(route('pipeline-check.store'), ['message' => 'abc'])
        ->assertOk()
        ->assertSee('cba');
});

it('rejects a missing or oversized message', function (mixed $message) {
    $this->postJson(route('pipeline-check.store'), ['message' => $message])
        ->assertJsonValidationErrorFor('message');
})->with([
    'missing' => [''],
    'too long' => [fn () => str_repeat('a', 101)],
]);

it('requires no authentication', function () {
    $this->assertGuest();

    $this->postJson(route('pipeline-check.store'), ['message' => 'hi'])->assertOk();
});

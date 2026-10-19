<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Guest account
    |--------------------------------------------------------------------------
    |
    | A shared sign-in, so visitors can try WOT Hub and Financial Fleet without
    | an account of their own. `guest:refresh` builds it from the source user
    | and rebuilds it nightly, which also undoes whatever visitors changed —
    | password included — so nobody can lock it for longer than a day.
    |
    | `username` goes in the `email` column; the login form takes a plain
    | username as well as an address. The password lives in the environment,
    | not here: it is handed out, but it is still a credential.
    |
    */

    'username' => 'guest',

    'name' => 'Guest',

    'password' => env('GUEST_PASSWORD'),

    // Whose data the guest is a copy of. Id 1 is the owner on every
    // environment this app runs on.
    'source_user_id' => (int) env('GUEST_SOURCE_USER_ID', 1),

    /*
    |--------------------------------------------------------------------------
    | Financial Fleet
    |--------------------------------------------------------------------------
    |
    | The guest's fleet is the owner's with every amount multiplied by
    | `scale`, and every name replaced by a generic one (the holding type, the
    | flow category, "Scenario 2"), with institutions, notes and descriptions
    | removed. Rates, ages and dates are copied as they are.
    |
    */

    'finance' => [
        'scale' => 1.5,
    ],

];

<?php

use App\Models\Model;
use App\Models\User;

arch('models extend the app base model')
    ->expect('App\Models')
    ->classes()
    ->toExtend(Model::class)
    ->ignoring([Model::class, User::class]);

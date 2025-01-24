<?php

use Brickhouse\Http\Router;

Router::root(fn() => json(['api_version' => '1.0.0']));

Router::get('greeting', \App\Controllers\GreetingController::class);

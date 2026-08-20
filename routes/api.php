<?php

use Illuminate\Support\Facades\Route;

Route::get('/v1/ping', fn () => ['status' => 'ok']);

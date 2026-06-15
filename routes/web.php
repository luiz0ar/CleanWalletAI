<?php

use Illuminate\Support\Facades\Route;

Route::get('/ping', function () {
    return response('pong', 200);
});

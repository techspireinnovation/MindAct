<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/phpinfo', function () {
    ob_start();
    phpinfo();
    return response(ob_get_clean());
});

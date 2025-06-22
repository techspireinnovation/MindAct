<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/phpinfo', function () {
    $tenant = Tenant::create([
        'id' => 'company1234',
        'data' => ['name' => 'Company 1234']
    ]);

    ob_start();
    phpinfo();
    return response(ob_get_clean());
});

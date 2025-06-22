<?php

use App\Models\Tenant;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/phpinfo', function () {

    $tenant = Tenant::create([
        'id' => 'company12345',
        'data' => ['name' => 'Company 12345']
    ]);
    $tenant->domains()->create(['domain' => 'foo.localhost']);

    ob_start();
    phpinfo();
    return response(ob_get_clean());
});

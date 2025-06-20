<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('backup:run', ['--only-db'])->twiceDaily();
Schedule::command('backup:clean')->weekly();
Schedule::command('app:clear-old-file')->daily();
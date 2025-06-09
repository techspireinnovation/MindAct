<?php

namespace App\Traits;

use Carbon\Carbon;
use Pratiksh\Nepalidate\Services\NepaliDate;

trait ConvertsAdToBsDate
{
    protected static function bootConvertsAdToBsDate()
    {
        static::saving(function ($model) {
            $model->invoice_date_bs = NepaliDate::create(Carbon::parse(time: $model->invoice_date))->toBS();
        });
    }
}

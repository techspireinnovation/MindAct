<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkShift extends Model

{

    use SoftDeletes;
    protected $fillable = [
        'title',
        'company_id',
        'time_from',
        'time_to',
        'is_active'


    ];
}

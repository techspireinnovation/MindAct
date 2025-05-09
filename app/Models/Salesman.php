<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Salesman extends Model
{
    protected $fillable = [
        'company_id',
        'email',
        'mobile',
        'salesman_id',
        'pan_number',
        'name',
        'address',
        'working_office',
        'joining_date',
        'designation',
        'dob',
        'citizenship_number',
        'nationality',
        'zone',
        'district',
        'vdc/municipality'
    ];
}

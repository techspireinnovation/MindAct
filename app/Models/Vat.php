<?php

namespace App\Models;

use Illuminate\Database\Eloquent\SoftDeletes;

use Illuminate\Database\Eloquent\Model;

class Vat extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'company_id',
        'vat_percent',
        'status'
    ];
}

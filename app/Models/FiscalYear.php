<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FiscalYear extends BaseTenantModel
{

    use SoftDeletes, HasFactory;

    protected $connection = 'tenant';


    protected $fillable = [
        'year_en',
        'year_np',
        'status'


    ];
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class FiscalYear extends Model
{

    use SoftDeletes, HasFactory;
    

    protected $fillable = [
        'name',
        'name_np',
        'start_date',
        'end_date',
        'is_active',
       
    ];
}

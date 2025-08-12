<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Nozzle extends Model
{
    use SoftDeletes, HasFactory;
    
    protected $fillable = [
        'title',
        'nozzle_number',
        'company_id',
        'fuel_type',
        'is_primary',
        'is_active',
    ];


}

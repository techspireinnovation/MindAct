<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class MeterReading extends Model
{
    use SoftDeletes, HasFactory;
    protected $fillable = [
        'nozzle_id',
        'company_id',
        'opening_reading',
        'sale_litres',
        'closing_reading',
        'due_sale_litre',
        'type_of_fuel',
        'is_active'
    ];
}

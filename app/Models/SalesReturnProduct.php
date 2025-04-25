<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class SalesReturnProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $casts = [
        'is_active' => 'boolean',
    ];
    protected $fillable = [
        'company_id',
        'item_id',
        'information',
        'expiry_date',
        'quantity',
        'expiry_date',
        'measure_unit_id',
        'rate',
        'discount_percent',
        'discount_amount',
        'is_active',

    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }


    
}

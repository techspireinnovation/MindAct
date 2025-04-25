<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;

class SaleAdditional extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'sale_id',
        'place',
        'transport',
        'vehicle_number',
        'vehicle_name',
        'driver_name',
        'dispatch_code',
        'driver_contact_number',
        'delivery_date',
        'delivery_time'
    ];

    protected $dates = ['deleted_at'];


    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}

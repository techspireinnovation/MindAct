<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class StockEntry extends Model
{
    use softDeletes,HasFactory;

    protected $fillable = [
        'product_code',
        'product_name',
        'company_id',
        'product_id',
        'uom',
        'batch_no',
        'expiry_date',
        'quantity',
        'rate',
        'amount',
        'location_id'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }


}

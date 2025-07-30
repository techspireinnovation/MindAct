<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockEntry extends Model
{
    use softDeletes, HasFactory;

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

    protected $dates = ['deleted_at', 'created_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }


    public function product()
    {
        return $this->hasOne(Product::class, 'id', 'product_id');
    }

    public function location()
    {
        return $this->hasOne(Location::class, 'id', 'location_id');
    }


}

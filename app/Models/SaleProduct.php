<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleProduct extends Model
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean'
    ];
    
    protected $fillable = [
        'company_id',
        'sale_id',
        'product_id',
        'purchase_product_id',
        'expiry_date',
        'code',
        'name',
        'batch_no',
        'measure_unit_id',
        'quantity',
        'free_quantity',
        'price',
        'discount_percent',
        'discount_amount',
        'is_vatable',   
    ];
    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product() {
        return $this->belongsTo(Product::class);
    }

    public function fieldValues()
    {
        return $this->hasMany(SalesProductFieldValue::class, 'sale_product_id');
    }
    
    public function saleProductReturns()
    {
        return $this->hasMany(SalesReturnProduct::class, 'sale_product_id');
    }
   
}

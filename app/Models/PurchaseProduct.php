<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Request;

class PurchaseProduct extends Model
{

    protected $fillable = [
        'customer_id',
        'company_id',
        'purchase_id',
        'product_id',
        'product_name',
        'product_code',
        'expiry_date',
        'quantity',
        'deleted_at',
        'free_quantity',
        'price',
        'discount_percent',
        'discount_amount',
        'amount',
        
        'is_vatable',
        'measure_unit_id',
    ];

    use SoftDeletes;
    protected $dates = ['deleted_at'];
    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
        static::creating(function ($model) {
            // Only set if not already set
            if (empty($model->company_id)) {
                // Get the header value, fallback to 'US'
                $headerValue = Request::input('company_id');
                $model->company_id = $headerValue;
            }
        });
    }

    public function fieldValues()
    {
        return $this->hasMany(PurchaseProductFieldValue::class, 'purchase_product_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'purchase_id');
    }

    public function purchaseProductReturns()
    {
        return $this->hasMany(PurchaseProductReturn::class, 'purchase_product_id');
    }

    public function saleProducts(){
        return $this->hasMany(SaleProduct::class, 'purchase_product_id');
    }

   
public function product()
{
    return $this->belongsTo(Product::class, 'product_id');
}
   
}

<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Request;

class PurchaseProductReturn extends Model
{

    protected $fillable = [
        'purchase_return_id',
        'purchase_product_id',
        'purchase_product_code',
        'product_name',
        'customer_id',
        'company_id',
        
        'product_id',
        'quantity',
        'mfd',
        'expiry_date',
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
    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id', 'id');
    }

    public function purchaseProduct()
    {
        return $this->belongsTo(PurchaseProduct::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function measureUnit()
    {
        return $this->belongsTo(MeasureUnit::class);
    }

    public function fieldValues()
    {
        return $this->hasMany(PurchaseReturnProductFieldValue::class, 'purchase_return_product_id');
    }

    public function getPrimaryUnitnameAttribute()
    {
        $primary = ProductList::where(['product_id' => $this->product_id, 'is_primary' => 1])->first();
        if ($primary)
            return MeasureUnit::find($primary->measure_unit_id)->name;
        else
            return null;
    }

    public function getAverageRateAttribute()
    {
        return self::where('product_id', $this->product_id)->avg('price') ?? 0;
    }

    public function getPurchaseReturnAverageRateAttribute()
    {
        return self::where('product_id', $this->product_id)->avg('price') ?? 0;
    }

    public function getPurchaseReturnCustomerAttribute()
    {
        //dd($this);
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id', 'id');
    }

}

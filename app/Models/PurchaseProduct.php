<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Pratiksh\Nepalidate\Services\NepaliDate;
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
        'mfd',
        'discount_percent',
        'discount_amount',
        'amount',

        'is_vatable',
        'measure_unit_id',
    ];

    use SoftDeletes;
    protected $dates = ['deleted_at', 'created_at_bs'];
    protected $appends = ['created_at_bs'];

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
        return $this->belongsTo(Purchase::class, 'purchase_id', 'id');
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
   
    public function getPurchaseQuantityAttribute()
    {
        return self::where('product_id', $this->product_id)->sum('quantity') ?? 0;
    }

    public function getPurchaseRateAttribute()
    {
        return self::where('product_id', $this->product_id)->latest('id')->first()->price ?? 0;
    }

    public function getPurchaseDiscountAmountAttribute()
    {
        return self::where('product_id', $this->product_id)->latest('id')->first()->discount_amount ?? 0;
    }

    public function getPurchaseUnitAttribute()
    {
        $primary = self::where('product_id', $this->product_id)->latest('id')->first();
        if ($primary)
            return MeasureUnit::find($primary->measure_unit_id);
        else
            return null;
    }

    public function getCreatedAtBsAttribute(): string
 {
    if (!$this->created_at) {
        return 'N/A'; 
     }

    return NepaliDate::create($this->created_at)->toBS();
 }

}

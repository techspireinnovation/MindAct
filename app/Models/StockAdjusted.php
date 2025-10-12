<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Helpers\Helper;
use App\Models\Scopes\CompanyIdScope;

use Illuminate\Database\Eloquent\SoftDeletes;
use Pratiksh\Nepalidate\Services\NepaliDate;
use Request;

class StockAdjusted extends Model
{
    protected $fillable = [
        'purchase_stock_product_id',
        'stock_adjustment_id',
        'purchase_type',
        'company_id',
        'branch_id',
        'mfd',
        'purchase_product_id',
        'adjusted_type',
        'stock_product_id',
        'purchase_id',
        'product_id',
        'product_name',
        'product_code',
        'expiry_date',
        'quantity',
        'diff_stock',
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

    public function measureUnit()
    {
        return $this->belongsTo(MeasureUnit::class, 'measure_unit_id');
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

    public function saleProducts()
    {
        return $this->hasMany(SaleProduct::class, 'purchase_product_id');
    }


    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function getPurchaseQuantityAttribute()
    {
        return (Helper::convertToPrimaryUnitQuantity($this->product_id, $this->measure_unit_id ?? 0, $this->quantity));
    }

    public function getPurchaseAverageRateAttribute()
    {
        return self::where('product_id', $this->product_id)->avg('price') ?? 0;

    }

    public function getPurchaseRateAttribute()
    {
        return self::where(['product_id' => $this->product_id, 'purchase_id' => $this->purchase_id])->first()->price ?? 0;
    }

    public function getPurchaseDiscountAmountAttribute()
    {
        return self::where(['product_id' => $this->product_id, 'purchase_id' => $this->purchase_id])->first()->discount_amount ?? 0;
    }

    public function getPurchaseUnitAttribute()
    {
        $primary = ProductList::where(['product_id' => $this->product_id, 'is_primary' => 1])->first();
        if ($primary)
            return MeasureUnit::find($primary->measure_unit_id);
        else
            return null;
    }

    public function getPurchasePrimaryUnitAttribute()
    {
        $primary = ProductList::where(['product_id' => $this->product_id, 'is_primary' => 1])->first();
        if ($primary)
            return MeasureUnit::find($primary->measure_unit_id);
        else
            return null;
    }

    public function getPrimaryUnitnameAttribute()
    {
        $primary = ProductList::where(['product_id' => $this->product_id, 'is_primary' => 1])->first();
        if ($primary)
            return MeasureUnit::find($primary->measure_unit_id)->name;
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

    public function getPurchasedPrimaryUnitQtyAttribute()
    {
        $averagePrice = self::where(['id' => $this->id])->get()->map(function ($purchaseProduct) {

            $primaryEntities = (Helper::convertToPrimaryUnitQuantityRate($purchaseProduct->product_id, $purchaseProduct->measure_unit_id ?? 0, $purchaseProduct->quantity ?? 0, $purchaseProduct->price));

            return [
                'total_price' => $primaryEntities[1],
                'primary_units' => $primaryEntities[0],
            ];

        })->reduce(function ($carry, $item) {
            $carry['total_price'] += $item['total_price'];
            $carry['primary_units'] += $item['primary_units'];
            return $carry;
        }, ['total_price' => 0, 'primary_units' => 0]);
        return $averagePrice['primary_units'];

    }

}

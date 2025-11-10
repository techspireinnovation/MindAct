<?php

namespace App\Models;

use App\Helpers\Helper;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Pratiksh\Nepalidate\Services\NepaliDate;

class SaleProduct extends BaseTenantModel
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'mfd' => 'date'
    ];

    protected $fillable = [
        'company_id',
        'branch_id',
        'sale_id',
        'product_id',
        'purchase_stock_product_id',
        'purchase_product_id',
        'stock_product_id',
        'stock_reconciliation_id',
        'stock_transfer_id',
        'stock_adjustment_id',
        'expiry_date',
        'code',
        'product_name',
        'name',
        'mfd',
        'batch_no',
        'measure_unit_id',
        'amount',
        'quantity',
        'free_quantity',
        'price',
        'discount_percent',
        'discount_amount',
        'is_vatable',
    ];
    protected $dates = ['deleted_at'];
    protected $appends = ['created_at_bs'];
    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function measureUnit()
    {
        return $this->belongsTo(MeasureUnit::class);
    }

    public function fieldValues()
    {
        return $this->hasMany(SalesProductFieldValue::class, 'sale_product_id');
    }

    public function saleProductReturns()
    {
        return $this->hasMany(SalesReturnProduct::class, 'sale_product_id');
    }

    public function saleReturnProducts()
    {
        return $this->hasMany(SalesReturnProduct::class, 'sale_product_id');
    }

    /** The stock line that was sold */
    public function purchaseStockProduct()
    {
        return $this->belongsTo(PurchaseStockProduct::class, 'purchase_stock_product_id');
    }

    public function getSaleQuantityAttribute()
    {
        return (Helper::convertToPrimaryUnitQuantity($this->product_id, $this->measure_unit_id ?? 0, $this->quantity));
    }

    public function getSaleRateAttribute()
    {
        return self::where(['product_id' => $this->product_id, 'sale_id' => $this->sale_id])->first()->price ?? 0;
    }

    public function getSaleDiscountAmountAttribute()
    {
        return self::where(['product_id' => $this->product_id, 'sale_id' => $this->sale_id])->first()->discount_amount ?? 0;
    }

    public function getSaleUnitAttribute()
    {
        $primary = ProductList::where(['product_id' => $this->product_id, 'is_primary' => 1])->first();
        if ($primary)
            return MeasureUnit::find($primary->measure_unit_id);
        else
            return null;
    }

    public function getCreatedAtBsAttribute(): string
    {
        return $this->created_at ? NepaliDate::create($this->created_at)->toBS() : "";
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
    public function getSaleAverageRateAttribute()
    {
        return self::where('product_id', $this->product_id)->avg('price') ?? 0;
    }

    public function getSoldPrimaryUnitQtyAttribute()
    {
        $averagePrice = self::where(['id' => $this->id])->get()->map(function ($item) {
            $primaryEntities = Helper::convertToPrimaryUnitQuantityRate($item->product_id, $item->measure_unit_id ?? 0, $item->quantity ?? 0, $item->price);
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


    public function salesReturnProductsUse()
    {
        return $this->hasMany(SalesReturnProduct::class, 'sale_product_id');
    }
    public function salesProductFieldValuesUse()
    {
        return $this->hasMany(SalesProductFieldValue::class, 'sale_product_id');
    }
    public function saleReturnProductFieldValuesUse()
    {
        return $this->hasMany(SaleReturnProductFieldValue::class, 'sale_product_id');
    }

}
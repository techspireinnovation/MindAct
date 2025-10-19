<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use App\Helpers\Helper;
use App\Models\Scopes\CompanyIdScope;

use Illuminate\Database\Eloquent\SoftDeletes;
use Request;

class PurchaseStockProductReturn extends BaseTenantModel
{
    
    protected $table = 'purchase_stock_product_returns';

    protected $fillable = [
        'purchase_stock_return_id',
        'purchase_stock_product_id',
        'stock_product_id',
        'stock_adjustment_id',
        'stock_reconciliation_id',
        'stock_transfer_id',
        'purchase_product_id',
        'purchase_product_code',
        'product_name',
        'customer_id',
        'company_id',
        'branch_id',
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
    public function purchasestockReturn()
    {
        return $this->belongsTo(PurchaseStockReturn::class, 'purchase_stock_return_id', 'id');
    }

    public function purchaseStockProduct()
    {
        return $this->belongsTo(PurchaseStockProduct::class);
    }

    public function purchaseProduct()
    {
        return $this->belongsTo(PurchaseProduct::class);
    }

     public function stockProduct()
    {
        return $this->belongsTo(StockProductDetails::class);
    }

    public function adjustmentProduct()
    {
        return $this->belongsTo(StockAdjustmentProduct::class);
    }


     public function reconciliationProduct()
    {
        return $this->belongsTo(StockReconciliationDetail::class);
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
        return $this->hasMany(PurchaseStockProductReturnFieldValue::class, 'purchase_stock_product_return_id');
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
        return $this->belongsTo(PurchaseReturn::class, 'purchase_return_id', 'id');
    }

    public function getPurchaseReturnedPrimaryUnitQtyAttribute()
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

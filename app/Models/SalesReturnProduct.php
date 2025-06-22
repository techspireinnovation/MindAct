<?php

namespace App\Models;

use App\Helpers\Helper;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesReturnProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $casts = [
        'is_vatable' => 'boolean',
        'expiry_date' => 'date',
        'mfd' => 'date',
    ];

    protected $dates = ['deleted_at'];

    protected $fillable = [
        'company_id',
        'sales_return_id',
        'sale_product_id',
        'purchase_product_id',
        'product_id',
        'product_code',
        'product_name',
        'expiry_date',
        'mfd',
        'quantity',
        'free_quantity',
        'price',
        'discount_percent',
        'discount_amount',
        'batch_no',
        'is_vatable',
        'measure_unit_id',
    ];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    // Relationships

    public function saleReturn()
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function saleProduct()
    {
        return $this->belongsTo(SaleProduct::class, 'sale_product_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function measureUnit()
    {
        return $this->belongsTo(MeasureUnit::class, 'measure_unit_id');
    }

    public function fieldValues()
    {
        return $this->hasMany(SaleReturnProductFieldValue::class, 'sale_return_product_id');
    }
    public function getAverageRateAttribute()
    {
        return self::where('product_id', $this->product_id)->avg('price') ?? 0;
    }

    public function getSaleReturnAverageRateAttribute()
    {
        return self::where('product_id', $this->product_id)->avg('price') ?? 0;
    }


    public function getSaleReturnedPrimaryUnitQtyAttribute()
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

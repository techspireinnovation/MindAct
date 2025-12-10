<?php

namespace App\Models;


use App\Helpers\Helper;
use App\Models\Brand;
use App\Models\Location;
use App\Models\MeasureUnit;
use App\Models\ProductCategory;

use App\Models\ProductType;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Auth\Request;

class Product extends BaseTenantModel
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',


    ];
    protected $fillable = [
        'name',
        'note',

        'product_code',

        'is_active',

        'deleted_at',

        'category_id',

        'brand_id',

        'measure_unit_id',


        'is_vatable',
        'product_type_id',
        'sku',

    ];

    protected $dates = ['deleted_at'];
    protected $appends = [];




    public function unitConversions()
    {
        return $this->hasMany(MeasureUnitConversion::class, 'product_id');
    }


    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }



    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function measureUnit()
    {
        return $this->belongsTo(MeasureUnit::class, 'measure_unit_id');
    }



    public function productType()
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }







    public function saleProduct()
    {
        return $this->hasMany(SaleProduct::class);
    }



    public function lastPurchase()
    {
        return $this->hasOne(PurchaseProduct::class, 'product_id', 'id')->latestOfMany();
    }



    public function getStockInQuantityAttribute()
    {
        return StockProductDetails::where('product_id', $this->id)->whereRaw('CAST(diff_stock AS SIGNED) > 0')->sum('diff_stock') ?? 0;
    }

    public function getStockOutQuantityAttribute()
    {
        return StockProductDetails::where('product_id', $this->id)->whereRaw('CAST(diff_stock AS SIGNED) < 0')->sum('diff_stock') ?? 0;
    }

    public function getStockOpeningAttribute()
    {
        $averagePrice = StockEntry::where(['product_id' => $this->id])->get()->map(function ($stockEntry) {

            $primaryEntities = (Helper::convertToPrimaryUnitQuantityRate($stockEntry->product_id, $stockEntry->uom ?? 0, $stockEntry->quantity ?? 0, $stockEntry->rate));

            return [
                'total_price' => $primaryEntities[1],
                'primary_units' => $primaryEntities[0],
            ];

        })->reduce(function ($carry, $item) {
            $carry['total_price'] += $item['total_price'];
            $carry['primary_units'] += $item['primary_units'];
            return $carry;
        }, ['total_price' => 0, 'primary_units' => 0]);
        return ['opening_qty' => $averagePrice['primary_units'], 'opening_avg_price' => $averagePrice['primary_units'] > 0 ? $averagePrice['total_price'] / $averagePrice['primary_units'] : 0];

    }

    public function purchaseDetail(array $params)
    {
        $request = (object) $params;
        $averagePrice = PurchaseProduct::where(['product_id' => $this->id])->whereHas('purchase', function ($query) use ($request) {
            $query->when(isset($request->from_date) && isset($request->to_date), function ($query1) use ($request) {
                $query1->whereDate('purchases.invoice_date_bs', '>=', $request->from_date)->whereDate('purchases.invoice_date_bs', '<=', $request->to_date);
            });
        })->get()->map(function ($purchase) {
            $primaryEntities = Helper::convertToPrimaryUnitQuantityRate($purchase->product_id, $purchase->measure_unit_id ?? 0, $purchase->quantity ?? 0, $purchase->price);
            return [
                'total_price' => $primaryEntities[1],
                'primary_units' => $primaryEntities[0],
                'total_amount' => $purchase->amount,
            ];
        })->reduce(function ($carry, $item) {
            $carry['total_price'] += $item['total_price'];
            $carry['primary_units'] += $item['primary_units'];
            $carry['total_amount'] += $item['total_amount'];
            return $carry;
        }, ['total_price' => 0, 'primary_units' => 0, 'total_amount' => 0]);
        return ['qty' => $averagePrice['primary_units'], 'total_price' => round($averagePrice['total_amount'], 2), 'avg_price' => $averagePrice['primary_units'] > 0 ? round($averagePrice['total_price'] / $averagePrice['primary_units'], 2) : 0];
    }

    public function getPurchaseDetailAttribute()
    {
        $request = request();
        return $this->purchaseDetail($request->all());
    }

    public function getSaleDetailAttribute()
    {
        $request = request();
        return $this->saleDetail($request->all());
    }

    public function getPurchaseReturnDetailAttribute()
    {
        $request = request();
        return $this->purchaseReturnDetail($request->all());
    }

    public function purchaseReturnDetail(array $params)
    {
        $request = (object) $params;
        $averagePrice = PurchaseProductReturn::where(['product_id' => $this->id])->whereHas('purchaseReturn', function ($query) use ($request) {
            $query->when(isset($request->from_date) && isset($request->to_date), function ($query1) use ($request) {
                $query1->whereDate('purchase_returns.invoice_date_bs', '>=', $request->from_date)->whereDate('purchase_returns.invoice_date_bs', '<=', $request->to_date);
            });
        })->get()->map(function ($purchase) {
            $primaryEntities = Helper::convertToPrimaryUnitQuantityRate($purchase->product_id, $purchase->measure_unit_id ?? 0, $purchase->quantity ?? 0, $purchase->price);
            return [
                'total_price' => $primaryEntities[1],
                'primary_units' => $primaryEntities[0],
                'total_amount' => $purchase->amount,

            ];
        })->reduce(function ($carry, $item) {
            $carry['total_price'] += $item['total_price'];
            $carry['primary_units'] += $item['primary_units'];
            $carry['total_amount'] += $item['total_amount'];
            return $carry;
        }, ['total_price' => 0, 'primary_units' => 0, 'total_amount' => 0]);
        return ['qty' => $averagePrice['primary_units'], 'total_price' => round($averagePrice['total_amount'], 2), 'avg_price' => $averagePrice['primary_units'] > 0 ? round($averagePrice['total_price'] / $averagePrice['primary_units'], 2) : 0];
    }

    public function getSaleReturnDetailAttribute()
    {
        $request = request();
        return $this->saleReturnDetail($request->all());
    }
    public function saleReturnDetail(array $params)
    {
        $request = (object) $params;
        $averagePrice = SalesReturnProduct::where(['product_id' => $this->id])->whereHas('saleReturn', function ($query) use ($request) {
            $query->when(isset($request->from_date) && isset($request->to_date), function ($query1) use ($request) {
                $query1->whereDate('sales_returns.invoice_date_bs', '>=', $request->from_date)->whereDate('sales_returns.invoice_date_bs', '<=', $request->to_date);
            });
        })->get()->map(function ($purchase) {
            $primaryEntities = Helper::convertToPrimaryUnitQuantityRate($purchase->product_id, $purchase->measure_unit_id ?? 0, $purchase->quantity ?? 0, $purchase->price);
            return [
                'total_price' => $primaryEntities[1],
                'primary_units' => $primaryEntities[0],
                'total_amount' => $purchase->amount,
            ];
        })->reduce(function ($carry, $item) {
            $carry['total_price'] += $item['total_price'];
            $carry['primary_units'] += $item['primary_units'];
            $carry['total_amount'] += $item['total_amount'];
            return $carry;
        }, ['total_price' => 0, 'primary_units' => 0, 'total_amount' => 0]);
        return ['qty' => $averagePrice['primary_units'], 'total_price' => round($averagePrice['total_amount'], 2), 'avg_price' => $averagePrice['primary_units'] > 0 ? round($averagePrice['total_price'] / $averagePrice['primary_units'], 2) : 0];
    }



    public function saleDetail(array $params)
    {
        $request = (object) $params;
        $averagePrice = SaleProduct::where(['product_id' => $this->id])->whereHas('sale', function ($query) use ($request) {
            $query->when(isset($request->from_date) && isset($request->to_date), function ($query1) use ($request) {
                $query1->whereDate('sales.invoice_date_bs', '>=', $request->from_date)->whereDate('sales.invoice_date_bs', '<=', $request->to_date);
            });
        })->get()->map(function ($sale) {

            $primaryEntities = Helper::convertToPrimaryUnitQuantityRate($sale->product_id, $sale->measure_unit_id ?? 0, $sale->quantity ?? 0, $sale->price);

            return [
                'total_price' => $primaryEntities[1],
                'primary_units' => $primaryEntities[0],
                'total_amount' => $sale->amount,
            ];

        })->reduce(function ($carry, $item) {
            $carry['total_price'] += $item['total_price'];
            $carry['primary_units'] += $item['primary_units'];
            $carry['total_amount'] += $item['total_amount'];
            return $carry;
        }, ['total_price' => 0, 'primary_units' => 0, 'total_amount' => 0]);
        return ['qty' => $averagePrice['primary_units'], 'total_price' => round($averagePrice['total_amount'], 2), 'avg_price' => $averagePrice['primary_units'] > 0 ? round($averagePrice['total_price'] / $averagePrice['primary_units'], 2) : 0];
    }






    public function salesProductFieldValueUse()
    {
        return $this->hasMany(SalesProductFieldValue::class, 'product_id');
    }
    public function saleProductsUse()
    {
        return $this->hasMany(SaleProduct::class, 'product_id');
    }
    public function purchaseProductsUse()
    {
        return $this->hasMany(PurchaseProduct::class, 'product_id');
    }

    public function productionSettingsUse()
    {
        return $this->hasMany(ProductionSetting::class, 'product_id');
    }
    public function productFieldValuesUse()
    {
        return $this->hasMany(ProductFieldValue::class, 'product_id');
    }



}

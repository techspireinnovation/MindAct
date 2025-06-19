<?php

namespace App\Models;


use App\Helpers\Helper;
use App\Models\Brand;
use App\Models\Location;
use App\Models\MeasureUnit;
use App\Models\ProductCategory;
use App\Models\ProductList;
use App\Models\ProductType;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Mostafaznv\LaraCache\CacheEntity;
use Mostafaznv\LaraCache\Traits\LaraCache;

class Product extends Model
{
    use SoftDeletes, HasFactory;
    use LaraCache;


    protected $casts = [
        'is_active' => 'boolean',
        'is_fixed_amount' => 'boolean',
        'values' => 'array',
    ];
    protected $fillable = [
        'name',
        'debit_note',
        'credit_note',
        'product_unique_id',
        'is_active',
        'is_fixed_amount',
        'deleted_at',
        'company_id',
        'category_id',
        'sub_category_id',
        'brand_id',
        'measure_unit_id',
        'purchase_rate',
        'purchase_rate_vat',
        'retail_sales_price',
        'retail_sales_price_vat',
        'retail_sales_price_profit_percent',
        'wholesales_price',
        'wholesales_price_vat',
        'wholesales_price_profit_percent',
        'stock_alert',
        'is_vatable',
        'product_type_id',
        'location_id',

    ];

    protected $dates = ['deleted_at'];
    protected $appends = ['primary_measure_unit'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public static function cacheEntities(): array
    {
        $request = app(Request::class);
        $params = $request->query();
        ksort($params); // Ensure consistent order
        $queryString = http_build_query($params);
        $cacheKey = 'productList.' . sha1($queryString);

        return [
            CacheEntity::make($cacheKey)
                ->cache(function () use ($request) {
                    return Product::select("products.id", "is_vatable", "brand_id", "product_type_id", "products.product_unique_id", "sub_category_id", "location_id", "category_id", "products.name")->with([
                        'location' => function ($query) {
                            return $query->select('locations.id', 'name')->get();
                        },
                        'category' => function ($query) {
                            return $query->select('product_categories.id', 'name')->get();
                        },
                        'subCategory' => function ($query) {
                            return $query->select('product_sub_categories.id', 'name')->get();
                        },
                        'brand' => function ($query) {
                            return $query->select('brands.id', 'name')->get();
                        },
                        'productType' => function ($query) {
                            return $query->select('product_types.id', 'name')->get();
                        },
                        'primaryProductItem'
                    ])
                        ->when(isset($request->brand_id), function ($query) use ($request) {
                            $query->where('brand_id', $request->brand_id);
                        })

                        ->get();
                })
                ->ttl(600) // 10 minutes
        ];
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }


    public function subCategory()
    {
        return $this->belongsTo(ProductSubCategory::class, 'sub_category_id');
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    public function measureUnit()
    {
        return $this->belongsTo(MeasureUnit::class, 'measure_unit_id');
    }

    public function getPrimaryMeasureUnitAttribute()
    {
        $primary = ProductList::where(['product_id' => $this->id, 'is_primary' => 1])->first();
        if ($primary)
            return MeasureUnit::find($primary->measure_unit_id);
        else
            return null;
    }

    public function productType()
    {
        return $this->belongsTo(ProductType::class, 'product_type_id');
    }

    public function location()
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function productFieldValues(): HasMany
    {
        return $this->hasMany(ProductFieldValue::class);
    }

    public function productLists(): HasMany
    {
        return $this->hasMany(ProductList::class);
    }

    public function saleProduct()
    {
        return $this->hasMany(SaleProduct::class);
    }

    public function latestProduct()
    {
        return $this->hasOne(ProductList::class, 'product_id', 'id')->latestOfMany();
    }

    public function primaryProductItem()
    {
        return $this->hasOne(ProductList::class)->where('is_primary', '=', 1);
    }

    public function lastPurchase()
    {
        return $this->hasOne(PurchaseProduct::class, 'product_id', 'id')->latestOfMany();
    }

    public function getProductStockQuantityAttribute()
    {
        $purchases = $this->getPurchaseQuantityAttribute();
        $purchaseReturn = PurchaseProductReturn::where('product_id', $this->id)->sum('quantity') ?? 0;
        $sale = SaleProduct::where('product_id', $this->id)->sum('quantity') ?? 0;
        $saleReturn = SalesReturnProduct::where('product_id', $this->id)->sum('quantity') ?? 0;
        $openQty = $this->getOpeningQuantityAttribute();
        $stock = $purchases - $purchaseReturn - $sale + $saleReturn + $openQty;
        return ($stock) >= 0 ? $stock : 0;
    }

    public function getOpeningQuantityAttribute()
    {
        return StockEntry::where('product_id', $this->id)->sum('quantity') ?? 0;
    }

    public function getOpeningRateAttribute()
    {
        return StockEntry::where('product_id', $this->id)->avg('rate') ?? 0;
    }

    public function getPurchaseQuantityAttribute()
    {
        return PurchaseProduct::where('product_id', $this->id)->sum('quantity') ?? 0;
    }

    public function getProductPurchaseRateAttribute()
    {
        return PurchaseProduct::where('product_id', $this->id)->latest('id')->first()->price ?? 0;
    }


    public function getSaleQuantityAttribute()
    {
        return SaleProduct::where('product_id', $this->id)->sum('quantity') ?? 0;
    }

    public function getSaleRateAttribute()
    {
        return SaleProduct::where('product_id', $this->id)->latest('id')->first()->price ?? 0;
    }

    public function getPurchaseReturnQuantityAttribute()
    {
        return PurchaseProductReturn::where('product_id', $this->id)->sum('quantity') ?? 0;
    }

    public function getPurchaseReturnRateAttribute()
    {
        return PurchaseProductReturn::where('product_id', $this->id)->latest('id')->first()->price ?? 0;
    }

    public function getSaleReturnQuantityAttribute()
    {
        return SalesReturnProduct::where('product_id', $this->id)->sum('quantity') ?? 0;
    }

    public function getSaleReturnRateAttribute()
    {
        return SalesReturnProduct::where('product_id', $this->id)->latest('id')->first()->price ?? 0;
    }

    public function getStockAdjustmentDetailAttribute()
    {
        $averagePrice = StockProductDetails::where(['product_id' => $this->id])->get()->map(function ($stock) {
            return Helper::getPrimaryUnitWithPrice($stock->product_id, $stock->measure_unit_id ?? 0, $stock->quantity ?? 0, $stock->price);
        })->reduce(function ($carry, $item) {
            $carry['total_price'] += $item['total_price'];
            $carry['primary_units'] += $item['primary_units'];
            return $carry;
        }, ['total_price' => 0, 'primary_units' => 0]);
        return ['qty' => $averagePrice['primary_units'], 'avg_price' => $averagePrice['primary_units'] > 0 ? round($averagePrice['total_price'] / $averagePrice['primary_units'], 2) : 0];

    }

    public function getStockInDetailAttribute()
    {
        $averagePrice = StockProductDetails::where(['product_id' => $this->id])->get()->map(function ($stock) {
            return Helper::getPrimaryUnitWithPrice($stock->product_id, $stock->measure_unit_id ?? 0, $stock->quantity ?? 0, $stock->price);
        })->reduce(function ($carry, $item) {
            $carry['total_price'] += $item['total_price'];
            $carry['primary_units'] += $item['primary_units'];
            return $carry;
        }, ['total_price' => 0, 'primary_units' => 0]);
        return ['qty' => $averagePrice['primary_units'], 'avg_price' => $averagePrice['primary_units'] > 0 ? round($averagePrice['total_price'] / $averagePrice['primary_units'], 2) : 0];

    }

    public function getStockOutDetailAttribute()
    {
        $averagePrice = StockProductDetails::where(['product_id' => $this->id])->get()->map(function ($stock) {
            return Helper::getPrimaryUnitWithPrice($stock->product_id, $stock->measure_unit_id ?? 0, $stock->quantity ?? 0, $stock->price);
        })->reduce(function ($carry, $item) {
            $carry['total_price'] += $item['total_price'];
            $carry['primary_units'] += $item['primary_units'];
            return $carry;
        }, ['total_price' => 0, 'primary_units' => 0]);
        return ['qty' => $averagePrice['primary_units'], 'avg_price' => $averagePrice['primary_units'] > 0 ? round($averagePrice['total_price'] / $averagePrice['primary_units'], 2) : 0];

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

    public function getPurchaseDetailAttribute()
    {
        $averagePrice = PurchaseProduct::where(['product_id' => $this->id])->get()->map(function ($purchase) {

            $primaryEntities = (Helper::convertToPrimaryUnitQuantityRate($purchase->product_id, $purchase->measure_unit_id ?? 0, $purchase->quantity ?? 0, $purchase->price));

            return [
                'total_price' => $primaryEntities[1],
                'primary_units' => $primaryEntities[0],
            ];

        })->reduce(function ($carry, $item) {
            $carry['total_price'] += $item['total_price'];
            $carry['primary_units'] += $item['primary_units'];
            return $carry;
        }, ['total_price' => 0, 'primary_units' => 0]);
        return ['qty' => $averagePrice['primary_units'], 'avg_price' => $averagePrice['primary_units'] > 0 ? round($averagePrice['total_price'] / $averagePrice['primary_units'], 2) : 0];
    }

    public function getSaleDetailAttribute()
    {
        $averagePrice = SaleProduct::where(['product_id' => $this->id])->get()->map(function ($sale) {

            $primaryEntities = (Helper::convertToPrimaryUnitQuantityRate($sale->product_id, $sale->measure_unit_id ?? 0, $sale->quantity ?? 0, $sale->price));

            return [
                'total_price' => $primaryEntities[1],
                'primary_units' => $primaryEntities[0],
            ];

        })->reduce(function ($carry, $item) {
            $carry['total_price'] += $item['total_price'];
            $carry['primary_units'] += $item['primary_units'];
            return $carry;
        }, ['total_price' => 0, 'primary_units' => 0]);
        return ['qty' => $averagePrice['primary_units'], 'avg_price' => $averagePrice['primary_units'] > 0 ? round($averagePrice['total_price'] / $averagePrice['primary_units'], 2) : 0];
    }



}

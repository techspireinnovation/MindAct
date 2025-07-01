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

class Product extends Model
{
    use SoftDeletes, HasFactory;

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
        'purchase_status',
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
        $purchases = $this->getPurchaseDetailAttribute();
        $purchaseReturn = $this->getPurchaseReturnDetailAttribute();
        $sale = $this->getSaleDetailAttribute();
        $saleReturn = $this->getSaleReturnDetailAttribute();
        $openQty = $this->getOpeningQuantityAttribute();
        $stock = $purchases['qty'] - $purchaseReturn['qty'] - $sale['qty'] + $saleReturn['qty'] + $openQty;
        return $stock >= 0 ? $stock : 0;
    }

    public function getOpeningQuantityAttribute()
    {
        $request = request();
        return StockEntry::where('product_id', $this->id)->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
            $query1->whereDate('stock_entries.created_at', '>=', $request->from_date)->whereDate('stock_entries.created_at', '<=', $request->to_date);
        })->sum('quantity') ?? 0;
    }

    public function getOpeningRateAttribute()
    {
        $request = request();
        return StockEntry::where('product_id', $this->id)->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
            $query1->whereDate('stock_entries.created_at', '>=', $request->from_date)->whereDate('stock_entries.created_at', '<=', $request->to_date);
        })->avg('rate') ?? 0;
    }

    public function getPurchaseQuantityAttribute()
    {
        $request = request();
        return PurchaseProduct::where('product_id', $this->id)
            ->whereHas('purchase', function ($query) use ($request) {
                $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                    $query1->whereDate('purchases.invoice_date_bs', '>=', $request->from_date)->whereDate('purchases.invoice_date_bs', '<=', $request->to_date);
                });
            })->sum('quantity') ?? 0;
    }

    public function getProductSaleRateAttribute()
    {
        $request = request();
        $averagePrice = SaleProduct::where('product_id', $this->id)->whereHas('sale', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                $query1->whereDate('sales.invoice_date_bs', '>=', $request->from_date)->whereDate('sales.invoice_date_bs', '<=', $request->to_date);
            });
        })->get();
        $count = $averagePrice->count();
        if ($request->method === 'fifo') {
            $averagePrice = $averagePrice->map(function ($purchase) use ($count) {
                $primaryEntities = Helper::convertToPrimaryUnitQuantityRate($purchase->product_id, $purchase->measure_unit_id ?? 0, $purchase->quantity ?? 0, $purchase->price);
                return [
                    'total_price' => $primaryEntities[1],
                    'primary_units' => $primaryEntities[0],
                ];
            })->reduce(function ($carry, $item) {
                $carry['total_price'] += $item['total_price'];
                $carry['primary_units'] += $item['primary_units'];
                return $carry;
            }, ['total_price' => 0, 'primary_units' => 0]);

            return $averagePrice['primary_units'] > 0 ? round($this->getProductSaleAmountAttribute() / $averagePrice['primary_units'], 2) : 0;
        } else {
            $averagePrice = $averagePrice->map(function ($purchase) use ($count) {
                $primaryEntities = Helper::convertToPrimaryUnitQuantityRate($purchase->product_id, $purchase->measure_unit_id ?? 0, $purchase->quantity ?? 0, $purchase->price);
                return [
                    'total_price' => $primaryEntities[1],
                    'primary_units' => $primaryEntities[0],
                ];
            })->reduce(function ($carry, $item) {
                $carry['total_price'] += $item['total_price'];
                $carry['primary_units'] += $item['primary_units'];
                return $carry;
            }, ['total_price' => 0, 'primary_units' => 0]);
            return $averagePrice['primary_units'] > 0 ? round($this->getProductSaleAmountAttribute() / $averagePrice['primary_units'], 2) : 0;
        }

    }

    public function getProductClosingDetailAttribute()
    {
        $request = request();
        if ($request->method === 'average') {
            $purchases = PurchaseProduct::where('product_id', $this->id)->whereHas('purchase', function ($query) use ($request) {
                $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                    $query1->whereDate('purchases.invoice_date_bs', '>=', $request->from_date)->whereDate('purchases.invoice_date_bs', '<=', $request->to_date);
                });
            })->get();


            $purchaseReturns = PurchaseProductReturn::where('product_id', $this->id)->whereHas('purchaseReturn', function ($query) use ($request) {
                $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                    $query1->whereDate('purchase_returns.invoice_date_bs', '>=', $request->from_date)->whereDate('purchase_returns.invoice_date_bs', '<=', $request->to_date);
                });
            })->get();


            $sales = SaleProduct::where('product_id', $this->id)->whereHas('sale', function ($query) use ($request) {
                $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                    $query1->whereDate('sales.invoice_date_bs', '>=', $request->from_date)->whereDate('sales.invoice_date_bs', '<=', $request->to_date);
                });
            })->get();

            // Calculate total quantity and total cost
            $purchaseQuantity = $purchases->sum('quantity');
            $salesQuantity = $sales->sum('quantity');
            $totalQuantity = $purchaseQuantity - $salesQuantity;

            // Calculate closing rate (average cost per unit)
            $WeightedAverageCostperUnit = $purchaseQuantity > 0 ? $purchases->sum('amount') / $purchaseQuantity : 0;

            return ['closing_amount' => round($totalQuantity * $WeightedAverageCostperUnit), 'closing_quantity' => $totalQuantity, 'closing_rate' => round($WeightedAverageCostperUnit, 2)];

        } else if ($request->method === 'fifo') {

            $purchases = PurchaseProduct::where('product_id', $this->id)->whereHas('purchase', function ($query) use ($request) {
                $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                    $query1->whereDate('purchases.invoice_date_bs', '>=', $request->from_date)->whereDate('purchases.invoice_date_bs', '<=', $request->to_date);
                });
            })->get();

            $sales = SaleProduct::where('product_id', $this->id)->whereHas('sale', function ($query) use ($request) {
                $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                    $query1->whereDate('sales.invoice_date_bs', '>=', $request->from_date)->whereDate('sales.invoice_date_bs', '<=', $request->to_date);
                });
            })->sum('quantity');

            $remainingSales = $sales;
            $closingBatches = [];
            $remainingQty = 0;

            foreach ($purchases as $purchase) {
                if ($remainingSales >= $purchase->quantity) {
                    // Entire batch is sold
                    $remainingSales -= $purchase->quantity;
                    continue;
                } else {
                    // Partial batch remains (or all if no sales left)
                    $remainingQty = $purchase->quantity - $remainingSales;
                    $closingBatches[] = [
                        'quantity' => $remainingQty,
                        'rate' => $purchase->price,
                        'amount' => $remainingQty * $purchase->price,
                    ];
                    $remainingSales = 0;
                }
            }

            // If there were more purchases after all sales are consumed
            if ($remainingSales == 0) {
                foreach ($purchases->skip(count($closingBatches)) as $purchase) {
                    $closingBatches[] = [
                        'quantity' => $purchase->quantity,
                        'rate' => $purchase->price,
                        'amount' => $purchase->quantity * $purchase->price,
                    ];
                }
            }

            $closingAmount = array_sum(array_column($closingBatches, 'amount'));
            $closingRate = $remainingQty > 0 ? $closingAmount / $remainingQty : 0;

            return [
                'closing_quantity' => $remainingQty,
                'closing_amount' => $closingAmount,
                'closing_rate' => round($closingRate, 2),

            ];

        }

    }

    public function getProductPurchaseRateAttribute()
    {
        $request = request();
        $averagePrice = PurchaseProduct::where('product_id', $this->id)->whereHas('purchase', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                $query1->whereDate('purchases.invoice_date_bs', '>=', $request->from_date)->whereDate('purchases.invoice_date_bs', '<=', $request->to_date);
            });
        })->get();

        if ($request->method === 'fifo') {
            $averagePrice = $averagePrice->map(function ($purchase) {
                $primaryEntities = Helper::convertToPrimaryUnitQuantityRate($purchase->product_id, $purchase->measure_unit_id ?? 0, $purchase->quantity ?? 0, $purchase->price);
                return [
                    'total_price' => $primaryEntities[1],
                    'primary_units' => $primaryEntities[0],
                ];
            })->reduce(function ($carry, $item) {
                $carry['total_price'] += $item['total_price'];
                $carry['primary_units'] += $item['primary_units'];
                return $carry;
            }, ['total_price' => 0, 'primary_units' => 0]);

            return $averagePrice['primary_units'] > 0 ? round($this->getProductPurchaseAmountAttribute() / $averagePrice['primary_units'], 2) : 0;
        } else {
            $averagePrice = $averagePrice->map(function ($purchase) {
                $primaryEntities = Helper::convertToPrimaryUnitQuantityRate($purchase->product_id, $purchase->measure_unit_id ?? 0, $purchase->quantity ?? 0, $purchase->price);
                return [
                    'total_price' => $primaryEntities[1],
                    'primary_units' => $primaryEntities[0],
                ];
            })->reduce(function ($carry, $item) {
                $carry['total_price'] += $item['total_price'];
                $carry['primary_units'] += $item['primary_units'];
                return $carry;
            }, ['total_price' => 0, 'primary_units' => 0]);
            return $averagePrice['primary_units'] > 0 ? round($this->getProductPurchaseAmountAttribute() / $averagePrice['primary_units'], 2) : 0;
        }

    }

    public function getProductPurchaseAmountAttribute()
    {
        $request = request();
        return round(PurchaseProduct::where('product_id', $this->id)->whereHas('purchase', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                $query1->whereDate('purchases.invoice_date_bs', '>=', $request->from_date)->whereDate('purchases.invoice_date_bs', '<=', $request->to_date);
            });
        })->sum('amount') ?? 0, 2);
    }


    public function getPurchaseReturnAmountAttribute()
    {
        $request = request();
        return round(PurchaseProductReturn::where('product_id', $this->id)->whereHas('purchaseReturn', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                $query1->whereDate('purchase_returns.invoice_date_bs', '>=', $request->from_date)->whereDate('purchase_returns.invoice_date_bs', '<=', $request->to_date);
            });
        })->sum('amount') ?? 0, 2);
    }



    public function getProductSaleAmountAttribute()
    {
        $request = request();
        return round(SaleProduct::where('product_id', $this->id)->whereHas('sale', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                $query1->whereDate('sales.invoice_date_bs', '>=', $request->from_date)->whereDate('sales.invoice_date_bs', '<=', $request->to_date);
            });
        })->sum('amount') ?? 0, 2);
    }


    public function getSaleQuantityAttribute()
    {
        $request = request();
        return SaleProduct::where('product_id', $this->id)->whereHas('sale', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                $query1->whereDate('sales.invoice_date_bs', '>=', $request->from_date)->whereDate('sales.invoice_date_bs', '<=', $request->to_date);
            });
        })->sum('quantity') ?? 0;
    }

    public function getSaleRateAttribute()
    {
        $request = request();
        return SaleProduct::where('product_id', $this->id)->whereHas('sale', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                $query1->whereDate('sales.invoice_date_bs', '>=', $request->from_date)->whereDate('sales.invoice_date_bs', '<=', $request->to_date);
            });
        })->latest('id')->first()->price ?? 0;
    }

    public function getPurchaseReturnQuantityAttribute()
    {
        $request = request();
        return PurchaseProductReturn::where('product_id', $this->id)->whereHas('purchaseReturn', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                $query1->whereDate('purchase_returns.invoice_date_bs', '>=', $request->from_date)->whereDate('purchase_returns.invoice_date_bs', '<=', $request->to_date);
            });
        })->sum('quantity') ?? 0;
    }

    public function getPurchaseReturnRateAttribute()
    {
        $request = request();
        return PurchaseProductReturn::where('product_id', $this->id)->whereHas('purchaseReturn', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                $query1->whereDate('purchase_returns.invoice_date_bs', '>=', $request->from_date)->whereDate('purchase_returns.invoice_date_bs', '<=', $request->to_date);
            });
        })->latest('id')->first()->price ?? 0;
    }

    public function getSaleReturnQuantityAttribute()
    {
        $request = request();
        return SalesReturnProduct::where('product_id', $this->id)->whereHas('saleReturn', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                $query1->whereDate('sales_returns.invoice_date_bs', '>=', $request->from_date)->whereDate('sales_returns.invoice_date_bs', '<=', $request->to_date);
            });
        })->sum('quantity') ?? 0;
    }

    public function getSaleReturnRateAttribute()
    {
        $request = request();
        return SalesReturnProduct::where('product_id', $this->id)->whereHas('saleReturn', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                $query1->whereDate('sales_returns.invoice_date_bs', '>=', $request->from_date)->whereDate('sales_returns.invoice_date_bs', '<=', $request->to_date);
            });
        })->latest('id')->first()->price ?? 0;
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
        $request = request();
        $averagePrice = PurchaseProduct::where(['product_id' => $this->id])->whereHas('purchase', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
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

    public function getPurchaseReturnDetailAttribute()
    {
        $request = request();
        $averagePrice = PurchaseProductReturn::where(['product_id' => $this->id])->whereHas('purchaseReturn', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
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
        $averagePrice = SalesReturnProduct::where(['product_id' => $this->id])->whereHas('saleReturn', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
                $query1->whereDate('sales_returns.invoice_date_bs', '>=', $request->from_date)->whereDate('sales_returns.invoice_date_bs', '<=', $request->to_date);
            });
        })->get()->map(function ($purchase) {
            $primaryEntities = Helper::convertToPrimaryUnitQuantityRate($purchase->product_id, $purchase->measure_unit_id ?? 0, $purchase->quantity ?? 0, $purchase->price);
            return [
                'total_price' => $primaryEntities[1],
                'primary_units' => $primaryEntities[0],
                'total_amount' => $purchase->amount ?? ($purchase->quantity * $purchase->price),
            ];
        })->reduce(function ($carry, $item) {
            $carry['total_price'] += $item['total_price'];
            $carry['primary_units'] += $item['primary_units'];
            $carry['total_amount'] += $item['total_amount'];
            return $carry;
        }, ['total_price' => 0, 'primary_units' => 0, 'total_amount' => 0]);
        return ['qty' => $averagePrice['primary_units'], 'total_price' => round($averagePrice['total_amount'], 2), 'avg_price' => $averagePrice['primary_units'] > 0 ? round($averagePrice['total_price'] / $averagePrice['primary_units'], 2) : 0];
    }



    public function getSaleDetailAttribute()
    {
        $request = request();
        $averagePrice = SaleProduct::where(['product_id' => $this->id])->whereHas('sale', function ($query) use ($request) {
            $query->when($request->has('from_date') && $request->has('to_date'), function ($query1) use ($request) {
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



}

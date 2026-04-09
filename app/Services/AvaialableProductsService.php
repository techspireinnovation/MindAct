<?php

namespace App\Services;

use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\Stock;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\MeasureUnit;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockProduct;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SalesReturnProduct;
use App\Models\PurchaseStockProductReturn;
use App\Models\PurchaseStockProductReturnFieldValue;
use App\Models\PurchaseStockProduct;
use App\Models\PurchaseProductReturn;
use App\Models\SaleProduct;
use App\Models\SalesProductFieldValue;
use App\Models\StockTransferFieldValue;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\SaleProductReturn;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;


use Illuminate\Validation\Rule;


class AvaialableProductsService
{



    public function productListforTransaction($companyId, $branchId)
    {
        $stockIn = StockProduct::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['purchase', 'opening_stock'])

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as stock_in'))
            ->groupBy('product_id');

        $movementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'purchase')

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_in'))
            ->groupBy('product_id');


        $transactionOut = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sales', 'purchase_return'])
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_out'))
            ->groupBy('product_id');

        $movementOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sales', 'purchase_return'])

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_out'))
            ->groupBy('product_id');

        $adjustmentOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'stock_adjustment')
            ->where('stock_type', 'subtract')
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as adjustment_out'))
            ->groupBy('product_id');

        $adjustmentIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'stock_adjustment')
            ->where('stock_type', 'add')
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as adjustment_in'))
            ->groupBy('product_id');

        $transactionIn = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_in'))
            ->groupBy('product_id');

        $returnMovementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as return_movement_in'))
            ->groupBy('product_id');


        $products = DB::table('products as p')
            ->leftJoinSub($movementIn, 'mi', function ($join) {
                $join->on('p.id', '=', 'mi.product_id');
            })
            ->leftJoinSub($transactionOut, 'to', function ($join) {
                $join->on('p.id', '=', 'to.product_id');
            })
            ->leftJoinSub($transactionIn, 'ti', function ($join) {
                $join->on('p.id', '=', 'ti.product_id');
            })
            ->leftJoinSub($stockIn, 'si', function ($join) {
                $join->on('p.id', '=', 'si.product_id');
            })
            ->leftJoinSub($movementOut, 'mo', function ($join) {
                $join->on('p.id', '=', 'mo.product_id');
            })
            ->leftJoinSub($returnMovementIn, 'rmi', function ($join) {
                $join->on('p.id', '=', 'rmi.product_id');
            })
            ->leftJoinSub($adjustmentOut, 'ao', function ($join) {
                $join->on('p.id', '=', 'ao.product_id');
            })
            ->leftJoinSub($adjustmentIn, 'ai', function ($join) {
                $join->on('p.id', '=', 'ai.product_id');
            })

            ->select(
                'p.id',
                'p.name',
                DB::raw('
                COALESCE(mi.movement_in,0)
                + COALESCE(ti.transaction_in,0)
                - COALESCE(to.transaction_out,0)
                + COALESCE(si.stock_in,0)
                - COALESCE(mo.movement_out,0)
                + COALESCE(rmi.return_movement_in,0)
                - COALESCE(ao.adjustment_out,0)
                + COALESCE(ai.adjustment_in,0)

                as available_quantity
            ')
            )
            ->havingRaw('available_quantity > 0')
            ->get();

        return $products;
    }

    public function productListforTransactionDetails($companyId, $branchId, $productId)
    {
        $stockIn = StockProduct::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->whereIn('type', ['purchase', 'opening_stock'])

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as stock_in'))
            ->groupBy('product_id');

        $movementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'purchase')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_in'))
            ->groupBy('product_id');


        $transactionOut = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sale', 'purchase_return'])
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_out'))
            ->groupBy('product_id');

        $movementOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sale', 'purchase_return'])
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_out'))
            ->groupBy('product_id');

        $adjustmentOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'stock_adjustment')
            ->where('stock_type', 'subtract')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as adjustment_out'))
            ->groupBy('product_id');

        $adjustmentIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'stock_adjustment')
            ->where('stock_type', 'add')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as adjustment_in'))
            ->groupBy('product_id');


        $transactionIn = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_in'))
            ->groupBy('product_id');

        $returnMovementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as return_movement_in'))
            ->groupBy('product_id');


        $measureUnits = DB::table('product_lists as pl')
            ->join('measure_units as mu', 'pl.measure_unit_id', '=', 'mu.id')
            ->whereNull('pl.deleted_at')
            ->select(
                'pl.product_id',
                'pl.measure_unit_id as id',
                'mu.name',

                'pl.price',
                'pl.discount',
                'pl.final_price',
                'pl.is_primary'
            )
            ->get()
            ->groupBy('product_id');


        $products = DB::table('products as p')
            ->leftJoin('measure_units as mu', 'p.measure_unit_id', '=', 'mu.id')
            ->leftJoinSub($movementIn, 'mi', function ($join) {
                $join->on('p.id', '=', 'mi.product_id');
            })
            ->leftJoinSub($transactionOut, 'to', function ($join) {
                $join->on('p.id', '=', 'to.product_id');
            })
            ->leftJoinSub($transactionIn, 'ti', function ($join) {
                $join->on('p.id', '=', 'ti.product_id');
            })
            ->leftJoinSub($stockIn, 'si', function ($join) {
                $join->on('p.id', '=', 'si.product_id');
            })
            ->leftJoinSub($movementOut, 'mo', function ($join) {
                $join->on('p.id', '=', 'mo.product_id');
            })
            ->leftJoinSub($returnMovementIn, 'rmi', function ($join) {
                $join->on('p.id', '=', 'rmi.product_id');
            })
            ->leftJoinSub($adjustmentIn, 'ai', function ($join) {
                $join->on('p.id', '=', 'ai.product_id');
            })
            ->leftJoinSub($adjustmentOut, 'ao', function ($join) {
                $join->on('p.id', '=', 'ao.product_id');
            })

            ->select(
                'p.id',
                'p.name',
                'p.purchase_rate',
                'p.retail_price',
                'p.wholesale_price',
                'p.mrp_price',
                'p.is_vatable',
                'p.measure_unit_id',

                'mu.name as measure_unit_name',
                'mu.quantity as measure_unit_quantity',

                DB::raw('
                COALESCE(mi.movement_in,0)
                + COALESCE(ti.transaction_in,0)
                - COALESCE(to.transaction_out,0)
                + COALESCE(si.stock_in,0)
                - COALESCE(mo.movement_out,0)
                + COALESCE(rmi.return_movement_in,0)
                - COALESCE(ao.adjustment_out,0)
                + COALESCE(ai.adjustment_in,0)
                

                as available_quantity
            ')
            )
            ->havingRaw('available_quantity > 0')
            ->get();


        $variantIndexes = DB::table('stock_product_field_values')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', 'stock_product_id', 'stock_movement_id', 'quantity_index', 'key', 'value')
            ->groupBy('product_id', 'stock_product_id', 'stock_movement_id', 'quantity_index', 'key', 'value')
            ->get();




        $transactions = DB::table('transaction_pivots')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select(
                'product_id',
                'stock_product_id',
                'stock_movement_id',
                'quantity_index',
                DB::raw("
                SUM(
                    CASE
                        WHEN type = 'sale' THEN -1
                        WHEN type = 'purchase_return' THEN -1
                        WHEN type = 'stock_adjustment' THEN -1
                      
                        WHEN type = 'sales_return' THEN 1
                        ELSE 0
                    END
                ) as effect
            ")
            )
            ->groupBy('product_id', 'stock_product_id', 'stock_movement_id', 'quantity_index')
            ->get();



        $variants = $variantIndexes->map(function ($variant) use ($transactions) {

            $effect = $transactions
                ->where('stock_product_id', $variant->stock_product_id)
                ->where('quantity_index', $variant->quantity_index)
                ->sum('effect');

            $available = 1 + $effect;

            if ($available > 0) {
                return [
                    "product_id" => $variant->product_id,
                    "stock_product_id" => $variant->stock_product_id ?? null,
                    "stock_movement_id" => $variant->stock_movement_id ?? null,
                    "quantity_index" => $variant->quantity_index,
                    "key" => $variant->key,
                    "value" => $variant->value,
                ];
            }

            return null;

        })->filter()->values();




        $products->transform(function ($product) use ($variants) {

            $product->field_values = $variants
                ->where('product_id', $product->id)
                ->values();

            $baseUnit = [
                "id" => $product->measure_unit_id,
                "name" => $product->measure_unit_name,
                "quantity" => $product->measure_unit_quantity

            ];

            $otherUnits = collect($measureUnits[$product->id] ?? [])
                ->map(function ($unit) {
                    return [
                        "id" => $unit->id,
                        "name" => $unit->name,
                        "quantity" => $unit->quantity,


                    ];
                });

            $product->measure_units = collect([$baseUnit])
                ->merge($otherUnits)
                ->unique('id')
                ->values();
            return $product;
        });


        return $products;


    }


    public function productListforTransactionItemWiseSalesReturnDetails($companyId, $branchId, $productId)
    {
        $transactionIn = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sale')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_in'))
            ->groupBy('product_id');

        $movementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sale')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_in'))
            ->groupBy('product_id');


        $transactionOut = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_out'))
            ->groupBy('product_id');

        $movementOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_out'))
            ->groupBy('product_id');





        $measureUnits = DB::table('product_lists as pl')
            ->join('measure_units as mu', 'pl.measure_unit_id', '=', 'mu.id')
            ->whereNull('pl.deleted_at')
            ->select(
                'pl.product_id',
                'pl.measure_unit_id as id',
                'mu.name',

                'pl.price',
                'pl.discount',
                'pl.final_price',
                'pl.is_primary'
            )
            ->get()
            ->groupBy('product_id');

        $products = DB::table('products as p')
            ->leftJoin('measure_units as mu', 'p.measure_unit_id', '=', 'mu.id')
            ->leftJoinSub($movementIn, 'mi', function ($join) {
                $join->on('p.id', '=', 'mi.product_id');
            })
            ->leftJoinSub($transactionOut, 'to', function ($join) {
                $join->on('p.id', '=', 'to.product_id');
            })
            ->leftJoinSub($transactionIn, 'ti', function ($join) {
                $join->on('p.id', '=', 'ti.product_id');
            })

            ->leftJoinSub($movementOut, 'mo', function ($join) {
                $join->on('p.id', '=', 'mo.product_id');
            })


            ->select(
                'p.id',
                'p.name',
                'p.purchase_rate',
                'p.retail_price',
                'p.wholesale_price',
                'p.mrp_price',
                'p.measure_unit_id',

                'mu.name as measure_unit_name',
                'mu.quantity as measure_unit_quantity',
                DB::raw('
                COALESCE(mi.movement_in,0)
                + COALESCE(ti.transaction_in,0)
                - COALESCE(to.transaction_out,0)
               
                - COALESCE(mo.movement_out,0)
                

                as available_quantity
            ')
            )
            ->havingRaw('available_quantity > 0')
            ->get();

        $variantIndexes = DB::table('stock_product_field_values')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select(
                'product_id',
                'stock_product_id',
                'quantity_index',
                'quantity_type',
                'key',
                'value'
            )
            ->groupBy(
                'product_id',
                'stock_product_id',
                'quantity_index',
                'quantity_type',
                'key',
                'value'
            )
            ->get();




        $transactions = DB::table('transaction_pivots')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select(
                'product_id',
                'stock_product_id',
                'stock_transaction_id',
                'stock_movement_id',
                'quantity_index',
                DB::raw("
                SUM(
                    CASE
                         WHEN type = 'sale' THEN 1
                        WHEN type = 'sales_return' THEN -1
                        ELSE 0
                    END
                ) as effect
            ")
            )
            ->groupBy(
                'product_id',
                'stock_product_id',
                'stock_transaction_id',
                'stock_movement_id',
                'quantity_index'
            )
            ->get();



        $variants = $variantIndexes->map(function ($variant) use ($transactions) {

            $effect = $transactions
                ->where('stock_product_id', $variant->stock_product_id)
                ->where('quantity_index', $variant->quantity_index)
                ->sum('effect');

           

            if ($effect > 0) {
                return [
                    "product_id" => $variant->product_id,
                    "stock_product_id" => $variant->stock_product_id,
                    "stock_transaction_id" => $variant->stock_transaction_id ?? null,
                    "stock_movement_id" => $variant->stock_movement_id ?? null,
                    "quantity_index" => $variant->quantity_index,
                    "key" => $variant->key,
                    "value" => $variant->value,
                ];
            }

            return null;

        })->filter()->values();




        $products->transform(function ($product) use ($variants) {

            $product->field_values = $variants
                ->where('product_id', $product->id)
                ->values();

            $baseUnit = [
                "id" => $product->measure_unit_id,
                "name" => $product->measure_unit_name,
                "quantity" => $product->measure_unit_quantity

            ];

            $otherUnits = collect($measureUnits[$product->id] ?? [])
                ->map(function ($unit) {
                    return [
                        "id" => $unit->id,
                        "name" => $unit->name,
                        "quantity" => $unit->quantity,


                    ];
                });

            $product->measure_units = collect([$baseUnit])
                ->merge($otherUnits)
                ->unique('id')
                ->values();

            return $product;
        });


        return $products;


    }


    public function productListforTransactionItemWiseSalesReturn($companyId, $branchId)
    {

        $transactionIn = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_in'))
            ->groupBy('product_id');


        $movementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sale')

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_in'))
            ->groupBy('product_id');


        $transactionOut = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_out'))
            ->groupBy('product_id');

        $movementOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')

            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_out'))
            ->groupBy('product_id');


        $products = DB::table('products as p')
            ->leftJoinSub($movementIn, 'mi', function ($join) {
                $join->on('p.id', '=', 'mi.product_id');
            })
            ->leftJoinSub($transactionOut, 'to', function ($join) {
                $join->on('p.id', '=', 'to.product_id');
            })
            ->leftJoinSub($transactionIn, 'ti', function ($join) {
                $join->on('p.id', '=', 'ti.product_id');
            })

            ->leftJoinSub($movementOut, 'mo', function ($join) {
                $join->on('p.id', '=', 'mo.product_id');
            })


            ->select(
                'p.id',
                'p.name',
                DB::raw('
                COALESCE(mi.movement_in,0)
                + COALESCE(ti.transaction_in,0)
                - COALESCE(to.transaction_out,0)
                
                - COALESCE(mo.movement_out,0)
               

                as available_quantity
            ')
            )
            ->havingRaw('available_quantity > 0')
            ->get();

        return $products;
    }




    public function productListforTransactionBillWisePurchaseReturn($companyId, $branchId)
    {

        $stockIn = DB::table('stock_products')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->select(
                'stock_id',
                'id as stock_product_id',
                'product_id',
                DB::raw('SUM(quantity) as stock_in')
            )
            ->groupBy('stock_id', 'id', 'product_id');


        $movementIn = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'purchase')
            ->whereNull('deleted_at')
            ->select(
                'stock_id',
                'id as movement_id',
                'product_id',
                DB::raw('SUM(quantity) as movement_in')
            )
            ->groupBy('stock_id', 'id', 'product_id');


        $stockProductIds = DB::table('stock_products')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->pluck('id');

        $movementIds = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->pluck('id');


        $transactionOut = DB::table('stock_transactions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sales', 'purchase_return'])
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {

                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })

                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select(
                'product_id',
                DB::raw('SUM(quantity) as transaction_out')
            )
            ->groupBy('product_id');


        $movementOut = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sales', 'purchase_return'])
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {

                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })

                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select(
                'product_id',
                DB::raw('SUM(quantity) as movement_out')
            )
            ->groupBy('product_id');


        $transactionIn = DB::table('stock_transactions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {

                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })

                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select(
                'product_id',
                DB::raw('SUM(quantity) as transaction_in')
            )
            ->groupBy('product_id');


        $returnMovementIn = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {

                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })

                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select(
                'product_id',
                DB::raw('SUM(quantity) as return_movement_in')
            )
            ->groupBy('product_id');


        $bills = DB::table('stock_products as sp')

            ->join('stocks as s', 'sp.stock_id', '=', 's.id')

            ->leftJoinSub($stockIn, 'si', function ($join) {
                $join->on('sp.stock_id', '=', 'si.stock_id')
                    ->on('sp.product_id', '=', 'si.product_id');
            })

            ->leftJoinSub($movementIn, 'mi', function ($join) {
                $join->on('sp.stock_id', '=', 'mi.stock_id')
                    ->on('sp.product_id', '=', 'mi.product_id');
            })

            ->leftJoinSub($transactionOut, 'to', function ($join) {
                $join->on('sp.product_id', '=', 'to.product_id');
            })

            ->leftJoinSub($movementOut, 'mo', function ($join) {
                $join->on('sp.product_id', '=', 'mo.product_id');
            })

            ->leftJoinSub($transactionIn, 'ti', function ($join) {
                $join->on('sp.product_id', '=', 'ti.product_id');
            })

            ->leftJoinSub($returnMovementIn, 'rmi', function ($join) {
                $join->on('sp.product_id', '=', 'rmi.product_id');
            })

            ->select(
                's.bill_number',
                DB::raw('
            COALESCE(si.stock_in,0)
            + COALESCE(mi.movement_in,0)
            + COALESCE(ti.transaction_in,0)
            + COALESCE(rmi.return_movement_in,0)
            - COALESCE(to.transaction_out,0)
            - COALESCE(mo.movement_out,0)
            as available_quantity
        ')
            )

            ->havingRaw('available_quantity > 0')

            ->pluck('s.bill_number')
            ->unique()
            ->values();

        return $bills;
    }

    public function productforTransactionBillWisePurchaseReturnDetails($companyId, $branchId, $billNumber)
    {
        // ====================== 1. Get Stock Details ======================
        $stock = DB::table('stocks')
            ->where('bill_number', $billNumber)
            ->where('company_id', $companyId)
            ->first([
                'id as stock_id',
                'fiscal_year_id',
                'company_id',
                'branch_id',
                'store_id',
                'type',


                'bill_number',
                'purchase_bill_number',
                'invoice_date',
                'invoice_date_bs',
                'party_id',
                'location_id',
                'batch_no',
                'credit_days',
                'balance',
                'ref_bill_number',
                'return_bill_no',
                'reasons',
                'discount_type',
                'discount_value',
                'discount_after_vat',
                'sub_total_before_discount',
                'taxable_amount',
                'non_taxable_amount',
                'excise_duty',
                'vat_percent',
                'health_insurance',
                'freight_amount',
                'roundoff_type',
                'roundoff_amount',
                'total_amount',
                'payment',
                'remarks',
            ]);

        if (!$stock) {
            return response()->json(["message" => "Bill not found !!"], 404);
        }

        $stockId = $stock->stock_id;

        // ====================== 2. Your Original Subqueries (UNCHANGED) ======================
        $stockIn = DB::table('stock_products')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('stock_id', $stockId)
            ->whereNull('deleted_at')
            ->select(
                'id as stock_product_id',
                'product_id',
                DB::raw('SUM(quantity) as stock_in')
            )
            ->groupBy('id', 'product_id');

        $movementIn = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'purchase')
            ->where('stock_id', $stockId)
            ->whereNull('deleted_at')
            ->select(
                'id as movement_id',
                'product_id',
                DB::raw('SUM(quantity) as movement_in')
            )
            ->groupBy('id', 'product_id');

        $stockProductIds = $stockIn->pluck('stock_product_id');
        $movementIds = $movementIn->pluck('movement_id');

        $transactionOut = DB::table('stock_transactions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sale', 'purchase_return'])
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {
                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })
                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select('product_id', DB::raw('SUM(quantity) as transaction_out'))
            ->groupBy('product_id');

        $movementOut = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sale', 'purchase_return'])
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {
                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })
                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select('product_id', DB::raw('SUM(quantity) as movement_out'))
            ->groupBy('product_id');

        $transactionIn = DB::table('stock_transactions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {
                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })
                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select('product_id', DB::raw('SUM(quantity) as transaction_in'))
            ->groupBy('product_id');

        $returnMovementIn = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->where(function ($q) use ($stockProductIds, $movementIds) {
                $q->where(function ($sub) use ($stockProductIds) {
                    $sub->where('source_type', 'stock_product')
                        ->whereIn('source_id', $stockProductIds);
                })
                    ->orWhere(function ($sub) use ($movementIds) {
                        $sub->where('source_type', 'stock_movement')
                            ->whereIn('source_id', $movementIds);
                    });
            })
            ->select('product_id', DB::raw('SUM(quantity) as return_movement_in'))
            ->groupBy('product_id');

        // ====================== 3. Products Query ======================
        $products = DB::table('products as p')
            ->leftJoinSub($stockIn, 'si', function ($join) {
                $join->on('p.id', '=', 'si.product_id');
            })
            ->leftJoinSub($movementIn, 'mi', function ($join) {
                $join->on('p.id', '=', 'mi.product_id');
            })
            ->leftJoinSub($transactionOut, 'to', function ($join) {
                $join->on('p.id', '=', 'to.product_id');
            })
            ->leftJoinSub($movementOut, 'mo', function ($join) {
                $join->on('p.id', '=', 'mo.product_id');
            })
            ->leftJoinSub($transactionIn, 'ti', function ($join) {
                $join->on('p.id', '=', 'ti.product_id');
            })
            ->leftJoinSub($returnMovementIn, 'rmi', function ($join) {
                $join->on('p.id', '=', 'rmi.product_id');
            })

            ->leftJoin('stock_products as sp', function ($join) use ($stockId, $companyId, $branchId) {
                $join->on('p.id', '=', 'sp.product_id')
                    ->where('sp.stock_id', $stockId)
                    ->where('sp.company_id', $companyId)
                    ->where('sp.branch_id', $branchId)
                    ->whereNull('sp.deleted_at');
            })
            ->leftJoin('measure_units as mu', 'sp.measure_unit_id', '=', 'mu.id')

            ->select(
                'p.id',
                'p.name',
                DB::raw('
                COALESCE(si.stock_in,0)
                + COALESCE(mi.movement_in,0)
                + COALESCE(ti.transaction_in,0)
                + COALESCE(rmi.return_movement_in,0)
                - COALESCE(to.transaction_out,0)
                - COALESCE(mo.movement_out,0)
                as available_quantity
            '),

                'sp.id as stock_product_id',
                'sp.quantity',
                'sp.price',
                'sp.discount_amount',
                'sp.amount',
                'sp.batch_no',
                'sp.expiry_date',
                'sp.mfd',
                'sp.measure_unit_id',
                'mu.name as measure_unit_name',
                'mu.quantity as measure_unit_quantity',
                'sp.is_vatable'
            )
            ->havingRaw('available_quantity > 0')
            ->get();

        // ====================== 4. Variants Logic (UNCHANGED) ======================
        $stockProductIds = DB::table('stock_products')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('stock_id', $stockId)
            ->pluck('id');

        $variantIndexes = DB::table('stock_product_field_values')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('stock_product_id', $stockProductIds)
            ->whereNull('deleted_at')
            ->select('product_id', 'stock_product_id', 'quantity_index', 'quantity_type', 'key', 'value')
            ->groupBy('product_id', 'stock_product_id', 'quantity_index', 'quantity_type', 'key', 'value')
            ->get();

        $transactions = DB::table('transaction_pivots')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('stock_product_id', $stockProductIds)
            ->whereNull('deleted_at')
            ->select(
                'product_id',
                'stock_product_id',
                'quantity_index',
                DB::raw("
                SUM(
                    CASE
                        WHEN type = 'sale' THEN -1
                        WHEN type = 'purchase_return' THEN -1
                        WHEN type = 'sales_return' THEN 1
                        ELSE 0
                    END
                ) as effect
            ")
            )
            ->groupBy('product_id', 'stock_product_id', 'quantity_index')
            ->get();

        $variants = $variantIndexes->map(function ($variant) use ($transactions) {
            $effect = $transactions
                ->where('stock_product_id', $variant->stock_product_id)
                ->where('quantity_index', $variant->quantity_index)
                ->sum('effect');

            $available = 1 + $effect;

            if ($available > 0) {
                return [
                    "product_id" => $variant->product_id,
                    "stock_product_id" => $variant->stock_product_id,
                    "quantity_index" => $variant->quantity_index,
                    "quantity_type" => $variant->quantity_type ?? null,
                    "key" => $variant->key,
                    "value" => $variant->value,
                ];
            }

            return null;
        })
            ->filter()
            // ->unique(function ($item) {
            //     return $item['stock_product_id'] . '-' . $item['quantity_index'];
            // })
            ->values();

        $products->transform(function ($product) use ($variants) {
            $product->field_values = $variants
                ->where('product_id', $product->id)
                ->values();

            $product->measure_unit = [
                [
                    "id" => $product->measure_unit_id,
                    "name" => $product->measure_unit_name,
                    "quantity" => $product->measure_unit_quantity
                ]
            ];


            unset($product->measure_unit_id);
            unset($product->measure_unit_name);

            return $product;
        });

        // ====================== 5. FINAL RESPONSE (Products inside Stock) ======================
        return [

            'id' => $stock->stock_id,
            'bill_number' => $stock->bill_number ?? null,
            'invoice_date' => $stock->invoice_date ?? null,
            'invoice_date_bs' => $stock->invoice_date_bs ?? null,
            'total_amount' => $stock->total_amount ?? null,
            'party_id' => $stock->party_id ?? null,
            'fiscal_year_id' => $stock->fiscal_year_id ?? null,

            'company_id' => $stock->company_id ?? null,
            'branch_id' => $stock->branch_id ?? null,
            'store_id' => $stock->store_id ?? null,
            'type' => $stock->type ?? null,



            'purchase_bill_number' => $stock->purchase_bill_number ?? null,


            'location_id' => $stock->location_id ?? null,
            'batch_no' => $stock->batch_no ?? null,
            'credit_days' => $stock->credit_days ?? null,
            'balance' => $stock->balance ?? null,
            'ref_bill_number' => $stock->ref_bill_number ?? null,
            'return_bill_no' => $stock->return_bill_no ?? null,
            'reasons' => $stock->reasons ?? null,
            'discount_type' => $stock->discount_type ?? null,
            'discount_value' => $stock->discount_value ?? null,
            'discount_after_vat' => $stock->discount_after_vat ?? null,
            'sub_total_before_discount' => $stock->sub_total_before_discount ?? null,
            'taxable_amount' => $stock->taxable_amount ?? null,
            'non_taxable_amount' => $stock->non_taxable_amount ?? null,
            'excise_duty' => $stock->excise_duty ?? null,
            'vat_percent' => $stock->vat_percent ?? null,
            'health_insurance' => $stock->health_insurance ?? null,
            'freight_amount' => $stock->freight_amount ?? null,
            'roundoff_type' => $stock->roundoff_type ?? null,
            'roundoff_amount' => $stock->roundoff_amount ?? null,

            'payment' => $stock->payment ?? null,
            'remarks' => $stock->remarks ?? null,



            'products' => $products

        ];
    }

    public function productListforTransactionBillWiseSalesReturn($companyId, $branchId)
    {
        \Log::info('=== Sales Return Bill List Started ===', compact('companyId', 'branchId'));


        $sales = collect();

        // Regular sales from stock_transactions
        $transSales = DB::table('stock_transactions as st')
            ->join('stocks as s', 'st.stock_id', '=', 's.id')
            ->where('st.company_id', $companyId)
            ->where('st.branch_id', $branchId)
            ->where('st.type', 'sale')
            ->whereNull('st.deleted_at')
            ->select('s.bill_number', 'st.id as source_id', DB::raw('SUM(st.quantity) as qty'))
            ->groupBy('s.bill_number', 'st.id')
            ->get();

        // Free / movement sales
        $moveSales = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->whereNotNull('sales_bill_number')
            ->select('sales_bill_number as bill_number', 'id as source_id', DB::raw('SUM(quantity) as qty'))
            ->groupBy('sales_bill_number', 'id')
            ->get();

        $allSales = $transSales->concat($moveSales);

        // Get all returns
        $returns = DB::table('stock_transactions')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNotNull('source_id')
            ->whereNull('deleted_at')
            ->select('source_id', DB::raw('SUM(quantity) as qty'))
            ->groupBy('source_id')
            ->get();

        $moveReturns = DB::table('stock_movements')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNotNull('source_id')
            ->whereNull('deleted_at')
            ->select('source_id', DB::raw('SUM(quantity) as qty'))
            ->groupBy('source_id')
            ->get();

        $allReturns = $returns->concat($moveReturns);

        // Calculate per bill
        $availableBills = [];

        foreach ($allSales->groupBy('bill_number') as $bill => $items) {
            $totalSold = $items->sum('qty');

            $sourceIds = $items->pluck('source_id');

            $totalReturned = $allReturns->whereIn('source_id', $sourceIds)->sum('qty');

            $available = $totalSold - $totalReturned;

            \Log::info("Bill: {$bill}", [
                'total_sold' => $totalSold,
                'total_returned' => $totalReturned,
                'available' => $available
            ]);

            if ($available > 0) {
                $availableBills[] = $bill;
            }
        }

        \Log::info('Final available bills for sales return', $availableBills);

        return $availableBills;
    }

    public function productforTransactionBillWiseSalesReturnDetails($companyId, $branchId, $billNumber)
    {
        
        $stock = DB::table('stocks as s')
            ->leftJoin('parties as p', 's.party_id', '=', 'p.id')
            ->where('s.bill_number', $billNumber)
            ->where('s.company_id', $companyId)
            ->select('s.*', 'p.name as party_name')
            ->first();

        if (!$stock) {
            return response()->json(["message" => "Bill not found !!"], 404);
        }

        $stockId = $stock->id;

        
        $salesTransactions = DB::table('stock_transactions')
            ->where('stock_id', $stockId)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->select(
                'id as source_id',
                'stock_id',
                'product_id',
                'quantity',
                DB::raw("'stock_transaction' as source_type")
            );

       
        $salesMovements = DB::table('stock_movements')
            ->where('stock_id', $stockId)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->select(
                'id as source_id',
                'stock_id',
                'product_id',
                'quantity',
                DB::raw("'stock_movement' as source_type")
            );

       
        $allSales = $salesTransactions->unionAll($salesMovements)->get();

       
        $returnFromTransactions = DB::table('stock_transactions as rt')
            
            ->where('rt.type', 'sales_return')
            ->whereNull('rt.deleted_at')
            ->select(
                'rt.source_id',
                'rt.source_type',
                'rt.product_id',
                DB::raw('SUM(rt.quantity) as return_qty')
            )
            ->groupBy('rt.source_id', 'rt.source_type', 'rt.product_id');

       
        $returnFromMovements = DB::table('stock_movements as rm')
           
            ->where('rm.type', 'sales_return')
            ->whereNull('rm.deleted_at')
            ->select(
                'rm.source_id',
                'rm.source_type',
                'rm.product_id',
                DB::raw('SUM(rm.quantity) as return_qty')
            )
            ->groupBy('rm.source_id', 'rm.source_type', 'rm.product_id');

      
        $allReturns = $returnFromTransactions->unionAll($returnFromMovements)->get();

       
        $returnMap = [];

        foreach ($allReturns as $r) {
            $key = $r->source_type . '_' . $r->source_id . '_' . $r->product_id;

            if (!isset($returnMap[$key])) {
                $returnMap[$key] = 0;
            }

            $returnMap[$key] += $r->return_qty;
        }

       
        $products = [];

        foreach ($allSales as $s) {

            $key = $s->source_type . '_' . $s->source_id . '_' . $s->product_id;

            $returnedQty = $returnMap[$key] ?? 0;

            if (!isset($products[$s->product_id])) {
                $products[$s->product_id] = [
                    'product_id' => $s->product_id,
                    'sold_qty' => 0,
                    'returned_qty' => 0,
                ];
            }

            $products[$s->product_id]['sold_qty'] += $s->quantity;
            $products[$s->product_id]['returned_qty'] += $returnedQty;
        }

       
        foreach ($products as &$p) {
            $p['available_quantity'] = $p['sold_qty'] - $p['returned_qty'];
        }

       
        $result = collect($products)->values()->map(function ($p) {
            $product = DB::table('products')->where('id', $p['product_id'])->first();

            $unit = null;
            if ($product && $product->measure_unit_id) {
                $unit = DB::table('measure_units')->where('id', $product->measure_unit_id)->first();
            }

            return [
                'product_id' => $p['product_id'],
                'product_name' => $product->name ?? null,
                'sold_qty' => $p['sold_qty'],
                'returned_qty' => $p['returned_qty'],
                'available_quantity' => $p['available_quantity'],
                'measure_unit_name' => $unit->name ?? null,
                'measure_unit_quantity' => $unit->quantity ?? null,
            ];
        });

        return [
            'id' => $stock->id,
            'bill_number' => $stock->bill_number,
            'party_name' => $stock->party_name,
            'products' => $result
        ];
    }


}
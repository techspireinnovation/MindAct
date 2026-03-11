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
            ->whereIn('type', ['sales', 'purchase_return'])
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as transaction_out'))
            ->groupBy('product_id');

        $movementOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereIn('type', ['sales', 'purchase_return'])
            ->where('product_id', $productId)
            ->whereNull('deleted_at')
            ->select('product_id', DB::raw('SUM(quantity) as movement_out'))
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

                as available_quantity
            ')
            )
            ->havingRaw('available_quantity > 0')
            ->get();

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
        $stockId = DB::table('stocks')
            ->where('bill_number', $billNumber)
            ->value('id');


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
            ')
            )

            ->havingRaw('available_quantity > 0')
            ->get();

        return $products;
    }

    public function productListforTransactionBillWiseSalesReturn($companyId, $branchId)
    {
        // Total quantity sold via StockMovement
        $movementOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->select('id', 'stock_id', 'product_id', DB::raw('SUM(quantity) as movement_out'))
            ->groupBy('id', 'stock_id', 'product_id');

        // Sales returns linked to StockTransaction
        $transactionIn = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNotNull('source_id')
            ->whereNull('deleted_at')
            ->select('source_id', DB::raw('SUM(quantity) as transaction_in'))
            ->groupBy('source_id');

        // Sales returns linked to StockMovement
        $returnMovementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNotNull('source_id')
            ->whereNull('deleted_at')
            ->select('source_id', DB::raw('SUM(quantity) as return_movement_in'))
            ->groupBy('source_id');

        // Join sold transactions with their returns and stocks to get bill numbers
        $bills = DB::table('stock_transactions as st')
            ->join('stocks as s', 'st.stock_id', '=', 's.id')
            ->leftJoinSub($movementOut, 'mo', function ($join) {
                $join->on('st.stock_id', '=', 'mo.stock_id')
                    ->on('st.product_id', '=', 'mo.product_id');
            })
            ->leftJoinSub($transactionIn, 'ti', function ($join) {
                $join->on('st.id', '=', 'ti.source_id'); // Link returns to original transaction
            })
            ->leftJoinSub($returnMovementIn, 'rmi', function ($join) {
                $join->on('mo.id', '=', 'rmi.source_id'); // Link returns from movements
            })
            ->where('st.company_id', $companyId)
            ->where('st.branch_id', $branchId)
            ->where('st.type', 'sale')
            ->whereNull('st.deleted_at')
            ->select(
                's.bill_number',
                DB::raw('
                SUM(st.quantity)           -- quantity sold in StockTransaction
                + COALESCE(SUM(mo.movement_out), 0)   -- quantity sold in StockMovement
                - COALESCE(SUM(ti.transaction_in), 0) -- quantity returned from StockTransaction
                - COALESCE(SUM(rmi.return_movement_in), 0) -- quantity returned from StockMovement
                as available_quantity
            ')
            )
            ->groupBy('s.bill_number')
            ->havingRaw('available_quantity > 0')
            ->pluck('s.bill_number') // only get bill numbers
            ->unique()
            ->values();

        return $bills;
    }

    public function productforTransactionBillWiseSalesReturnDetails($companyId, $branchId, $billNumber)
    {

        $movementOut = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->select('id', 'stock_id', 'product_id', DB::raw('SUM(quantity) as movement_out'))
            ->groupBy('id', 'stock_id', 'product_id');


        $transactionIn = StockTransaction::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNotNull('source_id')
            ->whereNull('deleted_at')
            ->select('source_id', DB::raw('SUM(quantity) as transaction_in'))
            ->groupBy('source_id');


        $returnMovementIn = StockMovement::where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('type', 'sales_return')
            ->whereNotNull('source_id')
            ->whereNull('deleted_at')
            ->select('source_id', DB::raw('SUM(quantity) as return_movement_in'))
            ->groupBy('source_id');


        $products = DB::table('stock_transactions as st')
            ->join('stocks as s', 'st.stock_id', '=', 's.id')
            ->join('products as p', 'st.product_id', '=', 'p.id')
            ->leftJoinSub($movementOut, 'mo', function ($join) {
                $join->on('st.stock_id', '=', 'mo.stock_id')
                    ->on('st.product_id', '=', 'mo.product_id');
            })
            ->leftJoinSub($transactionIn, 'ti', function ($join) {
                $join->on('st.id', '=', 'ti.source_id');
            })
            ->leftJoinSub($returnMovementIn, 'rmi', function ($join) {
                $join->on('mo.id', '=', 'rmi.source_id');
            })
            ->where('st.company_id', $companyId)
            ->where('st.branch_id', $branchId)
            ->where('st.type', 'sale')
            ->whereNull('st.deleted_at')
            ->where('s.bill_number', $billNumber)
            ->select(
                'st.product_id',
                'p.name as product_name',
                DB::raw('
                SUM(st.quantity)
                + COALESCE(SUM(mo.movement_out), 0)
                - COALESCE(SUM(ti.transaction_in), 0)
                - COALESCE(SUM(rmi.return_movement_in), 0)
                as quantity
            ')
            )
            ->groupBy('st.product_id', 'p.name')
            ->havingRaw('quantity > 0')
            ->get();

        return $products;
    }




}
<?php

namespace App\Http\Controllers\Report;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductReturn;
use App\Models\Sale;
use App\Models\SaleProduct;
use App\Models\SalesReturnProduct;
use App\Models\StockEntry;
use App\Models\StockProductDetails;
use DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Validator;


class ReportController extends Controller
{
    public function productListDetails(Request $request): JsonResponse
    {
        try {
            $items = Product::select("products.id", "is_vatable", "brand_id", "product_type_id", "products.product_unique_id", "sub_category_id", "location_id", "category_id", "products.name")->with([
                'location' => function ($query) use ($request) {
                    return $query->select('locations.id', 'name')->get();
                },
                'category' => function ($query) use ($request) {
                    return $query->select('product_categories.id', 'name')->get();
                },
                'subCategory' => function ($query) use ($request) {
                    return $query->select('product_sub_categories.id', 'name')->get();
                },
                'brand' => function ($query) use ($request) {
                    return $query->select('brands.id', 'name')->get();
                },
                'productType' => function ($query) use ($request) {
                    return $query->select('product_types.id', 'name')->get();
                },
                'latestProduct' => function ($query) use ($request) {
                    return $query->select('product_lists.id', 'product_lists.barcode', 'product_lists.hs_code', 'product_lists.product_id')->get();
                },
                'lastPurchase',
            ]);

            if ($request->has('product_id')) {
                $items->where('id', $request->input('product_id'));
            }
            if ($request->has('brand_id')) {
                $items->where('brand_id', $request->input('brand_id'));
            }

            if ($request->has('product_type_id')) {
                $items->where('product_type_id', $request->input('product_type_id'));
            }

            if ($request->has('sub_category_id')) {
                $items->where('sub_category_id', $request->input('sub_category_id'));
            }
            if ($request->has('location_id')) {
                $items->where('location_id', $request->input('location_id'));
            }

            $items = $items->get();
            $items->each->append('product_stock_quantity');

            $items = $items->map(function ($item) {
                $item->last_purchase_rate_amount = Helper::getPrimaryRateAmount($item->id, $item->lastPurchase->id ?? 0);
                $item->last_purchase_rate_amount_vat = Helper::getProductVatableAmount($item->id, $item->last_purchase_rate_amount ?? 0);
                return $item;
            });
            return response()->json($items);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }

    }
    public function stockRegisterDetails(Request $request): JsonResponse
    {
        //  try {
        $items = Product::select("products.id", "products.product_unique_id", "products.name")->with([
            'lastPurchase',
            'primaryProductItem'
        ]);

        if ($request->has('from_date') && $request->has('to_date')) {
            $items->whereDate('products.created_at', '>=', $request->from_date)->whereDate('products.created_at', '<=', $request->to_date);
        }
        $items->where('id', $request->input('product_id'));


        $items = $items->get();
        $items->each->append(['product_stock_quantity', 'opening_quantity', 'purchase_quantity', 'product_purchase_rate', 'purchase_return_quantity', 'purchase_return_rate', 'sale_quantity', 'sale_rate', 'sale_return_quantity', 'sale_return_rate', 'stock_adjustment_quantity', 'stock_in_quantity', 'stock_out_quantity']);

        $items = $items->map(function ($item) {
            $item->last_purchase_rate_amount = Helper::getPrimaryRateAmount($item->id, $item->lastPurchase->id ?? 0);
            $item->last_purchase_rate_amount_vat = Helper::getProductVatableAmount($item->id, $item->last_purchase_rate_amount ?? 0);
            return $item;

        });

        //$date = Carbon::now();
        //Excel::store(new ProductListDetailsReport($items), "product-list-{$date}.xlsx");
        return response()->json($items);

        // } catch (\Exception $e) {
        //      \Log::error($e);
        //return response()->json(['error' => 'An unexpected error occurred!!'], 500);

        //}
    }

    public function productPriceListDetails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:purchase,sales',
            'product_id' => 'required|numeric',

        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if ($request->type === "purchase") {
            $items = PurchaseProduct::select("purchase_products.id", "purchase_products.quantity", "purchase_products.measure_unit_id", "purchase_products.customer_id", "purchase_products.product_id", "purchase_products.created_at", "purchase_products.purchase_id")->with(['purchase:id,customer_id,purchase_bill_number,ref_bill_number', 'purchase.customer:id,party_name'])->whereHas('purchase', function ($query) use ($request) {
                if ($request->has('from_date') && $request->has('to_date')) {
                    $query->whereDate('invoice_date', '>=', $request->from_date)->whereDate('invoice_date', '<=', $request->to_date);
                }
            })->where('product_id', $request->product_id);

            if ($request->has('customer_id')) {
                $items->where('customer_id', $request->input('customer_id'));
            }

            if ($request->has('from_date') && $request->has('to_date')) {
                $items->whereDate('purchase_products.created_at', '>=', $request->from_date)->whereDate('purchase_products.created_at', '<=', $request->to_date);
            }

            $items = $items->get();
            $items->each->append(['purchase_quantity', 'purchase_unit', 'purchase_rate', 'purchase_discount_amount']);
        } else {
            $items = SaleProduct::select("sale_products.id", "sale_products.quantity", "sale_products.product_id", "sale_products.measure_unit_id", "sale_products.created_at", "sale_products.sale_id")->with([
                'sale' => function ($q) {
                    $q->select("sales.id", "sales.customer_id", "sales.ref_number", "sales.invoice_number", "sales.invoice_date_bs")->with([
                        "customer" => function ($cus) {
                            $cus->select("customers.id", "customers.party_name");
                        }
                    ]);
                }
            ])->whereHas('sale', function ($query) use ($request) {
                if ($request->has('from_date') && $request->has('to_date')) {
                    $query->whereDate('invoice_date', '>=', $request->from_date)->whereDate('invoice_date', '<=', $request->to_date);
                }
                if ($request->has('customer_id')) {
                    $query->where('customer_id', $request->input('customer_id'));
                }
            })->where('product_id', $request->product_id);

            if ($request->has('from_date') && $request->has('to_date')) {
                $items->whereDate('sale_products.created_at', '>=', $request->from_date)->whereDate('sale_products.created_at', '<=', $request->to_date);
            }

            $items = $items->get();
            $items->each->append(['sale_quantity', 'sale_unit', 'sale_rate', 'sale_discount_amount']);
        }
        return response()->json($items);
    }


    public function vendorSupplierListDetails(Request $request): JsonResponse
    {

        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:invoice,list',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if ($request->type === "list") {

            $items = Customer::select("customers.id", "customers.party_name", "customers.pan_number")->withSum('purchases', 'sub_total_before_discount')->withSum('purchases', 'discount_value')->withSum('purchaseReturns', 'sub_total_before_discount')->withSum('purchaseReturns', 'discount_value');

            if ($request->has('customer_id')) {
                $items->where('id', $request->input('customer_id'));
            }

            $items = $items->get();
        } else {
            $items = Purchase::select("purchases.id", "purchases.invoice_date", "purchases.sub_total_before_discount", "purchases.discount_value", "purchases.purchase_bill_number", "purchases.ref_bill_number", "purchases.customer_id")->with('customer:id,party_name,pan_number');

            if ($request->has('customer_id')) {
                $items->where('id', $request->input('customer_id'));
            }

            $items = $items->get();
            $items->each->append(['purchase_return_amount', 'purchase_return_discount_amount']);
        }
        return response()->json($items);
    }

    public function stockLedgerListDetails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'product_id' => 'required|integer|exists:products,id',
        ]);
        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }
        $product = Product::findOrFail($request->product_id);

        $openingItems = StockEntry::select("id", "product_id", "quantity AS opening_qty", DB::raw('DATE(created_at) AS date'))->where('product_id', $request->product_id)->where(function ($where) use ($request) {
            if ($request->has('from_date') && $request->has('to_date')) {
                $where->whereDate('created_at', '>=', $request->from_date)->whereDate('created_at', '<=', $request->to_date);
            }
        })->get();

        $stockAdjustments = StockProductDetails::select("id", "product_id", "diff_stock AS adjustment_qty", DB::raw('DATE(created_at) AS date'))->where('product_id', $request->product_id)->where(function ($where) use ($request) {
            if ($request->has('from_date') && $request->has('to_date')) {
                $where->whereDate('created_at', '>=', $request->from_date)->whereDate('created_at', '<=', $request->to_date);
            }
        })->get();

        $purchaseItems = PurchaseProduct::select("purchase_products.id AS id", "purchase_products.quantity AS purchase_qty", "purchases.purchase_bill_number AS bill_number", "customers.party_name AS customer_name", DB::raw('0 AS sale_qty'), "purchase_products.product_id AS product_id", "purchase_products.customer_id AS customer_id", "purchases.invoice_date AS date")->leftJoin("purchases", "purchases.id", "=", "purchase_products.purchase_id")->leftJoin("customers", "customers.id", "=", "purchases.customer_id")->where('product_id', $request->product_id)->where(function ($where) use ($request) {
            if ($request->has('from_date') && $request->has('to_date')) {
                $where->whereDate('purchase_products.created_at', '>=', $request->from_date)->whereDate('purchase_products.created_at', '<=', $request->to_date);
            }
        })->get();

        //$purchaseItems->each->append(['primary_unit_name']);

        $purchaseReturnItems = PurchaseProductReturn::select("purchase_product_returns.id AS id", "customers.party_name AS customer_name", "purchase_returns.purchase_bill_number AS bill_number", "purchase_product_returns.quantity AS purchase_return_qty", DB::raw('0 AS sale_qty'), "purchase_product_returns.product_id AS product_id", "purchase_product_returns.customer_id AS customer_id", "purchase_returns.invoice_date AS date")->leftJoin("purchase_returns", "purchase_returns.id", "=", "purchase_product_returns.purchase_return_id")->leftJoin("customers", "customers.id", "=", "purchase_returns.customer_id")->where('product_id', $request->product_id)->where(function ($where) use ($request) {
            if ($request->has('from_date') && $request->has('to_date')) {
                $where->whereDate('purchase_product_returns.created_at', '>=', $request->from_date)->whereDate('purchase_product_returns.created_at', '<=', $request->to_date);
            }
        })->get();

        $saleItems = SaleProduct::select("sale_products.id AS id", "sale_products.quantity AS sale_qty", "sales.invoice_number AS bill_number", "customers.party_name AS customer_name", DB::raw('0 AS purchase_qty'), "sale_products.product_id AS product_id", "sales.customer_id AS customer_id", "sales.invoice_date AS date")->leftJoin("sales", "sales.id", "=", "sale_products.sale_id")->leftJoin("customers", "customers.id", "=", "sales.customer_id")->where('product_id', $request->product_id)->where(function ($where) use ($request) {
            if ($request->has('from_date') && $request->has('to_date')) {
                $where->whereDate('sale_products.created_at', '>=', $request->from_date)->whereDate('sale_products.created_at', '<=', $request->to_date);
            }
        })->get();

        $saleReturnItems = SalesReturnProduct::select("sales_return_products.id AS id", "sales_returns.invoice_number AS bill_number", "sales_return_products.quantity AS sale_return_qty", "customers.party_name AS customer_name", DB::raw('0 AS sale_qty'), "sales_return_products.product_id AS product_id", "sales_returns.customer_id AS customer_id", "sales_returns.invoice_date AS date")->leftJoin("sales_returns", "sales_returns.id", "=", "sales_return_products.sales_return_id")->leftJoin("customers", "customers.id", "=", "sales_returns.customer_id")->where('product_id', $request->product_id)->where(function ($where) use ($request) {
            if ($request->has('from_date') && $request->has('to_date')) {
                $where->whereDate('sales_return_products.created_at', '>=', $request->from_date)->whereDate('sales_return_products.created_at', '<=', $request->to_date);
            }
        })->get();

        $merged = $saleItems->concat($purchaseItems);
        $merged = $merged->concat($purchaseReturnItems);
        $merged = $merged->concat($saleReturnItems);
        $merged = $merged->concat($stockAdjustments);
        $transactions = $merged->concat($openingItems);

        $balance = 0;
        $transactions->sortBy('date')->each(function ($transaction) use (&$balance, $product) {

            $balance += ($transaction['adjustment_qty'] ?? 0) + ($transaction['opening_qty'] ?? 0) + ($transaction['purchase_qty'] ?? 0) - ($transaction['purchase_return_qty'] ?? 0) - ($transaction['sale_qty'] ?? 0) + ($transaction['sale_return_qty'] ?? 0);
            $transaction['total_quantity'] = $balance;
            $transaction['primary_unit_name'] = $product->getPrimaryMeasureUnitAttribute()->name;
        });

        return response()->json($transactions);
    }

    public function cbmsVatReturnListDetails(Request $request): JsonResponse
    {

        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:sales,sales_return,purchases,purchase_return',
            'month' => 'required|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        if ($request->type === "purchases") {

            $items = Purchase::select("purchases.id", "purchases.invoice_date AS date", "purchases.total_amount as total_amount", "purchases.taxable_amount as taxable_amount", "purchases.purchase_bill_number as bill_number", "purchases.non_taxable_amount as non_taxable_amount", "purchases.customer_id")->with(relations: 'customer:id,party_name,pan_number')->orderBy('id', 'asc');

            if ($request->has('month')) {
                $items->whereMonth('invoice_date', $request->input('month'));
            }

            $items = $items->get();
            //$items->each->append(['purchase_return_amount', 'purchase_return_discount_amount']);
        } else if ($request->type === "sales") {
            $items = Sale::select("sales.id", "sales.invoice_date AS date", "sales.total_amount as total_amount", "sales.taxable_amount as taxable_amount", "sales.invoice_number as bill_number", "sales.non_taxable_amount as non_taxable_amount", "sales.customer_id")->with(relations: 'customer:id,party_name,pan_number')->orderBy('id', 'asc');

            if ($request->has('month')) {
                $items->whereMonth('invoice_date', $request->input('month'));
            }

            $items = $items->get();
        } else if ($request->type === "purchase_return") {
            $items = DB::table('purchase_product_returns as ppr')
                ->join('purchase_returns as pr', 'ppr.purchase_return_id', '=', 'pr.id')
                ->join('customers as c', 'pr.customer_id', '=', 'c.id')
                ->select([
                    'pr.invoice_date as date',
                    'pr.purchase_bill_number as bill_number',
                    'c.party_name as supplier_name',
                    'c.pan_number as supplier_pan',
                    'ppr.product_name as product_service_name',
                    'ppr.product_id as product_id',
                    DB::raw('SUM(ppr.quantity) as product_quantity'),
                    DB::raw('SUM(ppr.amount) as total_purchase'),
                    DB::raw('SUM(CASE WHEN ppr.is_vatable = 0 THEN ppr.amount ELSE 0 END) as non_taxable'),
                    DB::raw('SUM(CASE WHEN ppr.is_vatable = 1 THEN ppr.amount ELSE 0 END) as taxable'),
                ])
                ->when(isset($request->month) && isset($request->year), function ($query) use ($request) {
                    $query->whereMonth('pr.invoice_date', $request->month)
                        ->whereYear('pr.invoice_date', $request->year);
                })
                ->groupBy([
                    'pr.invoice_date',
                    'pr.purchase_bill_number',
                    'c.party_name',
                    'c.pan_number',
                    'ppr.product_name',
                    'ppr.product_id',
                ])
                ->orderBy('pr.invoice_date')
                ->get();

            $items->each(function ($item) {
                $product = Product::findOrFail($item->product_id);
                $item->primary_unit_name = $product->getPrimaryMeasureUnitAttribute()->name;
            });

        } else if ($request->type === "sales_return") {
            $items = DB::table('sales_return_products as ppr')
                ->join('sales_returns as pr', 'ppr.sales_return_id', '=', 'pr.id')
                ->join('customers as c', 'pr.customer_id', '=', 'c.id')
                ->select([
                    'pr.invoice_date as date',
                    'pr.invoice_number as bill_number',
                    'c.party_name as supplier_name',
                    'c.pan_number as supplier_pan',
                    'ppr.product_name as product_service_name',
                    DB::raw('SUM(ppr.quantity) as product_quantity'),
                    DB::raw('SUM(ppr.price) as total_purchase'),
                    DB::raw('SUM(CASE WHEN ppr.is_vatable = 0 THEN ppr.price ELSE 0 END) as non_taxable'),
                    DB::raw('SUM(CASE WHEN ppr.is_vatable = 1 THEN ppr.price ELSE 0 END) as taxable'),
                ])
                ->when(isset($request->month) && isset($request->year), function ($query) use ($request) {
                    $query->whereMonth('pr.invoice_date', $request->month)
                        ->whereYear('pr.invoice_date', $request->year);
                })
                ->groupBy([
                    'pr.invoice_date',
                    'pr.invoice_number',
                    'c.party_name',
                    'c.pan_number',
                    'ppr.product_name',
                ])
                ->orderBy('pr.invoice_date')
                ->get();
            $items->each(function ($item) {
                $product = Product::findOrFail($item->product_id);
                $item->primary_unit_name = $product->getPrimaryMeasureUnitAttribute()->name;
            });
        }
        return response()->json($items);
    }




}

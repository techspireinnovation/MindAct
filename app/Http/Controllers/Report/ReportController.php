<?php

namespace App\Http\Controllers\Report;

use App\Helpers\Helper;
use App\Http\Controllers\Controller;
use App\Jobs\GrossProfitListExportJob;
use App\Jobs\ProductListExportJob;
use App\Jobs\StockRegisterListExportJob;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SaleProduct;
use App\Models\SalesReturn;
use App\Models\SalesReturnProduct;
use App\Models\StockEntry;
use App\Models\StockProductDetails;
use App\Reports\ProductReport;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Validator;


class ReportController extends Controller
{
    public function productListDetails(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|string|in:list,download',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if ($request->type === "list") {

                if (Helper::checkDataInCache($request->fullUrlWithQuery($request->all()))) {
                    return response()->json(Helper::getDataFromCache($request->fullUrlWithQuery($request->all())));
                }

                $items = ProductReport::productListDetails($request->all());
                $items = $items->paginate(250);
                $items->getCollection()->transform(function ($item) {
                    $item->last_purchase_rate_amount = Helper::getPrimaryRateAmount($item->id, $item->lastPurchase->id ?? 0);
                    $item->last_purchase_rate_amount_vat = Helper::getProductVatableAmount($item->id, $item->last_purchase_rate_amount ?? 0);
                    $item->append('product_stock_quantity');
                    return $item;
                });
                Helper::applyCache($request->fullUrlWithQuery($request->all()), $items);

                return response()->json($items);
            } else if ($request->type === "download") {
                $user = $request->user();
                $tokenId = $user->currentAccessToken()->id;
                ProductListExportJob::dispatch($tokenId, $request->fullUrlWithQuery($request->all()));
                return response()->json([
                    'message' => 'Export started. You will receive a download link when it is ready.',

                ]);

            }
            return response()->json([]);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }

    }

    public function stockRegisterDetails(Request $request): JsonResponse
    {

        try {
            $validator = Validator::make($request->all(), [
                'method' => 'required|string|in:fifo,average',
                'type' => 'required|string|in:list,download',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
            if ($request->type === "list") {

                if (Helper::checkDataInCache($request->fullUrlWithQuery($request->all()))) {
                    return response()->json(Helper::getDataFromCache($request->fullUrlWithQuery($request->all())));
                }
                $items = ProductReport::stockRegisterListDetails($request->all());
                $items = $items->paginate(250);
                $items->getCollection()->transform(function ($item) {
                    $item->append(['product_stock_quantity', 'opening_quantity', 'opening_rate', 'purchase_quantity', 'product_purchase_amount', 'product_purchase_rate', 'purchase_return_quantity', 'purchase_return_rate', 'sale_quantity', 'product_sale_amount', 'product_sale_rate', 'sale_return_quantity', 'sale_return_rate', 'stock_adjustment_detail', 'stock_in_detail', 'stock_out_detail', 'product_closing_detail']);
                    return $item;
                });
                Helper::applyCache($request->fullUrlWithQuery($request->all()), $items);
                return response()->json($items);

            } else if ($request->type === "download") {
                $user = $request->user();
                $tokenId = $user->currentAccessToken()->id;
                StockRegisterListExportJob::dispatch($tokenId, $request->fullUrlWithQuery($request->all()));
                return response()->json([
                    'message' => 'Stock Register List export started. You will receive a download link when it is ready.',
                ]);

            }
            return response()->json([]);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function productPriceListDetails(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:purchase,sales',
            'product_id' => 'required|numeric',
        ]);
        try {
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
            $product = Product::findOrFail($request->product_id);

            if (Helper::checkDataInCache($request->fullUrlWithQuery($request->all()))) {
                return response()->json(Helper::getDataFromCache($request->fullUrlWithQuery($request->all())));
            }

            if ($request->type === "purchase") {
                $items = Purchase::
                    join('purchase_products', 'purchases.id', '=', 'purchase_products.purchase_id')
                    ->join('customers', 'purchases.customer_id', '=', 'customers.id')
                    ->groupBy('purchases.id', 'purchases.purchase_bill_number', 'purchases.customer_name', 'purchases.invoice_date_bs')
                    ->where('purchase_products.product_id', $request->product_id)
                    ->select([
                        'purchases.invoice_date_bs as date',
                        'customers.party_name as party_name',
                        'purchases.purchase_bill_number as invoice_number',
                        'purchases.ref_bill_number as ref_no',
                        DB::raw('SUM(purchase_products.quantity) as quantity'),
                        DB::raw('SUM(purchase_products.discount_amount) as discount_amount'),
                        DB::raw('AVG(purchase_products.price) as rate')
                    ])
                    ->when(isset($request->customer_id), function ($query) use ($request) {
                        $query->where('purchases.customer_id', $request->customer_id);
                    })
                    ->when(isset($request->from_date) && isset($request->to_date), function ($query) use ($request) {
                        $query->whereBetween('purchases.invoice_date_bs', [$request->from_date, $request->to_date]);
                    })
                    ->orderBy('purchases.invoice_date_bs', 'desc')
                    ->get();
                $items->each(function ($item) use ($product) {
                    $item->primary_unit_name = $product->getPrimaryMeasureUnitAttribute()->name;
                });
            } else {
                $items = Sale::
                    join('sale_products', 'sales.id', '=', 'sale_products.sale_id')
                    ->join('customers', 'sales.customer_id', '=', 'customers.id')
                    ->groupBy('sales.id', 'sales.invoice_date_bs', 'sales.customer_name', 'sales.invoice_number')
                    ->where('sale_products.product_id', $request->product_id)
                    ->select([
                        'sales.invoice_date_bs as date',
                        'customers.party_name as party_name',
                        'sales.invoice_number as invoice_number',
                        'sales.ref_number as ref_no',
                        DB::raw('SUM(sale_products.quantity) as quantity'),
                        DB::raw('SUM(sale_products.discount_amount) as discount_amount'),
                        DB::raw('AVG(sale_products.price) as rate')
                    ])
                    ->when(isset($request->customer_id), function ($query) use ($request) {
                        $query->where('sales.customer_id', $request->customer_id);
                    })
                    ->when(isset($request->from_date) && isset($request->to_date), function ($query) use ($request) {
                        $query->whereBetween('sales.invoice_date_bs', [$request->from_date, $request->to_date]);
                    })
                    ->orderBy('sales.invoice_date_bs', 'desc')
                    ->get();

                $items->each(function ($item) use ($product) {
                    $item->primary_unit_name = $product->getPrimaryMeasureUnitAttribute()->name;
                });

            }

            Helper::applyCache($request->fullUrlWithQuery($request->all()), $items);
            return response()->json($items);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Product not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }


    public function vendorSupplierListDetails(Request $request): JsonResponse
    {

        try {
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
                $items = Purchase::select("purchases.id", "purchases.invoice_date_bs", "purchases.sub_total_before_discount", "purchases.discount_value", "purchases.purchase_bill_number", "purchases.ref_bill_number", "purchases.customer_id")->with('customer:id,party_name,pan_number');

                if ($request->has('customer_id')) {
                    $items->where('id', $request->input('customer_id'));
                }

                $items = $items->get();
                $items->each->append(['purchase_return_amount', 'purchase_return_discount_amount']);
            }
            return response()->json($items);

        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function stockLedgerListDetails(Request $request): JsonResponse
    {

        try {
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

            $purchaseItems = PurchaseProduct::select("purchase_products.id AS id", "purchase_products.quantity AS purchase_qty", "purchase_products.measure_unit_id AS measure_unit_id", "measure_units.name AS measure_unit_name", "purchases.purchase_bill_number AS bill_number", "customers.party_name AS customer_name", DB::raw('0 AS sale_qty'), "purchase_products.product_id AS product_id", "purchase_products.customer_id AS customer_id", "purchases.invoice_date_bs AS date")->leftJoin("measure_units", "measure_units.id", "=", "purchase_products.measure_unit_id")->leftJoin("purchases", "purchases.id", "=", "purchase_products.purchase_id")->leftJoin("customers", "customers.id", "=", "purchases.customer_id")->where('product_id', $request->product_id)->whereHas('purchase', function ($query) use ($request) {
                $query->whereNull('deleted_at');
                if ($request->has('from_date') && $request->has('to_date')) {
                    $query->whereDate('purchases.invoice_date_bs', '>=', $request->from_date)->whereDate('purchases.invoice_date_bs', '<=', $request->to_date);
                }
            })->get();

            $purchaseItems->each->append('purchased_primary_unit_qty');

            $purchaseReturnItems = PurchaseProductReturn::select("purchase_product_returns.id AS id", "purchase_product_returns.measure_unit_id AS measure_unit_id", "customers.party_name AS customer_name", "purchase_returns.purchase_bill_number AS bill_number", "measure_units.name AS measure_unit_name", "purchase_product_returns.quantity AS purchase_return_qty", DB::raw('0 AS sale_qty'), "purchase_product_returns.product_id AS product_id", "purchase_product_returns.customer_id AS customer_id", "purchase_returns.invoice_date_bs AS date")->leftJoin("measure_units", "measure_units.id", "=", "purchase_product_returns.measure_unit_id")->leftJoin("purchase_returns", "purchase_returns.id", "=", "purchase_product_returns.purchase_return_id")->leftJoin("customers", "customers.id", "=", "purchase_returns.customer_id")->where('product_id', $request->product_id)->whereHas('purchaseReturn', function ($query) use ($request) {
                $query->whereNull('deleted_at');
                if ($request->has('from_date') && $request->has('to_date')) {
                    $query->whereDate('purchase_returns.invoice_date_bs', '>=', $request->from_date)->whereDate('purchase_returns.invoice_date_bs', '<=', $request->to_date);
                }
            })->get();
            $purchaseReturnItems->each->append('purchase_returned_primary_unit_qty');

            $saleItems = SaleProduct::select("sale_products.id AS id", "measure_units.name AS measure_unit_name", "sale_products.measure_unit_id AS measure_unit_id", "sale_products.quantity AS sale_qty", "sales.invoice_number AS bill_number", "customers.party_name AS customer_name", DB::raw('0 AS purchase_qty'), "sale_products.product_id AS product_id", "sales.customer_id AS customer_id", "sales.invoice_date_bs AS date")->leftJoin("measure_units", "measure_units.id", "=", "sale_products.measure_unit_id")->leftJoin("sales", "sales.id", "=", "sale_products.sale_id")->leftJoin("customers", "customers.id", "=", "sales.customer_id")->where('product_id', $request->product_id)->whereHas('sale', function ($query) use ($request) {
                $query->whereNull('deleted_at');
                if ($request->has('from_date') && $request->has('to_date')) {
                    $query->whereDate('sales.invoice_date_bs', '>=', $request->from_date)->whereDate('sales.invoice_date_bs', '<=', $request->to_date);
                }
            })->get();
            $saleItems->each->append('sold_primary_unit_qty');

            $saleReturnItems = SalesReturnProduct::select("sales_return_products.id AS id", "sales_return_products.measure_unit_id AS measure_unit_id", "measure_units.name AS measure_unit_name", "sales_returns.invoice_number AS bill_number", "sales_return_products.quantity AS sale_return_qty", "customers.party_name AS customer_name", DB::raw('0 AS sale_qty'), "sales_return_products.product_id AS product_id", "sales_returns.customer_id AS customer_id", "sales_returns.invoice_date AS date")->leftJoin("sales_returns", "sales_returns.id", "=", "sales_return_products.sales_return_id")->leftJoin("measure_units", "measure_units.id", "=", "sales_return_products.measure_unit_id")->leftJoin("customers", "customers.id", "=", "sales_returns.customer_id")->where('product_id', $request->product_id)->whereHas('saleReturn', function ($query) use ($request) {
                $query->whereNull('deleted_at');
                if ($request->has('from_date') && $request->has('to_date')) {
                    $query->whereDate('sales_returns.invoice_date_bs', '>=', $request->from_date)->whereDate('sales_returns.invoice_date_bs', '<=', $request->to_date);
                }
            })->get();
            $saleReturnItems->each->append('sale_returned_primary_unit_qty');

            $merged = $purchaseItems->concat($saleReturnItems);
            $merged = $merged->concat($saleItems);
            $merged = $merged->concat($purchaseReturnItems);
            $merged = $merged->concat($stockAdjustments);
            $transactions = $merged->concat($openingItems);

            $balance = 0;
            $transactions->sortBy('date')->each(function ($transaction) use (&$balance, $product) {
                $balance += ($transaction['adjustment_qty'] ?? 0) + ($transaction['opening_qty'] ?? 0) + ($transaction['purchased_primary_unit_qty'] ?? 0) - ($transaction['purchase_returned_primary_unit_qty'] ?? 0) - ($transaction['sold_primary_unit_qty'] ?? 0) + ($transaction['sale_returned_primary_unit_qty'] ?? 0);
                $transaction['total_quantity'] = $balance;
                $transaction['primary_unit_name'] = $product->getPrimaryMeasureUnitAttribute()->name;
            });

            return response()->json($transactions);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Product not found'], 404);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function cbmsVatReturnListDetails(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'type' => 'required|string|in:sales,sales_return,purchases,purchase_return',
                'month' => 'required|numeric',
                'year' => 'required|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if ($request->type === "purchases") {

                $items = Purchase::select("purchases.id", "purchases.invoice_date_bs AS date", "purchases.sub_total_before_discount as total_amount", "purchases.taxable_amount as taxable_amount", "purchases.purchase_bill_number as bill_number", "purchases.non_taxable_amount as non_taxable_amount", "purchases.customer_id", DB::raw('COALESCE(ROUND(purchases.vat_percent,2),0) as vat_amount'))->with(relations: 'customer:id,party_name,pan_number')->orderBy('id', 'asc');

                if ($request->has('month')) {
                    $items->whereRaw(' CAST(SUBSTRING(invoice_date_bs, 6, 2) AS UNSIGNED) = ?', $request->input('month'));
                }
                if ($request->has('year')) {
                    $items->whereRaw('SUBSTRING(invoice_date_bs, 1, 4) = ?', $request->input('year'));
                }
                $items = $items->get();

            } else if ($request->type === "sales") {
                $items = Sale::select("sales.id", "sales.invoice_date_bs AS date", "sales.sub_total_before_discount as total_amount", "sales.taxable_amount as taxable_amount", "sales.invoice_number as bill_number", "sales.non_taxable_amount as non_taxable_amount", "sales.customer_id", DB::raw('COALESCE(ROUND(sales.taxable_amount * .13,2),0) as vat_amount'))->with(relations: 'customer:id,party_name,pan_number')->orderBy('id', 'asc');

                if ($request->has('month')) {
                    $items->whereRaw(' CAST(SUBSTRING(invoice_date_bs, 6, 2) AS UNSIGNED) = ?', $request->input('month'));
                }
                if ($request->has('year')) {
                    $items->whereRaw('SUBSTRING(invoice_date_bs, 1, 4) = ?', $request->input('year'));
                }

                $items = $items->get();

            } else if ($request->type === "purchase_return") {
                $items = DB::table('purchase_product_returns as ppr')
                    ->join('purchase_returns as pr', 'ppr.purchase_return_id', '=', 'pr.id')
                    ->join('customers as c', 'pr.customer_id', '=', 'c.id')
                    ->select([
                        'pr.invoice_date_bs as date',
                        'pr.purchase_bill_number as bill_number',
                        'c.party_name as supplier_name',
                        'c.pan_number as supplier_pan',
                        'ppr.product_name as product_service_name',
                        'ppr.product_id as product_id',
                        DB::raw('SUM(ppr.quantity) as product_quantity'),
                        DB::raw('SUM(ppr.amount) as total_purchase'),
                        DB::raw('SUM(CASE WHEN ppr.is_vatable = 0 THEN ppr.amount ELSE 0 END) as non_taxable'),
                        DB::raw('SUM(CASE WHEN ppr.is_vatable = 1 THEN ppr.amount ELSE 0 END) as taxable'),
                        DB::raw('SUM(CASE WHEN ppr.is_vatable = 1 THEN ROUND(ppr.amount * .13,2) ELSE 0 END) as vat_amount'),
                    ])
                    ->when(isset($request->month) && isset($request->year), function ($query) use ($request) {
                        $query->whereRaw(' CAST(SUBSTRING(invoice_date_bs, 6, 2) AS UNSIGNED) = ?', $request->input('month'))->whereRaw('SUBSTRING(invoice_date_bs, 1, 4) = ?', $request->input('year'));
                    })
                    ->where('ppr.company_id', $request->company_id)
                    ->groupBy([
                        'pr.invoice_date_bs',
                        'pr.purchase_bill_number',
                        'c.party_name',
                        'c.pan_number',
                        'ppr.product_name',
                        'ppr.product_id',
                    ])
                    ->orderBy('pr.invoice_date_bs')
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
                        'pr.invoice_date_bs as date',
                        'pr.invoice_number as bill_number',
                        'c.party_name as supplier_name',
                        'c.pan_number as supplier_pan',
                        'ppr.product_name as product_service_name',
                        'ppr.product_id as product_id',
                        DB::raw('SUM(ppr.quantity) as product_quantity'),
                        DB::raw('SUM(ppr.price) as total_sales'),
                        DB::raw('SUM(CASE WHEN ppr.is_vatable = 0 THEN ppr.price ELSE 0 END) as non_taxable'),
                        DB::raw('SUM(CASE WHEN ppr.is_vatable = 1 THEN ppr.price ELSE 0 END) as taxable'),
                        DB::raw('SUM(CASE WHEN ppr.is_vatable = 1 THEN ROUND(ppr.price * .13,2) ELSE 0 END) as vat_amount'),
                    ])->where('ppr.company_id', $request->company_id)
                    ->when(isset($request->month) && isset($request->year), function ($query) use ($request) {
                        $query->whereRaw(' CAST(SUBSTRING(invoice_date_bs, 6, 2) AS UNSIGNED) = ?', $request->input('month'))->whereRaw('SUBSTRING(invoice_date_bs, 1, 4) = ?', $request->input('year'));
                    })
                    ->groupBy([
                        'pr.invoice_date_bs',
                        'pr.invoice_number',
                        'c.party_name',
                        'c.pan_number',
                        'ppr.product_name',
                        'ppr.product_id',
                    ])
                    ->orderBy('pr.invoice_date_bs')
                    ->get();
                $items->each(function ($item) {
                    $product = Product::findOrFail($item->product_id);
                    $item->primary_unit_name = $product->getPrimaryMeasureUnitAttribute()->name;
                });
            }
            return response()->json($items);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function vatReturnDataListDetails(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'month' => 'required|numeric',
                'year' => 'required|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if (Helper::checkDataInCache($request->fullUrlWithQuery($request->all()))) {
                return response()->json(Helper::getDataFromCache($request->fullUrlWithQuery($request->all())));
            }

            $applyFilters = function ($query) use ($request) {
                $query->when(isset($request->month) && isset($request->year), function ($query1) use ($request) {
                    $query1->whereRaw(' CAST(SUBSTRING(invoice_date_bs, 6, 2) AS UNSIGNED) = ?', $request->input('month'))->whereRaw('SUBSTRING(invoice_date_bs, 1, 4) = ?', $request->input('year'));
                });
            };

            $sale_taxable_amount = Sale::tap($applyFilters)->sum('taxable_amount');

            $purchase_taxable_amount = Purchase::tap($applyFilters)->sum('taxable_amount');

            $sale_return_amount = SalesReturn::tap($applyFilters)->sum('taxable_amount');

            $report = [
                'sales' => [
                    'vatable' => round($sale_taxable_amount, 2),
                    'non_vatable' => round(Sale::tap($applyFilters)->sum('non_taxable_amount'), 2),
                    'export' => 0,
                    'vat' => round($sale_taxable_amount * 0.13, 2),
                ],
                'purchase' => [
                    'vatable' => round($purchase_taxable_amount, 2),
                    'non_vatable' => round(Purchase::tap($applyFilters)->sum('non_taxable_amount'), 2),
                    'vatable_import' => round(0 * 0.13, 2),
                    'non_vatable_import' => round(0 * 0.13, 2),
                    'vat' => round($purchase_taxable_amount * 0.13, 2),
                ],
                'bill' => [
                    'purchase' => Purchase::tap($applyFilters)->count('id'),
                    'purchase_return' => PurchaseReturn::tap($applyFilters)->count('id'),
                    'sale_return' => SalesReturn::tap($applyFilters)->count('id'),
                    'sale_return_advice' => round(0 * 0.13, 2),
                    'purchase_return_advice' => round(0 * 0.13, 2),
                    'sale' => Sale::tap($applyFilters)->count('id'),
                ],
                'other' => [
                    'purchase_return_vat' => round(0 * 0.13, 2),
                    'sale_return_vat' => round($sale_return_amount * 0.13, 2),
                    'customer_return_vat' => round(0 * 0.13, 2),
                ],
                'net_payable_amount' => round(($sale_taxable_amount * 0.13) - ($purchase_taxable_amount * 0.13) - ($sale_return_amount * 0.13), 2),
            ];
            Helper::applyCache($request->fullUrlWithQuery($request->all()), $report);
            return response()->json($report);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function purchaseSalesBookListDetail(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'from_date' => 'required',
                'to_date' => 'required',
                'type' => 'required|in:sales,sales_return,purchase,purchase_return',
                'customer_id' => 'nullable|exists:customers,id',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if (Helper::checkDataInCache($request->fullUrlWithQuery($request->all()))) {
                return response()->json(Helper::getDataFromCache($request->fullUrlWithQuery($request->all())));
            }

            $fromDate = $request->input('from_date');
            $toDate = $request->input('to_date');
            $customerId = $request->input('customer_id');

            // Helper function to apply filters
            $applyFilters = function ($query) use ($fromDate, $toDate, $customerId) {

                if ($fromDate) {
                    $query->where("{$query->from}.invoice_date_bs", '>=', $fromDate);
                }
                if ($toDate) {
                    $query->where("{$query->from}.invoice_date_bs", '<=', $toDate);
                }
                if ($customerId) {
                    $query->where("{$query->from}.customer_id", $customerId);
                }
            };

            $items = match ($request->type) {
                'purchase' => Purchase::selectRaw('invoice_date_bs as tr_date,
                            purchase_bill_number as bill_number,
                            sub_total_before_discount as before_vat_amt,
                            vat_percent as vat_amount,
                            total_amount,
                            customers.pan_number as pan,
                            taxable_amount as taxable_sales,
                            non_taxable_amount as non_taxable_sales,
                            document_number as voucher_number,
                            customers.party_name as party_name,
        "Purchase" as type')->leftJoin("customers", "customers.id", "=", "purchases.customer_id")
                    ->tap($applyFilters)
                    ->get(),
                'purchase_return' => PurchaseReturn::selectRaw('
                                        invoice_date_bs as tr_date,
                                        purchase_bill_number as bill_number,
                                        sub_total_before_discount as before_vat_amt,
                                        vat_percent AS vat_amount,
                                        total_amount AS total_amount,
                                        customers.pan_number as pan,
                                        taxable_amount as taxable_sales,
                                        non_taxable_amount as non_taxable_sales,
                                        remarks as voucher_number,
                                        customers.party_name as party_name,
                                        "Purchase Return" as type
                                ')->leftJoin("customers", "customers.id", "=", "purchase_returns.customer_id")
                    ->get(),
                'sales' => Sale::selectRaw('
        invoice_date_bs as tr_date,
        invoice_number as bill_number,
        sub_total_before_discount as before_vat_amt,
        IFNULL(taxable_amount * 0.13, 0) as vat_amount, -- Adjust VAT rate as needed
        total_amount,
        customers.pan_number as pan,
        taxable_amount as taxable_sales,
        non_taxable_amount as non_taxable_sales,
        document_number as voucher_number,
         customers.party_name as party_name,
        "Sales" as type
    ')->leftJoin("customers", "customers.id", "=", "sales.customer_id")
                    ->get(),
                'sales_return' => SalesReturn::selectRaw('
        invoice_date_bs as tr_date,
        invoice_number as bill_number,
        sub_total_before_discount as before_vat_amt,
        IFNULL(taxable_amount * 0.13, 0) as vat_amount,
        total_amount as total_amount,
        customers.pan_number as pan,
        taxable_amount as taxable_sales,
        non_taxable_amount as non_taxable_sales,
        document_number as voucher_number,
         customers.party_name as party_name,
        "Sales Return" as type
    ')->leftJoin("customers", "customers.id", "=", "sales_returns.customer_id")
                    ->whereNull('sales_returns.deleted_at')->tap($applyFilters)
                    ->where("sales_returns.company_id", $request->company_id)
                    ->get(),
            };

            // Merge all collections
            $report = collect()
                ->merge($items)
                ->sortBy([
                    ['tr_date', 'asc'],
                    ['bill_number', 'asc'],
                ])
                ->values(); // Re-index

            Helper::applyCache($request->fullUrlWithQuery($request->all()), $report);
            return response()->json($report);

        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }


    }

    public function grossProfitRatioListDetails(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'from_date' => 'required',
                'to_date' => 'required',
                'type' => 'required|string|in:list,download',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            if ($request->type === "list") {

                if (Helper::checkDataInCache($request->fullUrlWithQuery($request->all()))) {
                    return response()->json(Helper::getDataFromCache($request->fullUrlWithQuery($request->all())));
                }
                $items = ProductReport::stockRegisterListDetails($request->all());
                $items = $items->paginate(250);
                $items->getCollection()->transform(function ($item) {
                    return $item->append(['opening_quantity', 'opening_rate', 'purchase_detail', 'sale_detail', 'purchase_return_detail', 'sale_return_detail']);
                });
                Helper::applyCache($request->fullUrlWithQuery($request->all()), $items);
                return response()->json($items);
            } else if ($request->type === "download") {
                $user = $request->user();
                $tokenId = $user->currentAccessToken()->id;
                GrossProfitListExportJob::dispatch($tokenId, $request->fullUrlWithQuery($request->all()));
                return response()->json([
                    'message' => 'Gross Profit List export started. You will receive a download link when it is ready.',
                ]);

            }
            return response()->json([]);

        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }



}

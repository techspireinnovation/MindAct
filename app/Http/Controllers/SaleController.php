<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Helpers\Helper;
use App\Models\SaleProduct;
use App\Models\MeasureUnit;
use App\Models\SaleAdditional;
use App\Models\SalesReturnProduct;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SalesProductFieldValue;
use App\Models\PurchaseProductFieldValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\ProductField;
use App\Models\Purchase;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;


use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

use Carbon\Carbon;


class SaleController extends Controller
{
    public function generateUniqueInvoiceNumber(Request $request): JsonResponse
    {
        // Prefix for the invoice number
        $prefix = 'INV';

        // Calculate fiscal year based on invoice_date or current date
        $date = Carbon::now();

        $fiscal_year_start = Carbon::create($date->year, 7, 16);
        $fiscalYear = $date->lessThan($fiscal_year_start)
            ? ($date->year - 1) . '-' . substr($date->year, 2, 2)
            : $date->year . '-' . substr($date->year + 1, 2, 2);

        // Extract the starting year from fiscal year (e.g., '2025' from '2025-26')
        $year = substr($fiscalYear, 0, 4);

        // Lock the sales_returns table to prevent race conditions
        return DB::transaction(function () use ($prefix, $year, $fiscalYear) {
            // Log the start of invoice number generation
            Log::info('Generating unique invoice number', [
                'prefix' => $prefix,
                'year' => $year,
                'fiscalYear' => $fiscalYear,
                'date' => now()->toDateTimeString()
            ]);

            // Find the latest invoice number in sales_returns (including soft-deleted)
            $latestInvoice = Sale::withTrashed()
                ->where('invoice_number', 'like', "{$prefix}-{$year}-%")
                ->orderBy('invoice_number', 'desc')
                ->first();

            // Extract the sequence number from the latest invoice or start at 0
            $sequence = 0;
            if ($latestInvoice && preg_match("/{$prefix}-{$year}-(\d+)/", $latestInvoice->invoice_number, $matches)) {
                $sequence = (int) $matches[1];
                Log::debug('Found latest invoice number', [
                    'invoice_number' => $latestInvoice->invoice_number,
                    'sequence' => $sequence
                ]);
            } else {
                Log::debug('No existing invoice numbers found for the year', [
                    'year' => $year
                ]);
            }

            // Increment the sequence
            $newSequence = $sequence + 1;

            // Format the new invoice number with leading zeros (6 digits)
            $formattedSequence = str_pad($newSequence, 6, '0', STR_PAD_LEFT);

            // Construct the new invoice number
            $newInvoiceNumber = "{$prefix}-{$year}-{$formattedSequence}";

            // Log the generated invoice number
            Log::info('Generated invoice number', [
                'new_invoice_number' => $newInvoiceNumber,
                'new_sequence' => $newSequence
            ]);

            // Double-check uniqueness in sales_returns (including soft-deleted)
            while (Sale::withTrashed()->where('invoice_number', $newInvoiceNumber)->exists()) {
                $newSequence++;
                $formattedSequence = str_pad($newSequence, 6, '0', STR_PAD_LEFT);
                $newInvoiceNumber = "{$prefix}-{$year}-{$formattedSequence}";
                Log::warning('Invoice number already exists, incrementing sequence', [
                    'new_invoice_number' => $newInvoiceNumber,
                    'new_sequence' => $newSequence
                ]);
            }

            return response()->json(['invoice_number' => $newInvoiceNumber]);
        });
    }


    private function getAvailableProductsForSale($companyId)
    {
        Log::debug('Fetching available products for sale', ['company_id' => $companyId]);

        try {
            DB::enableQueryLog();

            // Subquery for purchase_products quantities
            $purchaseSubQuery = DB::table('purchase_products as pp')
                ->select([
                    'pp.product_id',
                    DB::raw('MIN(pp.product_name) as purchase_product_name'),
                    DB::raw('SUM((pp.quantity + COALESCE(pp.free_quantity, 0)) * COALESCE(mu.quantity, 1)) as purchased_quantity')
                ])
                ->leftJoin('measure_units as mu', 'pp.measure_unit_id', '=', 'mu.id')
                ->whereNull('pp.deleted_at')
                ->where('pp.company_id', $companyId)
                ->groupBy('pp.product_id');

            // Subquery for sale_products quantities
            $saleSubQuery = DB::table('sale_products as sp')
                ->select([
                    'sp.product_id',
                    DB::raw('SUM((sp.quantity + COALESCE(sp.free_quantity, 0)) * COALESCE(mu.quantity, 1)) as sale_quantity')
                ])
                ->join('measure_units as mu', 'sp.measure_unit_id', '=', 'mu.id')
                ->whereNull('sp.deleted_at')
                ->where('sp.company_id', $companyId)
                ->where('mu.company_id', $companyId)
                ->whereNull('mu.deleted_at')
                ->groupBy('sp.product_id');

            // Subquery for purchase_product_returns quantities
            $returnSubQuery = DB::table('purchase_product_returns as ppr')
                ->select([
                    'pp.product_id',
                    DB::raw('SUM((ppr.quantity + COALESCE(ppr.free_quantity, 0)) * COALESCE(mu.quantity, 1)) as return_quantity')
                ])
                ->join('purchase_products as pp', 'ppr.purchase_product_id', '=', 'pp.id')
                ->join('measure_units as mu', 'ppr.measure_unit_id', '=', 'mu.id')
                ->whereNull('ppr.deleted_at')
                ->where('ppr.company_id', $companyId)
                ->where('mu.company_id', $companyId)
                ->whereNull('mu.deleted_at')
                ->groupBy('pp.product_id');

            // Subquery for sales_return_products quantities
            $salesReturnSubQuery = DB::table('sales_return_products as srp')
                ->select([
                    'sp.product_id',
                    DB::raw('SUM((srp.quantity + COALESCE(srp.free_quantity, 0)) * COALESCE(mu.quantity, 1)) as sales_return_quantity')
                ])
                ->join('sale_products as sp', 'srp.sale_product_id', '=', 'sp.id')
                ->join('measure_units as mu', 'srp.measure_unit_id', '=', 'mu.id')
                ->whereNull('srp.deleted_at')
                ->where('srp.company_id', $companyId)
                ->where('mu.company_id', $companyId)
                ->whereNull('mu.deleted_at')
                ->groupBy('sp.product_id');

            // Log purchase product IDs
            $purchaseProductIds = $purchaseSubQuery->pluck('product_id')->toArray();
            Log::debug('Purchase subquery product IDs', ['product_ids' => $purchaseProductIds]);

            // Main query
            $mainQuery = DB::table(DB::raw("({$purchaseSubQuery->toSql()}) as purchase_totals"))
                ->select([
                    'products.id',
                    DB::raw('COALESCE(products.name, purchase_totals.purchase_product_name) as name'),
                    'purchase_totals.purchased_quantity',
                    'return_totals.return_quantity',
                    'sale_totals.sale_quantity',
                    'sales_return_totals.sales_return_quantity'
                ])
                ->mergeBindings($purchaseSubQuery)
                ->leftJoin('products', function ($join) use ($companyId) {
                    $join->on('purchase_totals.product_id', '=', 'products.id')
                        ->where(function ($query) use ($companyId) {
                            $query->where('products.company_id', $companyId)
                                ->orWhereNull('products.company_id');
                        })
                        ->whereNull('products.deleted_at');
                })
                ->leftJoin(DB::raw("({$saleSubQuery->toSql()}) as sale_totals"), 'purchase_totals.product_id', '=', 'sale_totals.product_id')
                ->mergeBindings($saleSubQuery)
                ->leftJoin(DB::raw("({$returnSubQuery->toSql()}) as return_totals"), 'purchase_totals.product_id', '=', 'return_totals.product_id')
                ->mergeBindings($returnSubQuery)
                ->leftJoin(DB::raw("({$salesReturnSubQuery->toSql()}) as sales_return_totals"), 'purchase_totals.product_id', '=', 'sales_return_totals.product_id')
                ->mergeBindings($salesReturnSubQuery);

            // Final query
            $query = DB::table(DB::raw("({$mainQuery->toSql()}) as main"))
                ->select([
                    'main.id',
                    'main.name',
                    'main.purchased_quantity',
                    DB::raw('COALESCE(main.return_quantity, 0) as return_quantity'),
                    DB::raw('COALESCE(main.sale_quantity, 0) as sale_quantity'),
                    DB::raw('COALESCE(main.sales_return_quantity, 0) as sales_return_quantity'),
                    DB::raw('main.purchased_quantity - COALESCE(main.return_quantity, 0) - COALESCE(main.sale_quantity, 0) + COALESCE(main.sales_return_quantity, 0) as available_quantity')
                ])
                ->mergeBindings($mainQuery)
                ->whereRaw('main.purchased_quantity - COALESCE(main.return_quantity, 0) - COALESCE(main.sale_quantity, 0) + COALESCE(main.sales_return_quantity, 0) > 0');

            $products = $query->get();

            Log::debug('Available products query', [
                'sql' => DB::getQueryLog(),
                'results_count' => $products->count(),
                'products' => $products->toArray()
            ]);

            return $products;

        } catch (\Exception $e) {
            Log::error('Error fetching available products for sale', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        } finally {
            DB::disableQueryLog();
        }
    }



    public function listAvailableProducts(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer|exists:companies,id',
                'include_details' => 'nullable|boolean'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors()
                ], 422);
            }

            $companyId = $request->input('company_id');
            $includeDetails = $request->boolean('include_details', false);

            if (auth()->check()) {
                $user = auth()->user();
                $userCompanyId = optional($user->company)->company_id;

                if (!$userCompanyId || $userCompanyId != $companyId) {
                    return response()->json([
                        'message' => 'Unauthorized access to company resources'
                    ], 403);
                }
            } else {
                return response()->json([
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $products = $includeDetails
                ? collect($this->getAvailableProductsDetails(null, null, $companyId)['data'])
                : $this->getAvailableProductsForSale($companyId);

            return response()->json([
                'message' => 'Available products retrieved successfully',
                'count' => $products->count(),
                'data' => $products
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error listing available products', [
                'request' => $request->all(),
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'message' => 'Failed to retrieve available products',
                'error' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    public function getAvailableProductByIdOrName(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'nullable|integer|exists:products,id',
                'product_name' => 'nullable|string|max:255',
                'company_id' => 'required|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $productId = $request->input('product_id');
            $productName = trim(strtolower($request->input('product_name')));
            $companyId = $request->input('company_id');

            Log::debug('Input parameters', [
                'product_id' => $productId,
                'product_name' => $productName,
                'company_id' => $companyId
            ]);

            if (!$productId && !$productName) {
                return response()->json(['error' => 'Either product_id or product_name is required'], 422);
            }

            if (auth()->check()) {
                $user = auth()->user();
                $userCompanyId = optional($user->company)->company_id;
                if ($userCompanyId != $companyId) {
                    return response()->json(['error' => 'Unauthorized access to company resources'], 403);
                }
            } else {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $products = $this->getAvailableProductsDetails($productId, $productName, $companyId);

            return response()->json([
                'message' => !empty($products['data']) ? 'Product details retrieved' : 'No matching product found',
                'data' => $products['data'] ?: null
            ], !empty($products['data']) ? 200 : 404);

        } catch (ModelNotFoundException $e) {
            Log::error('Model not found in getAvailableProductByIdOrName', [
                'product_id' => $productId,
                'product_name' => $productName,
                'company_id' => $companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['message' => 'No matching product found', 'data' => []], 404);
        } catch (QueryException $e) {
            Log::error('Database query error in getAvailableProductByIdOrName', [
                'product_id' => $productId,
                'product_name' => $productName,
                'company_id' => $companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Database query error', 'message' => config('app.debug') ? $e->getMessage() : null], 500);
        } catch (\Exception $e) {
            Log::error('Error in getAvailableProductByIdOrName', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);
            return response()->json([
                'error' => 'An unexpected error occurred',
                'message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    private function getAvailableProductsDetails(?int $productId = null, ?string $productName = null, ?int $companyId = null): array
    {
        Log::debug('Fetching detailed available products with purchase products', [
            'product_id' => $productId,
            'product_name' => $productName,
            'company_id' => $companyId
        ]);

        try {
            DB::enableQueryLog();

            // Main query for product totals
            $purchaseSubQuery = DB::table('purchase_products')
                ->select([
                    'purchase_products.product_id',
                    DB::raw('MIN(purchase_products.product_name) as product_name'),
                    DB::raw('MIN(purchase_products.product_code) as product_code'),
                    DB::raw('MIN(products.measure_unit_id) as measure_unit_id'),
                    DB::raw('MIN(product_measure_units.name) as measure_unit_name'),
                    DB::raw('MIN(product_measure_units.quantity) as measure_unit_quantity'),
                    DB::raw('MIN(purchase_products.price) as min_price'),
                    DB::raw('MAX(purchase_products.is_vatable) as is_vatable'),
                    DB::raw('GROUP_CONCAT(DISTINCT purchase_products.expiry_date ORDER BY purchase_products.expiry_date) as expiry_dates'),
                    DB::raw('SUM((purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) * COALESCE(purchase_measure_units.quantity, 1)) as purchased_quantity')
                ])
                ->leftJoin('products', 'purchase_products.product_id', '=', 'products.id')
                ->leftJoin('measure_units as product_measure_units', 'products.measure_unit_id', '=', 'product_measure_units.id')
                ->leftJoin('measure_units as purchase_measure_units', 'purchase_products.measure_unit_id', '=', 'purchase_measure_units.id')
                ->whereNull('purchase_products.deleted_at')
                ->where('purchase_products.company_id', $companyId)
                ->where(function ($query) use ($companyId) {
                    $query->where('products.company_id', $companyId)
                        ->orWhereNull('products.company_id');
                })
                ->whereNull('products.deleted_at')
                ->groupBy('purchase_products.product_id');

            $saleSubQuery = DB::table('sale_products')
                ->select([
                    'sale_products.product_id',
                    DB::raw('SUM((sale_products.quantity + COALESCE(sale_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)) as sale_quantity')
                ])
                ->leftJoin('measure_units', 'sale_products.measure_unit_id', '=', 'measure_units.id')
                ->whereNull('sale_products.deleted_at')
                ->where('sale_products.company_id', $companyId)
                ->groupBy('sale_products.product_id');

            $returnSubQuery = DB::table('purchase_product_returns')
                ->select([
                    'purchase_products.product_id',
                    DB::raw('SUM((purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)) as return_quantity')
                ])
                ->join('purchase_products', 'purchase_product_returns.purchase_product_id', '=', 'purchase_products.id')
                ->leftJoin('measure_units', 'purchase_product_returns.measure_unit_id', '=', 'measure_units.id')
                ->whereNull('purchase_product_returns.deleted_at')
                ->where('purchase_product_returns.company_id', $companyId)
                ->groupBy('purchase_products.product_id');

            $salesReturnSubQuery = DB::table('sales_return_products')
                ->select([
                    'sale_products.product_id',
                    DB::raw('SUM((sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)) as sales_return_quantity')
                ])
                ->join('sale_products', 'sales_return_products.sale_product_id', '=', 'sale_products.id')
                ->leftJoin('measure_units', 'sales_return_products.measure_unit_id', '=', 'measure_units.id')
                ->whereNull('sales_return_products.deleted_at')
                ->where('sales_return_products.company_id', $companyId)
                ->groupBy('sale_products.product_id');

            $mainQuery = DB::table(DB::raw("({$purchaseSubQuery->toSql()}) as purchase_totals"))
                ->select([
                    'purchase_totals.product_id',
                    DB::raw('COALESCE(products.name, purchase_totals.product_name) as product_name'),
                    'purchase_totals.product_code',
                    'purchase_totals.min_price',
                    'purchase_totals.is_vatable',
                    'purchase_totals.measure_unit_id',
                    'purchase_totals.measure_unit_name',
                    'purchase_totals.measure_unit_quantity',
                    'purchase_totals.purchased_quantity',
                    'purchase_totals.expiry_dates',
                    DB::raw('COALESCE(return_totals.return_quantity, 0) as return_quantity'),
                    DB::raw('COALESCE(sale_totals.sale_quantity, 0) as sale_quantity'),
                    DB::raw('COALESCE(sales_return_totals.sales_return_quantity, 0) as sales_return_quantity'),
                    DB::raw('purchase_totals.purchased_quantity - 
                             COALESCE(return_totals.return_quantity, 0) - 
                             COALESCE(sale_totals.sale_quantity, 0) + 
                             COALESCE(sales_return_totals.sales_return_quantity, 0) as available_quantity')
                ])
                ->mergeBindings($purchaseSubQuery)
                ->leftJoin('products', function ($join) use ($companyId) {
                    $join->on('purchase_totals.product_id', '=', 'products.id')
                        ->where('products.company_id', $companyId)
                        ->whereNull('products.deleted_at');
                })
                ->leftJoin(DB::raw("({$saleSubQuery->toSql()}) as sale_totals"), 'purchase_totals.product_id', '=', 'sale_totals.product_id')
                ->mergeBindings($saleSubQuery)
                ->leftJoin(DB::raw("({$returnSubQuery->toSql()}) as return_totals"), 'purchase_totals.product_id', '=', 'return_totals.product_id')
                ->mergeBindings($returnSubQuery)
                ->leftJoin(DB::raw("({$salesReturnSubQuery->toSql()}) as sales_return_totals"), 'purchase_totals.product_id', '=', 'sales_return_totals.product_id')
                ->mergeBindings($salesReturnSubQuery);

            if ($productId) {
                $mainQuery->where('purchase_totals.product_id', $productId);
            }

            if ($productName) {
                $mainQuery->where(function ($db) use ($productName) {
                    $db->where('purchase_totals.product_name', 'like', "%{$productName}%")
                        ->orWhere('products.name', 'like', "%{$productName}%");
                });
            }

            $products = $mainQuery->get();

            if ($products->isEmpty()) {
                Log::warning('No products found in main query', [
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'company_id' => $companyId,
                    'query_log' => DB::getQueryLog()
                ]);
                return ['message' => 'No available products found', 'data' => []];
            }

            $productIds = $products->pluck('product_id')->toArray();

            // Fetch purchase products with FIFO ordering
            $purchaseProductsQuery = DB::table('purchase_products')
                ->select([
                    'purchase_products.id as purchase_product_id',
                    'purchase_products.purchase_id',
                    'purchase_products.product_id',
                    'purchase_products.product_name',
                    'purchase_products.product_code',
                    'purchase_products.quantity',
                    // 'purchase_products.batch_no',
                    'purchase_products.mfd',
                    'purchase_products.free_quantity',
                    'purchase_products.expiry_date',
                    'purchase_products.price',
                    'purchase_products.is_vatable',
                    'purchase_products.measure_unit_id',
                    'measure_units.name as measure_unit_name',
                    'measure_units.quantity as measure_unit_quantity',
                    'purchases.purchase_bill_number',
                    'purchases.invoice_date',
                    DB::raw('COALESCE(SUM((purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)) * COALESCE(return_measure_units.quantity, 1)), 0) as return_quantity'),
                    DB::raw('COALESCE(SUM((sale_products.quantity + COALESCE(sale_products.free_quantity, 0)) * COALESCE(sale_measure_units.quantity, 1)), 0) as sale_quantity'),
                    DB::raw('COALESCE(SUM((sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)) * COALESCE(sales_return_measure_units.quantity, 1)), 0) as sales_return_quantity'),
                    DB::raw('(
                        ((purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1))
                        - COALESCE(SUM((purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)) * COALESCE(return_measure_units.quantity, 1)), 0)
                        - COALESCE(SUM((sale_products.quantity + COALESCE(sale_products.free_quantity, 0)) * COALESCE(sale_measure_units.quantity, 1)), 0)
                        + COALESCE(SUM((sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)) * COALESCE(sales_return_measure_units.quantity, 1)), 0)
                    ) as available_quantity')
                ])
                ->join('measure_units', 'purchase_products.measure_unit_id', '=', 'measure_units.id')
                ->leftJoin('purchases', function ($join) use ($companyId) {
                    $join->on('purchase_products.purchase_id', '=', 'purchases.id')
                        ->where('purchases.company_id', $companyId)
                        ->whereNull('purchases.deleted_at');
                })
                ->leftJoin('purchase_product_returns', function ($join) use ($companyId) {
                    $join->on('purchase_products.id', '=', 'purchase_product_returns.purchase_product_id')
                        ->whereNull('purchase_product_returns.deleted_at')
                        ->where('purchase_product_returns.company_id', $companyId);
                })
                ->leftJoin('measure_units as return_measure_units', 'purchase_product_returns.measure_unit_id', '=', 'return_measure_units.id')
                ->leftJoin('sale_products', function ($join) use ($companyId) {
                    $join->on('purchase_products.id', '=', 'sale_products.purchase_product_id')
                        ->whereNull('sale_products.deleted_at')
                        ->where('sale_products.company_id', $companyId);
                })
                ->leftJoin('sales_return_products', function ($join) use ($companyId) {
                    $join->on('sale_products.id', '=', 'sales_return_products.sale_product_id')
                        ->whereNull('sales_return_products.deleted_at')
                        ->where('sales_return_products.company_id', $companyId);
                })
                ->leftJoin('measure_units as sale_measure_units', 'sale_products.measure_unit_id', '=', 'sale_measure_units.id')
                ->leftJoin('measure_units as sales_return_measure_units', 'sales_return_products.measure_unit_id', '=', 'sales_return_measure_units.id')
                ->whereIn('purchase_products.product_id', $productIds)
                ->where('purchase_products.company_id', $companyId)
                ->whereNull('purchase_products.deleted_at')
                ->groupBy([
                    'purchase_products.id',
                    'purchase_products.purchase_id',
                    'purchase_products.product_id',
                    'purchase_products.product_name',
                    'purchase_products.product_code',
                    'purchase_products.quantity',
                    'purchase_products.free_quantity',
                    'purchase_products.expiry_date',
                    'purchase_products.mfd',
                    'purchase_products.price',
                    'purchase_products.is_vatable',
                    'purchase_products.measure_unit_id',
                    'measure_units.name',
                    'measure_units.quantity',
                    'purchases.purchase_bill_number',
                    'purchases.invoice_date'
                ])
                ->orderBy('purchases.invoice_date', 'asc')
                ->orderBy('purchase_products.created_at', 'asc');

            $purchaseProducts = $purchaseProductsQuery->get();

            // Fetch sold quantity indexes
            // Fetch sold quantity indexes
            $soldQuantityIndexes = DB::table('sales_product_field_values')
                ->select([
                    'sale_products.purchase_product_id',
                    'sales_product_field_values.quantity_index'
                ])
                ->join('sale_products', 'sales_product_field_values.sale_product_id', '=', 'sale_products.id')
                ->leftJoin('product_field_values', function ($join) use ($companyId, $productIds) {
                    $join->on('sales_product_field_values.product_field_id', '=', 'product_field_values.product_field_id')
                        ->on('sales_product_field_values.value', '=', 'product_field_values.value')
                        ->where('product_field_values.company_id', $companyId)
                        ->whereIn('product_field_values.product_id', $productIds);
                })
                ->whereIn('sale_products.purchase_product_id', $purchaseProducts->pluck('purchase_product_id'))
                ->where('sale_products.company_id', $companyId)
                ->whereNull('sale_products.deleted_at')
               
                ->distinct()
                ->get()
                ->groupBy('purchase_product_id')
                ->map(function ($group) {
                    return $group->pluck('quantity_index')->toArray();
                });

            // Fetch returned quantity indexes
            $returnedQuantityIndexes = DB::table('purchase_return_product_field_values')
                ->select([
                    'purchase_product_returns.purchase_product_id',
                    'purchase_return_product_field_values.quantity_index'
                ])
                ->join('purchase_product_returns', 'purchase_return_product_field_values.purchase_return_product_id', '=', 'purchase_product_returns.id')
                ->whereIn('purchase_product_returns.purchase_product_id', $purchaseProducts->pluck('purchase_product_id'))
                ->where('purchase_product_returns.company_id', $companyId)
                ->whereNull('purchase_product_returns.deleted_at')
                ->distinct()
                ->get()
                ->groupBy('purchase_product_id')
                ->map(function ($group) {
                    return $group->pluck('quantity_index')->toArray();
                });

            Log::debug('Quantity indexes', [
                'sold_quantity_indexes' => $soldQuantityIndexes,
                'returned_quantity_indexes' => $returnedQuantityIndexes
            ]);

            // Fetch field values
            $fieldValues = DB::table('purchase_product_field_values')
                ->select([
                    'purchase_product_field_values.purchase_product_id',
                    'purchase_product_field_values.product_field_id',
                    'product_fields.name as product_field_name',
                    'purchase_product_field_values.value',
                    'purchase_product_field_values.quantity_index'
                ])
                ->join('product_fields', function ($join) use ($companyId) {
                    $join->on('purchase_product_field_values.product_field_id', '=', 'product_fields.id')
                        ->where('product_fields.company_id', $companyId)
                        ->whereNull('product_fields.deleted_at');
                })
                ->join('product_field_values', function ($join) use ($companyId, $productIds) {
                    $join->on('purchase_product_field_values.product_field_id', '=', 'product_field_values.product_field_id')
                      
                        ->where('product_field_values.company_id', $companyId)
                        ->whereIn('product_field_values.product_id', $productIds)
                        ->whereNull('product_field_values.deleted_at');
                })
                ->whereIn('purchase_product_field_values.purchase_product_id', $purchaseProducts->pluck('purchase_product_id'))
                ->where('purchase_product_field_values.company_id', $companyId)
                ->whereNull('purchase_product_field_values.deleted_at')
                ->orderBy('purchase_product_field_values.quantity_index', 'asc')
                ->get()
                ->groupBy('purchase_product_id');

            $result = $products->map(function ($product) use ($purchaseProducts, $fieldValues, $soldQuantityIndexes, $returnedQuantityIndexes, $companyId) {
                $productId = $product->product_id;
                $salesPrice = SaleProduct::where('product_id', $productId)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->pluck('price');
                $lastSalesPrice = SaleProduct::where('product_id', $productId)
                    ->whereNull('deleted_at')
                    ->orderByDesc('created_at')
                    ->value('price');

                $averageSalesPrice = round($salesPrice->avg(), 2);
                $minSalesPrice = round($salesPrice->min(), 2);
                $latesrSalesPrice = round($lastSalesPrice, 2);

                $productFieldValues = collect();
                $productPurchaseProducts = $purchaseProducts->filter(function ($pp) use ($productId) {
                    return $pp->product_id == $productId;
                })->map(function ($pp) use ($fieldValues, $soldQuantityIndexes, $returnedQuantityIndexes, &$productFieldValues) {
                    $availableUnits = floor($pp->available_quantity / ($pp->measure_unit_quantity ?? 1));
                    if ($availableUnits > 0 && isset($fieldValues[$pp->purchase_product_id])) {
                        $soldIndexes = $soldQuantityIndexes[$pp->purchase_product_id] ?? [];
                        $returnedIndexes = $returnedQuantityIndexes[$pp->purchase_product_id] ?? [];
                        $excludedIndexes = array_unique(array_merge($soldIndexes, $returnedIndexes));
                        $ppFieldValues = $fieldValues[$pp->purchase_product_id]->filter(function ($fv) use ($excludedIndexes) {
                            return !in_array($fv->quantity_index, $excludedIndexes);
                        })->map(function ($fv) {
                            return [
                                'purchase_product_id' => $fv->purchase_product_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->product_field_name,
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ];
                        })->values();
                        $productFieldValues = $productFieldValues->merge($ppFieldValues);
                    }

                    return [
                        'purchase_product_id' => $pp->purchase_product_id,
                        'purchase_id' => $pp->purchase_id,
                        'purchase_bill_number' => $pp->purchase_bill_number,
                        'invoice_date' => $pp->invoice_date,
                        'product_id' => $pp->product_id,
                        'product_name' => $pp->product_name,
                        'product_code' => $pp->product_code,
                        // 'batch_no' => $pp->batch_no,
                        'mfd' => $pp->mfd,
                        'quantity' => $pp->quantity,
                        'free_quantity' => $pp->free_quantity ?? 0,
                        'price' => $pp->price,
                        'is_vatable' => (bool) $pp->is_vatable,
                        'measure_unit_id' => $pp->measure_unit_id,
                        'measure_unit_name' => $pp->measure_unit_name,
                        'measure_unit_quantity' => $pp->measure_unit_quantity,
                        'expiry_date' => $pp->expiry_date,
                        'return_quantity' => $pp->return_quantity,
                        'sale_quantity' => $pp->sale_quantity,
                        'sales_return_quantity' => $pp->sales_return_quantity,
                        'available_quantity' => max($pp->available_quantity, 0)
                    ];
                })->filter()->values()->toArray();

                return [
                    'product_id' => $product->product_id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    'min_price' => $product->min_price,
                    'is_vatable' => (bool) $product->is_vatable,
                    'measure_unit_id' => $product->measure_unit_id,
                    'avg_sales_price' => $averageSalesPrice,
                    'min_sales_price' => $minSalesPrice,
                    'latest_sales_price' => $latesrSalesPrice,
                    'measure_unit_name' => $product->measure_unit_name,
                    'measure_unit_quantity' => $product->measure_unit_quantity,
                    'purchased_quantity' => $product->purchased_quantity,
                    'return_quantity' => $product->return_quantity,
                    'sale_quantity' => $product->sale_quantity,
                    'sales_return_quantity' => $product->sales_return_quantity,
                    'available_quantity' => max($product->available_quantity, 0),
                    'expiry_dates' => array_filter(explode(',', $product->expiry_dates)),
                    'field_values' => $productFieldValues->toArray(),
                    'purchase_products' => $productPurchaseProducts
                ];
            })->toArray();

            return [
                'message' => 'Product details retrieved',
                'data' => $result
            ];

        } catch (\Exception $e) {
            Log::error('Error fetching detailed available products', [
                'product_id' => $productId,
                'product_name' => $productName,
                'company_id' => $companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog()
            ]);
            throw $e;
        } finally {
            DB::disableQueryLog();
        }
    }

    public function index(Request $request): JsonResponse
    {
        $query = Sale::query();

        if ($request->has('keywords')) {
            $query->where('invoice_number', 'LIKE', '%' . $request->input('keywords') . '%');
        }
        return response()->json($query->paginate(10));
    }


    public function store(Request $request): JsonResponse
    {
        try {
            // Define validation rules
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer|exists:companies,id',
                'customer_id' => 'nullable|integer|exists:customers,id',
                'salesman_id' => 'nullable|integer|exists:salesmen,id',
                'customer_name' => 'required|string|max:255',
                'customer_address' => 'nullable|string|max:255',
                'contact_number' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                'credit_days' => 'nullable|string|max:255',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'invoice_number' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('sales')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'store_id' => 'nullable|integer|exists:stores,id',
                'location_id' => 'nullable|integer|exists:locations,id',
                'sub_total_before_discount' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'discount_after_vat' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'excise_duty' => 'nullable|numeric|min:0',
                'health_insurance' => 'nullable|numeric|min:0',
                'balance' => 'nullable|numeric|min:0',
                'taxable_amount' => 'nullable|numeric|min:0',
                'non_taxable_amount' => 'nullable|numeric|min:0',
                'ref_bill_number' => 'nullable|string|max:255',
                'roundoff_amount' => 'nullable|numeric|max:255',
                'roundoff_type' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'abvt' => 'nullable|boolean',
                'is_vatable' => 'nullable|boolean',
                'total_amount' => 'nullable|numeric|min:0',
                'sell_entire_batch' => 'nullable|boolean',
                'purchase_id' => 'nullable|integer|exists:purchases,id',
                'purchase_bill_number' => 'nullable|string|max:255',
                'batch_no_sale' => 'nullable|string|max:255',
                'sale_products' => [
                    'required_unless:sell_entire_batch,true',
                    'array',
                    'min:1',
                ],
                'sale_products.*.product_name' => 'required_without:sale_products.*.product_id|string|max:255',
                'sale_products.*.product_id' => 'nullable|integer|exists:products,id',
                'sale_products.*.purchase_product_id' => 'nullable|integer|exists:purchase_products,id',
                'sale_products.*.quantity' => 'required|numeric|min:0', // Allow decimals for pints
                'sale_products.*.free_quantity' => 'nullable|numeric|min:0', // Allow decimals
                'sale_products.*.price' => 'required|numeric|min:0',
                'sale_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sale_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sale_products.*.is_vatable' => 'nullable|boolean',
                'sale_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'sale_products.*.batch_no' => 'nullable|string|max:255',
                'sale_products.*.amount' => 'nullable|numeric|min:0',
                'sale_products.*.mfd' => 'nullable|string|max:255',
                'sale_products.*.expiry_date' => 'nullable|string|max:255',
                'sale_products.*.field_values' => 'present|array',
                'sale_products.*.field_values.*' => 'array|min:1',
                'sale_products.*.field_values.*.*.product_field_id' => 'required_if:field_values,array|integer|exists:product_fields,id',
                'sale_products.*.field_values.*.*.value' => 'required_if:field_values,array|string|max:255',
                'sale_products.*.field_values.*.*.quantity_index' => 'required_if:field_values,array|integer|min:0',
                'sale_products.*.field_values.*.*.quantity_type' => 'nullable|string|in:regular,free',
                'sale_products.*.field_values.*.*.purchase_product_id' => 'required_if:field_values,array|integer|exists:purchase_products,id',
                'sale_additionals' => 'nullable|array',
                'sale_additionals.company_id' => 'nullable|integer|exists:companies,id',
                'sale_additionals.sale_id' => 'nullable|string|max:255',
                'sale_additionals.place' => 'nullable|string|max:255',
                'sale_additionals.transport' => 'nullable|string|max:255',
                'sale_additionals.vehicle_number' => 'nullable|string|max:255',
                'sale_additionals.vehicle_name' => 'nullable|string|max:255',
                'sale_additionals.driver_name' => 'nullable|string|max:255',
                'sale_additionals.dispatch_code' => 'required_if:sale_additionals,exists|string|max:255',
                'sale_additionals.driver_contact_number' => 'nullable|string|max:255',
                'sale_additionals.delivery_date' => 'nullable|string|max:255',
                'sale_additionals.delivery_time' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            Log::debug('Sale request input', ['input' => $validated]);

            $hasBatchIdentifier = isset($validated['purchase_id']) || isset($validated['purchase_bill_number']) || isset($validated['batch_no_sale']);
            $sellEntireBatch = $validated['sell_entire_batch'] ?? false;

            if ($sellEntireBatch || $hasBatchIdentifier) {
                if ($sellEntireBatch && !$hasBatchIdentifier) {
                    return response()->json(['error' => 'At least one batch identifier is required when sell_entire_batch is true'], 422);
                }

                $purchaseProducts = collect();

                if (isset($validated['purchase_id'])) {
                    $purchaseProducts = PurchaseProduct::where('purchase_products.purchase_id', $validated['purchase_id'])
                        ->join('purchases', 'purchase_products.purchase_id', '=', 'purchases.id')
                        ->where('purchases.company_id', $validated['company_id'])
                        ->whereNull('purchase_products.deleted_at')
                        ->select('purchase_products.*')
                        ->distinct()
                        ->get();
                } elseif (isset($validated['purchase_bill_number'])) {
                    $purchase = Purchase::where('purchase_bill_number', $validated['purchase_bill_number'])
                        ->where('company_id', $validated['company_id'])
                        ->first();
                    if (!$purchase) {
                        return response()->json(['error' => 'Purchase with specified bill number not found'], 422);
                    }
                    $purchaseProducts = PurchaseProduct::where('purchase_products.purchase_id', $purchase->id)
                        ->join('purchases', 'purchase_products.purchase_id', '=', 'purchases.id')
                        ->whereNull('purchase_products.deleted_at')
                        ->select('purchase_products.*')
                        ->distinct()
                        ->get();
                } elseif (isset($validated['batch_no_sale'])) {
                    $purchaseProducts = PurchaseProduct::whereHas('purchase', function ($query) use ($validated) {
                        $query->where('batch_no', $validated['batch_no_sale'])
                            ->where('company_id', $validated['company_id']);
                    })
                        ->join('purchases', 'purchase_products.purchase_id', '=', 'purchases.id')
                        ->whereNull('purchase_products.deleted_at')
                        ->select('purchase_products.*')
                        ->distinct()
                        ->get();
                }

                if ($purchaseProducts->isEmpty()) {
                    return response()->json(['error' => 'No products found for the specified purchase ID, bill number, or batch number'], 422);
                }

                if ($sellEntireBatch) {
                    $validated['sale_products'] = $purchaseProducts->map(function ($product) use ($validated) {
                        $productModel = Product::find($product->product_id);
                        $measureUnit = MeasureUnit::find($product->measure_unit_id);

                        if (!$measureUnit) {
                            return null;
                        }

                        $measureUnitQuantity = $measureUnit->quantity ?? 1;
                        $totalAvailablePieces = $this->calculateAvailablePieces($product, $measureUnitQuantity, $validated['company_id']);

                        if ($totalAvailablePieces <= 0) {
                            return null;
                        }

                        [$quantityInUOM, $freeQuantityInUOM] = $this->convertToTargetMeasureUnit($totalAvailablePieces, 0, $measureUnitQuantity);

                        $fieldValues = DB::table('purchase_product_field_values')
                            ->where('purchase_product_id', $product->id)
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at')
                            ->select('product_field_id', 'value', 'quantity_index')
                            ->orderBy('quantity_index', 'asc')
                            ->get()
                            ->map(function ($fv) use ($product) {
                                return [
                                    'product_field_id' => $fv->product_field_id,
                                    'value' => $fv->value,
                                    'quantity_index' => $fv->quantity_index,
                                    'quantity_type' => 'regular',
                                    'purchase_product_id' => $product->id
                                ];
                            })->toArray();

                        return [
                            'product_id' => $product->product_id,
                            'product_name' => $productModel->name ?? $product->product_name,
                            'purchase_product_id' => $product->id,
                            'quantity' => $quantityInUOM,
                            'free_quantity' => $freeQuantityInUOM,
                            'price' => $productModel->price ?? $product->price,
                            'discount_percent' => $product->discount_percent ?? 0,
                            'discount_amount' => $product->discount_amount ?? 0,
                            'is_vatable' => $productModel->is_vatable ?? $product->is_vatable,
                            'measure_unit_id' => $product->measure_unit_id,
                            'mfd' => $product->mfd,
                            'batch_no' => 'BATCH-' . $product->id . '-' . now()->format('Ymd'),
                            'expiry_date' => $product->expiry_date,
                            'field_values' => $fieldValues,
                        ];
                    })->filter()->values()->toArray();

                    if (empty($validated['sale_products'])) {
                        return response()->json(['error' => 'No available stock for the specified batch'], 422);
                    }
                }
            }

            $sale = DB::transaction(function () use ($validated) {
                $sale = Sale::create([
                    'company_id' => $validated['company_id'],
                    'customer_id' => $validated['customer_id'] ?? null,
                    'salesman_id' => $validated['salesman_id'],
                    'customer_name' => $validated['customer_name'],
                    'customer_address' => $validated['customer_address'] ?? null,
                    'contact_number' => $validated['contact_number'] ?? null,
                    'pan_number' => $validated['pan_number'] ?? null,
                    'credit_days' => $validated['credit_days'] ?? null,
                    'invoice_number' => $validated['invoice_number'] ?? 'INV-' . now()->format('Ymd') . '-' . rand(1000, 9999),
                    'invoice_date' => $validated['invoice_date'] ?? now(),
                    'invoice_date_bs' => $validated['invoice_date_bs'] ?? now(),
                    'store_id' => $validated['store_id'],
                    'location_id' => $validated['location_id'],
                    'sub_total_before_discount' => $validated['sub_total_before_discount'] ?? 0,
                    'discount' => $validated['discount'] ?? 0,
                    'discount_after_vat' => $validated['discount_after_vat'] ?? 0,
                    'freight_amount' => $validated['freight_amount'] ?? 0,
                    'excise_duty' => $validated['excise_duty'] ?? 0,
                    'health_insurance' => $validated['health_insurance'] ?? 0,
                    'balance' => $validated['balance'] ?? 0,
                    'taxable_amount' => $validated['taxable_amount'] ?? 0,
                    'non_taxable_amount' => $validated['non_taxable_amount'] ?? 0,
                    'ref_bill_number' => $validated['ref_bill_number'] ?? null,
                    'roundoff_amount' => $validated['roundoff_amount'] ?? 0,
                    'roundoff_type' => $validated['roundoff_type'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'abvt' => $validated['abvt'] ?? false,
                    'cash' => $validated['payment']['cash'] ?? 0,
                    'credit' => $validated['payment']['credit'] ?? 0,
                    'bank' => $validated['payment']['bank'] ?? 0,
                    'is_vatable' => $validated['is_vatable'] ?? false,
                    'total_amount' => $validated['total_amount'] ?? 0,
                    'purchase_id' => $validated['purchase_id'] ?? null,
                    'purchase_bill_number' => $validated['purchase_bill_number'] ?? null,
                ]);

                if (isset($validated['sale_additionals']) && !empty($validated['sale_additionals'])) {
                    SaleAdditional::create([
                        'company_id' => $validated['company_id'],
                        'sale_id' => $sale->id,
                        'place' => $validated['sale_additionals']['place'] ?? null,
                        'transport' => $validated['sale_additionals']['transport'] ?? null,
                        'vehicle_number' => $validated['sale_additionals']['vehicle_number'] ?? null,
                        'vehicle_name' => $validated['sale_additionals']['vehicle_name'] ?? null,
                        'driver_name' => $validated['sale_additionals']['driver_name'] ?? null,
                        'dispatch_code' => $validated['sale_additionals']['dispatch_code'] ?? null,
                        'driver_contact_number' => $validated['sale_additionals']['driver_contact_number'] ?? null,
                        'delivery_date' => $validated['sale_additionals']['delivery_date'] ?? null,
                        'delivery_time' => $validated['sale_additionals']['delivery_time'] ?? null,
                    ]);
                }

                $purchases = collect();

                foreach ($validated['sale_products'] as $index => $productData) {
                    $productId = $productData['product_id'] ?? null;
                    $productModel = null;

                    if ($productId) {
                        $productModel = Product::where('id', $productId)
                            ->where(function ($query) use ($validated) {
                                $query->where('company_id', $validated['company_id'])
                                    ->orWhereNull('company_id');
                            })
                            ->whereNull('deleted_at')
                            ->first();
                        if (!$productModel) {
                            throw new \Exception("Product with ID {$productId} not found at index {$index}");
                        }
                    } elseif (isset($productData['product_name'])) {
                        $productModel = Product::where('name', $productData['product_name'])
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at')
                            ->first();
                        if (!$productModel) {
                            throw new \Exception("Product with name {$productData['product_name']} not found at index {$index}");
                        }
                        $productId = $productModel->id;
                    } else {
                        throw new \Exception("Either product_id or product_name must be provided at index {$index}");
                    }

                    $targetMeasureUnit = MeasureUnit::find($productData['measure_unit_id']);
                    if (!$targetMeasureUnit) {
                        throw new \Exception("Measure unit not found for ID {$productData['measure_unit_id']} at index {$index}");
                    }

                    $targetMeasureUnitQuantity = $targetMeasureUnit->quantity ?? 1;
                    $regularQuantity = $productData['quantity'] ?? 0;
                    $freeQuantity = $productData['free_quantity'] ?? 0;
                    $regularPieces = $this->calculatePieces($regularQuantity, $targetMeasureUnitQuantity);
                    $freePieces = $this->calculatePieces($freeQuantity, $targetMeasureUnitQuantity);
                    $totalRequestedPieces = $regularPieces + $freePieces;

                    $fieldValuesFlat = $this->flattenFieldValues($productData['field_values'], $index);

                    $groupedFieldValues = collect($fieldValuesFlat)
                        ->groupBy('purchase_product_id')
                        ->map(function ($group) {
                            return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                                return collect($fvGroup)->map(function ($fv) {
                                    return [
                                        'product_field_id' => $fv['product_field_id'],
                                        'value' => $fv['value'],
                                        'quantity_index' => $fv['quantity_index'],
                                        'quantity_type' => $fv['quantity_type'] ?? 'regular',
                                        'purchase_product_id' => $fv['purchase_product_id'],
                                    ];
                                })->unique(function ($fv) {
                                    return "{$fv['product_field_id']}:{$fv['value']}:{$fv['quantity_type']}";
                                })->values()->toArray();
                            })->toArray();
                        })->toArray();

                    $regularFieldValueSets = collect($fieldValuesFlat)
                        ->filter(fn($fv) => ($fv['quantity_type'] ?? 'regular') === 'regular')
                        ->map(fn($fv) => "{$fv['purchase_product_id']}:{$fv['quantity_index']}")
                        ->unique()
                        ->count();
                    $freeFieldValueSets = collect($fieldValuesFlat)
                        ->filter(fn($fv) => ($fv['quantity_type'] ?? 'regular') === 'free')
                        ->map(fn($fv) => "{$fv['purchase_product_id']}:{$fv['quantity_index']}")
                        ->unique()
                        ->count();

                    $hasFieldValues = !empty($fieldValuesFlat);
                    $purchaseProductIds = array_keys($groupedFieldValues);
                    $requiresFieldValues = !empty($purchaseProductIds) && DB::table('purchase_product_field_values')
                        ->whereIn('purchase_product_id', $purchaseProductIds)
                        ->where('company_id', $validated['company_id'])
                        ->whereNull('deleted_at')
                        ->exists();

                    if (!$hasFieldValues && $requiresFieldValues) {
                        throw new \Exception("Field values required for product ID {$productId} at index {$index}.");
                    }
                    if ($hasFieldValues && !$requiresFieldValues) {
                        throw new \Exception("Field values provided for product ID {$productId} at index {$index}, but none required.");
                    }
                    if ($hasFieldValues && ($regularFieldValueSets != $regularPieces || $freeFieldValueSets != $freePieces)) {
                        throw new \Exception("Field value sets (Regular: {$regularFieldValueSets}, Free: {$freeFieldValueSets}) must match pieces (Regular: {$regularPieces}, Free: {$freePieces}) at index {$index}.");
                    }

                    $remainingRegularPieces = $regularPieces;
                    $remainingFreePieces = $freePieces;
                    $allocations = [];
                    $usedQuantityIndexes = [];

                    $query = PurchaseProduct::where('product_id', $productId)
                        ->where('company_id', $validated['company_id'])
                        ->whereNull('deleted_at')
                        ->with([
                            'purchase' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']),
                            'purchaseProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->with('measureUnit'),
                            'fieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']),
                            'saleProducts' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->with(['saleProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']), 'measureUnit'])
                        ]);

                    if ($hasFieldValues) {
                        $query->whereIn('id', $purchaseProductIds);
                    } elseif (isset($productData['purchase_product_id'])) {
                        $query->where('id', $productData['purchase_product_id']);
                    } else {
                        $query->whereNotExists(fn($subQuery) => $subQuery->select(DB::raw(1))
                            ->from('purchase_product_field_values')
                            ->whereColumn('purchase_product_id', 'purchase_products.id')
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at'));
                    }

                    $purchaseProducts = $query->orderBy('created_at')->distinct()->get();

                    if ($purchaseProducts->isEmpty()) {
                        throw new \Exception("No valid purchase products found for product ID {$productId} at index {$index}.");
                    }

                    if ($hasFieldValues) {
                        foreach ($groupedFieldValues as $purchaseProductId => $fvByIndex) {
                            $purchaseProduct = $purchaseProducts->firstWhere('id', $purchaseProductId) ?? throw new \Exception("Purchase product ID {$purchaseProductId} not found at index {$index}.");
                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;

                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $purchaseMeasureUnitQuantity, $validated['company_id']);

                            $existingFieldValues = $purchaseProduct->fieldValues->groupBy('quantity_index')->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());
                            $unavailableQuantityIndices = $this->getUnavailableQuantityIndices($purchaseProduct, $validated['company_id']);
                            $salesReturnedIndices = SaleReturnProductFieldValue::whereIn('sale_return_product_id', $purchaseProduct->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns->pluck('id')))
                                ->whereNull('deleted_at')
                                ->pluck('quantity_index')
                                ->toArray();
                            $unavailableQuantityIndices = array_diff($unavailableQuantityIndices, $salesReturnedIndices);

                            foreach ($fvByIndex as $quantityIndex => $fvSet) {
                                if (in_array($quantityIndex, $unavailableQuantityIndices) || !isset($existingFieldValues[$quantityIndex])) {
                                    throw new \Exception("Invalid quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} at index {$index}.");
                                }
                                if (in_array($quantityIndex, $usedQuantityIndexes[$purchaseProductId] ?? [])) {
                                    throw new \Exception("Duplicate quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} at index {$index}.");
                                }
                                if (collect($fvSet)->pluck('value', 'product_field_id')->toArray() != $existingFieldValues[$quantityIndex]) {
                                    throw new \Exception("Field values for quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} do not match at index {$index}.");
                                }
                                $usedQuantityIndexes[$purchaseProductId][] = $quantityIndex;
                            }

                            $regularFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'regular')->toArray();
                            $freeFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'free')->toArray();

                            $totalRequestedForThisProduct = count($regularFvByIndex) + count($freeFvByIndex);
                            $allocatePieces = min($totalRequestedForThisProduct, $totalAvailablePieces);

                            if ($allocatePieces > 0) {
                                $allocateRegularPieces = min(count($regularFvByIndex), $allocatePieces);
                                $allocateFreePieces = min(count($freeFvByIndex), $allocatePieces - $allocateRegularPieces);

                                [$allocateRegularQuantity, $allocateFreeQuantity] = $this->convertToTargetMeasureUnit($allocateRegularPieces, $allocateFreePieces, $targetMeasureUnitQuantity);

                                $allocations[] = [
                                    'purchase_product_id' => $purchaseProductId,
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                    'field_values' => array_merge(
                                        array_values(array_slice($regularFvByIndex, 0, $allocateRegularPieces)),
                                        array_values(array_slice($freeFvByIndex, 0, $allocateFreePieces))
                                    ),
                                    'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                    'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                                ];

                                $remainingRegularPieces -= $allocateRegularPieces;
                                $remainingFreePieces -= $allocateFreePieces;
                            }
                        }
                    }

                    if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                        $purchaseProduct = isset($productData['purchase_product_id']) ? $purchaseProducts->firstWhere('id', $productData['purchase_product_id']) : null;

                        if ($purchaseProduct) {
                            if ($purchaseProduct->fieldValues->isNotEmpty()) {
                                throw new \Exception("Purchase product ID {$purchaseProduct->id} has field values; field_values must be provided at index {$index}.");
                            }
                            $purchaseProducts = collect([$purchaseProduct]);
                        }

                        foreach ($purchaseProducts as $purchaseProduct) {
                            if ($remainingRegularPieces <= 0 && $remainingFreePieces <= 0) {
                                break;
                            }

                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $purchaseMeasureUnitQuantity, $validated['company_id']);

                            if ($totalAvailablePieces <= 0) {
                                continue;
                            }

                            $totalRemainingPieces = $remainingRegularPieces + $remainingFreePieces;
                            $allocatePieces = min($totalRemainingPieces, $totalAvailablePieces);

                            $allocateRegularPieces = min($remainingRegularPieces, $allocatePieces);
                            $allocateFreePieces = min($remainingFreePieces, $allocatePieces - $allocateRegularPieces);

                            if ($allocateRegularPieces > 0 || $allocateFreePieces > 0) {
                                [$allocateRegularQuantity, $allocateFreeQuantity] = $this->convertToTargetMeasureUnit($allocateRegularPieces, $allocateFreePieces, $targetMeasureUnitQuantity);

                                $allocations[] = [
                                    'purchase_product_id' => $purchaseProduct->id,
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                    'field_values' => [],
                                    'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                    'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                                ];

                                $remainingRegularPieces -= $allocateRegularPieces;
                                $remainingFreePieces -= $allocateFreePieces;
                            }
                        }
                    }

                    if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                        throw new \Exception("Insufficient stock for product ID {$productId} at index {$index}. Requested: {$totalRequestedPieces} pieces (Regular: {$regularPieces}, Free: {$freePieces}), Allocated: " . ($totalRequestedPieces - ($remainingRegularPieces + $remainingFreePieces)) . " pieces.");
                    }

                    foreach ($allocations as $allocation) {
                        $purchaseProduct = PurchaseProduct::findOrFail($allocation['purchase_product_id']);
                        $saleProduct = $sale->saleProducts()->create([
                            'company_id' => $validated['company_id'],
                            'sale_id' => $sale->id,
                            'product_id' => $productId,
                            'purchase_product_id' => $allocation['purchase_product_id'],
                            'quantity' => $allocation['quantity'],
                            'free_quantity' => $allocation['free_quantity'],
                            'price' => $productData['price'],
                            'discount_percent' => $productData['discount_percent'] ?? 0,
                            'discount_amount' => $productData['discount_amount'] ?? 0,
                            'amount' => ($productData['price'] * $allocation['quantity']) - ($productData['discount_amount'] ?? 0),
                            'is_vatable' => $productData['is_vatable'] ?? false,
                            'measure_unit_id' => $productData['measure_unit_id'],
                            'mfd' => $allocation['mfd'],
                            'batch_no' => $productData['batch_no'] ?? 'BATCH-' . $purchaseProduct->id . '-' . now()->format('Ymd'),
                            'expiry_date' => $allocation['expiry_date'] ?? null,
                            'name' => $productModel->name,
                        ]);

                        if (!empty($allocation['field_values'])) {
                            foreach ($allocation['field_values'] as $fvSet) {
                                foreach ($fvSet as $fv) {
                                    DB::table('sales_product_field_values')->insert([
                                        'sale_product_id' => $saleProduct->id,
                                        'product_id' => $productId,
                                        'product_field_id' => $fv['product_field_id'],
                                        'value' => $fv['value'],
                                        'quantity_index' => $fv['quantity_index'],
                                        'quantity_type' => $fv['quantity_type'],
                                        'company_id' => $validated['company_id'],
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }
                            }
                        }
                    }
                }

                return $sale;
            });

            return response()->json([
                'message' => 'Sale created successfully',
                'data' => $sale->load([
                    'saleProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index', 'asc')->orderBy('product_field_id', 'asc');
                    },
                    'saleAdditionals'
                ])
            ], 201);
        } catch (ModelNotFoundException $e) {
            Log::error('Model not found', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Resource not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database error', ['error' => $e->getMessage(), 'sql' => $e->getSql(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    private function flattenFieldValues($fieldValues, $index): array
    {
        $flattened = [];
        $flattenRecursive = function ($items, $depth = 0) use (&$flattenRecursive, &$flattened, $index) {
            if ($depth > 5) {
                throw new \Exception("Excessive nesting in field_values at index {$index}");
            }
            foreach ($items as $item) {
                if (is_array($item)) {
                    if (isset($item['purchase_product_id'], $item['product_field_id'], $item['value'], $item['quantity_index'])) {
                        $flattened[] = [
                            'purchase_product_id' => $item['purchase_product_id'],
                            'product_field_id' => $item['product_field_id'],
                            'value' => $item['value'],
                            'quantity_index' => $item['quantity_index'],
                            'quantity_type' => $item['quantity_type'] ?? 'regular',
                        ];
                    } else {
                        $flattenRecursive($item, $depth + 1);
                    }
                }
            }
        };
        $flattenRecursive($fieldValues);
        return $flattened;
    }

    private function calculatePieces(float $quantity, float $measureUnitQuantity): float
    {
        $integerPart = floor($quantity);
        $decimalPart = $quantity - $integerPart;
        $decimalPieces = $decimalPart > 0 ? (int) str_replace('.', '', (string) $decimalPart) : 0;
        return ($integerPart * $measureUnitQuantity) + $decimalPieces;
    }

    private function calculateAvailablePieces($purchaseProduct, float $measureUnitQuantity, int $companyId): float
    {
        $regularPieces = $this->calculatePieces($purchaseProduct->quantity ?? 0, $measureUnitQuantity);
        $freePieces = $this->calculatePieces($purchaseProduct->free_quantity ?? 0, $measureUnitQuantity);

        $purchaseReturnedPieces = $purchaseProduct->purchaseProductReturns->reduce(
            fn($carry, $return) => $carry + $this->calculatePieces($return->quantity ?? 0, $return->measureUnit->quantity ?? 1) + $this->calculatePieces($return->free_quantity ?? 0, $return->measureUnit->quantity ?? 1),
            0
        );

        $soldPieces = $purchaseProduct->saleProducts->reduce(
            fn($carry, $sale) => $carry + $this->calculatePieces($sale->quantity ?? 0, $sale->measureUnit->quantity ?? 1) + $this->calculatePieces($sale->free_quantity ?? 0, $sale->measureUnit->quantity ?? 1),
            0
        );

        $salesReturnedPieces = SalesReturnProduct::where('product_id', $purchaseProduct->product_id)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->with('measureUnit')
            ->get()
            ->reduce(
                fn($carry, $return) => $carry + $this->calculatePieces($return->quantity ?? 0, $return->measureUnit->quantity ?? 1) + $this->calculatePieces($return->free_quantity ?? 0, $return->measureUnit->quantity ?? 1),
                0
            );

        return max(0, ($regularPieces + $freePieces) - $purchaseReturnedPieces - $soldPieces + $salesReturnedPieces);
    }

    private function convertToTargetMeasureUnit(float $regularPieces, float $freePieces, float $targetMeasureUnitQuantity): array
    {
        $regularUnits = $regularPieces / $targetMeasureUnitQuantity;
        $regularIntegerUnits = floor($regularUnits);
        $regularRemainingPieces = $regularPieces - ($regularIntegerUnits * $targetMeasureUnitQuantity);
        $regularQuantity = $regularIntegerUnits + ($regularRemainingPieces * 0.1);

        $freeUnits = $freePieces / $targetMeasureUnitQuantity;
        $freeIntegerUnits = floor($freeUnits);
        $freeRemainingPieces = $freePieces - ($freeIntegerUnits * $targetMeasureUnitQuantity);
        $freeQuantity = $freeIntegerUnits + ($freeRemainingPieces * 0.1);

        return [$regularQuantity, $freeQuantity];
    }

    private function getUnavailableQuantityIndices($purchaseProduct, int $companyId): array
    {
        $returnIndices = $purchaseProduct->purchaseProductReturns->isNotEmpty()
            ? PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $purchaseProduct->purchaseProductReturns->pluck('id'))
                ->whereNull('deleted_at')
                ->pluck('quantity_index')
                ->toArray()
            : [];
        $soldIndices = $purchaseProduct->saleProducts->isNotEmpty()
            ? SalesProductFieldValue::whereIn('sale_product_id', $purchaseProduct->saleProducts->pluck('id'))
                ->whereNull('deleted_at')
                ->pluck('quantity_index')
                ->toArray()
            : [];
        return array_merge($returnIndices, $soldIndices);
    }



    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'customer_id' => 'nullable|exists:customers,id',
                'salesman_id' => 'required|exists:salesmen,id',
                'customer_name' => 'required|string|max:255',
                'customer_address' => 'nullable|string|max:255',
                'contact_number' => 'nullable|string|max:255',
                'credit_days' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                'ref_number' => 'nullable|string|max:255',
                'invoice_number' => ['nullable', 'string', 'max:255', Rule::unique('sales', 'invoice_number')->ignore($id)],
                'document_number' => 'nullable|string|max:255',
                'batch_no' => 'nullable|string|max:255',
                'balance' => 'nullable|numeric',
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'store_id' => 'required|exists:stores,id',
                'location_id' => 'required|exists:locations,id',
                'sub_total_before_discount' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'non_taxable_amount' => 'nullable|numeric|min:0',
                'taxable_amount' => 'nullable|numeric|min:0',
                'excise_duty' => 'nullable|numeric|min:0',
                'health_insurance' => 'nullable|numeric|min:0',
                'freight_charge' => 'nullable|numeric|min:0',
                'discount_after_vat' => 'nullable|numeric|min:0',
                'round_off_amount' => 'nullable|numeric',
                'roundoff_type' => 'nullable|string|max:255',
                'total_amount' => 'nullable|numeric|min:0',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'note' => 'nullable|string|max:255',
                'is_mail_notify' => 'nullable|boolean',
                'is_vatable' => 'nullable|boolean',
                'abvt' => 'nullable|boolean',
                'is_whatsapp_notify' => 'nullable|boolean',
                'sell_entire_batch' => 'nullable|boolean',
                'purchase_bill_number' => 'nullable|string|max:255',
                'batch_no_sale' => 'nullable|string|max:255',
                'purchase_id' => 'nullable|integer|exists:purchases,id',
                'sale_products' => [
                    'required_unless:sell_entire_batch,true',
                    'array',
                    'min:1',
                ],
                'sale_products.*.id' => ['nullable', 'integer', Rule::exists('sale_products', 'id')->where('sale_id', $id)],
                'sale_products.*.product_id' => 'required|exists:products,id',
                'sale_products.*.purchase_product_id' => 'required|exists:purchase_products,id',
                'sale_products.*.quantity' => 'required|numeric|min:0',
                'sale_products.*.free_quantity' => 'nullable|numeric|min:0',
                'sale_products.*.price' => 'required|numeric|min:0',
                'sale_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sale_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sale_products.*.is_vatable' => 'nullable|boolean',
                'sale_products.*.measure_unit_id' => 'required|exists:measure_units,id',
                'sale_products.*.batch_no' => 'required|string|max:255',
                'sale_products.mfd' => 'nullable|string|max:255',
                'sale_products.*.expiry_date' => 'nullable|string|max:255',
                'sale_products.*.field_values' => 'nullable|array',
                'sale_products.*.field_values.*' => 'array|min:1',
                'sale_products.*.field_values.*.*.id' => ['nullable', 'integer', Rule::exists('sales_product_field_values', 'id')],
                'sale_products.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
                'sale_products.*.field_values.*.*.value' => 'required|string|max:255',
                'sale_additionals' => 'nullable|array',
                'sale_additionals.place' => 'nullable|string|max:255',
                'sale_additionals.transport' => 'nullable|string|max:255',
                'sale_additionals.vehicle_number' => 'nullable|string|max:255',
                'sale_additionals.vehicle_name' => 'nullable|string|max:255',
                'sale_additionals.driver_name' => 'nullable|string|max:255',
                'sale_additionals.dispatch_code' => 'nullable|string|max:255',
                'sale_additionals.driver_contact_number' => 'nullable|string|max:255',
                'sale_additionals.delivery_date' => 'nullable|date',
                'sale_additionals.delivery_time' => 'nullable|date_format:H:i:s',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            \Log::debug('Initial validated sale_products', ['sale_products' => $validated['sale_products']]);

            // Fetch available products for the company
            $availableProducts = $this->getAvailableProductsForSale($validated['company_id']);
            $availableProductsMap = $availableProducts->keyBy('id'); // Map by product_id for quick lookup

            // Handle batch processing
            $hasBatchIdentifier = isset($validated['purchase_id']) || isset($validated['purchase_bill_number']) || isset($validated['batch_no_sale']);
            $sellEntireBatch = $validated['sell_entire_batch'] ?? false;

            if ($sellEntireBatch || $hasBatchIdentifier) {
                if ($sellEntireBatch && !$hasBatchIdentifier) {
                    return response()->json(['error' => 'At least one batch identifier (purchase_id, purchase_bill_number, or batch_no_sale) is required when sell_entire_batch is true'], 422);
                }

                $purchaseProducts = collect();

                if (isset($validated['purchase_id'])) {
                    $purchaseProducts = PurchaseProduct::where('purchase_id', $validated['purchase_id'])
                        ->whereHas('purchase', function ($query) use ($validated) {
                            $query->where('company_id', $validated['company_id']);
                        })
                        ->with('fieldValues')
                        ->get();
                } elseif (isset($validated['purchase_bill_number'])) {
                    $purchase = Purchase::where('purchase_bill_number', $validated['purchase_bill_number'])
                        ->where('company_id', $validated['company_id'])
                        ->first();
                    if (!$purchase) {
                        return response()->json(['error' => 'Purchase with specified bill number not found'], 422);
                    }
                    $purchaseProducts = PurchaseProduct::where('purchase_id', $purchase->id)
                        ->with('fieldValues')
                        ->get();
                } elseif (isset($validated['batch_no_sale'])) {
                    $purchaseProducts = PurchaseProduct::whereHas('purchase', function ($query) use ($validated) {
                        $query->where('batch_no', $validated['batch_no_sale'])
                            ->where('company_id', $validated['company_id']);
                    })->with('fieldValues')->get();
                }

                if ($purchaseProducts->isEmpty()) {
                    return response()->json(['error' => 'No products found for the specified purchase ID, bill number, or batch number'], 422);
                }

                if ($sellEntireBatch) {
                    $validated['sale_products'] = $purchaseProducts->map(function ($product) use ($validated, $availableProductsMap, $id) {
                        $productModel = Product::find($product->product_id);
                        if (!$availableProductsMap->has($product->product_id)) {
                            return null; // Skip if product is not available
                        }

                        // Adjust available quantity by adding back the current sale's quantity
                        $existingQuantity = SaleProduct::where('sale_id', $id)
                            ->where('product_id', $product->product_id)
                            ->whereNull('deleted_at')
                            ->sum(DB::raw('quantity + COALESCE(free_quantity, 0)'));
                        $availableQuantity = $availableProductsMap[$product->product_id]->available_quantity + $existingQuantity;

                        if ($availableQuantity <= 0) {
                            return null;
                        }

                        $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                            return $group->map(function ($field) {
                                return [
                                    'product_field_id' => $field->product_field_id,
                                    'value' => $field->value,
                                ];
                            })->toArray();
                        })->take($availableQuantity)->values()->toArray();

                        return [
                            'product_id' => $product->product_id,
                            'purchase_product_id' => $product->id,
                            'quantity' => $availableQuantity,
                            'free_quantity' => 0,
                            'price' => $productModel->price ?? $product->price,
                            'discount_percent' => $product->discount_percent ?? 0,
                            'discount_amount' => $product->discount_amount ?? 0,
                            'is_vatable' => $productModel->is_vatable ?? $product->is_vatable,
                            'measure_unit_id' => $product->measure_unit_id,
                            'mfd' => $product->mfd,
                            'batch_no' => 'BATCH-' . $product->id . '-' . now()->format('Ymd'),
                            'expiry_date' => $product->expiry_date,
                            'field_values' => $fieldValues,
                        ];
                    })->filter()->values()->toArray();

                    if (empty($validated['sale_products'])) {
                        return response()->json(['error' => 'No available stock for the specified batch'], 422);
                    }
                } else {
                    $purchaseProductIds = $purchaseProducts->pluck('id')->toArray();
                    foreach ($validated['sale_products'] as $index => $saleProduct) {
                        if (!in_array($saleProduct['purchase_product_id'], $purchaseProductIds)) {
                            return response()->json([
                                'error' => "Purchase product ID {$saleProduct['purchase_product_id']} at index {$index} does not belong to the specified purchase or batch"
                            ], 422);
                        }
                    }
                }
            }

            \Log::debug('Validated sale_products after batch processing', ['sale_products' => $validated['sale_products']]);

            // Validate field values and stock using getAvailableProductsForSale
            foreach ($validated['sale_products'] as $index => $product) {
                \Log::debug('Processing sale product at index ' . $index, ['product' => $product]);
                $productId = $product['product_id'];
                $purchaseProductId = $product['purchase_product_id'];
                $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);

                // Check stock availability
                if (!$availableProductsMap->has($productId)) {
                    return response()->json([
                        'error' => "Product ID {$productId} at index {$index} is not available for sale"
                    ], 422);
                }

                // Adjust available quantity by adding back the existing quantity for this sale
                $existingQuantity = SaleProduct::where('sale_id', $id)
                    ->where('product_id', $productId)
                    ->whereNull('deleted_at')
                    ->sum(DB::raw('quantity + COALESCE(free_quantity, 0)'));
                $availableQuantity = $availableProductsMap[$productId]->available_quantity + $existingQuantity;

                if ($requestedQuantity > $availableQuantity) {
                    return response()->json([
                        'error' => "Insufficient stock for product ID {$productId} at index {$index}. Available: {$availableQuantity}, Requested: {$requestedQuantity}"
                    ], 422);
                }

                // Validate field_values
                $hasFieldValues = PurchaseProductFieldValue::where('purchase_product_id', $purchaseProductId)
                    ->exists();
                if ($hasFieldValues && !$sellEntireBatch && (!isset($product['field_values']) || count($product['field_values']) !== $product['quantity'])) {
                    return response()->json([
                        'error' => "Field values count (" . (isset($product['field_values']) ? count($product['field_values']) : 0) . ") must match quantity ({$product['quantity']}) for product ID {$productId} at index {$index}"
                    ], 422);
                }

                if (isset($product['field_values'])) {
                    foreach ($product['field_values'] as $setIndex => $fieldValueSet) {
                        $fieldIds = array_column($fieldValueSet, 'product_field_id');
                        if (count($fieldIds) !== count(array_unique($fieldIds))) {
                            return response()->json([
                                'error' => "Duplicate product_field_id found in field_values set {$setIndex} for product ID {$productId} at index {$index}"
                            ], 422);
                        }
                    }
                }
            }

            $sale = DB::transaction(function () use ($validated, $id, $availableProductsMap) {
                $sale = Sale::findOrFail($id);
                $sale->update($validated);

                $existingProductIds = $sale->saleProducts()->withTrashed()->pluck('id')->toArray();
                $incomingProductIds = collect($validated['sale_products'])->pluck('id')->filter()->toArray();
                $productsToDelete = array_diff($existingProductIds, $incomingProductIds);
                if (!empty($productsToDelete)) {
                    SaleProduct::whereIn('id', $productsToDelete)->delete();
                }

                foreach ($validated['sale_products'] as $product) {
                    if (!isset($product['purchase_product_id']) || !isset($product['quantity']) || $product['quantity'] <= 0) {
                        throw new \Exception('purchase_product_id and valid quantity are required for each sale product');
                    }

                    // Double-check stock in transaction
                    $existingQuantity = SaleProduct::where('sale_id', $id)
                        ->where('product_id', $product['product_id'])
                        ->whereNull('deleted_at')
                        ->sum(DB::raw('quantity + COALESCE(free_quantity, 0)'));
                    $availableQuantity = $availableProductsMap[$product['product_id']]->available_quantity + $existingQuantity;
                    $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);
                    if ($requestedQuantity > $availableQuantity) {
                        throw new \Exception("Insufficient stock for product ID {$product['product_id']}. Available: {$availableQuantity}, Requested: {$requestedQuantity}");
                    }

                    $product['company_id'] = $validated['company_id'];
                    $product['sale_id'] = $sale->id;
                    $productModel = Product::find($product['product_id']);
                    if (!$productModel) {
                        throw new \Exception('Product not found for product_id: ' . $product['product_id']);
                    }
                    $product['product_code'] = $productModel->product_unique_id ?? null;
                    $product['product_name'] = $productModel->name ?? null;
                    $product['name'] = $productModel->name ?? null;
                    $product['amount'] = ($product['quantity'] * $product['price']) - ($product['discount_amount'] ?? 0);

                    if (isset($product['id'])) {
                        $saleProduct = SaleProduct::where('id', $product['id'])->where('sale_id', $sale->id)->withTrashed()->first();
                        if ($saleProduct) {
                            if ($saleProduct->trashed()) {
                                $saleProduct->restore();
                            }
                            $saleProduct->update($product);
                        } else {
                            throw new \Exception('Sale product ID ' . $product['id'] . ' not found for sale ID ' . $sale->id);
                        }
                    } else {
                        $saleProduct = $sale->saleProducts()->create($product);
                    }

                    if (!empty($product['field_values'])) {
                        $purchaseProduct = PurchaseProduct::withoutGlobalScopes()
                            ->where('id', $product['purchase_product_id'])
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at')
                            ->firstOrFail();

                        $availableFieldValues = PurchaseProductFieldValue::withoutGlobalScopes()
                            ->select([
                                'purchase_product_field_values.quantity_index',
                                'purchase_product_field_values.product_field_id',
                                'purchase_product_field_values.value',
                            ])
                            ->join('purchase_products', 'purchase_product_field_values.purchase_product_id', '=', 'purchase_products.id')
                            ->leftJoin('purchase_product_returns', function ($join) use ($validated) {
                                $join->on('purchase_products.id', '=', 'purchase_product_returns.purchase_product_id')
                                    ->whereNull('purchase_product_returns.deleted_at')
                                    ->where('purchase_product_returns.company_id', $validated['company_id']);
                            })
                            ->leftJoin('purchase_return_product_field_values', function ($join) use ($validated) {
                                $join->on('purchase_product_returns.id', '=', 'purchase_return_product_field_values.purchase_return_product_id')
                                    ->on('purchase_product_field_values.quantity_index', '=', 'purchase_return_product_field_values.quantity_index')
                                    ->whereNull('purchase_return_product_field_values.deleted_at')
                                    ->where('purchase_return_product_field_values.company_id', $validated['company_id']);
                            })
                            ->leftJoin('sale_products', function ($join) use ($validated, $id) {
                                $join->on('purchase_products.id', '=', 'sale_products.purchase_product_id')
                                    ->whereNull('sale_products.deleted_at')
                                    ->where('sale_products.company_id', $validated['company_id'])
                                    ->where('sale_products.sale_id', '!=', $id);
                            })
                            ->leftJoin('sales_product_field_values', function ($join) use ($validated) {
                                $join->on('sale_products.id', '=', 'sales_product_field_values.sale_product_id')
                                    ->on('purchase_product_field_values.quantity_index', '=', 'sales_product_field_values.quantity_index')
                                    ->whereNull('sales_product_field_values.deleted_at')
                                    ->where('sales_product_field_values.company_id', $validated['company_id']);
                            })
                            ->leftJoin('sales_return_products', function ($join) use ($validated) {
                                $join->on('sale_products.id', '=', 'sales_return_products.sale_product_id')
                                    ->whereNull('sales_return_products.deleted_at')
                                    ->where('sales_return_products.company_id', $validated['company_id']);
                            })
                            ->leftJoin('sale_return_product_field_values', function ($join) use ($validated) {
                                $join->on('sales_return_products.id', '=', 'sale_return_product_field_values.sale_return_product_id')
                                    ->on('purchase_product_field_values.quantity_index', '=', 'sale_return_product_field_values.quantity_index')
                                    ->whereNull('sale_return_product_field_values.deleted_at')
                                    ->where('sale_return_product_field_values.company_id', $validated['company_id']);
                            })
                            ->where('purchase_product_field_values.purchase_product_id', $product['purchase_product_id'])
                            ->whereNull('purchase_product_field_values.deleted_at')
                            ->where('purchase_product_field_values.company_id', $validated['company_id'])
                            ->whereNull('purchase_return_product_field_values.id')
                            ->where(function ($q) {
                                $q->whereNull('sales_product_field_values.id')
                                    ->orWhere(function ($subQ) {
                                        $subQ->whereNotNull('sales_product_field_values.id')
                                            ->whereNotNull('sale_return_product_field_values.id')
                                            ->whereColumn('sales_product_field_values.quantity_index', 'sale_return_product_field_values.quantity_index');
                                    });
                            })
                            ->groupBy([
                                'purchase_product_field_values.quantity_index',
                                'purchase_product_field_values.product_field_id',
                                'purchase_product_field_values.value',
                            ])
                            ->get();

                        \Log::debug('Available field values for sale update', [
                            'purchase_product_id' => $product['purchase_product_id'],
                            'field_values' => $availableFieldValues->toArray(),
                        ]);

                        $fieldValues = [];
                        $selectedQuantityIndices = [];
                        foreach ($product['field_values'] as $quantityIndex => $fieldValueSet) {
                            $matched = false;
                            foreach ($availableFieldValues as $availableFieldValue) {
                                $matchesAllFields = true;
                                foreach ($fieldValueSet as $fieldValue) {
                                    $found = $availableFieldValues->contains(function ($item) use ($fieldValue, $availableFieldValue) {
                                        return $item->product_field_id == $fieldValue['product_field_id'] &&
                                            $item->value == $fieldValue['value'] &&
                                            $item->quantity_index == $availableFieldValue->quantity_index;
                                    });
                                    if (!$found) {
                                        $matchesAllFields = false;
                                        break;
                                    }
                                }
                                if ($matchesAllFields && !in_array($availableFieldValue->quantity_index, $selectedQuantityIndices)) {
                                    foreach ($fieldValueSet as $fieldValue) {
                                        $fieldValues[] = [
                                            'company_id' => $validated['company_id'],
                                            'product_field_id' => $fieldValue['product_field_id'],
                                            'product_id' => $saleProduct->product_id,
                                            'sale_product_id' => $saleProduct->id,
                                            'quantity_index' => $availableFieldValue->quantity_index,
                                            'value' => $fieldValue['value'],
                                            'created_at' => now(),
                                            'updated_at' => now(),
                                        ];
                                    }
                                    $selectedQuantityIndices[] = $availableFieldValue->quantity_index;
                                    $matched = true;
                                    break;
                                }
                            }
                            if (!$matched) {
                                \Log::warning('Field values mismatch during update', [
                                    'purchase_product_id' => $product['purchase_product_id'],
                                    'field_value_set' => $fieldValueSet,
                                    'available_field_values' => $availableFieldValues->toArray(),
                                ]);
                                throw new \Exception('Provided field values do not match available stock for purchase_product_id: ' . $product['purchase_product_id']);
                            }
                        }

                        if (count($selectedQuantityIndices) > $product['quantity']) {
                            throw new \Exception('Number of selected field value sets (' . count($selectedQuantityIndices) . ') exceeds requested quantity (' . $product['quantity'] . ') for purchase_product_id: ' . $product['purchase_product_id']);
                        }

                        // Update or create field values
                        $processedFieldIds = [];
                        if (!empty($fieldValues)) {
                            foreach ($fieldValues as $fieldValue) {
                                if (isset($fieldValue['id']) && !empty($fieldValue['id'])) {
                                    $existingValue = SalesProductFieldValue::where('id', $fieldValue['id'])
                                        ->where('sale_product_id', $saleProduct->id)
                                        ->withTrashed()
                                        ->first();
                                    if ($existingValue) {
                                        if ($existingValue->trashed()) {
                                            $existingValue->restore();
                                        }
                                        $existingValue->update([
                                            'product_field_id' => $fieldValue['product_field_id'],
                                            'value' => $fieldValue['value'],
                                            'quantity_index' => $fieldValue['quantity_index'],
                                            'updated_at' => now(),
                                        ]);
                                        $processedFieldIds[] = $existingValue->id;
                                    }
                                } else {
                                    $newFieldValue = SalesProductFieldValue::create($fieldValue);
                                    $processedFieldIds[] = $newFieldValue->id;
                                }
                            }
                        }

                        // Delete unprocessed field values
                        $existingFieldIds = SalesProductFieldValue::where('sale_product_id', $saleProduct->id)->withTrashed()->pluck('id')->toArray();
                        $unprocessedFieldIds = array_diff($existingFieldIds, $processedFieldIds);
                        if (!empty($unprocessedFieldIds)) {
                            SalesProductFieldValue::where('sale_product_id', $saleProduct->id)
                                ->whereIn('id', $unprocessedFieldIds)
                                ->delete();
                        }
                    } else {
                        SalesProductFieldValue::where('sale_product_id', $saleProduct->id)->delete();
                    }
                }

                if (isset($validated['sale_additionals'])) {
                    $saleAdditionals = $validated['sale_additionals'];
                    $saleAdditionals['company_id'] = $validated['company_id'];
                    $saleAdditionals['sale_id'] = $sale->id;
                    if (isset($validated['purchase_bill_number'])) {
                        $saleAdditionals['purchase_bill_number'] = $validated['purchase_bill_number'];
                    }
                    SaleAdditional::updateOrCreate(['sale_id' => $sale->id], $saleAdditionals);
                } else {
                    SaleAdditional::where('sale_id', $sale->id)->delete();
                }

                return $sale;
            });

            return response()->json([
                'message' => 'Sale updated successfully',
                'data' => $sale->load([
                    'saleProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index')->orderBy('product_field_id');
                    },
                    'saleAdditionals'
                ])
            ], 200);
        } catch (ModelNotFoundException $e) {
            \Log::error('Model not found during sale update', [
                'sale_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Sale or related resource not found'], 404);
        } catch (QueryException $e) {
            \Log::error('Database error during sale update', [
                'sale_id' => $id,
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Database error occurred. Please try again later.'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error during sale update', [
                'sale_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }


    public function show($id): JsonResponse
    {
        try {
            $item = Sale::with('saleProducts')->findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }





    private function getSalesByCustomer(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $customerID = $request->input('customer_id');
            $companyId = $request->input('company_id');

            $sales = Helper::getSalesByCustomer($customerID, $companyId);

            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales found for the specified customer'], 404);
            }

            return response()->json([
                'message' => 'Sales retrieved successfully',
                'data' => $sales
            ], 200);

        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }


    public function getSalesByBatch(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'batch_no' => 'required|exists:sales,batch_no',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $batchNo = $request->input('batch_no');
            $companyId = $request->input('company_id');

            $sales = Helper::getSalesByBatch($batchNo, $companyId);

            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales found for the specified batch'], 404);
            }

            return response()->json([
                'message' => 'Sales retrieved successfully',
                'data' => $sales
            ], 200);

        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }

    public function getAllExpiryDates(): JsonResponse
    {
        $expiryDates = SaleProduct::select('expiry_date')
            ->distinct()
            ->orderBy('expiry_date', 'asc')
            ->pluck('expiry_date');

        return response()->json([
            'message' => 'Expiry dates retrieved successfully',
            'data' => $expiryDates
        ], 200);
    }


    public function getSalesByExpiryDate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'expiry_date' => 'required|exists:sale_products,expiry_date',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $expiryDate = $request->input('expiry_date');
            $companyId = $request->input('company_id');

            $sales = Helper::getSalesByExpiryDate($expiryDate, $companyId);

            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales found for the specified Expiry Date'], 404);
            }

            return response()->json([
                'message' => 'Sales retrieved successfully',
                'data' => $sales
            ], 200);

        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = Sale::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Sale deleted']);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


}
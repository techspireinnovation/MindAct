<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Helpers\Helper;
use App\Models\SaleProduct;
use App\Models\SaleAdditional;
use App\Models\SalesReturnProduct;
use App\Models\SalesProductFieldValue;
use App\Models\PurchaseProductFieldValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
use Illuminate\Http\Request;
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
                $sequence = (int)$matches[1];
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

            return response()->json(['invoice_number'=>$newInvoiceNumber]);
        });
    }


     private function getAvailableProductsForSale($companyId)
{
    Log::debug('Fetching available products for sale', ['company_id' => $companyId]);

    try {
        DB::enableQueryLog();

        // Debug: Log purchase_products and sale_products for product_id = 36
        $rawPurchaseProducts = DB::table('purchase_products')
            ->select(['id', 'purchase_id', 'product_id', 'quantity', 'free_quantity', 'company_id', 'deleted_at'])
            ->where('product_id', 36)
            ->where('company_id', $companyId)
            ->get();
        Log::debug('Raw purchase_products for product_id 36', ['records' => $rawPurchaseProducts->toArray()]);

        $rawSaleProducts = DB::table('sale_products')
            ->select(['id', 'purchase_product_id', 'product_id', 'quantity', 'free_quantity', 'company_id', 'deleted_at', 'batch_no'])
            ->where('product_id', 36)
            ->where('company_id', $companyId)
            ->get();
        Log::debug('Raw sale_products for product_id 36', ['records' => $rawSaleProducts->toArray()]);

        // Subquery for purchase_products quantities
        $purchaseSubQuery = DB::table('purchase_products')
            ->select([
                'product_id',
                DB::raw('SUM(quantity + COALESCE(free_quantity, 0)) as purchased_quantity')
            ])
            ->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->groupBy('product_id');

        // Subquery for sale_products quantities
        $saleSubQuery = DB::table('sale_products')
            ->select([
                'product_id',
                DB::raw('SUM(quantity + COALESCE(free_quantity, 0)) as sale_quantity')
            ])
            ->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->groupBy('product_id');

        // Subquery for purchase_product_returns quantities
        $returnSubQuery = DB::table('purchase_product_returns')
            ->select([
                'purchase_products.product_id',
                DB::raw('SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)) as return_quantity')
            ])
            ->join('purchase_products', 'purchase_product_returns.purchase_product_id', '=', 'purchase_products.id')
            ->whereNull('purchase_product_returns.deleted_at')
            ->where('purchase_product_returns.company_id', $companyId)
            ->groupBy('purchase_products.product_id');

        // Subquery for sales_return_products quantities
        $salesReturnSubQuery = DB::table('sales_return_products')
            ->select([
                'sale_products.product_id',
                DB::raw('SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)) as sales_return_quantity')
            ])
            ->join('sale_products', 'sales_return_products.sale_product_id', '=', 'sale_products.id')
            ->whereNull('sales_return_products.deleted_at')
            ->where('sales_return_products.company_id', $companyId)
            ->groupBy('sale_products.product_id');

        // Main query as a derived table
        $mainQuery = DB::table(DB::raw("({$purchaseSubQuery->toSql()}) as purchase_totals"))
            ->select([
                'products.id',
                'products.name',
                'purchase_totals.purchased_quantity',
                'return_totals.return_quantity',
                'sale_totals.sale_quantity',
                'sales_return_totals.sales_return_quantity'
            ])
            ->mergeBindings($purchaseSubQuery)
            ->join('products', function ($join) use ($companyId) {
                $join->on('purchase_totals.product_id', '=', 'products.id')
                     ->where('products.company_id', $companyId)
                     ->whereNull('products.deleted_at');
            })
            ->leftJoin(DB::raw("({$saleSubQuery->toSql()}) as sale_totals"), function ($join) {
                $join->on('purchase_totals.product_id', '=', 'sale_totals.product_id');
            })
            ->mergeBindings($saleSubQuery)
            ->leftJoin(DB::raw("({$returnSubQuery->toSql()}) as return_totals"), function ($join) {
                $join->on('purchase_totals.product_id', '=', 'return_totals.product_id');
            })
            ->mergeBindings($returnSubQuery)
            ->leftJoin(DB::raw("({$salesReturnSubQuery->toSql()}) as sales_return_totals"), function ($join) {
                $join->on('purchase_totals.product_id', '=', 'sales_return_totals.product_id');
            })
            ->mergeBindings($salesReturnSubQuery);

        // Final query with calculations
        $query = DB::table(DB::raw("({$mainQuery->toSql()}) as main"))
            ->select([
                'main.id',
                'main.name',
                'main.purchased_quantity',
                DB::raw('COALESCE(main.return_quantity, 0) as return_quantity'),
                DB::raw('COALESCE(main.sale_quantity, 0) as sale_quantity'),
                DB::raw('COALESCE(main.sales_return_quantity, 0) as sales_return_quantity'),
                DB::raw('main.purchased_quantity - 
                         COALESCE(main.return_quantity, 0) - 
                         COALESCE(main.sale_quantity, 0) + 
                         COALESCE(main.sales_return_quantity, 0) as available_quantity')
            ])
            ->mergeBindings($mainQuery)
            ->whereRaw('main.purchased_quantity - 
                        COALESCE(main.return_quantity, 0) - 
                        COALESCE(main.sale_quantity, 0) + 
                        COALESCE(main.sales_return_quantity, 0) > 0');

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
    private function getAvailableProductsDetails(?int $productId = null, ?string $productName = null, ?int $companyId = null): array
{
    Log::debug('Fetching detailed available products with purchase products', [
        'product_id' => $productId,
        'product_name' => $productName,
        'company_id' => $companyId
    ]);

    try {
        DB::enableQueryLog();

        // Debug: Log purchase_products, sale_products, and field values for product_id = 39
        if ($productId === 39 || is_null($productId)) {
            $rawPurchaseProducts = DB::table('purchase_products')
                ->select(['id', 'purchase_id', 'product_id', 'quantity', 'free_quantity', 'company_id', 'deleted_at'])
                ->where('product_id', 39)
                ->where('company_id', $companyId)
                ->get();
            Log::debug('Raw purchase_products for product_id 39', ['records' => $rawPurchaseProducts->toArray()]);

            $rawSaleProducts = DB::table('sale_products')
                ->select(['id', 'purchase_product_id', 'product_id', 'quantity', 'free_quantity', 'company_id', 'deleted_at', 'batch_no'])
                ->where('product_id', 39)
                ->where('company_id', $companyId)
                ->get();
            Log::debug('Raw sale_products for product_id 39', ['records' => $rawSaleProducts->toArray()]);

            $rawFieldValues = DB::table('purchase_product_field_values')
                ->select(['purchase_product_id', 'quantity_index', 'product_field_id', 'value'])
                ->whereIn('purchase_product_id', $rawPurchaseProducts->pluck('id'))
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get();
            Log::debug('Raw purchase_product_field_values for product_id 39', ['records' => $rawFieldValues->toArray()]);

            $rawSalesFieldValues = DB::table('sales_product_field_values')
                ->select(['sale_product_id', 'quantity_index', 'product_field_id', 'value'])
                ->whereIn('sale_product_id', $rawSaleProducts->pluck('id'))
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get();
            Log::debug('Raw sales_product_field_values for product_id 39', ['records' => $rawSalesFieldValues->toArray()]);
        }

        // Subquery for purchase_products quantities
        $purchaseSubQuery = DB::table('purchase_products')
            ->select([
                'product_id',
                'product_name',
                'product_code',
                'measure_unit_id',
                DB::raw('MIN(price) as min_price'),
                DB::raw('MAX(is_vatable) as is_vatable'),
                DB::raw('GROUP_CONCAT(DISTINCT expiry_date) as expiry_dates'),
                DB::raw('SUM(quantity + COALESCE(free_quantity, 0)) as purchased_quantity')
            ])
            ->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->groupBy('product_id', 'product_name', 'product_code', 'measure_unit_id');

        // Subquery for sale_products quantities
        $saleSubQuery = DB::table('sale_products')
            ->select([
                'product_id',
                DB::raw('SUM(quantity + COALESCE(free_quantity, 0)) as sale_quantity')
            ])
            ->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->groupBy('product_id');

        // Subquery for purchase_product_returns quantities
        $returnSubQuery = DB::table('purchase_product_returns')
            ->select([
                'purchase_products.product_id',
                DB::raw('SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)) as return_quantity')
            ])
            ->join('purchase_products', 'purchase_product_returns.purchase_product_id', '=', 'purchase_products.id')
            ->whereNull('purchase_product_returns.deleted_at')
            ->where('purchase_product_returns.company_id', $companyId)
            ->groupBy('purchase_products.product_id');

        // Subquery for sales_return_products quantities
        $salesReturnSubQuery = DB::table('sales_return_products')
            ->select([
                'sale_products.product_id',
                DB::raw('SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)) as sales_return_quantity')
            ])
            ->join('sale_products', 'sales_return_products.sale_product_id', '=', 'sale_products.id')
            ->whereNull('sales_return_products.deleted_at')
            ->where('sales_return_products.company_id', $companyId)
            ->groupBy('sale_products.product_id');

        // Main query as a derived table
        $mainQuery = DB::table(DB::raw("({$purchaseSubQuery->toSql()}) as purchase_totals"))
            ->select([
                'products.id as product_id',
                'purchase_totals.product_name',
                'purchase_totals.product_code',
                'purchase_totals.min_price',
                'purchase_totals.is_vatable',
                'purchase_totals.measure_unit_id',
                'purchase_totals.purchased_quantity',
                'purchase_totals.expiry_dates',
                'return_totals.return_quantity',
                'sale_totals.sale_quantity',
                'sales_return_totals.sales_return_quantity'
            ])
            ->mergeBindings($purchaseSubQuery)
            ->join('products', function ($join) use ($companyId) {
                $join->on('purchase_totals.product_id', '=', 'products.id')
                     ->where('products.company_id', $companyId)
                     ->whereNull('products.deleted_at');
            })
            ->leftJoin(DB::raw("({$saleSubQuery->toSql()}) as sale_totals"), function ($join) {
                $join->on('purchase_totals.product_id', '=', 'sale_totals.product_id');
            })
            ->mergeBindings($saleSubQuery)
            ->leftJoin(DB::raw("({$returnSubQuery->toSql()}) as return_totals"), function ($join) {
                $join->on('purchase_totals.product_id', '=', 'return_totals.product_id');
            })
            ->mergeBindings($returnSubQuery)
            ->leftJoin(DB::raw("({$salesReturnSubQuery->toSql()}) as sales_return_totals"), function ($join) {
                $join->on('purchase_totals.product_id', '=', 'sales_return_totals.product_id');
            })
            ->mergeBindings($salesReturnSubQuery);

        if ($productId) {
            $mainQuery->where('products.id', $productId);
        }

        if ($productName) {
            $mainQuery->where(function ($q) use ($productName) {
                $q->where('purchase_totals.product_name', 'like', "%{$productName}%")
                  ->orWhere('products.name', 'like', "%{$productName}%");
            });
        }

        // Final query with calculations
        $query = DB::table(DB::raw("({$mainQuery->toSql()}) as main"))
            ->select([
                'main.product_id',
                'main.product_name',
                'main.product_code',
                'main.min_price',
                'main.is_vatable',
                'main.measure_unit_id',
                'main.purchased_quantity',
                DB::raw('COALESCE(main.return_quantity, 0) as return_quantity'),
                DB::raw('COALESCE(main.sale_quantity, 0) as sale_quantity'),
                DB::raw('COALESCE(main.sales_return_quantity, 0) as sales_return_quantity'),
                DB::raw('main.purchased_quantity - 
                         COALESCE(main.return_quantity, 0) - 
                         COALESCE(main.sale_quantity, 0) + 
                         COALESCE(main.sales_return_quantity, 0) as available_quantity'),
                'main.expiry_dates'
            ])
            ->mergeBindings($mainQuery)
            ->whereRaw('main.purchased_quantity - 
                        COALESCE(main.return_quantity, 0) - 
                        COALESCE(main.sale_quantity, 0) + 
                        COALESCE(main.sales_return_quantity, 0) > 0');

        $products = $query->get();

        Log::debug('Available product details query', [
            'sql' => DB::getQueryLog(),
            'product_count' => $products->count(),
            'products' => $products->toArray()
        ]);

        if ($products->isEmpty()) {
            return ['message' => 'No available products found', 'data' => []];
        }

        $productIds = $products->pluck('product_id')->toArray();
        // Subquery to identify sold quantity_indices
        $soldQuantityIndicesSubQuery = DB::table('sales_product_field_values')
            ->select([
                'purchase_product_field_values.purchase_product_id',
                'purchase_product_field_values.quantity_index'
            ])
            ->join('sale_products', 'sales_product_field_values.sale_product_id', '=', 'sale_products.id')
            ->join('purchase_product_field_values', function ($join) {
                $join->on('sale_products.purchase_product_id', '=', 'purchase_product_field_values.purchase_product_id')
                     ->on('sales_product_field_values.quantity_index', '=', 'purchase_product_field_values.quantity_index');
            })
            ->leftJoin('sales_return_products', function ($join) use ($companyId) {
                $join->on('sale_products.id', '=', 'sales_return_products.sale_product_id')
                     ->whereNull('sales_return_products.deleted_at')
                     ->where('sales_return_products.company_id', $companyId);
            })
            ->leftJoin('sale_return_product_field_values', function ($join) use ($companyId) {
                $join->on('sales_return_products.id', '=', 'sale_return_product_field_values.sale_return_product_id')
                     ->on('sales_product_field_values.quantity_index', '=', 'sale_return_product_field_values.quantity_index')
                     ->whereNull('sale_return_product_field_values.deleted_at')
                     ->where('sale_return_product_field_values.company_id', $companyId);
            })
            ->whereNull('sale_products.deleted_at')
            ->where('sale_products.company_id', $companyId)
            ->whereNull('sales_product_field_values.deleted_at')
            ->whereNull('sale_return_product_field_values.id')
            ->groupBy('purchase_product_field_values.purchase_product_id', 'purchase_product_field_values.quantity_index');

        $fieldValuesQuery = PurchaseProductFieldValue::withoutGlobalScopes()
            ->select([
                'purchase_product_field_values.purchase_product_id',
                'purchase_product_field_values.quantity_index',
                'purchase_product_field_values.product_field_id',
                'purchase_product_field_values.value',
                'product_fields.name as field_name',
                'purchase_products.expiry_date',
                'purchase_products.product_id'
            ])
            ->join('product_fields', 'purchase_product_field_values.product_field_id', '=', 'product_fields.id')
            ->join('purchase_products', 'purchase_product_field_values.purchase_product_id', '=', 'purchase_products.id')
            ->leftJoin('purchase_product_returns', function ($join) use ($companyId) {
                $join->on('purchase_products.id', '=', 'purchase_product_returns.purchase_product_id')
                     ->whereNull('purchase_product_returns.deleted_at')
                     ->where('purchase_product_returns.company_id', $companyId);
            })
            ->leftJoin('purchase_return_product_field_values', function ($join) use ($companyId) {
                $join->on('purchase_product_returns.id', '=', 'purchase_return_product_field_values.purchase_return_product_id')
                     ->on('purchase_product_field_values.quantity_index', '=', 'purchase_return_product_field_values.quantity_index')
                     ->whereNull('purchase_return_product_field_values.deleted_at')
                     ->where('purchase_return_product_field_values.company_id', $companyId);
            })
            ->leftJoin(DB::raw("({$soldQuantityIndicesSubQuery->toSql()}) as sold_indices"), function ($join) {
                $join->on('purchase_product_field_values.purchase_product_id', '=', 'sold_indices.purchase_product_id')
                     ->on('purchase_product_field_values.quantity_index', '=', 'sold_indices.quantity_index');
            })
            ->mergeBindings($soldQuantityIndicesSubQuery)
            ->whereIn('purchase_products.product_id', $productIds)
            ->whereNull('purchase_products.deleted_at')
            ->whereNull('purchase_product_field_values.deleted_at')
            ->where('purchase_products.company_id', $companyId)
            ->where('product_fields.company_id', $companyId)
            ->where('purchase_product_field_values.company_id', $companyId)
            ->whereNull('purchase_return_product_field_values.id')
            ->whereNull('sold_indices.quantity_index') // Exclude sold quantity_indices
            ->groupBy([
                'purchase_product_field_values.purchase_product_id',
                'purchase_product_field_values.quantity_index',
                'purchase_product_field_values.product_field_id',
                'purchase_product_field_values.value',
                'product_fields.name',
                'purchase_products.expiry_date',
                'purchase_products.product_id'
            ]);

        $fieldValuesRaw = $fieldValuesQuery->get();

        Log::debug('Field values query results', [
            'field_values_count' => $fieldValuesRaw->count(),
            'quantity_indices' => $fieldValuesRaw->pluck('quantity_index')->unique()->toArray(),
            'field_values' => $fieldValuesRaw->toArray()
        ]);

        $fieldValues = [];
        foreach ($products as $product) {
            $productId = $product->product_id;
            $productFieldValues = $fieldValuesRaw->filter(function ($field) use ($productId) {
                return $field->product_id == $productId;
            });

            $groupedFieldValues = $productFieldValues->groupBy('quantity_index')->map(function ($indexGroup) {
                return $indexGroup->map(function ($field) {
                    return [
                        'product_field_id' => $field->product_field_id,
                        'field_name' => $field->field_name ?? 'Unknown',
                        'value' => $field->value,
                        'expiry_date' => $field->expiry_date
                    ];
                })->values()->toArray();
            })->values()->toArray();

            $fieldValues[$productId] = $groupedFieldValues;
        }

        $purchaseProductsQuery = PurchaseProduct::withoutGlobalScopes()
            ->select([
                'purchase_products.id',
                'purchase_products.purchase_id',
                'purchase_products.product_id',
                'purchase_products.product_name',
                'purchase_products.product_code',
                'purchase_products.quantity',
                'purchase_products.free_quantity',
                'purchase_products.expiry_date',
                'purchase_products.price',
                'purchase_products.is_vatable',
                'purchase_products.measure_unit_id',
                'purchases.purchase_bill_number',
                'purchases.invoice_date',
                DB::raw('COALESCE(SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)), 0) as return_quantity'),
                DB::raw('COALESCE(SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)), 0) as sale_quantity'),
                DB::raw('COALESCE(SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)), 0) as sales_return_quantity'),
                DB::raw('(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) - 
                         COALESCE(SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)), 0) - 
                         COALESCE(SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)), 0) + 
                         COALESCE(SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)), 0) as available_quantity')
            ])
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
                'purchase_products.price',
                'purchase_products.is_vatable',
                'purchase_products.measure_unit_id',
                'purchases.purchase_bill_number',
                'purchases.invoice_date'
            ])
            ->havingRaw('(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) - 
                         COALESCE(SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)), 0) - 
                         COALESCE(SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)), 0) + 
                         COALESCE(SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)), 0) > 0');

        $purchaseProducts = $purchaseProductsQuery->get();

        Log::debug('PurchaseProduct details query', [
            'purchase_product_count' => $purchaseProducts->count(),
            'purchase_products' => $purchaseProducts->toArray()
        ]);

        $result = $products->map(function ($product) use ($fieldValues, $purchaseProducts) {
            $productId = $product->product_id;
            $productPurchaseProducts = $purchaseProducts->filter(function ($pp) use ($productId) {
                return $pp->product_id == $productId;
            })->map(function ($pp) {
                return [
                    'purchase_product_id' => $pp->id,
                    'purchase_id' => $pp->purchase_id,
                    'purchase_bill_number' => $pp->purchase_bill_number,
                    'invoice_date' => $pp->invoice_date,
                    'product_id' => $pp->product_id,
                    'product_name' => $pp->product_name,
                    'product_code' => $pp->product_code,
                    'quantity' => $pp->quantity,
                    'free_quantity' => $pp->free_quantity ?? 0,
                    'price' => $pp->price,
                    'is_vatable' => (bool)$pp->is_vatable,
                    'measure_unit_id' => $pp->measure_unit_id,
                    'expiry_date' => $pp->expiry_date,
                    'return_quantity' => $pp->return_quantity,
                    'sale_quantity' => $pp->sale_quantity,
                    'sales_return_quantity' => $pp->sales_return_quantity,
                    'available_quantity' => $pp->available_quantity
                ];
            })->values()->toArray();

            return [
                'product_id' => $product->product_id,
                'product_name' => $product->product_name,
                'product_code' => $product->product_code,
                'min_price' => $product->min_price,
                'is_vatable' => (bool)$product->is_vatable,
                'measure_unit_id' => $product->measure_unit_id,
                'purchased_quantity' => $product->purchased_quantity,
                'return_quantity' => $product->return_quantity,
                'sale_quantity' => $product->sale_quantity,
                'sales_return_quantity' => $product->sales_return_quantity,
                'available_quantity' => $product->available_quantity,
                'expiry_dates' => array_filter(explode(',', $product->expiry_dates)),
                'field_values' => $fieldValues[$productId] ?? [],
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
            $productName = $request->input('product_name');
            $companyId = $request->input('company_id');

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

        }catch(ModelNotFoundException $e){
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
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'customer_id' => 'required|exists:customers,id',
            'salesman_id' => 'required|exists:salesmen,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_address' => 'nullable|string|max:255',
            'contact_number' => 'nullable|string|max:255',
            'credit_days' => 'nullable|string|max:255',
            'pan_number' => 'nullable|string|max:255',
            'ref_number' => 'nullable|string|max:255',
            'invoice_number' => 'nullable|string|max:255|unique:sales,invoice_number',
            'document_number' => 'nullable|string|max:255',
            'batch_no' => 'nullable|string|max:255',
            'balance' => 'nullable|numeric',
            'invoice_date' => 'nullable|date',
            'invoice_date_bs' => 'nullable|date',
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
            'sale_products.*.expiry_date' => 'nullable|date',
            'sale_products.*.field_values' => 'nullable|array',
            'sale_products.*.field_values.*' => 'array|min:1',
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
                $validated['sale_products'] = $purchaseProducts->map(function ($product) use ($validated) {
                    $productModel = Product::find($product->product_id);

                    $purchasedQuantity = $product->quantity + ($product->free_quantity ?? 0);
                    $purchaseReturnedQuantity = PurchaseProductReturn::where('purchase_product_id', $product->id)
                        ->where('company_id', $validated['company_id'])
                        ->whereNull('deleted_at')
                        ->sum('quantity');
                    $soldQuantity = SaleProduct::where('product_id', $product->product_id)
                        ->where('company_id', $validated['company_id'])
                        ->whereNull('deleted_at')
                        ->sum(DB::raw('quantity + COALESCE(free_quantity, 0)'));
                    $salesReturnedQuantity = SalesReturnProduct::where('product_id', $product->product_id)
                        ->where('company_id', $validated['company_id'])
                        ->whereNull('deleted_at')
                        ->sum(DB::raw('quantity + COALESCE(free_quantity, 0)'));

                    $availableQuantity = ($purchasedQuantity - $purchaseReturnedQuantity) - ($soldQuantity - $salesReturnedQuantity);

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
                    $purchaseProduct = $purchaseProducts->firstWhere('id', $saleProduct['purchase_product_id']);
                    $requestedQuantity = $saleProduct['quantity'] + ($saleProduct['free_quantity'] ?? 0);
                    $availablePurchaseQuantity = $purchaseProduct->quantity + ($purchaseProduct->free_quantity ?? 0);
                    if ($requestedQuantity > $availablePurchaseQuantity) {
                        return response()->json([
                            'error' => "Requested quantity for purchase product ID {$saleProduct['purchase_product_id']} at index {$index} exceeds purchased quantity ({$availablePurchaseQuantity})"
                        ], 422);
                    }
                }
            }
        }

        \Log::debug('Validated sale_products after batch processing', ['sale_products' => $validated['sale_products']]);

        // Validate field values and stock
        foreach ($validated['sale_products'] as $index => $product) {
            \Log::debug('Processing sale product at index ' . $index, ['product' => $product]);
            $productId = $product['product_id'];
            $purchaseProductId = $product['purchase_product_id'];
            $quantity = $product['quantity'];

            // Validate field_values
            $hasFieldValues = PurchaseProductFieldValue::where('purchase_product_id', $purchaseProductId)
                ->exists();
            if ($hasFieldValues && !$sellEntireBatch && (!isset($product['field_values']) || count($product['field_values']) !== $quantity)) {
                return response()->json([
                    'error' => "Field values count (" . (isset($product['field_values']) ? count($product['field_values']) : 0) . ") must match quantity ({$quantity}) for product ID {$productId} at index {$index}"
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

            // Stock check
            if (!$sellEntireBatch) {
                $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);
                $purchasedQuantity = PurchaseProduct::where('id', $purchaseProductId)
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->sum(DB::raw('quantity + COALESCE(free_quantity, 0)'));
                $purchaseReturnedQuantity = PurchaseProductReturn::where('purchase_product_id', $purchaseProductId)
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->sum('quantity');
                $batchNo = $product['batch_no'] ?? null;
                $soldQuantity = SaleProduct::where('product_id', $productId)
                    ->when($batchNo, function ($query) use ($batchNo) {
                        $query->where('batch_no', $batchNo);
                    }, function ($query) {
                        $query->whereNull('batch_no');
                    })
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->sum(DB::raw('quantity + COALESCE(free_quantity, 0)'));
                $salesReturnedQuantity = SalesReturnProduct::where('product_id', $productId)
                    ->when($batchNo, function ($query) use ($batchNo) {
                        $query->where('batch_no', $batchNo);
                    }, function ($query) {
                        $query->whereNull('batch_no');
                    })
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->sum(DB::raw('quantity + COALESCE(free_quantity, 0)'));

                $availableQuantity = ($purchasedQuantity - $purchaseReturnedQuantity) - ($soldQuantity - $salesReturnedQuantity);

                if ($requestedQuantity > $availableQuantity) {
                    return response()->json([
                        'error' => "Insufficient stock for purchase product ID {$purchaseProductId} at index {$index}. Available: {$availableQuantity}, Requested: {$requestedQuantity}"
                    ], 422);
                }
            }
        }

        $sale = DB::transaction(function () use ($validated) {
            $sale = Sale::create($validated);

            foreach ($validated['sale_products'] as $product) {
                // Validate required fields
                if (!isset($product['purchase_product_id'])) {
                    throw new \Exception('purchase_product_id is required for each sale product');
                }
                if (!isset($product['quantity']) || $product['quantity'] <= 0) {
                    throw new \Exception('Valid quantity is required for each sale product');
                }

                // Validate available quantity
                $availableQuantity = PurchaseProduct::withoutGlobalScopes()
                    ->where('purchase_products.id', $product['purchase_product_id'])
                    ->where('purchase_products.company_id', $validated['company_id'])
                    ->whereNull('purchase_products.deleted_at')
                    ->selectRaw('(SUM(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) - 
                                  COALESCE(SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)), 0) - 
                                  COALESCE(SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)), 0) + 
                                  COALESCE(SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)), 0)) as available_quantity')
                    ->leftJoin('purchase_product_returns', function ($join) use ($validated) {
                        $join->on('purchase_products.id', '=', 'purchase_product_returns.purchase_product_id')
                             ->whereNull('purchase_product_returns.deleted_at')
                             ->where('purchase_product_returns.company_id', $validated['company_id']);
                    })
                    ->leftJoin('sale_products', function ($join) use ($validated) {
                        $join->on('purchase_products.id', '=', 'sale_products.purchase_product_id')
                             ->whereNull('sale_products.deleted_at')
                             ->where('sale_products.company_id', $validated['company_id']);
                    })
                    ->leftJoin('sales_return_products', function ($join) use ($validated) {
                        $join->on('sale_products.id', '=', 'sales_return_products.sale_product_id')
                             ->whereNull('sales_return_products.deleted_at')
                             ->where('sales_return_products.company_id', $validated['company_id']);
                    })
                    ->groupBy('purchase_products.id')
                    ->value('available_quantity');

                if ($product['quantity'] > $availableQuantity) {
                    throw new \Exception('Requested quantity (' . $product['quantity'] . ') exceeds available stock (' . $availableQuantity . ') for purchase_product_id: ' . $product['purchase_product_id']);
                }

                // Set product details
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

                // Create the SaleProduct record
                $saleProduct = $sale->saleProducts()->create($product);

                if (!empty($product['field_values'])) {
                    // Fetch available PurchaseProductFieldValue records for the purchase_product_id
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
                        ->leftJoin('sale_products', function ($join) use ($validated) {
                            $join->on('purchase_products.id', '=', 'sale_products.purchase_product_id')
                                 ->whereNull('sale_products.deleted_at')
                                 ->where('sale_products.company_id', $validated['company_id']);
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
                        ->whereNull('purchase_return_product_field_values.id') // Not purchase-returned
                        ->where(function ($q) {
                            $q->whereNull('sales_product_field_values.id') // Not sold
                              ->orWhere(function ($subQ) {
                                  $subQ->whereNotNull('sales_product_field_values.id')
                                       ->whereNotNull('sale_return_product_field_values.id')
                                       ->whereColumn('sales_product_field_values.quantity_index', 'sale_return_product_field_values.quantity_index');
                              }); // Sold but fully returned
                        })
                        ->groupBy([
                            'purchase_product_field_values.quantity_index',
                            'purchase_product_field_values.product_field_id',
                            'purchase_product_field_values.value',
                        ])
                        ->get();

                    // Log available field values for debugging
                    Log::debug('Available field values for sale', [
                        'purchase_product_id' => $product['purchase_product_id'],
                        'field_values' => $availableFieldValues->toArray(),
                    ]);

                    // Validate provided field values against available ones
                    $fieldValues = [];
                    $selectedQuantityIndices = [];
                    foreach ($product['field_values'] as $fieldValueSet) {
                        $matched = false;
                        foreach ($availableFieldValues as $availableFieldValue) {
                            // Check if the field_value set matches the available field values
                            $matchesAllFields = true;
                            foreach ($fieldValueSet as $fieldValue) {
                                $found = $availableFieldValues->contains(function ($item) use ($fieldValue, $availableFieldValue) { // Fixed: Added $availableFieldValue to use
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
                                // Use the quantity_index from PurchaseProductFieldValue
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
                            Log::warning('Field values mismatch', [
                                'purchase_product_id' => $product['purchase_product_id'],
                                'field_value_set' => $fieldValueSet,
                                'available_field_values' => $availableFieldValues->toArray(),
                            ]);
                            throw new \Exception('Provided field values do not match available stock for purchase_product_id: ' . $product['purchase_product_id']);
                        }
                    }

                    // Ensure the number of matched quantity indices does not exceed requested quantity
                    if (count($selectedQuantityIndices) > $product['quantity']) {
                        throw new \Exception('Number of selected field value sets (' . count($selectedQuantityIndices) . ') exceeds requested quantity (' . $product['quantity'] . ') for purchase_product_id: ' . $product['purchase_product_id']);
                    }

                    if (!empty($fieldValues)) {
                        SalesProductFieldValue::insert($fieldValues);
                        Log::debug('SalesProductFieldValue created', [
                            'sale_product_id' => $saleProduct->id,
                            'field_values' => $fieldValues,
                        ]);
                    }
                }

                if (isset($validated['sale_additionals'])) {
                    $saleAdditionals = $validated['sale_additionals'];
                    $saleAdditionals['company_id'] = $validated['company_id'];
                    $saleAdditionals['sale_id'] = $sale->id;
                    if (isset($validated['purchase_bill_number'])) {
                        $saleAdditionals['purchase_bill_number'] = $validated['purchase_bill_number'];
                    }
                    $sale->saleAdditionals()->create($saleAdditionals);
                }
            }

            return $sale;
        });

        return response()->json([
            'message' => 'Sale created successfully',
            'data' => $sale->load([
                'saleProducts.fieldValues' => function ($query) {
                    $query->orderBy('quantity_index')->orderBy('product_field_id');
                },
                'saleAdditionals'
            ])
        ], 201);
    } catch (ModelNotFoundException $e) {
        Log::error('Model not found', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        return response()->json(['error' => 'Resource not found'], 404);
    } catch (QueryException $e) {
        Log::error('Database error', [
            'error' => $e->getMessage(),
            'sql' => $e->getSql(),
            'bindings' => $e->getBindings(),
        ]);
        return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
    } catch (\Exception $e) {
        Log::error('Unexpected error', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        return response()->json(['error' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
    }
}
       
    


 

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'customer_id' => 'required|exists:customers,id',
                'salesman_id' => 'required|exists:salesmen,id',
                'customer_name' => 'nullable|string|max:255',
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
                'invoice_date_bs' => 'nullable|date',
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
                'sale_products.*.expiry_date' => 'nullable|date',
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
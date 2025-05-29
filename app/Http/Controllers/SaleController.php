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
    protected function generateUniqueInvoiceNumber(string $fiscalYear): string
{
    // Prefix for the invoice number, e.g., "INV"
    $prefix = 'INV';

    
    $year = substr($fiscalYear, 0, 4);

    // Lock the sales table to prevent race conditions
    return DB::transaction(function () use ($prefix, $year) {
        // Find the latest invoice number for the given fiscal year
        $latestInvoice = Sale::where('invoice_number', 'like', "{$prefix}-{$year}-%")
            ->orderBy('invoice_number', 'desc')
            ->first();

        // Extract the sequence number from the latest invoice or start at 0
        $sequence = 0;
        if ($latestInvoice && preg_match("/{$prefix}-{$year}-(\d+)/", $latestInvoice->invoice_number, $matches)) {
            $sequence = (int)$matches[1];
        }

        // Increment the sequence
        $newSequence = $sequence + 1;

        // Format the new invoice number with leading zeros (e.g., 000001)
        $formattedSequence = str_pad($newSequence, 6, '0', STR_PAD_LEFT);

        // Construct the new invoice number
        $newInvoiceNumber = "{$prefix}-{$year}-{$formattedSequence}";

        // Double-check uniqueness (in case of concurrent transactions)
        while (Sale::where('invoice_number', $newInvoiceNumber)->exists()) {
            $newSequence++;
            $formattedSequence = str_pad($newSequence, 6, '0', STR_PAD_LEFT);
            $newInvoiceNumber = "{$prefix}-{$year}-{$formattedSequence}";
        }

        return $newInvoiceNumber;
    });
}

     private function getAvailableProductsForSale($companyId)
    {
        Log::debug('Fetching available products for sale', ['company_id' => $companyId]);

        try {
            DB::enableQueryLog();

            $query = PurchaseProduct::withoutGlobalScopes()
                ->select([
                    'products.id',
                    'products.name',
                    DB::raw('SUM(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) as purchased_quantity'),
                    DB::raw('COALESCE(SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)), 0) as return_quantity'),
                    DB::raw('COALESCE(SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)), 0) as sale_quantity'),
                    DB::raw('COALESCE(SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)), 0) as sales_return_quantity'),
                    DB::raw('SUM(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) - 
                            COALESCE(SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)), 0) - 
                            COALESCE(SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)), 0) + 
                            COALESCE(SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)), 0) as available_quantity')
                ])
                ->join('products', function ($join) use ($companyId) {
                    $join->on('purchase_products.product_id', '=', 'products.id')
                         ->where('products.company_id', $companyId)
                         ->whereNull('products.deleted_at');
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
                    $join->on('purchase_products.id', '=', 'sales_return_products.purchase_product_id')
                         ->whereNull('sales_return_products.deleted_at')
                         ->where('sales_return_products.company_id', $companyId);
                })
                ->whereNull('purchase_products.deleted_at')
                ->where('purchase_products.company_id', $companyId);

            $products = $query->groupBy('products.id', 'products.name')
                             ->having('available_quantity', '>', 0)
                             ->get();

            // Debug data counts
            $productCounts = PurchaseProduct::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->groupBy('product_id')
                ->pluck('product_id')
                ->count();
            $returnCounts = PurchaseProductReturn::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->count();
            $saleCounts = SaleProduct::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->count();
            $salesReturnCounts = SalesReturnProduct::withoutGlobalScopes()
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->count();

            Log::debug('Available products query', [
                'sql' => DB::getQueryLog(),
                'results_count' => $products->count(),
                'product_counts' => $productCounts,
                'return_counts' => $returnCounts,
                'sale_counts' => $saleCounts,
                'sales_return_counts' => $salesReturnCounts,
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

    
    // private function getAvailableProductsDetails(?int $productId = null, ?string $productName = null, ?int $companyId = null): array
    // {
    //     Log::debug('Fetching detailed available products with field values', [
    //         'product_id' => $productId,
    //         'product_name' => $productName,
    //         'company_id' => $companyId
    //     ]);

    //     try {
    //         DB::enableQueryLog();

    //         // Check unmatched PurchaseProduct records
    //         $unmatchedPurchases = PurchaseProduct::withoutGlobalScopes()
    //             ->select(
    //                 'purchase_products.id',
    //                 'purchase_products.product_id',
    //                 'purchase_products.quantity',
    //                 'purchase_products.free_quantity',
    //                 'purchase_products.expiry_date',
    //                 'purchase_products.company_id',
    //                 'purchase_products.measure_unit_id'
    //             )
    //             ->leftJoin('products', function ($join) use ($companyId) {
    //                 $join->on('purchase_products.product_id', '=', 'products.id')
    //                      ->where('products.company_id', $companyId)
    //                      ->whereNull('products.deleted_at');
    //             })
    //             ->leftJoin('measure_units', function ($join) {
    //                 $join->on('purchase_products.measure_unit_id', '=', 'measure_units.id');
    //             })
    //             ->whereNull('purchase_products.deleted_at')
    //             ->where('purchase_products.company_id', $companyId)
    //             ->where(function ($query) {
    //                 $query->whereNull('products.id')
    //                       ->orWhereNull('measure_units.id');
    //             })
    //             ->when($productId, function ($query) use ($productId) {
    //                 $query->where('purchase_products.product_id', $productId);
    //             })
    //             ->when($productName, function ($query) use ($productName) {
    //                 $query->where('purchase_products.product_name', $productName)
    //                       ->orWhere('products.name', $productName);
    //             })
    //             ->get();

    //         Log::debug('Unmatched PurchaseProduct records', [
    //             'company_id' => $companyId,
    //             'product_id' => $productId,
    //             'product_name' => $productName,
    //             'unmatched_count' => $unmatchedPurchases->count(),
    //             'unmatched_records' => $unmatchedPurchases->toArray()
    //         ]);

    //         // Debug product fields
    //         $productFields = ProductField::withoutGlobalScopes()
    //             ->whereIn('id', [1, 2, 3, 4, 5])
    //             ->select('id', 'name', 'company_id')
    //             ->get();

    //         Log::debug('Product fields check', [
    //             'company_id' => $companyId,
    //             'product_fields' => $productFields->toArray()
    //         ]);

    //         // Debug purchase product field values
    //         $fieldValuesCheck = PurchaseProductFieldValue::withoutGlobalScopes()
    //             ->select(
    //                 'id',
    //                 'purchase_product_id',
    //                 'quantity_index',
    //                 'product_field_id',
    //                 'value',
    //                 'company_id',
    //                 'product_id',
    //                 'deleted_at'
    //             )
    //             ->whereIn('purchase_product_id', [55, 56])
    //             ->get();

    //         Log::debug('Purchase product field values check', [
    //             'company_id' => $companyId,
    //             'purchase_product_ids' => [55, 56],
    //             'product_id' => 26,
    //             'field_values' => $fieldValuesCheck->toArray()
    //         ]);

    //         // Debug purchase_products data
    //         $purchaseProductsCheck = PurchaseProduct::withoutGlobalScopes()
    //             ->select(
    //                 'id',
    //                 'product_id',
    //                 'product_name',
    //                 'company_id',
    //                 'measure_unit_id',
    //                 'deleted_at'
    //             )
    //             ->whereIn('id', [55, 56])
    //             ->get();

    //         Log::debug('Purchase product data check', [
    //             'company_id' => $companyId,
    //             'purchase_product_ids' => [55, 56],
    //             'purchase_products' => $purchaseProductsCheck->toArray()
    //         ]);

    //         // Main product query
    //         $query = PurchaseProduct::withoutGlobalScopes()
    //             ->select([
    //                 'products.id as product_id',
    //                 'purchase_products.product_name as product_name',
    //                 'products.product_unique_id as product_code',
    //                 DB::raw('MIN(purchase_products.price) as min_price'),
    //                 DB::raw('MAX(purchase_products.is_vatable) as is_vatable'),
    //                 'purchase_products.measure_unit_id',
    //                 DB::raw('SUM(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) as purchased_quantity'),
    //                 DB::raw('COALESCE(SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)), 0) as return_quantity'),
    //                 DB::raw('COALESCE(SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)), 0) as sale_quantity'),
    //                 DB::raw('COALESCE(SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)), 0) as sales_return_quantity'),
    //                 DB::raw('SUM(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) - 
    //                          COALESCE(SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)), 0) - 
    //                          COALESCE(SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)), 0) + 
    //                          COALESCE(SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)), 0) as available_quantity'),
    //                 DB::raw('GROUP_CONCAT(DISTINCT purchase_products.expiry_date) as expiry_dates')
    //             ])
    //             ->join('products', function ($join) use ($companyId) {
    //                 $join->on('purchase_products.product_id', '=', 'products.id')
    //                      ->where('products.company_id', $companyId)
    //                      ->whereNull('products.deleted_at');
    //             })
    //             ->leftJoin('purchase_product_returns', function ($join) use ($companyId) {
    //                 $join->on('purchase_products.id', '=', 'purchase_product_returns.purchase_product_id')
    //                      ->whereNull('purchase_product_returns.deleted_at')
    //                      ->where('purchase_product_returns.company_id', $companyId);
    //             })
    //             ->leftJoin('sale_products', function ($join) use ($companyId) {
    //                 $join->on('purchase_products.id', '=', 'sale_products.purchase_product_id')
    //                      ->whereNull('sale_products.deleted_at')
    //                      ->where('sale_products.company_id', $companyId);
    //             })
    //             ->leftJoin('sales_return_products', function ($join) use ($companyId) {
    //                 $join->on('purchase_products.id', '=', 'sales_return_products.purchase_product_id')
    //                      ->whereNull('sales_return_products.deleted_at')
    //                      ->where('sales_return_products.company_id', $companyId);
    //             })
    //             ->whereNull('purchase_products.deleted_at')
    //             ->where('purchase_products.company_id', $companyId);

    //         if ($productId) {
    //             $query->where('products.id', $productId);
    //         }

    //         if ($productName) {
    //             $query->where('purchase_products.product_name', $productName)
    //                   ->orWhere('products.name', $productName);
    //         }

    //         $query->groupBy(['products.id', 'purchase_products.product_name', 'products.product_unique_id', 'purchase_products.measure_unit_id'])
    //               ->having('available_quantity', '>', 0);

    //         $products = $query->get();

    //         Log::debug('Available product details query', [
    //             'sql' => DB::getQueryLog(),
    //             'product_count' => $products->count(),
    //             'products' => $products->toArray()
    //         ]);

    //         if ($products->isEmpty()) {
    //             return [];
    //         }

    //         // Log product IDs
    //         $productIds = $products->pluck('product_id')->toArray();
    //         Log::debug('Product IDs for field values query', [
    //             'product_ids' => $productIds
    //         ]);

    //         // Fetch field values for available quantities
    //         $fieldValuesQuery = PurchaseProductFieldValue::withoutGlobalScopes()
    //             ->select([
    //                 'purchase_product_field_values.purchase_product_id',
    //                 'purchase_product_field_values.quantity_index',
    //                 'purchase_product_field_values.product_field_id',
    //                 'purchase_product_field_values.value',
    //                 'product_fields.name as field_name',
    //                 'purchase_products.expiry_date',
    //                 'purchase_products.product_id'
    //             ])
    //             ->leftJoin('product_fields', 'purchase_product_field_values.product_field_id', '=', 'product_fields.id')
    //             ->join('purchase_products', 'purchase_product_field_values.purchase_product_id', '=', 'purchase_products.id')
    //             ->leftJoin('sale_products', function ($join) use ($companyId) {
    //                 $join->on('purchase_products.id', '=', 'sale_products.purchase_product_id')
    //                      ->whereNull('sale_products.deleted_at')
    //                      ->where('sale_products.company_id', $companyId);
    //             })
    //             ->leftJoin('purchase_product_returns', function ($join) use ($companyId) {
    //                 $join->on('purchase_products.id', '=', 'purchase_product_returns.purchase_product_id')
    //                      ->whereNull('purchase_product_returns.deleted_at')
    //                      ->where('purchase_product_returns.company_id', $companyId);
    //             })
    //             ->leftJoin('sales_return_products', function ($join) use ($companyId) {
    //                 $join->on('purchase_products.id', '=', 'sales_return_products.purchase_product_id')
    //                      ->whereNull('sales_return_products.deleted_at')
    //                      ->where('sales_return_products.company_id', $companyId);
    //             })
    //             ->whereIn('purchase_products.product_id', $productIds)
    //             ->whereNull('purchase_product_field_values.deleted_at')
    //             ->whereNull('purchase_products.deleted_at')
    //             ->where('purchase_products.company_id', $companyId)
    //             // Comment out temporarily
    //             ->where('product_fields.company_id', $companyId)
    //             ->where('purchase_product_field_values.company_id', $companyId)
    //             ->groupBy([
    //                 'purchase_product_field_values.purchase_product_id',
    //                 'purchase_product_field_values.quantity_index',
    //                 'purchase_product_field_values.product_field_id',
    //                 'purchase_product_field_values.value',
    //                 'product_fields.name',
    //                 'purchase_products.expiry_date',
    //                 'purchase_products.product_id'
    //             ]);

    //         Log::debug('Field values query SQL', [
    //             'sql' => $fieldValuesQuery->toSql(),
    //             'bindings' => $fieldValuesQuery->getBindings()
    //         ]);

    //         $fieldValuesRaw = $fieldValuesQuery->get();

    //         Log::debug('Field values query result', [
    //             'field_values_count' => $fieldValuesRaw->count(),
    //             'field_values_raw' => $fieldValuesRaw->toArray()
    //         ]);

    //         // Map field values to match available quantities
    //         $fieldValues = [];
    //         foreach ($products as $product) {
    //             $productId = $product->product_id;
    //             $availableQuantity = $product->available_quantity;

    //             // Filter field values for this product
    //             $productFieldValues = $fieldValuesRaw->filter(function ($field) use ($productId) {
    //                 return $field->product_id == $productId;
    //             });

    //             Log::debug('Filtered field values for product', [
    //                 'product_id' => $productId,
    //                 'filtered_count' => $productFieldValues->count(),
    //                 'filtered_values' => $productFieldValues->toArray()
    //             ]);

    //             if ($productFieldValues->isEmpty()) {
    //                 $fieldValues[$productId] = [];
    //                 continue;
    //             }

    //             // Group by purchase_product_id and quantity_index
    //             $groupedFieldValues = $productFieldValues
    //                 ->groupBy('purchase_product_id')
    //                 ->flatMap(function ($purchaseGroup) {
    //                     return $purchaseGroup->groupBy('quantity_index')->map(function ($indexGroup) {
    //                         return $indexGroup->map(function ($field) {
    //                             return [
    //                                 'product_field_id' => $field->product_field_id,
    //                                 'field_name' => $field->field_name ?? 'Unknown',
    //                                 'value' => $field->value,
    //                                 'expiry_date' => $field->expiry_date
    //                             ];
    //                         })->values()->toArray();
    //                     })->values();
    //                 })->values()->toArray();

    //             // Limit field values to available quantity
    //             $fieldValues[$productId] = array_slice($groupedFieldValues, 0, $availableQuantity);
    //         }

    //         $result = $products->map(function ($product) use ($fieldValues) {
    //             return [
    //                 'product_id' => $product->product_id,
    //                 'product_name' => $product->product_name,
    //                 'product_code' => $product->product_code,
    //                 'min_price' => $product->min_price,
    //                 'is_vatable' => (bool)$product->is_vatable,
    //                 'measure_unit_id' => $product->measure_unit_id,
    //                 'purchased_quantity' => $product->purchased_quantity,
    //                 'return_quantity' => $product->return_quantity,
    //                 'sale_quantity' => $product->sale_quantity,
    //                 'sales_return_quantity' => $product->sales_return_quantity,
    //                 'available_quantity' => $product->available_quantity,
    //                 'expiry_dates' => array_filter(explode(',', $product->expiry_dates)),
    //                 'field_values' => $fieldValues[$product->product_id] ?? []
    //             ];
    //         })->toArray();

    //         return $result;

    //     } catch (\Exception $e) {
    //         Log::error('Error fetching detailed available products', [
    //             'product_id' => $productId,
    //             'product_name' => $productName,
    //             'company_id' => $companyId,
    //             'error' => $e->getMessage(),
    //             'trace' => $e->getTraceAsString()
    //         ]);
    //         throw $e;
    //     } finally {
    //         DB::disableQueryLog();
    //     }
    // }

    private function getAvailableProductsDetails(?int $productId = null, ?string $productName = null, ?int $companyId = null): array
{
    Log::debug('Fetching detailed available products with field values', [
        'product_id' => $productId,
        'product_name' => $productName,
        'company_id' => $companyId
    ]);

    try {
        DB::enableQueryLog();

        // Check unmatched PurchaseProduct records
        $unmatchedPurchases = PurchaseProduct::withoutGlobalScopes()
            ->select(
                'purchase_products.id',
                'purchase_products.product_id',
                'purchase_products.quantity',
                'purchase_products.free_quantity',
                'purchase_products.expiry_date',
                'purchase_products.company_id',
                'purchase_products.measure_unit_id'
            )
            ->leftJoin('products', function ($join) use ($companyId) {
                $join->on('purchase_products.product_id', '=', 'products.id')
                     ->where('products.company_id', $companyId)
                     ->whereNull('products.deleted_at');
            })
            ->leftJoin('measure_units', function ($join) {
                $join->on('purchase_products.measure_unit_id', '=', 'measure_units.id');
            })
            ->whereNull('purchase_products.deleted_at')
            ->where('purchase_products.company_id', $companyId)
            ->where(function ($query) {
                $query->whereNull('products.id')
                      ->orWhereNull('measure_units.id');
            })
            ->when($productId, function ($query) use ($productId) {
                $query->where('purchase_products.product_id', $productId);
            })
            ->when($productName, function ($query) use ($productName) {
                $query->where('purchase_products.product_name', $productName)
                      ->orWhere('products.name', $productName);
            })
            ->get();

        Log::debug('Unmatched PurchaseProduct records', [
            'company_id' => $companyId,
            'product_id' => $productId,
            'product_name' => $productName,
            'unmatched_count' => $unmatchedPurchases->count(),
            'unmatched_records' => $unmatchedPurchases->toArray()
        ]);

        // Debug product fields
        $productFields = ProductField::withoutGlobalScopes()
            ->whereIn('id', [1, 2, 3, 4, 5])
            ->select('id', 'name', 'company_id')
            ->get();

        Log::debug('Product fields check', [
            'company_id' => $companyId,
            'product_fields' => $productFields->toArray()
        ]);

        // Debug purchase product field values
        $fieldValuesCheck = PurchaseProductFieldValue::withoutGlobalScopes()
            ->select(
                'id',
                'purchase_product_id',
                'quantity_index',
                'product_field_id',
                'value',
                'company_id',
                'product_id',
                'deleted_at'
            )
            ->whereIn('purchase_product_id', [55, 56, 60])
            ->get();

        Log::debug('Purchase product field values check', [
            'company_id' => $companyId,
            'purchase_product_ids' => [55, 56, 60],
            'product_id' => $productId ?? 29,
            'field_values' => $fieldValuesCheck->toArray()
        ]);

        // Debug purchase_products data
        $purchaseProductsCheck = PurchaseProduct::withoutGlobalScopes()
            ->select(
                'id',
                'product_id',
                'product_name',
                'company_id',
                'measure_unit_id',
                'deleted_at'
            )
            ->whereIn('id', [55, 56, 60])
            ->get();

        Log::debug('Purchase product data check', [
            'company_id' => $companyId,
            'purchase_product_ids' => [55, 56, 60],
            'purchase_products' => $purchaseProductsCheck->toArray()
        ]);

        // Main product query
        $query = PurchaseProduct::withoutGlobalScopes()
            ->select([
                'products.id as product_id',
                'purchase_products.product_name as product_name',
                'products.product_unique_id as product_code',
                DB::raw('MIN(purchase_products.price) as min_price'),
                DB::raw('MAX(purchase_products.is_vatable) as is_vatable'),
                'purchase_products.measure_unit_id',
                DB::raw('SUM(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) as purchased_quantity'),
                DB::raw('COALESCE(SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)), 0) as return_quantity'),
                DB::raw('COALESCE(SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)), 0) as sale_quantity'),
                DB::raw('COALESCE(SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)), 0) as sales_return_quantity'),
                DB::raw('SUM(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) - 
                         COALESCE(SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)), 0) - 
                         COALESCE(SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)), 0) + 
                         COALESCE(SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)), 0) as available_quantity'),
                DB::raw('GROUP_CONCAT(DISTINCT purchase_products.expiry_date) as expiry_dates')
            ])
            ->join('products', function ($join) use ($companyId) {
                $join->on('purchase_products.product_id', '=', 'products.id')
                     ->where('products.company_id', $companyId)
                     ->whereNull('products.deleted_at');
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
                $join->on('purchase_products.id', '=', 'sales_return_products.purchase_product_id')
                     ->whereNull('sales_return_products.deleted_at')
                     ->where('sales_return_products.company_id', $companyId);
            })
            ->whereNull('purchase_products.deleted_at')
            ->where('purchase_products.company_id', $companyId);

        if ($productId) {
            $query->where('products.id', $productId);
        }

        if ($productName) {
            $query->where('purchase_products.product_name', $productName)
                  ->orWhere('products.name', $productName);
        }

        $query->groupBy(['products.id', 'purchase_products.product_name', 'products.product_unique_id', 'purchase_products.measure_unit_id'])
              ->having('available_quantity', '>', 0);

        $products = $query->get();

        Log::debug('Available product details query', [
            'sql' => DB::getQueryLog(),
            'product_count' => $products->count(),
            'products' => $products->toArray()
        ]);

        if ($products->isEmpty()) {
            return [];
        }

        // Log product IDs
        $productIds = $products->pluck('product_id')->toArray();
        Log::debug('Product IDs for field values query', [
            'product_ids' => $productIds
        ]);

        // Fetch field values for available quantities with quantity_index adjustments
        $fieldValuesQuery = PurchaseProductFieldValue::withoutGlobalScopes()
            ->select([
                'purchase_product_field_values.purchase_product_id',
                'purchase_product_field_values.quantity_index',
                'purchase_product_field_values.product_field_id',
                'purchase_product_field_values.value',
                'product_fields.name as field_name',
                'purchase_products.expiry_date',
                'purchase_products.product_id',
                DB::raw('1 as purchased_quantity'), // Each quantity_index represents 1 unit
                DB::raw('COALESCE(COUNT(DISTINCT purchase_return_product_field_values.quantity_index), 0) as return_quantity'),
                DB::raw('COALESCE(COUNT(DISTINCT sales_product_field_values.quantity_index), 0) as sale_quantity'),
                DB::raw('COALESCE(COUNT(DISTINCT sale_return_product_field_values.quantity_index), 0) as sales_return_quantity'),
                DB::raw('1 - 
                         COALESCE(COUNT(DISTINCT purchase_return_product_field_values.quantity_index), 0) - 
                         COALESCE(COUNT(DISTINCT sales_product_field_values.quantity_index), 0) + 
                         COALESCE(COUNT(DISTINCT sale_return_product_field_values.quantity_index), 0) as available_quantity')
            ])
            ->leftJoin('product_fields', 'purchase_product_field_values.product_field_id', '=', 'product_fields.id')
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
            ->leftJoin('sale_products', function ($join) use ($companyId) {
                $join->on('purchase_products.id', '=', 'sale_products.purchase_product_id')
                     ->whereNull('sale_products.deleted_at')
                     ->where('sale_products.company_id', $companyId);
            })
            ->leftJoin('sales_product_field_values', function ($sale) use ($companyId) {
                $sale->on('sale_products.id', '=', 'sales_product_field_values.sale_product_id')
                    ->on('purchase_product_field_values.quantity_index', '=', 'sales_product_field_values.quantity_index')
                    ->whereNull('sales_product_field_values.deleted_at')
                    ->where('sales_product_field_values.company_id', $companyId);
            })
            ->leftJoin('sales_return_products', function ($join) use ($companyId) {
                $join->on('purchase_products.id', '=', 'sales_return_products.purchase_product_id')
                     ->whereNull('sales_return_products.deleted_at')
                     ->where('sales_return_products.company_id', $companyId);
            })
            ->leftJoin('sale_return_product_field_values', function ($join) use ($companyId) {
                $join->on('sales_return_products.id', '=', 'sale_return_product_field_values.sale_return_product_id')
                     ->on('purchase_product_field_values.quantity_index', '=', 'sale_return_product_field_values.quantity_index')
                     ->whereNull('sale_return_product_field_values.deleted_at')
                     ->where('sale_return_product_field_values.company_id', $companyId);
            })
            ->whereIn('purchase_products.product_id', $productIds)
            ->whereNull('purchase_product_field_values.deleted_at')
            ->whereNull('purchase_products.deleted_at')
            ->where('purchase_products.company_id', $companyId)
            ->where('product_fields.company_id', $companyId)
            ->where('purchase_product_field_values.company_id', $companyId)
            ->groupBy([
                'purchase_product_field_values.purchase_product_id',
                'purchase_product_field_values.quantity_index',
                'purchase_product_field_values.product_field_id',
                'purchase_product_field_values.value',
                'product_fields.name',
                'purchase_products.expiry_date',
                'purchase_products.product_id'
            ])
            ->having('available_quantity', '>', 0);

        Log::debug('Field values query SQL', [
            'sql' => $fieldValuesQuery->toSql(),
            'bindings' => $fieldValuesQuery->getBindings()
        ]);

        $fieldValuesRaw = $fieldValuesQuery->get();

        Log::debug('Field values query results', [
            'field_values_count' => $fieldValuesRaw->count(),
            'field_values_raw' => $fieldValuesRaw->toArray(),
            'quantity_indices' => $fieldValuesRaw->pluck('quantity_index')->unique()->toArray()
        ]);

        // Map field values to match available quantities
        $fieldValues = [];
        foreach ($products as $product) {
            $productId = $product->product_id;
            $availableQuantity = $product->available_quantity;

            // Filter field values for this product
            $productFieldValues = $fieldValuesRaw->filter(function ($field) use ($productId) {
                return $field->product_id == $productId;
            });

            Log::debug('Filtered field values for product', [
                'product_id' => $productId,
                'filtered_count' => $productFieldValues->count(),
                'filtered_values' => $productFieldValues->toArray(),
                'quantity_indices' => $productFieldValues->pluck('quantity_index')->unique()->toArray()
            ]);

            if ($productFieldValues->isEmpty()) {
                $fieldValues[$productId] = [];
                continue;
            }

            // Group by purchase_product_id and quantity_index
            $groupedFieldValues = $productFieldValues
                ->groupBy('purchase_product_id')
                ->flatMap(function ($purchaseGroup) use ($availableQuantity) {
                    return $purchaseGroup->groupBy('quantity_index')->map(function ($indexGroup) {
                        $indexAvailableQuantity = $indexGroup->first()->available_quantity ?? 0;
                        return $indexGroup->map(function ($field) {
                            return [
                                'product_field_id' => $field->product_field_id,
                                'field_name' => $field->field_name ?? 'Unknown',
                                'value' => $field->value,
                                'expiry_date' => $field->expiry_date,
                                'available_quantity' => $field->available_quantity
                            ];
                        })->values()->toArray();
                    })->filter(function ($group) {
                        return !empty($group); // Only include non-empty groups
                    })->take($availableQuantity)->values();
                })->values()->toArray();

            // Limit field values to the product's total available quantity
            $fieldValues[$productId] = array_slice($groupedFieldValues, 0, $availableQuantity);
        }

        $result = $products->map(function ($product) use ($fieldValues) {
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
                'field_values' => $fieldValues[$product->product_id] ?? []
            ];
        })->toArray();

        return $result;

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

            // Authorization check
            if (auth()->check()) {
                $user = auth()->user();
                $userCompanyId = optional($user->company)->company_id;

                if (!$userCompanyId || $userCompanyId != $companyId) {
                    return response()->json([
                        'message' => 'Unauthorized access to company resources'
                    ], 403);
                }

                if ($user->hasRole('company_admin') && $userCompanyId != $companyId) {
                    return response()->json([
                        'message' => 'Unauthorized access to company resources for company admin'
                    ], 403);
                }
            } else {
                return response()->json([
                    'message' => 'Unauthenticated'
                ], 401);
            }

            $products = $this->getAvailableProductsForSale($companyId);

            if ($includeDetails) {
                $products = collect($this->getAvailableProductsDetails(null, null, $companyId));
            }

            Log::debug('Available products response', [
                'company_id' => $companyId,
                'include_details' => $includeDetails,
                'product_count' => $products->count(),
                'products' => $products->toArray()
            ]);

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

            // Authorization check
            if (auth()->check() && auth()->user()->hasRole('company_admin')) {
                $userCompanyId = optional(auth()->user()->company)->company_id;
                if ($userCompanyId != $companyId) {
                    return response()->json(['error' => 'Unauthorized access to company resources'], 403);
                }
            }

            $products = $this->getAvailableProductsDetails($productId, $productName, $companyId);

            return response()->json([
                'message' => !empty($products) ? 'Product details retrieved' : 'No matching product found',
                'data' => !empty($products) ? $products : null
            ], !empty($products) ? 200 : 404);

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
            'pan_number' => 'nullable|string|max:255',
            'ref_number' => 'nullable|string|max:255',
            'invoice_number' => 'nullable|string|max:255|unique:sales,invoice_number',
            'document_number' => 'nullable|string|max:255',
            'batch_no' => 'nullable|string|max:255',
            'balance' => 'nullable|numeric',
            'invoice_date' => 'nullable|date',
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

        // Calculate fiscal year
        $date = $request->invoice_date ? Carbon::parse($request->invoice_date) : now();
        $fiscal_year_start = Carbon::create($date->year, 7, 16);
        $fiscalYear = $date->lessThan($fiscal_year_start)
            ? ($date->year - 1) . '-' . substr($date->year, 2, 2)
            : $date->year . '-' . substr($date->year + 1, 2, 2);

        // Generate unique invoice number if not provided
        $validated['invoice_number'] = $request->input('invoice_number') ?? $this->generateUniqueInvoiceNumber($fiscalYear);

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
                        'batch_no' => 'BATCH-' . $product->id . '-' . now()->format('Ymd'), // Generate fallback batch_no
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
                $product['company_id'] = $validated['company_id'];
                $product['sale_id'] = $sale->id;
                $productModel = Product::find($product['product_id']);
                $product['product_code'] = $productModel->product_unique_id ?? null;
                $product['product_name'] = $productModel->name ?? null;
                $product['name'] = $productModel->name ?? null;
                $product['amount'] = ($product['quantity'] * $product['price']) - ($product['discount_amount'] ?? 0);

                $saleProduct = $sale->saleProducts()->create($product);

                if (!empty($product['field_values'])) {
                    $fieldValues = [];
                    foreach ($product['field_values'] as $quantityIndex => $fieldValueSet) {
                        foreach ($fieldValueSet as $fieldValue) {
                            $fieldValues[] = [
                                'company_id' => $validated['company_id'],
                                'product_field_id' => $fieldValue['product_field_id'],
                                'product_id' => $saleProduct->product_id,
                                'sale_product_id' => $saleProduct->id,
                                'quantity_index' => $quantityIndex,
                                'value' => $fieldValue['value'],
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    }
                    SalesProductFieldValue::insert($fieldValues);
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
        Log::error($e);
        return response()->json(['error' => 'Resource not found'], 404);
    } catch (QueryException $e) {
        Log::error($e);
        return response()->json(['error' => 'Database error occurred'], 500);
    } catch (\Exception $e) {
        Log::error($e);
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
            'pan_number' => 'nullable|string|max:255',
            'ref_number' => 'nullable|string|max:255',
            'invoice_number' => ['nullable', 'string', 'max:255', Rule::unique('sales', 'invoice_number')->ignore($id)],
            'document_number' => 'nullable|string|max:255',
            'batch_no' => 'nullable|string|max:255',
            'balance' => 'nullable|numeric',
            'invoice_date' => 'nullable|date',
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

        // Calculate fiscal year
        $newInvoiceDate = $request->invoice_date ? Carbon::parse($request->invoice_date) : now();
        $fiscal_year_start = Carbon::create($newInvoiceDate->year, 7, 16);
        $fiscalYearNew = $newInvoiceDate->lessThan($fiscal_year_start)
            ? ($newInvoiceDate->year - 1) . '-' . substr($newInvoiceDate->year, 2, 2)
            : $newInvoiceDate->year . '-' . substr($newInvoiceDate->year + 1, 2, 2);

        // Generate unique invoice number if not provided
        if (!isset($validated['invoice_number'])) {
            $validated['invoice_number'] = $this->generateUniqueInvoiceNumber($fiscalYearNew);
        }

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
                        ->where('sale_id', '!=', $id)
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
                    $availableQuantity = $purchaseProduct->quantity + ($purchaseProduct->free_quantity ?? 0);
                    if ($requestedQuantity > $availableQuantity) {
                        return response()->json([
                            'error' => "Requested quantity for purchase product ID {$saleProduct['purchase_product_id']} at index {$index} exceeds purchased quantity ({$availableQuantity})"
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
                    ->where('sale_id', '!=', $id)
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

                if (isset($product['id'])) {
                    $existingProduct = SaleProduct::where('id', $product['id'])->where('sale_id', $id)->first();
                    if ($existingProduct) {
                        $existingQuantity = $existingProduct->quantity + ($existingProduct->free_quantity ?? 0);
                        $availableQuantity += $existingQuantity;
                    }
                }

                if ($requestedQuantity > $availableQuantity) {
                    return response()->json([
                        'error' => "Insufficient stock for purchase product ID {$purchaseProductId} at index {$index}. Available: {$availableQuantity}, Requested: {$requestedQuantity}"
                    ], 422);
                }
            }
        }

        $sale = DB::transaction(function () use ($validated, $id) {
            $sale = Sale::findOrFail($id);
            $sale->update($validated);

            $existingProductIds = $sale->saleProducts()->withTrashed()->pluck('id')->toArray();
            $incomingProductIds = collect($validated['sale_products'])->pluck('id')->filter()->toArray();
            $productsToDelete = array_diff($existingProductIds, $incomingProductIds);
            if (!empty($productsToDelete)) {
                SaleProduct::whereIn('id', $productsToDelete)->delete();
            }

            foreach ($validated['sale_products'] as $product) {
                $product['company_id'] = $validated['company_id'];
                $product['sale_id'] = $sale->id;
                $productModel = Product::find($product['product_id']);
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
                    }
                } else {
                    $saleProduct = $sale->saleProducts()->create($product);
                }

                if (isset($product['field_values'])) {
                    $processedFieldIds = [];
                    $existingFieldIds = SalesProductFieldValue::where('sale_product_id', $saleProduct->id)->withTrashed()->pluck('id')->toArray();

                    foreach ($product['field_values'] as $quantityIndex => $fieldValueSet) {
                        foreach ($fieldValueSet as $fieldValue) {
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
                                        'quantity_index' => $quantityIndex,
                                        'updated_at' => now(),
                                    ]);
                                    $processedFieldIds[] = $existingValue->id;
                                }
                            } else {
                                $newFieldValue = SalesProductFieldValue::create([
                                    'company_id' => $validated['company_id'],
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'product_id' => $saleProduct->product_id,
                                    'sale_product_id' => $saleProduct->id,
                                    'quantity_index' => $quantityIndex,
                                    'value' => $fieldValue['value'],
                                ]);
                                $processedFieldIds[] = $newFieldValue->id;
                            }
                        }
                    }

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
    } catch (ValidationException $e) {
        \Log::warning('Validation failed during sale update', [
            'sale_id' => $id,
            'errors' => $e->errors(),
            'trace' => $e->getTraceAsString()
        ]);
        return response()->json([
            'message' => $e->getMessage(),
            'errors' => $e->errors()
        ], 422);
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


}
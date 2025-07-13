<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\MeasureUnit;
use App\Models\Product;
use App\Models\ProductList;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductFieldValue;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\Sale;
use App\Models\SaleAdditional;
use App\Models\SaleProduct;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SalesProductFieldValue;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;


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

            // Pre-fetch measure units for efficiency
            $measureUnitsCalc = MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            // Fetch all relevant products
            $products = Product::select(['id', 'name'])
                ->where(function ($query) use ($companyId) {
                    $query->where('company_id', $companyId)
                        ->orWhereNull('company_id');
                })
                ->whereNull('deleted_at')
                ->get();

            if ($products->isEmpty()) {
                Log::warning('No products found', ['company_id' => $companyId]);
                return collect([]);
            }

            $productIds = $products->pluck('id')->toArray();

            // Fetch purchase products
            $purchaseProducts = PurchaseProduct::whereIn('product_id', $productIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->with([
                    'purchaseProductReturns' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->with(['measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity'])]),
                    'saleProducts' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->with([
                            'measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity']),
                            'saleProductReturns' => fn($q) => $q->whereNull('deleted_at')
                                ->where('company_id', $companyId)
                                ->with(['measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity'])])
                        ]),
                    'fieldValues' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                ])
                ->get();

            if ($purchaseProducts->isEmpty()) {
                Log::warning('No purchase products found', ['company_id' => $companyId, 'product_ids' => $productIds]);
                return collect([]);
            }

            // Fetch quantity indexes
            $soldQuantityIndexes = SalesProductFieldValue::whereIn('sale_product_id', $purchaseProducts->flatMap(fn($pp) => $pp->saleProducts->pluck('id')))
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->select(['sale_product_id', 'quantity_index'])
                ->get()
                ->groupBy(function ($fv) use ($purchaseProducts) {
                    $saleProduct = SaleProduct::find($fv->sale_product_id);
                    return $saleProduct ? $saleProduct->purchase_product_id : null;
                })
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            $returnedQuantityIndexes = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $purchaseProducts->flatMap(fn($pp) => $pp->purchaseProductReturns->pluck('id')))
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->select(['purchase_return_product_id', 'quantity_index'])
                ->get()
                ->groupBy(function ($fv) use ($purchaseProducts) {
                    $returnProduct = PurchaseProductReturn::find($fv->purchase_return_product_id);
                    return $returnProduct ? $returnProduct->purchase_product_id : null;
                })
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            // Process products
            $results = $products->map(function ($product) use ($purchaseProducts, $soldQuantityIndexes, $returnedQuantityIndexes, $companyId, $measureUnitsCalc) {
                $productPurchaseProducts = $purchaseProducts->filter(fn($pp) => $pp->product_id == $product->id);

                $purchasedPieces = $productPurchaseProducts->sum(function ($pp) use ($measureUnitsCalc) {
                    return $this->calculatePieces(
                        ($pp->quantity ?? 0) + ($pp->free_quantity ?? 0),
                        $measureUnitsCalc[$pp->measure_unit_id]->quantity ?? 1
                    );
                });

                $returnPieces = $productPurchaseProducts->sum(function ($pp) use ($measureUnitsCalc) {
                    return $pp->purchaseProductReturns->reduce(
                        fn($carry, $return) => $carry + $this->calculatePieces(
                            ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                            $measureUnitsCalc[$return->measure_unit_id]->quantity ?? 1
                        ),
                        0
                    );
                });
                $returnPieces = min($returnPieces, $purchasedPieces);

                $salePieces = $productPurchaseProducts->sum(function ($pp) use ($measureUnitsCalc) {
                    return $pp->saleProducts->reduce(
                        fn($carry, $sale) => $carry + $this->calculatePieces(
                            ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0),
                            $measureUnitsCalc[$sale->measure_unit_id]->quantity ?? 1
                        ),
                        0
                    );
                });

                $salesReturnPieces = $productPurchaseProducts->sum(function ($pp) use ($measureUnitsCalc) {
                    return $pp->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns)->reduce(
                        fn($carry, $return) => $carry + $this->calculatePieces(
                            ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                            $measureUnitsCalc[$return->measure_unit_id]->quantity ?? 1
                        ),
                        0
                    );
                });

                $availablePieces = $purchasedPieces - $returnPieces - $salePieces + $salesReturnPieces;

                return (object) [
                    'id' => $product->id,
                    'name' => $product->name,
                    'purchased_quantity' => $purchasedPieces,
                    'return_quantity' => $returnPieces,
                    'sale_quantity' => $salePieces,
                    'sales_return_quantity' => $salesReturnPieces,
                    'available_quantity' => max(0, (int) $availablePieces),
                ];
            })->filter(fn($product) => $product->available_quantity > 0)->values();

            Log::debug('Available products query', [
                'sql' => DB::getQueryLog(),
                'results_count' => $results->count(),
                'products' => $results->toArray()
            ]);

            return $results;

        } catch (\Exception $e) {
            Log::error('Error fetching available products for sale', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
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
                'response_unit_id' => 'nullable|integer|exists:measure_units,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $productId = $request->input('product_id');
            $productName = trim(strtolower($request->input('product_name')));
            $companyId = $request->input('company_id');
            $responseUnitId = $request->input('response_unit_id');

            Log::debug('Input parameters', [
                'product_id' => $productId,
                'product_name' => $productName,
                'company_id' => $companyId,
                'response_unit_id' => $responseUnitId
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

            $products = $this->getAvailableProductsDetails($productId, $productName, $companyId, $responseUnitId);

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


    private function getAvailableProductsDetails(?int $productId = null, ?string $productName = null, ?int $companyId = null, ?int $responseUnitId = null): array
    {
        Log::debug('Fetching detailed available products with purchase products', [
            'product_id' => $productId,
            'product_name' => $productName,
            'company_id' => $companyId,
            'response_unit_id' => $responseUnitId
        ]);

        try {
            DB::enableQueryLog();

            // Pre-fetch measure units for efficiency
            $measureUnitsCalc = MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            // Validate response_unit_id (optional)
            if ($responseUnitId && !isset($measureUnitsCalc[$responseUnitId])) {
                Log::warning('Invalid response unit ID', ['response_unit_id' => $responseUnitId]);
                return ['message' => 'Invalid response unit ID', 'data' => []];
            }

            // Fetch products
            $productsQuery = Product::select([
                'products.id as product_id',
                'products.name as product_name',
                'products.product_unique_id as product_code',
                'products.measure_unit_id',
                'measure_units.name as measure_unit_name',
                'measure_units.quantity as measure_unit_quantity',
                'products.is_vatable',
            ])
                ->leftJoin('measure_units', 'products.measure_unit_id', '=', 'measure_units.id')
                ->whereNull('products.deleted_at')
                ->where(function ($query) use ($companyId) {
                    $query->where('products.company_id', $companyId)
                        ->orWhereNull('products.company_id');
                });

            if ($productId) {
                $productsQuery->where('products.id', $productId);
            }

            if ($productName) {
                $productsQuery->where('products.name', 'like', "%{$productName}%");
            }

            $products = $productsQuery->get();

            if ($products->isEmpty()) {
                Log::warning('No products found', [
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'company_id' => $companyId,
                    'query_log' => DB::getQueryLog()
                ]);
                return ['message' => 'No available products found', 'data' => []];
            }

            $productIds = $products->pluck('product_id')->toArray();

            if ($productName) {
                $productNameForUnit = Product::where('name', $productName)->first();
                $productForUnit = $productNameForUnit->id;
            }

            if ($productId) {
                $productNameForUnit = Product::where('id', $productId)->first();
                $productForUnit = $productNameForUnit->id;
            }


            $retailSalePrice = Product::where('id', $productForUnit)->pluck('retail_sales_price')->first();
            $productSoldPrice = SaleProduct::where('product_id', $productForUnit)
                ->orderByDesc('created_at')
                ->get(['price', 'created_at']);


            $avgPrice = $productSoldPrice->avg('price');
            $minPrice = $productSoldPrice->min('price');
            $latestSoldPrice = $productSoldPrice->first()->price ?? 0;



            $getProductForMeasureUnits = Product::with('productLists')
                ->where('id', $productForUnit)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->first();

            $productPrimaryMeasureUnit = ProductList::where('product_id', $productForUnit)
                ->where('is_primary', 1)
                ->pluck('measure_unit_id')
                ->first();

            if (!$productPrimaryMeasureUnit) {
                $productPrimaryMeasureUnit = ProductList::where('product_id', $productForUnit)
                    ->orderBy('created_at', 'asc')
                    ->pluck('measure_unit_id')
                    ->first();
            }

            $primarayMeasureUnitId = MeasureUnit::where('id', $productPrimaryMeasureUnit)->first();
            $primaryMeasureUnitQuantity = $primarayMeasureUnitId->quantity ?? 0;



            if ($getProductForMeasureUnits) {
                // Step 1: Get measure_unit_id from Product
                $unitIds = collect([$getProductForMeasureUnits->measure_unit_id]);

                // Step 2: Add all measure_unit_ids from ProductList
                $productListUnitIds = $getProductForMeasureUnits->productLists->pluck('measure_unit_id');

                // Step 3: Merge and make unique
                $allUnitIds = $unitIds->merge($productListUnitIds)->unique()->values();


            } else {
                echo 'Product not found';
            }

            $measureUnitsUsed = MeasureUnit::whereIn('id', $allUnitIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'quantity']) // Get as a collection
                ->map(function ($unit) {
                    return [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'measure_unit_quantity' => $unit->quantity ?? null,
                    ];
                });



            $purchaseProducts = PurchaseProduct::whereIn('product_id', $productIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->with([
                    'purchase' => fn($q) => $q->select(['id', 'company_id', 'purchase_bill_number', 'invoice_date'])
                        ->whereNull('deleted_at'),
                    'purchaseProductReturns' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->with(['measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity'])]),
                    'saleProducts' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->with([
                            'measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity']),
                            'saleProductReturns' => fn($q) => $q->whereNull('deleted_at')
                                ->where('company_id', $companyId)
                                ->with(['measureUnit' => fn($q) => $q->select(['id', 'name', 'quantity'])])
                        ]),
                    'fieldValues' => fn($q) => $q->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->with([
                            'productField' => fn($q) => $q->select(['id', 'name', 'company_id'])
                                ->where('company_id', $companyId)
                                ->whereNull('deleted_at')
                        ])
                ])
                // Optional: Add period filtering if needed
                // ->whereHas('purchase', fn($q) => $q->whereBetween('invoice_date', [$startDate, $endDate]))
                ->orderBy('created_at', 'asc')
                ->get();

            if ($purchaseProducts->isEmpty()) {
                Log::warning('No purchase products found', [
                    'product_ids' => $productIds,
                    'company_id' => $companyId,
                    'query_log' => DB::getQueryLog()
                ]);
                return ['message' => 'No available products found', 'data' => []];
            }

            // Fetch quantity indexes
            $soldQuantityIndexes = SalesProductFieldValue::whereIn('sale_product_id', $purchaseProducts->flatMap(fn($pp) => $pp->saleProducts->pluck('id')))
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->select(['sale_product_id', 'quantity_index'])
                ->get()
                ->groupBy(function ($fv) use ($purchaseProducts) {
                    $saleProduct = SaleProduct::find($fv->sale_product_id);
                    return $saleProduct ? $saleProduct->purchase_product_id : null;
                })
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            $returnedQuantityIndexes = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $purchaseProducts->flatMap(fn($pp) => $pp->purchaseProductReturns->pluck('id')))
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->select(['purchase_return_product_id', 'quantity_index'])
                ->get()
                ->groupBy(function ($fv) use ($purchaseProducts) {
                    $returnProduct = PurchaseProductReturn::find($fv->purchase_return_product_id);
                    return $returnProduct ? $returnProduct->purchase_product_id : null;
                })
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            // Process results
            $result = $products->map(function ($product) use ($purchaseProducts, $soldQuantityIndexes, $returnedQuantityIndexes, $companyId, $measureUnitsCalc, $measureUnitsUsed, $latestSoldPrice, $minPrice, $avgPrice, $retailSalePrice, $primaryMeasureUnitQuantity, $primarayMeasureUnitId) {

                $allFieldValues = $purchaseProducts->filter(fn($pp) => $pp->product_id == $product->product_id)
                    ->flatMap(function ($pp) use ($soldQuantityIndexes, $returnedQuantityIndexes) {
                        return $pp->fieldValues->filter(function ($fv) use ($soldQuantityIndexes, $returnedQuantityIndexes, $pp) {
                            $excludedIndexes = array_unique(array_merge(
                                $soldQuantityIndexes[$pp->id] ?? [],
                                $returnedQuantityIndexes[$pp->id] ?? []
                            ));
                            return !in_array($fv->quantity_index, $excludedIndexes);
                        })->map(function ($fv) {
                            return [
                                'purchase_product_id' => $fv->purchase_product_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->productField->name ?? null,
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ];
                        })->values();
                    })->toArray();


                $productPurchaseProducts = $purchaseProducts->filter(fn($pp) => $pp->product_id == $product->product_id)
                    ->map(function ($pp) use ($soldQuantityIndexes, $returnedQuantityIndexes, $companyId, $measureUnitsCalc) {
                        // Calculate purchased pieces
                        $purchasedPieces = $this->calculatePieces(
                            ($pp->quantity ?? 0) + ($pp->free_quantity ?? 0),
                            measureUnitQuantity: isset($measureUnitsCalc[$pp->measure_unit_id]) ? $measureUnitsCalc[$pp->measure_unit_id]->quantity : 1
                        );

                        // Calculate return pieces, capped at purchased pieces
                        $returnPieces = $pp->purchaseProductReturns->reduce(
                            fn($carry, $return) => $carry + $this->calculatePieces(
                                ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                                isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1
                            ),
                            0
                        );
                        $returnPieces = min($returnPieces, $purchasedPieces);

                        // Calculate sale and sales return pieces
                        $salePieces = $pp->saleProducts->reduce(
                            fn($carry, $sale) => $carry + $this->calculatePieces(
                                ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0),
                                isset($measureUnitsCalc[$sale->measure_unit_id]) ? $measureUnitsCalc[$sale->measure_unit_id]->quantity : 1
                            ),
                            0
                        );
                        $salesReturnPieces = $pp->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns)->reduce(
                            fn($carry, $return) => $carry + $this->calculatePieces(
                                ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                                isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1
                            ),
                            0
                        );

                        // Calculate available pieces
                        $availablePieces = $this->calculateAvailablePieces($pp, $companyId, $measureUnitsCalc);

                        // Collect field values for this purchase product
                        $fieldValues = $pp->fieldValues->filter(function ($fv) use ($soldQuantityIndexes, $returnedQuantityIndexes, $pp) {
                            $excludedIndexes = array_unique(array_merge(
                                $soldQuantityIndexes[$pp->id] ?? [],
                                $returnedQuantityIndexes[$pp->id] ?? []
                            ));
                            return !in_array($fv->quantity_index, $excludedIndexes);
                        })->map(function ($fv) {
                            return [
                                'purchase_product_id' => $fv->purchase_product_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->productField->name ?? null,
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ];
                        })->values()->toArray();

                        return [
                            'purchase_product_id' => $pp->id,
                            'purchase_id' => $pp->purchase_id,
                            'purchase_bill_number' => $pp->purchase->purchase_bill_number ?? null,
                            'invoice_date' => $pp->purchase->invoice_date ?? null,
                            'product_id' => $pp->product_id,
                            'product_name' => $pp->product_name,
                            'product_code' => $pp->product_code,
                            'mfd' => $pp->mfd,
                            'quantity' => $pp->quantity,
                            'free_quantity' => $pp->free_quantity ?? 0,
                            'price' => $pp->price ?? 0,
                            'is_vatable' => (bool) $pp->is_vatable,
                            'measure_unit_id' => $pp->measure_unit_id,
                            'measure_unit_name' => isset($measureUnitsCalc[$pp->measure_unit_id]) ? $measureUnitsCalc[$pp->measure_unit_id]->name : null,
                            'measure_unit_quantity' => isset($measureUnitsCalc[$pp->measure_unit_id]) ? $measureUnitsCalc[$pp->measure_unit_id]->quantity : 1,
                            'expiry_date' => $pp->expiry_date,
                            'return_quantity' => $returnPieces, // In pieces
                            'sale_quantity' => $salePieces, // In pieces
                            'sales_return_quantity' => $salesReturnPieces, // In pieces
                            'available_quantity' => max($availablePieces, 0), // In pieces
                            'purchased_quantity' => $purchasedPieces, // In pieces
    
                        ];
                    })->values()->toArray();

                // Aggregate totals in pieces
                $purchasedPieces = array_sum(array_map(
                    fn($pp) => $this->calculatePieces(
                        ($pp['quantity'] ?? 0) + ($pp['free_quantity'] ?? 0),
                        $pp['measure_unit_quantity'] ?? 1
                    ),
                    $productPurchaseProducts
                ));
                $returnPieces = array_sum(array_map(
                    fn($pp) => $pp['return_quantity'],
                    $productPurchaseProducts
                ));
                $returnPieces = min($returnPieces, $purchasedPieces);
                $salePieces = array_sum(array_map(
                    fn($pp) => $pp['sale_quantity'],
                    $productPurchaseProducts
                ));
                $salesReturnPieces = array_sum(array_map(
                    fn($pp) => $pp['sales_return_quantity'],
                    $productPurchaseProducts
                ));

                $availablePieces = $purchasedPieces - $returnPieces - $salePieces + $salesReturnPieces;

                $salesPrice = SaleProduct::where('product_id', $product->product_id)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->pluck('price');
                $lastSalesPrice = SaleProduct::where('product_id', $product->product_id)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->orderByDesc('created_at')
                    ->value('price');

                // Aggregate all field values for the product (optional, if you still want them at product level)

                return [
                    'product_id' => $product->product_id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    // 'min_price' => !empty($productPurchaseProducts) ? min(array_column($productPurchaseProducts, 'price')) : 0,
                    'is_vatable' => (bool) $product->is_vatable,
                    'measure_unit_id' => $primarayMeasureUnitId->id ?? null, // No specific measure unit for pieces

                    'measure_unit_quantity' => $primaryMeasureUnitQuantity, // 1 piece = 1 
                    'retail_sale_price' => $retailSalePrice ?? 0,
                    'avg_price' => $avgPrice ?? 0,
                    'min_price' => $minPrice ?? 0,
                    'latest_price' => $latestSoldPrice ?? 0,
                    'measure_units_used' => $measureUnitsUsed,
                    'avg_sales_price' => round($salesPrice->avg(), 2) ?: null,
                    'min_sales_price' => round($salesPrice->min(), 2) ?: null,
                    'latest_sales_price' => round($lastSalesPrice, 2) ?: null,
                    'purchased_quantity' => $purchasedPieces, // In pieces
                    'return_quantity' => $returnPieces, // In pieces
                    'sale_quantity' => $salePieces, // In pieces
                    'sales_return_quantity' => $salesReturnPieces, // In pieces
                    'available_quantity' => max($availablePieces, 0), // In pieces
                    'expiry_dates' => array_filter(array_unique(array_column($productPurchaseProducts, 'expiry_date'))),
                    'field_values' => $allFieldValues, // Include aggregated field values
                    'purchase_products' => $productPurchaseProducts

                ];
            })->filter()->values()->toArray();
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


    private function calculatePieces(float $quantity, float $measureUnitQuantity): float
    {
        if ($measureUnitQuantity <= 0) {
            Log::warning('Invalid measure unit quantity', ['measureUnitQuantity' => $measureUnitQuantity]);
            return 0;
        }

        $integerPart = floor($quantity);

        $decimalPart = $quantity - $integerPart;

        $decimalStr = (string) $decimalPart;
        $decimalPieces = $decimalStr > 0 ? (int) str_replace('.', '', (string) $decimalStr) : 0;

        return ($integerPart * $measureUnitQuantity) + $decimalPieces;
    }

    private function calculateAvailablePieces($purchaseProduct, int $companyId, $measureUnitsCalc): int
    {
        $purchaseMeasureUnitQuantity = isset($measureUnitsCalc[$purchaseProduct->measure_unit_id]) ? $measureUnitsCalc[$purchaseProduct->measure_unit_id]->quantity : 1;
        Log::debug('Measure unit quantity', [
            'purchase_product_id' => $purchaseProduct->id,
            'measure_unit_id' => $purchaseProduct->measure_unit_id,
            'purchaseMeasureUnitQuantity' => $purchaseMeasureUnitQuantity
        ]);

        if ($purchaseMeasureUnitQuantity <= 0) {
            Log::warning('Invalid measure unit quantity for purchase product', [
                'purchase_product_id' => $purchaseProduct->id,
                'measureUnitQuantity' => $purchaseMeasureUnitQuantity
            ]);
            return 0;
        }

        // Log purchase product data
        Log::debug('Purchase product data', [
            'purchase_product_id' => $purchaseProduct->id,
            'quantity' => $purchaseProduct->quantity ?? 0,
            'free_quantity' => $purchaseProduct->free_quantity ?? 0
        ]);

        // Prioritize field values if they exist
        $fieldValues = $purchaseProduct->fieldValues->whereNull('deleted_at')->groupBy('quantity_index');
        if ($fieldValues->isNotEmpty()) {
            $unavailableIndices = $this->getUnavailableQuantityIndices($purchaseProduct, $companyId);
            $availablePieces = $fieldValues->filter(function ($fv, $index) use ($unavailableIndices) {
                return !in_array($index, $unavailableIndices);
            })->count();

            Log::debug('Calculated available pieces via field values', [
                'purchase_product_id' => $purchaseProduct->id,
                'total_field_values' => $fieldValues->count(),
                'unavailable_indices' => $unavailableIndices,
                'available_pieces' => $availablePieces
            ]);

            return max(0, $availablePieces);
        }

        // Fallback to quantity-based calculation
        $regularPieces = $this->calculatePieces($purchaseProduct->quantity ?? 0, $purchaseMeasureUnitQuantity);
        $freePieces = $this->calculatePieces($purchaseProduct->free_quantity ?? 0, $purchaseMeasureUnitQuantity);
        $totalPurchasedPieces = $regularPieces + $freePieces;

        $purchaseReturnedPieces = $purchaseProduct->purchaseProductReturns->reduce(
            function ($carry, $return) use ($measureUnitsCalc) {
                $returnMeasureUnitQuantity = isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1;
                return $carry + $this->calculatePieces(
                    ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                    $returnMeasureUnitQuantity
                );
            },
            0
        );

        $soldPieces = $purchaseProduct->saleProducts->reduce(
            function ($carry, $sale) use ($measureUnitsCalc) {
                $saleMeasureUnitQuantity = isset($measureUnitsCalc[$sale->measure_unit_id]) ? $measureUnitsCalc[$sale->measure_unit_id]->quantity : 1;
                return $carry + $this->calculatePieces(
                    ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0),
                    $saleMeasureUnitQuantity
                );
            },
            0
        );

        $salesReturnedPieces = $purchaseProduct->saleProducts->flatMap(function ($sale) use ($companyId, $measureUnitsCalc) {
            return $sale->saleProductReturns->where('company_id', $companyId)->whereNull('deleted_at');
        })->reduce(
                function ($carry, $return) use ($measureUnitsCalc) {
                    $returnMeasureUnitQuantity = isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1;
                    return $carry + $this->calculatePieces(
                        ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                        $returnMeasureUnitQuantity
                    );
                },
                0
            );

        $availablePieces = $totalPurchasedPieces - $purchaseReturnedPieces - $soldPieces + $salesReturnedPieces;

        if ($availablePieces < 0) {
            Log::warning('Negative available pieces detected', [
                'purchase_product_id' => $purchaseProduct->id,
                'total_purchased' => $totalPurchasedPieces,
                'purchase_returned' => $purchaseReturnedPieces,
                'sold' => $soldPieces,
                'sales_returned' => $salesReturnedPieces,
                'available' => $availablePieces
            ]);
        }

        Log::debug('Calculated available pieces via quantities', [
            'purchase_product_id' => $purchaseProduct->id,
            'total_purchased' => $totalPurchasedPieces,
            'purchase_returned' => $purchaseReturnedPieces,
            'sold' => $soldPieces,
            'sales_returned' => $salesReturnedPieces,
            'available' => $availablePieces
        ]);

        return max(0, (int) $availablePieces); // Remove floor, cast to int
    }






    public function getItemByBillNumber($billNumber): JsonResponse
    {
        try {
            $purchase = Sale::where('invoice_number', $billNumber)->firstOrFail();
            return $this->show($purchase->id);
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


    public function index(Request $request): JsonResponse
    {
        $query = Sale::query();

        if ($request->has('keywords')) {
            $query->where('ref_number', 'LIKE', '%' . $request->input('keywords') . '%')->orWhere('invoice_number', 'LIKE', '%' . $request->input('keywords') . '%')->orWhere('customer_name', 'LIKE', '%' . $request->input('keywords') . '%');

        }
        return response()->json($query->paginate(100));
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
                'payment.bank_name' => 'nullable|string',
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
                'freight_charge' => 'nullable|numeric|min:0',
                'excise_duty' => 'nullable|numeric|min:0',
                'health_insurance' => 'nullable|numeric|min:0',
                'balance' => 'nullable|numeric|min:0',
                'taxable_amount' => 'nullable|numeric|min:0',
                'non_taxable_amount' => 'nullable|numeric|min:0',
                'ref_bill_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('sales', 'ref_number')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'round_off_amount' => 'nullable|numeric|max:255',
                'roundoff_type' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'vat_amount' => 'nullable|numeric',
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
                'sale_products.*.quantity' => 'nullable|string',
                'sale_products.*.free_quantity' => 'nullable|string',
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

            Log::debug('Sale request validated', ['sale_products' => $validated['sale_products']]);

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
                            'amount' => $product->amount,
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

                    Log::debug('Sale products for entire batch', ['sale_products' => $validated['sale_products']]);

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
                    'ref_number' => $validated['ref_bill_number'] ?? null,
                    'invoice_number' => $validated['invoice_number'] ?? 'INV-' . now()->format('Ymd') . '-' . rand(1000, 9999),
                    'invoice_date' => $validated['invoice_date'] ?? now(),
                    'invoice_date_bs' => $validated['invoice_date_bs'] ?? now(),
                    'store_id' => $validated['store_id'],
                    'location_id' => $validated['location_id'],
                    'sub_total_before_discount' => $validated['sub_total_before_discount'] ?? 0,
                    'discount' => $validated['discount'] ?? 0,
                    'discount_after_vat' => $validated['discount_after_vat'] ?? 0,
                    'freight_charge' => $validated['freight_charge'] ?? 0,
                    'excise_duty' => $validated['excise_duty'] ?? 0,
                    'health_insurance' => $validated['health_insurance'] ?? 0,
                    'balance' => $validated['balance'] ?? 0,
                    'payment' => $validated['payment'] ?? "",
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
                    'vat_amount' => $validated['vat_amount'] ?? null,
                    'purchase_bill_number' => $validated['purchase_bill_number'] ?? null,
                ]);

                Log::debug('Sale created', ['sale_id' => $sale->id]);

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

                    Log::debug('Sale additionals created', ['sale_id' => $sale->id]);
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


                    Log::debug('Sale product quantities', [
                        'index' => $index,
                        'product_id' => $productId,
                        'regular_quantity' => $regularQuantity,
                        'free_quantity' => $freeQuantity,
                        'regular_pieces' => $regularPieces,
                        'free_pieces' => $freePieces,
                        'total_requested_pieces' => $totalRequestedPieces
                    ]);

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

                    Log::debug('Field values processed', [
                        'index' => $index,
                        'product_id' => $productId,
                        'grouped_field_values' => $groupedFieldValues
                    ]);

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

                    Log::debug('Field value validation', [
                        'index' => $index,
                        'product_id' => $productId,
                        'regular_field_value_sets' => $regularFieldValueSets,
                        'free_field_value_sets' => $freeFieldValueSets,
                        'regular_pieces' => $regularPieces,
                        'free_pieces' => $freePieces,
                        'has_field_values' => $hasFieldValues,
                        'requires_field_values' => $requiresFieldValues
                    ]);

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

                            if ($totalAvailablePieces < 0) {
                                throw new \Exception("Negative stock for purchase_product_id {$purchaseProductId} at index {$index}.");
                            }

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
                                    Log::debug('Field value mismatch', [
                                        'index' => $index,
                                        'purchase_product_id' => $purchaseProductId,
                                        'quantity_index' => $quantityIndex,
                                        'submitted' => collect($fvSet)->pluck('value', 'product_field_id')->toArray(),
                                        'existing' => $existingFieldValues[$quantityIndex]
                                    ]);
                                    throw new \Exception("Field values for quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} do not match at index {$index}.");
                                }
                                $usedQuantityIndexes[$purchaseProductId][] = $quantityIndex;
                            }

                            $regularFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'regular')->toArray();
                            $freeFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'free')->toArray();

                            $requestedRegularPieces = count($regularFvByIndex);

                            $requestedFreePieces = count($freeFvByIndex);

                            $totalRequestedForThisProduct = $requestedRegularPieces + $requestedFreePieces;

                            if ($totalRequestedForThisProduct > $totalAvailablePieces) {
                                throw new \Exception("Insufficient stock for purchase_product_id {$purchaseProductId} at index {$index}. Requested: {$totalRequestedForThisProduct}, Available: {$totalAvailablePieces}.");
                            }


                            [$allocateRegularQuantity, $allocateFreeQuantity] = $this->convertToTargetMeasureUnit($requestedRegularPieces, $requestedFreePieces, $targetMeasureUnitQuantity);

                            $allocations[] = [
                                'purchase_product_id' => $purchaseProductId,
                                'quantity' => $allocateRegularQuantity,
                                'free_quantity' => $allocateFreeQuantity,
                                'field_values' => array_merge(
                                    array_values($regularFvByIndex),
                                    array_values($freeFvByIndex)
                                ),
                                'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                            ];

                            $remainingRegularPieces -= $requestedRegularPieces;
                            $remainingFreePieces -= $requestedFreePieces;

                            Log::debug('Allocation created', [
                                'index' => $index,
                                'purchase_product_id' => $purchaseProductId,
                                'quantity' => $allocateRegularQuantity,
                                'free_quantity' => $allocateFreeQuantity,
                                'remaining_regular_pieces' => $remainingRegularPieces,
                                'remaining_free_pieces' => $remainingFreePieces
                            ]);
                        }

                        if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {

                            throw new \Exception("Insufficient stock for product ID {$productId} at index {$index}. Remaining: Regular {$remainingRegularPieces}, Free {$remainingFreePieces}.");
                        }
                    } else {
                        $purchaseProduct = isset($productData['purchase_product_id']) ? $purchaseProducts->firstWhere('id', $productData['purchase_product_id']) : null;
                        if ($purchaseProduct) {
                            if ($purchaseProduct->fieldValues->isNotEmpty()) {
                                throw new \Exception("Purchase product ID {$purchaseProduct->id} has field values; field_values must be provided at index {$index}.");
                            }
                            $purchaseProducts = collect([$purchaseProduct]);
                        }
                        $measureUnitIds = $purchaseProducts->pluck('measure_unit_id')->unique()->toArray();
                        $measureUnits = MeasureUnit::whereIn('id', $measureUnitIds)->get()->keyBy('id');
                        $measureUnitsCalc = $measureUnits->map(function ($unit) {
                            return (object) ['quantity' => $unit->quantity ?? 1];
                        })->toArray();

                        Log::debug('PurchaseProducts found', [
                            'product_id' => $productId,
                            'count' => $purchaseProducts->count(),
                            'ids' => $purchaseProducts->pluck('id')->toArray()
                        ]);

                        $totalAllocatedPieces = 0;

                        foreach ($purchaseProducts as $purchaseProduct) {
                            if ($remainingRegularPieces <= 0 && $remainingFreePieces <= 0) {
                                break;
                            }

                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $validated['company_id'], $measureUnitsCalc);
                            if ($totalAvailablePieces < 0) {
                                throw new \Exception("Negative stock for purchase_product_id {$purchaseProduct->id} at index {$index}.");
                            }
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

                                Log::debug('FIFO allocation', [
                                    'index' => $index,
                                    'purchase_product_id' => $purchaseProduct->id,
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                    'remaining_regular_pieces' => $remainingRegularPieces,
                                    'remaining_free_pieces' => $remainingFreePieces
                                ]);
                            }
                        }

                        if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {

                            throw new \Exception("Insufficient stock for product ID {$productId} at index {$index}. Requested: {$totalRequestedPieces} pieces (Regular: {$regularPieces}, Free: {$freePieces}), Allocated: " . ($totalRequestedPieces - ($remainingRegularPieces + $remainingFreePieces)) . " pieces.");
                        }
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
                            'amount' => $productData['amount'],
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

                        Log::debug('Sale product created', [
                            'index' => $index,
                            'sale_product_id' => $saleProduct->id,
                            'purchase_product_id' => $allocation['purchase_product_id'],
                            'quantity' => $allocation['quantity'],
                            'free_quantity' => $allocation['free_quantity']
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

                            Log::debug('Field values inserted', [
                                'index' => $index,
                                'sale_product_id' => $saleProduct->id,
                                'field_values' => $allocation['field_values']
                            ]);
                        }
                    }
                }

                return $sale;
            });

            Log::debug('Sale transaction completed', ['sale_id' => $sale->id]);

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
        $flat = [];
        foreach ($fieldValues as $fvSet) {
            foreach ($fvSet as $fv) {
                $flat[] = [
                    'purchase_product_id' => $fv['purchase_product_id'] ?? throw new \Exception("Missing purchase_product_id in field values at index {$index}."),
                    'product_field_id' => $fv['product_field_id'] ?? throw new \Exception("Missing product_field_id in field values at index {$index}."),
                    'value' => $fv['value'] ?? throw new \Exception("Missing value in field values at index {$index}."),
                    'quantity_index' => $fv['quantity_index'] ?? throw new \Exception("Missing quantity_index in field values at index {$index}."),
                    'quantity_type' => $fv['quantity_type'] ?? 'regular',
                ];
            }
        }
        return $flat;
    }


    private function convertToTargetMeasureUnit(float $regularPieces, float $freePieces, float $targetMeasureUnitQuantity): array
    {
        if ($targetMeasureUnitQuantity <= 0) {
            Log::warning('Invalid target measure unit quantity', ['targetMeasureUnitQuantity' => $targetMeasureUnitQuantity]);
            return [0, 0];
        }


        //For Regular 
        $regularPiecesInt = floor($regularPieces / $targetMeasureUnitQuantity);
        $regularRemainingPieces = $regularPieces - ($regularPiecesInt * $targetMeasureUnitQuantity);
        $regularDecimal = $regularRemainingPieces > 0 ? (float) ('0.' . (int) $regularRemainingPieces) : 0;
        $regularQuantity = $regularPiecesInt + $regularDecimal;

        //For Free Pieces

        $freePiecesInt = floor($freePieces / $targetMeasureUnitQuantity);
        $freeRemainingPieces = $freePieces - ($freePiecesInt * $targetMeasureUnitQuantity);
        $freeDecimal = $freeRemainingPieces > 0 ? (float) ('0.' . (int) $freeRemainingPieces) : 0;
        $freeQuantity = $freePiecesInt + $freeDecimal;


        Log::debug('Converted to target measure unit', [
            'regular_pieces' => $regularPieces,
            'free_pieces' => $freePieces,
            'target_measure_unit_quantity' => $targetMeasureUnitQuantity,
            'regular_quantity' => $regularQuantity,
            'free_quantity' => $freeQuantity
        ]);

        return [$regularQuantity, $freeQuantity];
    }

    private function getUnavailableQuantityIndices($purchaseProduct, int $companyId): array
    {
        $soldIndices = SalesProductFieldValue::whereIn('sale_product_id', $purchaseProduct->saleProducts->pluck('id'))
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->pluck('quantity_index')
            ->unique()
            ->values()
            ->toArray();

        $returnedIndices = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $purchaseProduct->purchaseProductReturns->pluck('id'))
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->pluck('quantity_index')
            ->unique()
            ->values()
            ->toArray();

        $unavailableIndices = array_unique(array_merge($soldIndices, $returnedIndices));

        Log::debug('Unavailable quantity indices', [
            'purchase_product_id' => $purchaseProduct->id,
            'sold_indices' => $soldIndices,
            'returned_indices' => $returnedIndices,
            'unavailable_indices' => $unavailableIndices
        ]);

        return $unavailableIndices;
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
                'ref_number' => ['nullable', 'string', 'max:255', Rule::unique('sales', 'ref_number')->ignore($id)],
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
                'payment.bank_name' => 'nullable|string',
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
            $item = Sale::with('saleProducts.measureUnit:id,name')->findOrFail($id);
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
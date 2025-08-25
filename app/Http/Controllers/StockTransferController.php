<?php

namespace App\Http\Controllers;

use App\Models\StockTransfer;
use App\Models\StockTransferDetails;
use Illuminate\Http\JsonResponse;
use App\Services\StockTransferService;
use App\Models\StockTransferFieldValue;
use App\Models\PurchaseStockProductFieldValue;


use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use App\Models\Product;
use App\Models\StockReceive;
use App\Models\ProductList;
use App\Models\MeasureUnit;
use App\Models\PurchaseStockProduct;
use App\Models\SalesReturnProduct;
use App\Models\SaleProduct;
use App\Models\SalesProductFieldValue;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\PurchaseProductReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;


class StockTransferController extends Controller
{


    protected $stockTransferService;

    public function __construct(StockTransferService $stockTransferService)
    {
        $this->stockTransferService = $stockTransferService;
    }



    public function getProductListforStockTransfer(Request $request): JsonResponse
    {
        try {
            $purchaseType = $request->input('purchase_type', 'inventory');
            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');


            return $this->stockTransferService->listAvailableStock($purchaseType, $companyId, $branchId);
        } catch (ModelNotFoundException $e) {
            Log::error('ModelNotFoundException in StockTransferController@getProductListforStockTransfer: ' . $e->getMessage());
            return response()->json(['error' => 'Product not found'], 404);
        } catch (QueryException $e) {
            Log::error('QueryException in StockTransferController@getProductListforStockTransfer: ' . $e->getMessage());
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Exception in StockTransferController@getProductListforStockTransfer: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function getProductDetails(Request $request): JsonResponse
    {
        try {
            $purchaseType = $request->input('purchase_type', 'inventory');
            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');
            $productId = $request->input('product_id');
            $productName = $request->input('product_name');
            $productBarcode = $request->input('product_barcode');

            if ($productName) {
                $products = Product::where('company_id', $companyId)

                    ->where('name', $productName)

                    ->first();

                $productId = $products ? $products->id : null;
            }

            if ($productBarcode) {
                $products = Product::where('company_id', $companyId)

                    ->where('barcode', $productBarcode)

                    ->first();

                $productId = $products ? $products->id : null;
            }


            return $this->stockTransferService->getAvailableProductByIdOrName($purchaseType, $companyId, $branchId, $productId);
        } catch (ModelNotFoundException $e) {
            Log::error('ModelNotFoundException in StockTransferController@getProductListforStockTransfer: ' . $e->getMessage());
            return response()->json(['error' => 'Product not found'], 404);
        } catch (QueryException $e) {
          
            Log::error('QueryException in StockTransferController@getProductListforStockTransfer: ' . $e->getMessage());
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Exception in StockTransferController@getProductListforStockTransfer: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function index(Request $request): JsonResponse
    {

        $query = StockTransfer::query();


        return response()->json($query->paginate(50));
    }



    public function update(Request $request, $id): JsonResponse
    {
        try {
            $stockTransfer = StockTransfer::findOrFail($id);

            // Validation rules
            $validator = Validator::make($request->all(), [
                'reference_no' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stock_transfers')

                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->company_id)
                                ->whereNull('deleted_at');
                        })->ignore($id),
                ],
                'transfer_to' => 'nullable|numeric|max:255',
                'document_no' => 'nullable|string|max:255',
                'current_location' => 'nullable|numeric|max:255',
                'date_ad' => 'nullable|date',
                'transfer_date_bs' => 'nullable|string',
                'document_number' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reasons_for' => 'nullable|string|max:255',
                'product_details' => 'nullable|array',
                'product_details.*.id' => 'nullable|integer|exists:stock_transfer_details,id',
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.quantity' => 'required_with:product_details|numeric',
                'product_details.*.unit' => 'required_with:product_details|integer|max:50',
                'product_details.*.batch_no' => 'required_with:product_details|string|max:255',
                'product_details.*.price' => 'required_with:product_details|numeric',
                'product_details.*.amount' => 'required_with:product_details|numeric',
                'company_id' => 'required|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            // Wrap in DB transaction
            $stockTransfer = DB::transaction(function () use ($validated, $id) {
                $stockTransfer = StockTransfer::findOrFail($id);

                // Update main record (excluding product_details temporarily)
                $updateData = $validated;
                unset($updateData['product_details']);
                $stockTransfer->update($updateData);

                // Handle product_details if provided
                if (!empty($validated['product_details'])) {
                    $incomingIds = [];

                    foreach ($validated['product_details'] as $detail) {
                        $detail['stock_transfer_id'] = $stockTransfer->id;
                        $detail['company_id'] = $validated['company_id'];

                        if (!empty($detail['id'])) {
                            $existing = StockTransferDetails::find($detail['id']);
                            if ($existing) {
                                $existing->update($detail);
                                $incomingIds[] = $existing->id;
                            } else {
                                $new = StockTransferDetails::create($detail);
                                $incomingIds[] = $new->id;
                            }
                        } else {
                            $new = StockTransferDetails::create($detail);
                            $incomingIds[] = $new->id;
                        }
                    }

                    // Delete removed product detail records
                    $stockTransfer->stockTransferDetails()->whereNotIn('id', $incomingIds)->delete();
                }

                return $stockTransfer;
            });

            return response()->json($stockTransfer->load('stockTransferDetails'), 200);

        } catch (ModelNotFoundException $e) {
            \Log::error('StockTransfer not found: ' . $e->getMessage());
            return response()->json(['error' => 'Stock transfer not found'], 404);
        } catch (QueryException $e) {
            \Log::error('QueryException in Stock Transfer::update: ' . $e->getMessage());
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Exception in Stock Transfer::update: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }




    public function store(Request $request): JsonResponse
    {
        try {
            \Log::info('Starting stock transfer store process', [
                'request_data' => $request->all(),
                'user_id' => auth()->id(),
            ]);

            $validator = Validator::make($request->all(), [
                'reference_no' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stock_transfers')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->company_id)
                                ->whereNull('deleted_at');
                        }),
                ],
                'transfer_to' => 'required|integer|exists:branches,id',
                'document_no' => 'nullable|string|max:255',
                'current_location' => 'required|integer|exists:branches,id',
                'date_ad' => 'nullable|date',
                'transfer_date_bs' => 'nullable|string',
                'document_number' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reasons_for' => 'nullable|string|max:255',
                'product_details' => 'required|array',
                'product_details.*.product_id' => 'required|integer|exists:products,id',
                'product_details.*.product_name' => 'required|string|max:255',
                'product_details.*.product_code' => 'required|string|max:255',
                'product_details.*.quantity' => 'required|numeric|min:0.01',
                'product_details.*.purchase_type' => 'required|string',
                'product_details.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'product_details.*.price' => 'required|numeric|min:0',
                'product_details.*.amount' => 'required|numeric|min:0',
                'product_details.*.field_values' => 'present|array',
                'product_details.*.field_values.*' => 'array|min:1',
                'product_details.*.field_values.*.*.product_field_id' => 'required_if:field_values,array|integer|exists:product_fields,id',
                'product_details.*.field_values.*.*.value' => 'required_if:field_values,array|string|max:255',
                'product_details.*.field_values.*.*.quantity_index' => 'required_if:field_values,array|integer|min:0',
                'product_details.*.field_values.*.*.quantity_type' => 'nullable|string|in:regular,free',
                'product_details.*.field_values.*.*.purchase_stock_product_id' => 'required_if:field_values,array|integer|exists:purchase_stock_products,id',
                'company_id' => 'required|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                \Log::warning('Validation failed for stock transfer', [
                    'errors' => $validator->errors()->toArray(),
                    'request_data' => $request->all(),
                ]);
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            \Log::info('Validation passed, proceeding with stock transfer', [
                'validated_data' => $validator->validated(),
            ]);

            $validated = $validator->validated();

            $item = DB::transaction(function () use ($validated) {
                \Log::debug('Starting database transaction for stock transfer', [
                    'company_id' => $validated['company_id'],
                    'branch_id' => $validated['current_location'],
                    'transfer_to' => $validated['transfer_to'],
                ]);

                // Set branch_id to current_location (source branch)
                $validated['branch_id'] = $validated['current_location'];

                $productDetails = $validated['product_details'];
                unset($validated['product_details']);

                // Create the stock transfer record
                \Log::info('Creating stock transfer record', [
                    'validated_data' => $validated,
                ]);
                $item = StockTransfer::create($validated);

                // Fetch measure units for calculations
                \Log::debug('Fetching measure units', [
                    'company_id' => $validated['company_id'],
                ]);
                $measureUnitsCalc = MeasureUnit::where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->get()
                    ->keyBy('id');

                // Process each product detail
                $details = [];
                foreach ($productDetails as $index => $detail) {
                    \Log::debug('Processing product detail', [
                        'index' => $index,
                        'product_id' => $detail['product_id'],
                        'quantity' => $detail['quantity'],
                    ]);

                    // Transfer product stock
                    $this->transferProduct($detail, $validated['company_id'], $validated['current_location'], $validated['transfer_to'], $measureUnitsCalc, $item->id, $index);

                    // Prepare stock transfer details
                    $detail['stock_transfer_id'] = $item->id;
                    $detail['company_id'] = $validated['company_id'];
                    unset($detail['field_values']); // Remove field_values from details
                    $details[] = $detail;
                }

                // Create stock transfer details
                \Log::info('Creating stock transfer details', [
                    'stock_transfer_id' => $item->id,
                    'details_count' => count($details),
                ]);
                $item->stockTransferDetails()->createMany($details);

                \Log::info('Stock transfer transaction completed successfully', [
                    'stock_transfer_id' => $item->id,
                ]);
                return $item;
            });

            \Log::info('Stock transfer created successfully', [
                'stock_transfer_id' => $item->id,
            ]);
            return response()->json($item->load('stockTransferDetails'), 201);
        } catch (ModelNotFoundException $e) {
            \Log::error('ModelNotFoundException in StockTransfer::store', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error('QueryException in StockTransfer::store', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Exception in StockTransfer::store', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function transferProduct($detail, $companyId, $branchId, $targetBranchId, $measureUnitsCalc, $stockTransferId, $index)
    {
        \Log::info('Starting transferProduct', [
            'product_id' => $detail['product_id'],
            'index' => $index,
            'quantity' => $detail['quantity'],
            'company_id' => $companyId,
            'source_branch_id' => $branchId,
            'target_branch_id' => $targetBranchId,
        ]);

        $productId = $detail['product_id'];
        $quantity = $detail['quantity'];
        $measureUnitId = $detail['measure_unit_id'];
        $price = $detail['price'];

        \Log::debug('Fetching measure unit', [
            'measure_unit_id' => $measureUnitId,
        ]);
        $measureUnit = MeasureUnit::where('id', $measureUnitId)->first();
        $measureUnitQuantity = $measureUnit->quantity ?? 1;

        $transferredPieces = $this->stockTransferService->calculatePieces($quantity, $measureUnitQuantity);
        \Log::debug('Calculated transferred pieces', [
            'quantity' => $quantity,
            'measure_unit_quantity' => $measureUnitQuantity,
            'transferred_pieces' => $transferredPieces,
        ]);

        $fieldValuesFlat = $this->stockTransferService->flattenFieldValues($detail['field_values'] ?? [], $index);
        $hasFieldValues = !empty($fieldValuesFlat);
        \Log::debug('Processed field values', [
            'has_field_values' => $hasFieldValues,
            'field_values_count' => count($fieldValuesFlat),
        ]);

        $allocations = [];

        if ($hasFieldValues) {
            \Log::info('Processing field-valued product transfer', [
                'product_id' => $productId,
                'index' => $index,
            ]);

            // Group field values by purchase_stock_product_id and quantity_index
            $groupedFieldValues = collect($fieldValuesFlat)
                ->groupBy('purchase_stock_product_id')
                ->map(function ($group) {
                    return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                        return collect($fvGroup)->map(function ($fv) {
                            return [
                                'product_field_id' => $fv['product_field_id'],
                                'value' => $fv['value'],
                                'quantity_index' => $fv['quantity_index'],
                                'quantity_type' => $fv['quantity_type'] ?? 'regular',
                                'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                            ];
                        })->unique(function ($fv) {
                            return "{$fv['product_field_id']}:{$fv['value']}:{$fv['quantity_type']}";
                        })->values()->toArray();
                    })->toArray();
                })->toArray();

            $regularFieldValueSets = collect($fieldValuesFlat)
                ->filter(fn($fv) => ($fv['quantity_type'] ?? 'regular') === 'regular')
                ->map(fn($fv) => "{$fv['purchase_stock_product_id']}:{$fv['quantity_index']}")
                ->unique()
                ->count();
            $freeFieldValueSets = collect($fieldValuesFlat)
                ->filter(fn($fv) => ($fv['quantity_type'] ?? 'regular') === 'free')
                ->map(fn($fv) => "{$fv['purchase_stock_product_id']}:{$fv['quantity_index']}")
                ->unique()
                ->count();

            $totalRequestedPieces = $regularFieldValueSets + $freeFieldValueSets;
            \Log::debug('Calculated field value sets', [
                'regular_sets' => $regularFieldValueSets,
                'free_sets' => $freeFieldValueSets,
                'total_requested_pieces' => $totalRequestedPieces,
                'transferred_pieces' => $transferredPieces,
            ]);

            if ($totalRequestedPieces > $transferredPieces) {
                \Log::error('Field value sets exceed requested pieces', [
                    'index' => $index,
                    'regular_sets' => $regularFieldValueSets,
                    'free_sets' => $freeFieldValueSets,
                    'transferred_pieces' => $transferredPieces,
                ]);
                throw new \Exception("Field value sets (Regular: {$regularFieldValueSets}, Free: {$freeFieldValueSets}) exceed requested pieces ({$transferredPieces}) at index {$index}.");
            }

            $purchaseStockProductIds = array_keys($groupedFieldValues);
            $usedQuantityIndexes = [];
            $remainingPieces = $transferredPieces;

            \Log::debug('Fetching purchase stock products', [
                'purchase_stock_product_ids' => $purchaseStockProductIds,
                'company_id' => $companyId,
                'branch_id' => $branchId,
            ]);
            $purchaseStockProducts = PurchaseStockProduct::whereIn('id', $purchaseStockProductIds)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->with('fieldValues.productField')
                ->get();

            foreach ($groupedFieldValues as $purchaseStockProductId => $fvByIndex) {
                $psp = $purchaseStockProducts->firstWhere('id', $purchaseStockProductId) ?? throw new \Exception("Purchase stock product ID {$purchaseStockProductId} not found at index {$index}.");
                \Log::debug('Processing purchase stock product', [
                    'purchase_stock_product_id' => $purchaseStockProductId,
                    'index' => $index,
                ]);

                $requiresFieldValues = $psp->fieldValues->isNotEmpty();
                if (!$requiresFieldValues) {
                    \Log::error('Field values provided but not required', [
                        'purchase_stock_product_id' => $purchaseStockProductId,
                        'index' => $index,
                    ]);
                    throw new \Exception("Field values provided for purchase_stock_product_id {$purchaseStockProductId} at index {$index}, but none required.");
                }

                $regularPieces = $this->stockTransferService->calculatePieces(max($psp->quantity, 0), $measureUnitQuantity);
                $freePieces = $this->stockTransferService->calculatePieces(max($psp->free_quantity ?? 0, 0), $measureUnitQuantity);
                $totalAvailable = $regularPieces + $freePieces;
                \Log::debug('Stock availability check', [
                    'purchase_stock_product_id' => $purchaseStockProductId,
                    'regular_pieces' => $regularPieces,
                    'free_pieces' => $freePieces,
                    'total_available' => $totalAvailable,
                ]);

                $regularFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'regular')->toArray();
                $freeFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'free')->toArray();

                $requestedRegularPieces = count($regularFvByIndex);
                $requestedFreePieces = count($freeFvByIndex);
                $totalRequestedForThisPsp = $requestedRegularPieces + $requestedFreePieces;

                if ($totalRequestedForThisPsp > $totalAvailable) {
                    \Log::error('Insufficient stock for purchase stock product', [
                        'purchase_stock_product_id' => $purchaseStockProductId,
                        'index' => $index,
                        'requested_total' => $totalRequestedForThisPsp,
                        'available_total' => $totalAvailable,
                    ]);
                    throw new \Exception("Insufficient stock for purchase_stock_product_id {$purchaseStockProductId} at index {$index}. Requested: {$totalRequestedForThisPsp}, Available: {$totalAvailable}.");
                }

                // Define $existingFieldValues and $unavailableQuantityIndices before use
                $existingFieldValues = $psp->fieldValues->groupBy('quantity_index')->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());
                $unavailableQuantityIndices = $this->stockTransferService->getUnavailableQuantityIndices($psp, $companyId);

                foreach ($fvByIndex as $quantityIndex => $fvSet) {
                    if (in_array($quantityIndex, $unavailableQuantityIndices) || !isset($existingFieldValues[$quantityIndex])) {
                        \Log::error('Invalid quantity index', [
                            'purchase_stock_product_id' => $purchaseStockProductId,
                            'quantity_index' => $quantityIndex,
                            'index' => $index,
                        ]);
                        throw new \Exception("Invalid quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseStockProductId} at index {$index}.");
                    }
                    if (in_array($quantityIndex, $usedQuantityIndexes[$purchaseStockProductId] ?? [])) {
                        \Log::error('Duplicate quantity index', [
                            'purchase_stock_product_id' => $purchaseStockProductId,
                            'quantity_index' => $quantityIndex,
                            'index' => $index,
                        ]);
                        throw new \Exception("Duplicate quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseStockProductId} at index {$index}.");
                    }
                    if (collect($fvSet)->pluck('value', 'product_field_id')->toArray() != $existingFieldValues[$quantityIndex]) {
                        \Log::error('Field values mismatch', [
                            'purchase_stock_product_id' => $purchaseStockProductId,
                            'quantity_index' => $quantityIndex,
                            'index' => $index,
                        ]);
                        throw new \Exception("Field values for quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseStockProductId} do not match at index {$index}.");
                    }
                    $usedQuantityIndexes[$purchaseStockProductId][] = $quantityIndex;

                    foreach ($fvSet as $fv) {
                        \Log::debug('Creating stock transfer field value', [
                            'purchase_stock_product_id' => $purchaseStockProductId,
                            'quantity_index' => $fv['quantity_index'],
                            'quantity_type' => $fv['quantity_type'],
                        ]);
                        StockTransferFieldValue::create([
                            'company_id' => $companyId,
                            'branch_id' => $branchId,
                            'stock_transfer_id' => $stockTransferId,
                            'purchase_stock_product_id' => $purchaseStockProductId,
                            'product_field_id' => $fv['product_field_id'],
                            'quantity_index' => $fv['quantity_index'],
                            'quantity_type' => $fv['quantity_type'],
                            'product_id' => $productId,
                            'value' => $fv['value'],
                        ]);
                    }
                }

                $allocations[] = [
                    'purchase_stock_product_id' => $purchaseStockProductId,
                    'regular_pieces' => $requestedRegularPieces,
                    'free_pieces' => $requestedFreePieces,
                    'field_values' => array_values($fvByIndex),
                ];

                \Log::info('Updating source purchase stock product', [
                    'purchase_stock_product_id' => $purchaseStockProductId,
                    'regular_pieces' => $requestedRegularPieces,
                    'free_pieces' => $requestedFreePieces,
                ]);
                $oldQuantity = $psp->quantity;
                $oldFreeQuantity = $psp->free_quantity ?? 0;
                $psp->quantity = max(0, $psp->quantity - ($requestedRegularPieces / $measureUnitQuantity));
                $psp->free_quantity = max(0, ($psp->free_quantity ?? 0) - ($requestedFreePieces / $measureUnitQuantity));
                \Log::debug('Before saving source purchase stock product', [
                    'purchase_stock_product_id' => $purchaseStockProductId,
                    'old_quantity' => $oldQuantity,
                    'new_quantity' => $psp->quantity,
                    'old_free_quantity' => $oldFreeQuantity,
                    'new_free_quantity' => $psp->free_quantity,
                ]);
                try {
                    $psp->save();
                    \Log::info('Saved source purchase stock product', [
                        'purchase_stock_product_id' => $purchaseStockProductId,
                        'quantity' => $psp->quantity,
                        'free_quantity' => $psp->free_quantity,
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Failed to save source purchase stock product', [
                        'purchase_stock_product_id' => $purchaseStockProductId,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                // Convert to target measure unit before saving
                [$targetRegularQuantity, $targetFreeQuantity] = $this->stockTransferService->convertToTargetMeasureUnit(
                    $requestedRegularPieces,
                    $requestedFreePieces,
                    $measureUnitQuantity
                );

                \Log::debug('Creating new purchase stock product for target branch', [
                    'target_branch_id' => $targetBranchId,
                    'regular_quantity' => $targetRegularQuantity,
                    'free_quantity' => $targetFreeQuantity,
                ]);
                // Create new PurchaseStockProduct for target branch
                $newPsp = new PurchaseStockProduct([
                    'branch_id' => $targetBranchId,
                    'quantity' => $targetRegularQuantity,
                    'free_quantity' => $targetFreeQuantity,
                    'purchase_id' => $psp->purchase_id,
                    'purchase_type' => 'transfer',
                    'product_id' => $productId,
                    'product_code' => $psp->product_code,
                    'product_name' => $detail['product_name'],
                    'company_id' => $companyId,
                    'batch_no' => $psp->batch_no,
                    'measure_unit_id' => $measureUnitId,
                    'price' => $price,
                    'is_vatable' => $psp->is_vatable,
                ]);
                try {
                    $newPsp->save();
                    \Log::info('Saved new PurchaseStockProduct', [
                        'id' => $newPsp->id,
                        'attributes' => $newPsp->getAttributes(),
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Failed to save PurchaseStockProduct', [
                        'attributes' => $newPsp->getAttributes(),
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                $remainingPieces -= $totalRequestedForThisPsp;
                \Log::debug('Updated remaining pieces', [
                    'purchase_stock_product_id' => $purchaseStockProductId,
                    'remaining_pieces' => $remainingPieces,
                ]);
            }

            if ($remainingPieces > 0) {
                \Log::info('Fetching additional purchase stock products for remaining pieces', [
                    'product_id' => $productId,
                    'remaining_pieces' => $remainingPieces,
                ]);
                $additionalPsps = PurchaseStockProduct::where('product_id', $productId)
                    ->where('measure_unit_id', $measureUnitId)
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->whereNotIn('id', $purchaseStockProductIds)
                    ->whereHas('fieldValues')
                    ->orderBy('created_at')
                    ->with('fieldValues.productField')
                    ->get();

                foreach ($additionalPsps as $psp) {
                    if ($remainingPieces <= 0) {
                        \Log::debug('No more pieces to transfer', [
                            'purchase_stock_product_id' => $psp->id,
                        ]);
                        break;
                    }

                    $regularPieces = $this->stockTransferService->calculatePieces(max($psp->quantity, 0), $measureUnitQuantity);
                    $freePieces = $this->stockTransferService->calculatePieces(max($psp->free_quantity ?? 0, 0), $measureUnitQuantity);
                    $totalAvailable = $regularPieces + $freePieces;
                    \Log::debug('Checking additional stock availability', [
                        'purchase_stock_product_id' => $psp->id,
                        'regular_pieces' => $regularPieces,
                        'free_pieces' => $freePieces,
                        'total_available' => $totalAvailable,
                    ]);

                    if ($totalAvailable <= 0) {
                        \Log::warning('No available stock in additional purchase stock product', [
                            'purchase_stock_product_id' => $psp->id,
                        ]);
                        continue;
                    }

                    $toTransfer = min($remainingPieces, $totalAvailable);
                    $toReduceRegular = min($toTransfer, $regularPieces);
                    $toReduceFree = $toTransfer - $toReduceRegular;

                    if ($toReduceFree > $freePieces) {
                        \Log::error('Insufficient free quantity in additional purchase stock product', [
                            'purchase_stock_product_id' => $psp->id,
                            'index' => $index,
                            'to_reduce_free' => $toReduceFree,
                            'free_pieces' => $freePieces,
                        ]);
                        throw new \Exception("Insufficient free quantity in purchase_stock_product_id {$psp->id} for product {$detail['product_name']} at index {$index}.");
                    }

                    $existingFieldValues = $psp->fieldValues->groupBy('quantity_index')->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());
                    $unavailableQuantityIndices = $this->stockTransferService->getUnavailableQuantityIndices($psp, $companyId);
                    $availableIndices = collect(array_keys($existingFieldValues))->diff($unavailableQuantityIndices)->sort()->values();

                    $toAllocate = min($toTransfer, $availableIndices->count());
                    $allocateRegular = min($toAllocate, $toReduceRegular);
                    $allocateFree = $toAllocate - $allocateRegular;

                    $selectedIndices = $availableIndices->take($toAllocate);

                    $newFieldValues = [];
                    foreach ($selectedIndices as $qIndex) {
                        $newFieldValues[$qIndex] = collect($existingFieldValues[$qIndex])->map(function ($value, $productFieldId) use ($qIndex, $psp) {
                            return [
                                'product_field_id' => $productFieldId,
                                'value' => $value,
                                'quantity_index' => $qIndex,
                                'quantity_type' => 'regular',
                                'purchase_stock_product_id' => $psp->id,
                            ];
                        })->values()->toArray();
                    }

                    if ($allocateFree > 0) {
                        $lastFieldValue = $existingFieldValues[$availableIndices->last()] ?? $existingFieldValues->first();
                        $newIndex = $availableIndices->max() + 1;
                        for ($i = 0; $i < $allocateFree; $i++) {
                            $newFieldValues[$newIndex + $i] = collect($lastFieldValue)->map(function ($value, $productFieldId) use ($newIndex, $i, $psp) {
                                return [
                                    'product_field_id' => $productFieldId,
                                    'value' => $value,
                                    'quantity_index' => $newIndex + $i,
                                    'quantity_type' => 'free',
                                    'purchase_stock_product_id' => $psp->id,
                                ];
                            })->values()->toArray();
                        }
                    }

                    foreach ($newFieldValues as $fvSet) {
                        foreach ($fvSet as $fv) {
                            \Log::debug('Creating stock transfer field value for additional PSP', [
                                'purchase_stock_product_id' => $psp->id,
                                'quantity_index' => $fv['quantity_index'],
                                'quantity_type' => $fv['quantity_type'],
                            ]);
                            StockTransferFieldValue::create([
                                'company_id' => $companyId,
                                'branch_id' => $branchId,
                                'stock_transfer_id' => $stockTransferId,
                                'purchase_stock_product_id' => $psp->id,
                                'product_field_id' => $fv['product_field_id'],
                                'quantity_index' => $fv['quantity_index'],
                                'quantity_type' => $fv['quantity_type'],
                                'product_id' => $productId,
                                'value' => $fv['value'],
                            ]);
                        }
                    }

                    $allocations[] = [
                        'purchase_stock_product_id' => $psp->id,
                        'regular_pieces' => $allocateRegular,
                        'free_pieces' => $allocateFree,
                        'field_values' => array_values($newFieldValues),
                    ];

                    \Log::info('Updating additional source purchase stock product', [
                        'purchase_stock_product_id' => $psp->id,
                        'regular_pieces' => $allocateRegular,
                        'free_pieces' => $allocateFree,
                    ]);
                    $oldQuantity = $psp->quantity;
                    $oldFreeQuantity = $psp->free_quantity ?? 0;
                    $psp->quantity = max(0, $psp->quantity - ($allocateRegular / $measureUnitQuantity));
                    $psp->free_quantity = max(0, ($psp->free_quantity ?? 0) - ($allocateFree / $measureUnitQuantity));
                    \Log::debug('Before saving additional source purchase stock product', [
                        'purchase_stock_product_id' => $psp->id,
                        'old_quantity' => $oldQuantity,
                        'new_quantity' => $psp->quantity,
                        'old_free_quantity' => $oldFreeQuantity,
                        'new_free_quantity' => $psp->free_quantity,
                    ]);
                    try {
                        $psp->save();
                        \Log::info('Saved additional source purchase stock product', [
                            'purchase_stock_product_id' => $psp->id,
                            'quantity' => $psp->quantity,
                            'free_quantity' => $psp->free_quantity,
                        ]);
                    } catch (\Exception $e) {
                        \Log::error('Failed to save additional source purchase stock product', [
                            'purchase_stock_product_id' => $psp->id,
                            'error' => $e->getMessage(),
                        ]);
                        throw $e;
                    }

                    // Convert to target measure unit before saving
                    [$targetRegularQuantity, $targetFreeQuantity] = $this->stockTransferService->convertToTargetMeasureUnit(
                        $allocateRegular,
                        $allocateFree,
                        $measureUnitQuantity
                    );

                    \Log::debug('Creating new purchase stock product for target branch (additional PSP)', [
                        'target_branch_id' => $targetBranchId,
                        'regular_quantity' => $targetRegularQuantity,
                        'free_quantity' => $targetFreeQuantity,
                    ]);
                    // Create new PurchaseStockProduct for target branch
                    $newPsp = new PurchaseStockProduct([
                        'branch_id' => $targetBranchId,
                        'quantity' => $targetRegularQuantity,
                        'free_quantity' => $targetFreeQuantity,
                        'purchase_id' => $psp->purchase_id,
                        'purchase_type' => 'transfer',
                        'product_id' => $productId,
                        'product_code' => $psp->product_code,
                        'product_name' => $detail['product_name'],
                        'company_id' => $companyId,
                        'batch_no' => $psp->batch_no,
                        'measure_unit_id' => $measureUnitId,
                        'price' => $price,
                        'is_vatable' => $psp->is_vatable,
                    ]);
                    try {
                        $newPsp->save();
                        \Log::info('Saved new PurchaseStockProduct', [
                            'id' => $newPsp->id,
                            'attributes' => $newPsp->getAttributes(),
                        ]);
                    } catch (\Exception $e) {
                        \Log::error('Failed to save PurchaseStockProduct', [
                            'attributes' => $newPsp->getAttributes(),
                            'error' => $e->getMessage(),
                        ]);
                        throw $e;
                    }

                    $remainingPieces -= $toAllocate;
                    \Log::debug('Updated remaining pieces for additional PSP', [
                        'purchase_stock_product_id' => $psp->id,
                        'remaining_pieces' => $remainingPieces,
                    ]);
                }

                if ($remainingPieces > 0) {
                    \Log::error('Insufficient stock for product after processing additional PSPs', [
                        'product_id' => $productId,
                        'index' => $index,
                        'remaining_pieces' => $remainingPieces,
                    ]);
                    throw new \Exception("Insufficient stock for product {$detail['product_name']} at index {$index}. Remaining pieces: {$remainingPieces}.");
                }
            }
        } else {
            \Log::info('Processing non-field-valued product transfer', [
                'product_id' => $productId,
                'index' => $index,
            ]);

            // Handle non-field-valued products with FIFO across multiple PurchaseStockProduct
            $purchaseStockProducts = PurchaseStockProduct::where('product_id', $productId)
                ->where('measure_unit_id', $measureUnitId)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->whereDoesntHave('fieldValues')
                ->orderBy('created_at')
                ->get();

            \Log::debug('Fetching non-field-valued purchase stock products', [
                'product_id' => $productId,
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'measure_unit_id' => $measureUnitId,
                'count' => $purchaseStockProducts->count(),
                'ids' => $purchaseStockProducts->pluck('id')->toArray(),
            ]);

            if ($purchaseStockProducts->isEmpty()) {
               
                \Log::error('No stock found for non-field-valued product', [
                    'product_id' => $productId,
                    'index' => $index,
                    'product_name' => $detail['product_name'],
                ]);
                
                throw new \Exception("No stock found for product {$detail['product_name']} at index {$index}.");
            }

            $remainingPieces = $transferredPieces;

            foreach ($purchaseStockProducts as $psp) {
                if ($remainingPieces <= 0) {
                    \Log::debug('No more pieces to transfer for non-field-valued product', [
                        'purchase_stock_product_id' => $psp->id,
                    ]);
                    break;
                }

                $requiresFieldValues = $psp->fieldValues->isNotEmpty();
                if ($requiresFieldValues) {
                    \Log::error('Field values required but not provided', [
                        'purchase_stock_product_id' => $psp->id,
                        'index' => $index,
                    ]);
                    throw new \Exception("Field values required for purchase_stock_product_id {$psp->id} at index {$index}, but none provided.");
                }

                // Ensure quantities don't go negative
                $regularPieces = $this->stockTransferService->calculatePieces(max($psp->quantity, 0), $measureUnitQuantity);
                $freePieces = $this->stockTransferService->calculatePieces(max($psp->free_quantity ?? 0, 0), $measureUnitQuantity);
                $totalAvailable = $regularPieces + $freePieces;
                \Log::debug('Checking stock availability for non-field-valued product', [
                    'purchase_stock_product_id' => $psp->id,
                    'regular_pieces' => $regularPieces,
                    'free_pieces' => $freePieces,
                    'total_available' => $totalAvailable,
                ]);

                if ($totalAvailable <= 0) {
                    \Log::warning('No available stock in purchase stock product', [
                        'purchase_stock_product_id' => $psp->id,
                    ]);
                    continue;
                }

                $toTransfer = min($remainingPieces, $totalAvailable);
                $toReduceRegular = min($toTransfer, $regularPieces);
                $toReduceFree = $toTransfer - $toReduceRegular;

                if ($toReduceFree > $freePieces) {
                    \Log::error('Insufficient free quantity for non-field-valued product', [
                        'purchase_stock_product_id' => $psp->id,
                        'index' => $index,
                        'to_reduce_free' => $toReduceFree,
                        'free_pieces' => $freePieces,
                    ]);
                    throw new \Exception("Insufficient free quantity in purchase_stock_product_id {$psp->id} for product {$detail['product_name']} at index {$index}.");
                }

                $allocations[] = [
                    'purchase_stock_product_id' => $psp->id,
                    'purchase_id' => $psp->purchase_id,
                    'regular_pieces' => $toReduceRegular,
                    'free_pieces' => $toReduceFree,
                    'batch_no' => $psp->batch_no,
                    'product_code' => $psp->product_code,
                    'field_values' => [],
                ];

                \Log::info('Updating source purchase stock product for non-field-valued product', [
                    'purchase_stock_product_id' => $psp->id,
                    'regular_pieces' => $toReduceRegular,
                    'free_pieces' => $toReduceFree,
                ]);
                // Update source PurchaseStockProduct, ensuring non-negative quantities
                $oldQuantity = $psp->quantity;
                $oldFreeQuantity = $psp->free_quantity ?? 0;
                $psp->quantity = max(0, $psp->quantity - ($toReduceRegular / $measureUnitQuantity));
                $psp->free_quantity = max(0, ($psp->free_quantity ?? 0) - ($toReduceFree / $measureUnitQuantity));
                \Log::debug('Before saving source purchase stock product for non-field-valued', [
                    'purchase_stock_product_id' => $psp->id,
                    'old_quantity' => $oldQuantity,
                    'new_quantity' => $psp->quantity,
                    'old_free_quantity' => $oldFreeQuantity,
                    'new_free_quantity' => $psp->free_quantity,
                ]);
                try {
                    $psp->save();
                    \Log::info('Saved source purchase stock product for non-field-valued', [
                        'purchase_stock_product_id' => $psp->id,
                        'quantity' => $psp->quantity,
                        'free_quantity' => $psp->free_quantity,
                    ]);
                    // Verify the update in the database
                    $updatedPsp = PurchaseStockProduct::find($psp->id);
                    \Log::debug('Verified source purchase stock product after save', [
                        'purchase_stock_product_id' => $psp->id,
                        'db_quantity' => $updatedPsp->quantity,
                        'db_free_quantity' => $updatedPsp->free_quantity,
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Failed to save source purchase stock product for non-field-valued', [
                        'purchase_stock_product_id' => $psp->id,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                // Convert to target measure unit before saving
                [$targetRegularQuantity, $targetFreeQuantity] = $this->stockTransferService->convertToTargetMeasureUnit(
                    $toReduceRegular,
                    $toReduceFree,
                    $measureUnitQuantity
                );

                \Log::debug('Creating new purchase stock product for target branch (non-field-valued)', [
                    'target_branch_id' => $targetBranchId,
                    'regular_quantity' => $targetRegularQuantity,
                    'free_quantity' => $targetFreeQuantity,
                ]);
                // Create new PurchaseStockProduct for target branch
                $newPsp = new PurchaseStockProduct([
                    'branch_id' => $targetBranchId,
                    'quantity' => $targetRegularQuantity,
                    'free_quantity' => $targetFreeQuantity,
                    'purchase_id' => $psp->purchase_id,
                    'purchase_type' => 'transfer',
                    'product_id' => $productId,
                    'product_code' => $psp->product_code,
                    'product_name' => $detail['product_name'],
                    'company_id' => $companyId,
                    'batch_no' => $psp->batch_no,
                    'measure_unit_id' => $measureUnitId,
                    'price' => $price,
                    'is_vatable' => $psp->is_vatable,
                ]);
                try {
                    $newPsp->save();
                    \Log::info('Saved new PurchaseStockProduct', [
                        'id' => $newPsp->id,
                        'attributes' => $newPsp->getAttributes(),
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Failed to save PurchaseStockProduct', [
                        'attributes' => $newPsp->getAttributes(),
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                $remainingPieces -= $toTransfer;
                \Log::debug('Updated remaining pieces for non-field-valued product', [
                    'purchase_stock_product_id' => $psp->id,
                    'remaining_pieces' => $remainingPieces,
                ]);
            }

            if ($remainingPieces > 0) {
                \Log::error('Insufficient stock for non-field-valued product', [
                    'product_id' => $productId,
                    'index' => $index,
                    'remaining_pieces' => $remainingPieces,
                ]);
                throw new \Exception("Insufficient stock for product {$detail['product_name']} at index {$index}. Remaining pieces: {$remainingPieces}.");
            }
        }

        \Log::info('Completed transferProduct', [
            'product_id' => $productId,
            'index' => $index,
            'allocations_count' => count($allocations),
        ]);
    }



    private function getUnavailableQuantityIndices($purchaseStockProduct, $companyId)
    {
        $soldIndices = SalesProductFieldValue::whereIn('sale_product_id', $purchaseStockProduct->saleProducts->pluck('id'))
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->pluck('quantity_index')
            ->toArray();

        $returnedIndices = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $purchaseStockProduct->purchaseProductReturns->pluck('id'))
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->pluck('quantity_index')
            ->toArray();

        return array_unique(array_merge($soldIndices, $returnedIndices));
    }

    private function calculatePieces($quantity, $measureUnitQuantity)
    {
        return floor($quantity * $measureUnitQuantity);
    }

    private function calculateAvailablePieces($purchaseStockProduct, $companyId, $measureUnitsCalc)
    {
        $purchasedPieces = $this->calculatePieces(
            ($purchaseStockProduct->quantity ?? 0) + ($purchaseStockProduct->free_quantity ?? 0),
            $measureUnitsCalc[$purchaseStockProduct->measure_unit_id]->quantity ?? 1
        );

        $returnPieces = $purchaseStockProduct->purchaseProductReturns->reduce(
            fn($carry, $return) => $carry + $this->calculatePieces(
                ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                $measureUnitsCalc[$return->measure_unit_id]->quantity ?? 1
            ),
            0
        );

        $salePieces = $purchaseStockProduct->saleProducts->reduce(
            fn($carry, $sale) => $carry + $this->calculatePieces(
                ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0),
                $measureUnitsCalc[$sale->measure_unit_id]->quantity ?? 1
            ),
            0
        );

        $salesReturnPieces = $purchaseStockProduct->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns)->reduce(
            fn($carry, $return) => $carry + $this->calculatePieces(
                ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                $measureUnitsCalc[$return->measure_unit_id]->quantity ?? 1
            ),
            0
        );

        return $purchasedPieces - $returnPieces - $salePieces + $salesReturnPieces;
    }

    public function show($id): JsonResponse
    {
        try {
            $item = StockTransfer::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Transfer not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = StockTransfer::with('stockTransferDetails')->findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Stock Transfer deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Tranfer not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

}

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
                'product_details.*.expiry_date' => 'nullable|string|max:255',
                'product_details.*.mfd' => 'nullable|string|max:255',
                'product_details.*.discount_amount' => 'nullable|numeric',
                'product_details.*.discount_percent' => 'nullable|numeric',
                'product_details.*.quantity' => 'required|numeric|min:0.01',
                'product_details.*.purchase_type' => 'required|string',
                'product_details.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'product_details.*.price' => 'required|numeric|min:0',
                'product_details.*.amount' => 'required|numeric|min:0',
                'product_details.*.field_values' => 'present|array',
                'product_details.*.field_values.*' => 'array|min:1',
                'product_details.*.field_values.*.*.product_field_id' => 'required_if:field_values,array|integer|exists:product_fields,id',
                'product_details.*.field_values.*.*.purchase_stock_product_field_value_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.purchase_product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_product_id' => 'nullable|numeric',

                'product_details.*.field_values.*.*.product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_adjustment_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_reconciliation_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.value' => 'required_if:field_values,array|string|max:255',
                'product_details.*.field_values.*.*.quantity_index' => 'required_if:field_values,array|integer|min:0',
                'product_details.*.field_values.*.*.quantity_type' => 'nullable|string|in:regular,free',
                'product_details.*.field_values.*.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',
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
            'measure_unit_id' => $detail['measure_unit_id'],
        ]);

        $productId = $detail['product_id'];
        $quantity = (float) $detail['quantity'];
        $measureUnitId = $detail['measure_unit_id'];
        $price = $detail['price'];

        // Fetch target measure unit
        \Log::debug('Fetching target measure unit', ['measure_unit_id' => $measureUnitId]);
        $targetMeasureUnit = $measureUnitsCalc[$measureUnitId] ?? throw new \Exception("Measure unit ID {$measureUnitId} not found.");
        $targetMeasureUnitQuantity = (float) ($targetMeasureUnit->quantity ?? 1);

        // Calculate total pieces requested
        $transferredPieces = $this->stockTransferService->calculatePieces($quantity, $targetMeasureUnitQuantity);
        \Log::debug('Calculated transferred pieces', [
            'quantity' => $quantity,
            'measure_unit_quantity' => $targetMeasureUnitQuantity,
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

            // Group field values by purchase_stock_product_id
            $fieldValuesByStockId = [];
            foreach ($detail['field_values'] as $fieldValueSet) {
                $purchaseStockProductId = $fieldValueSet[0]['purchase_stock_product_id'];
                $fieldValuesByStockId[$purchaseStockProductId][] = $fieldValueSet;
            }

            \Log::debug('Grouped field values by purchase_stock_product_id', [
                'product_id' => $productId,
                'index' => $index,
                'field_values_by_stock_id' => array_keys($fieldValuesByStockId),
            ]);

            // Validate all field values upfront
            $totalPiecesToTransfer = 0;
            $validatedFieldValues = [];
            $piecesByStockId = [];

            foreach ($fieldValuesByStockId as $purchaseStockProductId => $fieldValueSets) {
                $psp = PurchaseStockProduct::where('id', $purchaseStockProductId)
                    ->where('product_id', $productId)
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$psp) {
                    \Log::error('PurchaseStockProduct not found for field-valued product', [
                        'purchase_stock_product_id' => $purchaseStockProductId,
                        'product_id' => $productId,
                        'index' => $index,
                        'product_name' => $detail['product_name'],
                    ]);
                    throw new \Exception("PurchaseStockProduct ID {$purchaseStockProductId} not found for product {$detail['product_name']} (ID: {$productId}) at index {$index}.");
                }

                // Track unique quantity_index values for this purchase_stock_product_id
                $quantityIndexes = [];
                foreach ($fieldValueSets as $fieldValueSet) {
                    $quantityIndex = $fieldValueSet[0]['quantity_index'] ?? null;
                    if ($quantityIndex === null) {
                        \Log::error('Missing quantity_index in field value set', [
                            'purchase_stock_product_id' => $purchaseStockProductId,
                            'field_value_set' => $fieldValueSet,
                            'index' => $index,
                        ]);
                        throw new \Exception("Missing quantity_index for purchase_stock_product_id {$purchaseStockProductId} at index {$index}.");
                    }

                    foreach ($fieldValueSet as $fieldValue) {
                        $fieldValueId = $fieldValue['purchase_stock_product_field_value_id'] ?? null;
                        if (!$fieldValueId) {
                            \Log::error('Missing purchase_stock_product_field_value_id in field value', [
                                'purchase_stock_product_id' => $purchaseStockProductId,
                                'field_value' => $fieldValue,
                                'index' => $index,
                            ]);
                            throw new \Exception("Missing purchase_stock_product_field_value_id for purchase_stock_product_id {$purchaseStockProductId} at index {$index}.");
                        }

                        $fieldValueRecord = PurchaseStockProductFieldValue::where('id', $fieldValueId)
                            ->where('purchase_stock_product_id', $purchaseStockProductId)
                            ->where('company_id', $companyId)
                            ->where('branch_id', $branchId)
                            ->whereNull('deleted_at')
                            ->first();

                        if (!$fieldValueRecord) {
                            \Log::error('PurchaseStockProductFieldValue not found or unavailable', [
                                'purchase_stock_product_field_value_id' => $fieldValueId,
                                'purchase_stock_product_id' => $purchaseStockProductId,
                                'index' => $index,
                            ]);
                            throw new \Exception("PurchaseStockProductFieldValue ID {$fieldValueId} not found or unavailable for purchase_stock_product_id {$purchaseStockProductId} at index {$index}.");
                        }

                        $validatedFieldValues[$purchaseStockProductId][] = $fieldValue;
                    }

                    // Count unique quantity_index
                    if (!in_array($quantityIndex, $quantityIndexes)) {
                        $quantityIndexes[] = $quantityIndex;
                        $totalPiecesToTransfer++;
                    }
                }

                $piecesByStockId[$purchaseStockProductId] = count($quantityIndexes);
            }

            // Validate total pieces against requested quantity
            \Log::debug('Validating total pieces to transfer', [
                'product_id' => $productId,
                'index' => $index,
                'transferred_pieces' => $transferredPieces,
                'total_pieces_to_transfer' => $totalPiecesToTransfer,
                'pieces_by_stock_id' => $piecesByStockId,
            ]);

            if ($totalPiecesToTransfer != $transferredPieces) {
                \Log::error('Mismatch between requested quantity and field value pieces', [
                    'product_id' => $productId,
                    'index' => $index,
                    'transferred_pieces' => $transferredPieces,
                    'total_pieces_to_transfer' => $totalPiecesToTransfer,
                    'pieces_by_stock_id' => $piecesByStockId,
                ]);
                throw new \Exception("Requested quantity ({$transferredPieces}) does not match provided field value pieces ({$totalPiecesToTransfer}) for product {$detail['product_name']} at index {$index}.");
            }

            $processedStockIds = [];
            foreach ($fieldValuesByStockId as $purchaseStockProductId => $fieldValueSets) {
                \Log::debug('Processing field-valued stock for purchase_stock_product_id', [
                    'purchase_stock_product_id' => $purchaseStockProductId,
                    'field_value_sets_count' => count($fieldValueSets),
                ]);

                // Fetch the specific PurchaseStockProduct
                $psp = PurchaseStockProduct::where('id', $purchaseStockProductId)
                    ->where('product_id', $productId)
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->first();

                $processedStockIds[] = $purchaseStockProductId;

                $pspMuQty = (float) ($measureUnitsCalc[$psp->measure_unit_id]->quantity ?? 1);
                $regularPieces = $this->stockTransferService->calculatePieces(max($psp->quantity, 0), $pspMuQty);
                $freePieces = $this->stockTransferService->calculatePieces(max($psp->free_quantity ?? 0, 0), $pspMuQty);
                $totalAvailable = $regularPieces + $freePieces;

                \Log::debug('Checking stock availability for field-valued product', [
                    'purchase_stock_product_id' => $psp->id,
                    'regular_pieces' => $regularPieces,
                    'free_pieces' => $freePieces,
                    'total_available' => $totalAvailable,
                    'source_quantity' => $psp->quantity,
                    'source_free_quantity' => $psp->free_quantity,
                    'source_measure_unit_id' => $psp->measure_unit_id,
                    'source_measure_unit_quantity' => $pspMuQty,
                ]);

                // Count unique quantity_index values for this purchase_stock_product_id
                $quantityIndexes = array_unique(array_column(array_merge(...$fieldValueSets), 'quantity_index'));
                $toTransfer = count($quantityIndexes);

                if ($toTransfer > $totalAvailable) {
                    \Log::error('Insufficient stock for field-valued product', [
                        'purchase_stock_product_id' => $psp->id,
                        'index' => $index,
                        'to_transfer' => $toTransfer,
                        'total_available' => $totalAvailable,
                    ]);
                    throw new \Exception("Insufficient stock in purchase_stock_product_id {$psp->id} for product {$detail['product_name']} at index {$index}. Requested: {$toTransfer}, Available: {$totalAvailable}.");
                }

                $toReduceRegular = min($toTransfer, $regularPieces);
                $toReduceFree = $toTransfer - $toReduceRegular;

                if ($toReduceFree > $freePieces) {
                    \Log::error('Insufficient free quantity for field-valued product', [
                        'purchase_stock_product_id' => $psp->id,
                        'index' => $index,
                        'to_reduce_free' => $toReduceFree,
                        'free_pieces' => $freePieces,
                    ]);
                    throw new \Exception("Insufficient free quantity in purchase_stock_product_id {$psp->id} for product {$detail['product_name']} at index {$index}.");
                }

                // Prepare field values for allocation
                $pspFieldValues = [];
                foreach ($fieldValueSets as $fieldValueSet) {
                    foreach ($fieldValueSet as $fieldValue) {
                        $pspFieldValues[] = [
                            'purchase_stock_product_field_value_id' => $fieldValue['purchase_stock_product_field_value_id'],
                            'product_field_id' => $fieldValue['product_field_id'],
                            'value' => $fieldValue['value'],
                            'quantity_index' => $fieldValue['quantity_index'],
                            'quantity_type' => $fieldValue['quantity_type'] ?? 'regular',
                            'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                            'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                            'product_id' => $fieldValue['product_id'] ?? null,
                            'stock_adjustment_id' => $fieldValue['stock_adjustment_id'] ?? null,
                            'stock_reconciliation_id' => $fieldValue['stock_reconciliation_id'] ?? null,
                        ];
                    }
                }

                $allocations[] = [
                    'purchase_stock_product_id' => $psp->id,
                    'purchase_id' => $psp->purchase_id,
                    'regular_pieces' => $toReduceRegular,
                    'free_pieces' => $toReduceFree,
                    'batch_no' => $psp->batch_no,
                    'product_code' => $psp->product_code,
                    'field_values' => $pspFieldValues,
                ];

                \Log::info('Updating source purchase stock product for field-valued product', [
                    'purchase_stock_product_id' => $psp->id,
                    'regular_pieces' => $toReduceRegular,
                    'free_pieces' => $toReduceFree,
                ]);
                $oldQuantity = $psp->quantity;
                $oldFreeQuantity = $psp->free_quantity ?? 0;

                $psp->quantity = $this->stockTransferService->calculatePiecestoReduce($oldQuantity, $toReduceRegular, $pspMuQty);
                $psp->free_quantity = $this->stockTransferService->calculatePiecestoReduce($oldFreeQuantity, $toReduceFree, $pspMuQty);
                \Log::debug('Before saving source purchase stock product for field-valued', [
                    'purchase_stock_product_id' => $psp->id,
                    'old_quantity' => $oldQuantity,
                    'new_quantity' => $psp->quantity,
                    'old_free_quantity' => $oldFreeQuantity,
                    'new_free_quantity' => $psp->free_quantity,
                ]);
                try {
                    $saved = $psp->save();
                    if (!$saved) {
                        \Log::error('Source purchase stock product save returned false for field-valued', [
                            'purchase_stock_product_id' => $psp->id,
                            'quantity' => $psp->quantity,
                            'free_quantity' => $psp->free_quantity,
                        ]);
                        throw new \Exception("Failed to save source purchase stock product ID {$psp->id} at index {$index}.");
                    }
                    \Log::info('Saved source purchase stock product for field-valued', [
                        'purchase_stock_product_id' => $psp->id,
                        'quantity' => $psp->quantity,
                        'free_quantity' => $psp->free_quantity,
                    ]);
                    $updatedPsp = PurchaseStockProduct::find($psp->id);
                    if (!$updatedPsp) {
                        \Log::error('Source purchase stock product not found after save for field-valued', [
                            'purchase_stock_product_id' => $psp->id,
                        ]);
                        throw new \Exception("Source purchase stock product ID {$psp->id} not found after save at index {$index}.");
                    }
                    \Log::debug('Verified source purchase stock product after save', [
                        'purchase_stock_product_id' => $psp->id,
                        'db_quantity' => $updatedPsp->quantity,
                        'db_free_quantity' => $updatedPsp->free_quantity,
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Failed to save source purchase stock product for field-valued', [
                        'purchase_stock_product_id' => $psp->id,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                // Convert to target measure unit
                [$targetRegularQuantity, $targetFreeQuantity] = $this->stockTransferService->convertToTargetMeasureUnit(
                    $toReduceRegular,
                    $toReduceFree,
                    $targetMeasureUnitQuantity
                );

                \Log::debug('Creating new purchase stock product for target branch (field-valued)', [
                    'target_branch_id' => $targetBranchId,
                    'regular_quantity' => $targetRegularQuantity,
                    'free_quantity' => $targetFreeQuantity,
                    'product_id' => $productId,
                    'measure_unit_id' => $measureUnitId,
                    'purchase_id' => $psp->purchase_id,
                ]);
                $newPsp = new PurchaseStockProduct([
                    'branch_id' => $targetBranchId,
                    'quantity' => $targetRegularQuantity,
                    'free_quantity' => $targetFreeQuantity,
                    'purchase_id' => $psp->purchase_id ?? null,
                    'purchase_product_id' => $psp->purchase_product_id ?? null,
                    'stock_transfer_id' => $stockTransferId,
                    'stock_product_id' => $psp->stock_product_id ?? null,
                    'stock_reconciliation_id' => $psp->stock_reconciliation_id ?? null,
                    'product_id' => $productId,
                    'product_code' => $psp->product_code,
                    'purchase_type' => $psp->purchase_type,
                    'product_name' => $detail['product_name'],
                    'transfer_status' => 'pending',
                    'discount_amount' => $detail['discount_amount'],
                    'amount' => $detail['amount'],
                    'mfd' => $psp->mfd ?? null,
                    'expiry_date' => $psp->expiry_date ?? null,
                    'discount_percent' => $detail['discount_percent'],
                    'company_id' => $companyId,
                    'batch_no' => $psp->batch_no,
                    'measure_unit_id' => $measureUnitId,
                    'price' => $price,
                    'is_vatable' => $psp->is_vatable,
                ]);
                try {
                    \Log::debug('Attempting to save new PurchaseStockProduct (field-valued)', [
                        'attributes' => $newPsp->getAttributes(),
                    ]);
                    $saved = $newPsp->save();
                    if (!$saved) {
                        \Log::error('Target purchase stock product save returned false for field-valued', [
                            'attributes' => $newPsp->getAttributes(),
                        ]);
                        throw new \Exception("Failed to save target purchase stock product for product ID {$productId} at index {$index}.");
                    }
                    \Log::info('Saved new PurchaseStockProduct for field-valued', [
                        'id' => $newPsp->id,
                        'attributes' => $newPsp->getAttributes(),
                    ]);
                    $createdPsp = PurchaseStockProduct::find($newPsp->id);
                    if (!$createdPsp) {
                        \Log::error('Target purchase stock product not found after save for field-valued', [
                            'id' => $newPsp->id,
                        ]);
                        throw new \Exception("Target purchase stock product ID {$newPsp->id} not found after save at index {$index}.");
                    }
                    \Log::debug('Verified target purchase stock product after save', [
                        'id' => $newPsp->id,
                        'db_quantity' => $createdPsp->quantity,
                        'db_free_quantity' => $createdPsp->free_quantity,
                    ]);

                    // Update specific PurchaseStockProductFieldValue records
                    foreach ($validatedFieldValues[$purchaseStockProductId] as $fieldValue) {
                        $fieldValueId = $fieldValue['purchase_stock_product_field_value_id'];
                        $fieldValueRecord = PurchaseStockProductFieldValue::where('id', $fieldValueId)
                            ->where('purchase_stock_product_id', $purchaseStockProductId)
                            ->first();

                        \Log::debug('Updating PurchaseStockProductFieldValue', [
                            'purchase_stock_product_field_value_id' => $fieldValueId,
                            'purchase_stock_product_id' => $purchaseStockProductId,
                            'new_purchase_stock_product_id' => $newPsp->id,
                            'branch_id' => $targetBranchId,
                            'stock_transfer_id' => $stockTransferId,
                        ]);

                        try {
                            $fieldValueRecord->update([
                                'purchase_stock_product_id' => $newPsp->id,
                                'branch_id' => $targetBranchId,
                                'stock_transfer_id' => $stockTransferId,
                            ]);
                            \Log::info('Updated PurchaseStockProductFieldValue', [
                                'purchase_stock_product_field_value_id' => $fieldValueId,
                                'purchase_stock_product_id' => $newPsp->id,
                                'branch_id' => $targetBranchId,
                                'stock_transfer_id' => $stockTransferId,
                            ]);
                        } catch (\Exception $e) {
                            \Log::error('Failed to update PurchaseStockProductFieldValue', [
                                'purchase_stock_product_field_value_id' => $fieldValueId,
                                'purchase_stock_product_id' => $newPsp->id,
                                'error' => $e->getMessage(),
                            ]);
                            throw new \Exception("Failed to update PurchaseStockProductFieldValue ID {$fieldValueId} for purchase_stock_product_id {$newPsp->id} at index {$index}.");
                        }
                    }
                } catch (\Exception $e) {
                    \Log::error('Failed to save PurchaseStockProduct for field-valued', [
                        'attributes' => $newPsp->getAttributes(),
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }
            }
        } else {
            \Log::info('Entering non-field-valued product transfer loop', [
                'product_id' => $productId,
                'index' => $index,
                'transferred_pieces' => $transferredPieces,
            ]);

            // Fetch non-field-valued products with FIFO
            $purchaseStockProducts = PurchaseStockProduct::where('product_id', $productId)
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
                'count' => $purchaseStockProducts->count(),
                'records' => $purchaseStockProducts->map(function ($psp) use ($measureUnitsCalc) {
                    $muQty = (float) ($measureUnitsCalc[$psp->measure_unit_id]->quantity ?? 1);
                    return [
                        'id' => $psp->id,
                        'quantity' => $psp->quantity,
                        'free_quantity' => $psp->free_quantity ?? 0,
                        'measure_unit_id' => $psp->measure_unit_id,
                        'measure_unit_quantity' => $muQty,
                        'purchase_id' => $psp->purchase_id,
                        'batch_no' => $psp->batch_no,
                        'product_code' => $psp->product_code,
                    ];
                })->toArray(),
            ]);

            if ($purchaseStockProducts->isEmpty()) {
                \Log::error('No stock found for non-field-valued product', [
                    'product_id' => $productId,
                    'index' => $index,
                    'product_name' => $detail['product_name'],
                ]);
                throw new \Exception("No stock found for product {$detail['product_name']} (ID: {$productId}) at index {$index}.");
            }

            $remainingPieces = $transferredPieces;

            foreach ($purchaseStockProducts as $psp) {
                \Log::debug('Processing purchase stock product for transfer', [
                    'purchase_stock_product_id' => $psp->id,
                    'remaining_pieces' => $remainingPieces,
                ]);

                if ($remainingPieces <= 0) {
                    \Log::debug('No more pieces to transfer for non-field-valued product', [
                        'purchase_stock_product_id' => $psp->id,
                    ]);
                    break;
                }

                $pspMuQty = (float) ($measureUnitsCalc[$psp->measure_unit_id]->quantity ?? 1);

                $regularPieces = $this->stockTransferService->calculatePieces(max($psp->quantity, 0), $pspMuQty);
                $freePieces = $this->stockTransferService->calculatePieces(max($psp->free_quantity ?? 0, 0), $pspMuQty);
                $totalAvailable = $regularPieces + $freePieces;
                \Log::debug('Checking stock availability for non-field-valued product', [
                    'purchase_stock_product_id' => $psp->id,
                    'regular_pieces' => $regularPieces,
                    'free_pieces' => $freePieces,
                    'total_available' => $totalAvailable,
                    'source_quantity' => $psp->quantity,
                    'source_free_quantity' => $psp->free_quantity,
                    'source_measure_unit_id' => $psp->measure_unit_id,
                    'source_measure_unit_quantity' => $pspMuQty,
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
                $oldQuantity = $psp->quantity;
                $oldFreeQuantity = $psp->free_quantity ?? 0;

                $psp->quantity = $this->stockTransferService->calculatePiecestoReduce($oldQuantity, $toReduceRegular, $pspMuQty);
                $psp->free_quantity = $this->stockTransferService->calculatePiecestoReduce($oldFreeQuantity, $toReduceFree, $pspMuQty);
                \Log::debug('Before saving source purchase stock product for non-field-valued', [
                    'purchase_stock_product_id' => $psp->id,
                    'old_quantity' => $oldQuantity,
                    'new_quantity' => $psp->quantity,
                    'old_free_quantity' => $oldFreeQuantity,
                    'new_free_quantity' => $psp->free_quantity,
                ]);
                try {
                    $saved = $psp->save();
                    if (!$saved) {
                        \Log::error('Source purchase stock product save returned false for non-field-valued', [
                            'purchase_stock_product_id' => $psp->id,
                            'quantity' => $psp->quantity,
                            'free_quantity' => $psp->free_quantity,
                        ]);
                        throw new \Exception("Failed to save source purchase stock product ID {$psp->id} at index {$index}.");
                    }
                    \Log::info('Saved source purchase stock product for non-field-valued', [
                        'purchase_stock_product_id' => $psp->id,
                        'quantity' => $psp->quantity,
                        'free_quantity' => $psp->free_quantity,
                    ]);
                    $updatedPsp = PurchaseStockProduct::find($psp->id);
                    if (!$updatedPsp) {
                        \Log::error('Source purchase stock product not found after save', [
                            'purchase_stock_product_id' => $psp->id,
                        ]);
                        throw new \Exception("Source purchase stock product ID {$psp->id} not found after save at index {$index}.");
                    }
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

                // Convert to target measure unit
                [$targetRegularQuantity, $targetFreeQuantity] = $this->stockTransferService->convertToTargetMeasureUnit(
                    $toReduceRegular,
                    $toReduceFree,
                    $targetMeasureUnitQuantity
                );

                \Log::debug('Creating new purchase stock product for target branch (non-field-valued)', [
                    'target_branch_id' => $targetBranchId,
                    'regular_quantity' => $targetRegularQuantity,
                    'free_quantity' => $targetFreeQuantity,
                    'product_id' => $productId,
                    'measure_unit_id' => $measureUnitId,
                    'purchase_id' => $psp->purchase_id,
                ]);
                $newPsp = new PurchaseStockProduct([
                    'branch_id' => $targetBranchId,
                    'quantity' => $targetRegularQuantity,
                    'free_quantity' => $targetFreeQuantity,
                    'purchase_id' => $psp->purchase_id ?? null,
                    'stock_transfer_id' => $stockTransferId,
                    'purchase_product_id' => $psp->purchase_product_id ?? null,
                    'stock_product_id' => $psp->stock_product_id ?? null,
                    'stock_reconciliation_id' => $psp->stock_reconciliation_id ?? null,
                    'transfer_status' => 'pending',
                    'product_id' => $productId,
                    'product_code' => $psp->product_code,
                    'purchase_type' => $psp->purchase_type,
                    'product_name' => $detail['product_name'],
                    'discount_amount' => $detail['discount_amount'],
                    'amount' => $detail['amount'],
                    'mfd' => $psp->mfd ?? null,
                    'expiry_date' => $psp->expiry_date ?? null,
                    'discount_percent' => $detail['discount_percent'],

                    'company_id' => $companyId,
                    'batch_no' => $psp->batch_no,
                    'measure_unit_id' => $measureUnitId,
                    'price' => $price,
                    'is_vatable' => $psp->is_vatable,
                ]);
                try {
                    \Log::debug('Attempting to save new PurchaseStockProduct (non-field-valued)', [
                        'attributes' => $newPsp->getAttributes(),
                    ]);
                    $saved = $newPsp->save();
                    if (!$saved) {
                        \Log::error('Target purchase stock product save returned false for non-field-valued', [
                            'attributes' => $newPsp->getAttributes(),
                        ]);
                        throw new \Exception("Failed to save target purchase stock product for product ID {$productId} at index {$index}.");
                    }
                    \Log::info('Saved new PurchaseStockProduct for non-field-valued', [
                        'id' => $newPsp->id,
                        'attributes' => $newPsp->getAttributes(),
                    ]);
                    $createdPsp = PurchaseStockProduct::find($newPsp->id);
                    if (!$createdPsp) {
                        \Log::error('Target purchase stock product not found after save', [
                            'id' => $newPsp->id,
                        ]);
                        throw new \Exception("Target purchase stock product ID {$newPsp->id} not found after save at index {$index}.");
                    }
                    \Log::debug('Verified target purchase stock product after save', [
                        'id' => $newPsp->id,
                        'db_quantity' => $createdPsp->quantity,
                        'db_free_quantity' => $createdPsp->free_quantity,
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Failed to save PurchaseStockProduct for non-field-valued', [
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
                throw new \Exception("Insufficient stock for product {$detail['product_name']} (ID: {$productId}) at index {$index}. Remaining pieces: {$remainingPieces}.");
            }
        }

        \Log::info('Completed transferProduct', [
            'product_id' => $productId,
            'index' => $index,
            'allocations_count' => count($allocations),
        ]);

        return $allocations;
    }



    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validated = $request->validate([
                'reference_no' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stock_transfers')
                        ->where(function ($query) use ($request, $id) {
                            return $query->where('company_id', $request->company_id)
                                ->whereNull('deleted_at')
                                ->where('id', '!=', $id);
                        }),
                ],
                'transfer_to' => 'required|integer|exists:branches,id',
                'document_no' => 'nullable|string|max:255',
                'current_location' => 'required|integer|exists:branches,id',
                'date_ad' => 'nullable|date',
                'transfer_date_bs' => 'nullable|string|max:255',
                'document_number' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reasons_for' => 'nullable|string|max:255',
                'product_details' => 'required|array|min:1',
                'product_details.*.id' => 'nullable|numeric|exists:stock_transfer_details,id',
                'product_details.*.product_id' => 'required|integer|exists:products,id',
                'product_details.*.product_name' => 'required|string|max:255',
                'product_details.*.product_code' => 'required|string|max:255',
                'product_details.*.expiry_date' => 'nullable|string|max:255',
                'product_details.*.mfd' => 'nullable|string|max:255',
                'product_details.*.discount_amount' => 'nullable|numeric',
                'product_details.*.discount_percent' => 'nullable|numeric|',
                'product_details.*.quantity' => 'required|numeric|min:0.01',
                'product_details.*.purchase_type' => 'required|string',
                'product_details.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'product_details.*.price' => 'required|numeric|min:0',
                'product_details.*.amount' => 'required|numeric|min:0',
                'product_details.*.field_values' => 'present|array',
                'product_details.*.field_values.*' => 'array|min:1',
                'product_details.*.field_values.*.*.product_field_id' => 'required_if:field_values,array|integer|exists:product_fields,id',
                'product_details.*.field_values.*.*.purchase_stock_product_field_value_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.purchase_product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_adjustment_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_reconciliation_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.value' => 'required_if:field_values,array|string|max:255',
                'product_details.*.field_values.*.*.quantity_index' => 'required_if:field_values,array|integer|min:0',
                'product_details.*.field_values.*.*.quantity_type' => 'nullable|string|in:regular,free',
                'product_details.*.field_values.*.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',
                'company_id' => 'required|integer|exists:companies,id',
            ]);

            DB::beginTransaction();
            try {
                Log::info('Starting stock transfer update process', [
                    'stock_transfer_id' => $id,
                    'request_data' => $request->except(['product_details']),
                    'user_id' => auth()->id(),
                ]);

                $stockTransfer = StockTransfer::where('id', $id)
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->with('stockTransferDetails')
                    ->first();

                if (!$stockTransfer) {
                    Log::error('Stock transfer not found', [
                        'stock_transfer_id' => $id,
                        'company_id' => $validated['company_id'],
                    ]);
                    throw new ModelNotFoundException("Stock transfer ID {$id} not found.");
                }


                $this->reverseStockTransfer($stockTransfer);


                Log::debug('Fetching measure units', [
                    'company_id' => $validated['company_id'],
                ]);
                $measureUnitsCalc = MeasureUnit::where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->get()
                    ->keyBy('id');

                $validated['branch_id'] = $validated['current_location'];
                $productDetails = $validated['product_details'];
                unset($validated['product_details']);

                Log::info('Updating stock transfer record', [
                    'stock_transfer_id' => $stockTransfer->id,
                    'validated_data' => $validated,
                ]);

                // Update main stock transfer
                $stockTransfer->update($validated);

                // Track IDs from request for cleanup
                $idsFromRequest = collect($productDetails)->pluck('id')->filter()->toArray();
                $newlyCreatedIds = [];

                foreach ($productDetails as $index => $detail) {
                    Log::debug('Processing product detail for update', [
                        'index' => $index,
                        'product_id' => $detail['product_id'],
                        'quantity' => $detail['quantity'],
                        'detail_id' => $detail['id'] ?? null,
                    ]);

                    // Transfer product stock
                    $this->transferProduct($detail, $validated['company_id'], $validated['current_location'], $validated['transfer_to'], $measureUnitsCalc, $stockTransfer->id, $index);

                    // Prepare stock transfer detail data
                    $detailData = [
                        'stock_transfer_id' => $stockTransfer->id,
                        'company_id' => $validated['company_id'],
                        'product_id' => $detail['product_id'],
                        'product_name' => $detail['product_name'],
                        'product_code' => $detail['product_code'],
                        'expiry_date' => $detail['expiry_date'] ?? null,
                        'mfd' => $detail['mfd'] ?? null,
                        'discount_amount' => $detail['discount_amount'] ?? 0,
                        'discount_percent' => $detail['discount_percent'] ?? 0,
                        'quantity' => $detail['quantity'],
                        'purchase_type' => $detail['purchase_type'],
                        'measure_unit_id' => $detail['measure_unit_id'],
                        'price' => $detail['price'],
                        'amount' => $detail['amount'],
                    ];

                    if (!empty($detail['id'])) {
                        $stockTransferDetail = $stockTransfer->stockTransferDetails()
                            ->where('id', $detail['id'])
                            ->first();

                        if ($stockTransferDetail) {
                            Log::debug('Updating existing stock transfer detail', [
                                'stock_transfer_detail_id' => $detail['id'],
                                'data' => $detailData,
                            ]);
                            $stockTransferDetail->update($detailData);
                            $newlyCreatedIds[] = $stockTransferDetail->id;
                        } else {
                            Log::debug('Creating new stock transfer detail for provided ID', [
                                'stock_transfer_detail_id' => $detail['id'],
                                'data' => $detailData,
                            ]);
                            $newDetail = $stockTransfer->stockTransferDetails()->create($detailData);
                            $newlyCreatedIds[] = $newDetail->id;
                        }
                    } else {
                        Log::debug('Creating new stock transfer detail', [
                            'data' => $detailData,
                        ]);
                        $newDetail = $stockTransfer->stockTransferDetails()->create($detailData);
                        $newlyCreatedIds[] = $newDetail->id;
                    }
                }

                // Delete details not included in the request
                $allIdsToKeep = array_merge($idsFromRequest, $newlyCreatedIds);
                Log::info('Deleting unupdated stock transfer details', [
                    'stock_transfer_id' => $stockTransfer->id,
                    'ids_to_keep' => $allIdsToKeep,
                ]);
                $stockTransfer->stockTransferDetails()
                    ->whereNotIn('id', $allIdsToKeep)
                    ->delete();

                DB::commit();

                Log::info('Stock transfer updated successfully', [
                    'stock_transfer_id' => $stockTransfer->id,
                ]);
                return response()->json([
                    'status' => true,
                    'message' => 'Stock transfer updated successfully.',
                    'data' => $stockTransfer->load('stockTransferDetails'),
                ], 200);
            } catch (ModelNotFoundException $e) {
                DB::rollBack();
                Log::error('ModelNotFoundException in StockTransfer::update', [
                    'stock_transfer_id' => $id,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json([
                    'status' => false,
                    'message' => 'Stock transfer not found.',
                    'error' => $e->getMessage(),
                ], 404);
            } catch (QueryException $e) {
                DB::rollBack();
                Log::error('QueryException in StockTransfer::update', [
                    'stock_transfer_id' => $id,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to update stock transfer due to a database error.',
                    'error' => $e->getMessage(),
                ], 500);
            } catch (\Exception $e) {
                DB::rollBack();


                Log::error('Exception in StockTransfer::update', [
                    'stock_transfer_id' => $id,
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
                return response()->json([
                    'status' => false,
                    'message' => 'Failed to update stock transfer.',
                    'error' => $e->getMessage(),
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('Root exception in StockTransfer::update', [
                'stock_transfer_id' => $id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'status' => false,
                'message' => 'Failed to update stock transfer.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    private function reverseStockTransfer(StockTransfer $stockTransfer)
    {
        try {
            Log::info('Reversing stock transfer for field values and purchase stock products', [
                'stock_transfer_id' => $stockTransfer->id,
                'source_branch_id' => $stockTransfer->branch_id,
                'target_branch_id' => $stockTransfer->transfer_to,
            ]);

            $companyId = $stockTransfer->company_id;
            $sourceBranchId = $stockTransfer->branch_id;


            $psps = PurchaseStockProduct::where('stock_transfer_id', $stockTransfer->id)
                ->where('company_id', $companyId)
                ->withTrashed()
                ->get();

            Log::debug('PurchaseStockProduct records found for stock transfer', [
                'stock_transfer_id' => $stockTransfer->id,
                'psp_count' => $psps->count(),
                'psp_details' => $psps->map(function ($psp) {
                    return [
                        'id' => $psp->id,
                        'product_id' => $psp->product_id,
                        'branch_id' => $psp->branch_id,
                        'deleted_at' => $psp->deleted_at ? $psp->deleted_at->toDateTimeString() : null,
                        'quantity' => $psp->quantity,
                        'free_quantity' => $psp->free_quantity,
                    ];
                })->toArray(),
            ]);

            // Update PurchaseStockProductFieldValue records to reset to source branch
            $fieldValueUpdatedCount = PurchaseStockProductFieldValue::where('stock_transfer_id', $stockTransfer->id)
                ->where('company_id', $companyId)
                ->update([
                    'branch_id' => $sourceBranchId,
                    'stock_transfer_id' => null,
                ]);

            Log::info('Stock transfer field values reset successfully', [
                'stock_transfer_id' => $stockTransfer->id,
                'field_value_updated_count' => $fieldValueUpdatedCount,
            ]);

            if ($fieldValueUpdatedCount === 0) {
                Log::warning('No field values found to reset for stock transfer', [
                    'stock_transfer_id' => $stockTransfer->id,
                ]);
            } else {
                // Log field values to check their linked PSPs
                $fieldValues = PurchaseStockProductFieldValue::where('stock_transfer_id', null)
                    ->where('company_id', $companyId)
                    ->where('branch_id', $sourceBranchId)
                    ->get();

                Log::debug('Field values after reset', [
                    'stock_transfer_id' => $stockTransfer->id,
                    'field_value_count' => $fieldValues->count(),
                    'field_value_details' => $fieldValues->map(function ($fv) {
                        return [
                            'id' => $fv->id,
                            'purchase_stock_product_id' => $fv->purchase_stock_product_id,
                            'product_field_id' => $fv->product_field_id,
                            'value' => $fv->value,
                            'quantity_index' => $fv->quantity_index,
                        ];
                    })->toArray(),
                ]);
            }

            // Update PurchaseStockProduct records to reset branch_id and stock_transfer_id
            $pspUpdatedCount = PurchaseStockProduct::where('stock_transfer_id', $stockTransfer->id)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->update([
                    'branch_id' => $sourceBranchId,
                    'stock_transfer_id' => null,
                ]);

            Log::info('Purchase stock products reset to source branch successfully', [
                'stock_transfer_id' => $stockTransfer->id,
                'psp_updated_count' => $pspUpdatedCount,
            ]);

            if ($pspUpdatedCount === 0) {
                Log::warning('No purchase stock products found to reset for stock transfer', [
                    'stock_transfer_id' => $stockTransfer->id,
                ]);

                // Additional check for PSPs in unexpected branches
                $pspsInOtherBranches = PurchaseStockProduct::where('stock_transfer_id', $stockTransfer->id)
                    ->where('company_id', $companyId)
                    ->where('branch_id', '!=', $stockTransfer->transfer_to)
                    ->whereNull('deleted_at')
                    ->get();

                Log::debug('PurchaseStockProduct records in unexpected branches', [
                    'stock_transfer_id' => $stockTransfer->id,
                    'count' => $pspsInOtherBranches->count(),
                    'details' => $pspsInOtherBranches->map(function ($psp) {
                        return [
                            'id' => $psp->id,
                            'product_id' => $psp->product_id,
                            'branch_id' => $psp->branch_id,
                            'quantity' => $psp->quantity,
                        ];
                    })->toArray(),
                ]);

                // Check for soft-deleted PSPs
                $softDeletedPsps = PurchaseStockProduct::where('stock_transfer_id', $stockTransfer->id)
                    ->where('company_id', $companyId)
                    ->onlyTrashed()
                    ->get();

                Log::debug('Soft-deleted PurchaseStockProduct records', [
                    'stock_transfer_id' => $stockTransfer->id,
                    'count' => $softDeletedPsps->count(),
                    'details' => $softDeletedPsps->map(function ($psp) {
                        return [
                            'id' => $psp->id,
                            'product_id' => $psp->product_id,
                            'branch_id' => $psp->branch_id,
                            'deleted_at' => $psp->deleted_at ? $psp->deleted_at->toDateTimeString() : null,
                        ];
                    })->toArray(),
                ]);
            }

            // Check for orphaned field values (linked to non-existent or soft-deleted PSPs)
            $orphanedFieldValues = PurchaseStockProductFieldValue::where('stock_transfer_id', null)
                ->where('company_id', $companyId)
                ->where('branch_id', $sourceBranchId)
                ->whereNotExists(function ($query) use ($companyId) {
                    $query->select(DB::raw(1))
                        ->from('purchase_stock_products')
                        ->whereColumn('purchase_stock_products.id', 'purchase_stock_product_field_values.purchase_stock_product_id')
                        ->where('purchase_stock_products.company_id', $companyId)
                        ->whereNull('purchase_stock_products.deleted_at');
                })
                ->get();

            if ($orphanedFieldValues->isNotEmpty()) {
                Log::warning('Found orphaned field values with no matching purchase stock products', [
                    'stock_transfer_id' => $stockTransfer->id,
                    'orphaned_field_value_ids' => $orphanedFieldValues->pluck('id')->toArray(),
                    'count' => $orphanedFieldValues->count(),
                    'details' => $orphanedFieldValues->map(function ($fv) {
                        return [
                            'id' => $fv->id,
                            'purchase_stock_product_id' => $fv->purchase_stock_product_id,
                            'product_field_id' => $fv->product_field_id,
                            'value' => $fv->value,
                        ];
                    })->toArray(),
                ]);
            }

            Log::info('Stock transfer reversal completed successfully', [
                'stock_transfer_id' => $stockTransfer->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Exception in reverseStockTransfer', [
                'stock_transfer_id' => $stockTransfer->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \Exception("Failed to reverse stock transfer ID {$stockTransfer->id}: {$e->getMessage()}");
        }
    }

    //     private function reverseStockTransfer(StockTransfer $stockTransfer)
// {
//     try {
//         Log::info('Reversing stock transfer for field values and purchase stock products', [
//             'stock_transfer_id' => $stockTransfer->id,
//             'source_branch_id' => $stockTransfer->branch_id,
//             'target_branch_id' => $stockTransfer->transfer_to,
//         ]);

    //         $companyId = $stockTransfer->company_id;
//         $sourceBranchId = $stockTransfer->branch_id;

    //         // Check existing PurchaseStockProduct records for the stock transfer
//         $psps = PurchaseStockProduct::where('stock_transfer_id', $stockTransfer->id)
//             ->where('company_id', $companyId)
//             ->withTrashed()
//             ->get();

    //         Log::debug('PurchaseStockProduct records found for stock transfer', [
//             'stock_transfer_id' => $stockTransfer->id,
//             'psp_count' => $psps->count(),
//             'psp_details' => $psps->map(function ($psp) {
//                 return [
//                     'id' => $psp->id,
//                     'product_id' => $psp->product_id,
//                     'branch_id' => $psp->branch_id,
//                     'deleted_at' => $psp->deleted_at ? $psp->deleted_at->toDateTimeString() : null,
//                     'quantity' => $psp->quantity,
//                     'free_quantity' => $psp->free_quantity,
//                     'has_field_values' => PurchaseStockProductFieldValue::where('purchase_stock_product_id', $psp->id)->exists(),
//                 ];
//             })->toArray(),
//         ]);

    //         // Update PurchaseStockProductFieldValue records to reset to source branch
//         $fieldValueUpdatedCount = PurchaseStockProductFieldValue::where('stock_transfer_id', $stockTransfer->id)
//             ->where('company_id', $companyId)
//             ->update([
//                 'branch_id' => $sourceBranchId,
//                 'stock_transfer_id' => null,
//             ]);

    //         Log::info('Stock transfer field values reset successfully', [
//             'stock_transfer_id' => $stockTransfer->id,
//             'field_value_updated_count' => $fieldValueUpdatedCount,
//         ]);

    //         if ($fieldValueUpdatedCount === 0) {
//             Log::warning('No field values found to reset for stock transfer', [
//                 'stock_transfer_id' => $stockTransfer->id,
//             ]);
//         } else {
//             $fieldValues = PurchaseStockProductFieldValue::where('stock_transfer_id', null)
//                 ->where('company_id', $companyId)
//                 ->where('branch_id', $sourceBranchId)
//                 ->get();

    //             Log::debug('Field values after reset', [
//                 'stock_transfer_id' => $stockTransfer->id,
//                 'field_value_count' => $fieldValues->count(),
//                 'field_value_details' => $fieldValues->map(function ($fv) {
//                     return [
//                         'id' => $fv->id,
//                         'purchase_stock_product_id' => $fv->purchase_stock_product_id,
//                         'product_field_id' => $fv->product_field_id,
//                         'value' => $fv->value,
//                         'quantity_index' => $fv->quantity_index,
//                     ];
//                 })->toArray(),
//             ]);
//         }

    //         // Update PurchaseStockProduct records to reset branch_id and stock_transfer_id
//         $pspUpdatedCount = PurchaseStockProduct::where('stock_transfer_id', $stockTransfer->id)
//             ->where('company_id', $companyId)
//             ->whereNull('deleted_at')
//             ->update([
//                 'branch_id' => $sourceBranchId,
//                 'stock_transfer_id' => null,
//             ]);

    //         Log::info('Purchase stock products reset to source branch successfully', [
//             'stock_transfer_id' => $stockTransfer->id,
//             'psp_updated_count' => $pspUpdatedCount,
//             'non_field_valued_psps' => $psps->filter(function ($psp) {
//                 return !PurchaseStockProductFieldValue::where('purchase_stock_product_id', $psp->id)->exists();
//             })->count(),
//         ]);

    //         if ($pspUpdatedCount === 0) {
//             Log::warning('No purchase stock products found to reset for stock transfer', [
//                 'stock_transfer_id' => $stockTransfer->id,
//             ]);

    //             // Check PSPs linked to field values
//             $fieldValues = PurchaseStockProductFieldValue::where('stock_transfer_id', null)
//                 ->where('company_id', $companyId)
//                 ->where('branch_id', $sourceBranchId)
//                 ->pluck('purchase_stock_product_id')
//                 ->unique()
//                 ->toArray();

    //             if (!empty($fieldValues)) {
//                 $pspsLinkedToFieldValues = PurchaseStockProduct::whereIn('id', $fieldValues)
//                     ->where('company_id', $companyId)
//                     ->withTrashed()
//                     ->get();

    //                 Log::debug('PurchaseStockProduct records linked to field values', [
//                     'stock_transfer_id' => $stockTransfer->id,
//                     'psp_ids' => $fieldValues,
//                     'count' => $pspsLinkedToFieldValues->count(),
//                     'details' => $pspsLinkedToFieldValues->map(function ($psp) {
//                         return [
//                             'id' => $psp->id,
//                             'product_id' => $psp->product_id,
//                             'branch_id' => $psp->branch_id,
//                             'stock_transfer_id' => $psp->stock_transfer_id,
//                             'deleted_at' => $psp->deleted_at ? $psp->deleted_at->toDateTimeString() : null,
//                         ];
//                     })->toArray(),
//                 ]);

    //                 // Restore soft-deleted PSPs linked to field values
//                 $restoredCount = PurchaseStockProduct::whereIn('id', $fieldValues)
//                     ->where('company_id', $companyId)
//                     ->onlyTrashed()
//                     ->update([
//                         'deleted_at' => null,
//                         'branch_id' => $sourceBranchId,
//                         'stock_transfer_id' => null,
//                     ]);

    //                 Log::info('Restored soft-deleted PurchaseStockProduct records linked to field values', [
//                     'stock_transfer_id' => $stockTransfer->id,
//                     'restored_count' => $restoredCount,
//                     'psp_ids' => $fieldValues,
//                 ]);
//             }

    //             // Check PSPs linked to stock transfer details (for non-field-valued products)
//             $stockTransferDetails = $stockTransfer->stockTransferDetails()->pluck('product_id')->toArray();
//             if (!empty($stockTransferDetails)) {
//                 $pspsFromDetails = PurchaseStockProduct::whereIn('product_id', $stockTransferDetails)
//                     ->where('company_id', $companyId)
//                     ->where('stock_transfer_id', $stockTransfer->id)
//                     ->withTrashed()
//                     ->get();

    //                 Log::debug('PurchaseStockProduct records linked to stock transfer details', [
//                     'stock_transfer_id' => $stockTransfer->id,
//                     'product_ids' => $stockTransferDetails,
//                     'count' => $pspsFromDetails->count(),
//                     'details' => $pspsFromDetails->map(function ($psp) {
//                         return [
//                             'id' => $psp->id,
//                             'product_id' => $psp->product_id,
//                             'branch_id' => $psp->branch_id,
//                             'stock_transfer_id' => $psp->stock_transfer_id,
//                             'deleted_at' => $psp->deleted_at ? $psp->deleted_at->toDateTimeString() : null,
//                             'has_field_values' => PurchaseStockProductFieldValue::where('purchase_stock_product_id', $psp->id)->exists(),
//                         ];
//                     })->toArray(),
//                 ]);
//             }
//         }

    //         // Check for orphaned field values
//         $orphanedFieldValues = PurchaseStockProductFieldValue::where('stock_transfer_id', null)
//             ->where('company_id', $companyId)
//             ->where('branch_id', $sourceBranchId)
//             ->whereNotExists(function ($query) use ($companyId) {
//                 $query->select(DB::raw(1))
//                     ->from('purchase_stock_products')
//                     ->whereColumn('purchase_stock_products.id', 'purchase_stock_product_field_values.purchase_stock_product_id')
//                     ->where('purchase_stock_products.company_id', $companyId)
//                     ->whereNull('purchase_stock_products.deleted_at');
//             })
//             ->get();

    //         if ($orphanedFieldValues->isNotEmpty()) {
//             Log::warning('Found orphaned field values with no matching purchase stock products', [
//                 'stock_transfer_id' => $stockTransfer->id,
//                 'orphaned_field_value_ids' => $orphanedFieldValues->pluck('id')->toArray(),
//                 'count' => $orphanedFieldValues->count(),
//                 'details' => $orphanedFieldValues->map(function ($fv) {
//                     return [
//                         'id' => $fv->id,
//                         'purchase_stock_product_id' => $fv->purchase_stock_product_id,
//                         'product_field_id' => $fv->product_field_id,
//                         'value' => $fv->value,
//                     ];
//                 })->toArray(),
//             ]);
//         }

    //         Log::info('Stock transfer reversal completed successfully', [
//             'stock_transfer_id' => $stockTransfer->id,
//         ]);
//     } catch (\Exception $e) {
//         Log::error('Exception in reverseStockTransfer', [
//             'stock_transfer_id' => $stockTransfer->id,
//             'message' => $e->getMessage(),
//             'trace' => $e->getTraceAsString(),
//         ]);
//         throw new \Exception("Failed to reverse stock transfer ID {$stockTransfer->id}: {$e->getMessage()}");
//     }
// }

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
            $item = StockTransfer::with('stockTransferDetails')->findOrFail($id);
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

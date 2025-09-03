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
                        ->where(fn($query) => $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at')),
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
                'product_details.*.field_values.*.*.product_field_id' => 'nullable|integer|exists:product_fields,id',
                'product_details.*.field_values.*.*.purchase_stock_product_field_value_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.purchase_product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_adjustment_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_reconciliation_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.value' => 'nullable|string|max:255',
                'product_details.*.field_values.*.*.quantity_index' => 'nullable|integer|min:0',
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

            $validated = $validator->validated();
            \Log::debug('Validated product details', [
                'product_details' => $validated['product_details'],
            ]);

            $item = DB::transaction(function () use ($validated) {
                $validated['branch_id'] = $validated['current_location'];
                $validated['accept_status'] = '0';
                $productDetails = $validated['product_details'];
                unset($validated['product_details']);

                // Create StockTransfer
                $item = StockTransfer::create($validated);

                // Fetch measure units
                $measureUnitsCalc = MeasureUnit::where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->get()
                    ->keyBy('id');

                $fieldValuesToCreate = [];

                foreach ($productDetails as $index => $detail) {
                    \Log::debug('Processing product detail', [
                        'index' => $index,
                        'detail' => $detail,
                    ]);

                    // Normalize field_values
                    $fieldValues = is_array($detail['field_values']) && isset($detail['field_values'][0]) && is_array($detail['field_values'][0]) && !isset($detail['field_values'][0]['product_field_id']) ? $detail['field_values'] : [$detail['field_values']];

                    // Calculate pieces for validation
                    $quantity =  $detail['quantity'];
                    $measureUnitId = $detail['measure_unit_id'];
                    $targetMeasureUnit = $measureUnitsCalc[$measureUnitId] ?? throw new \Exception("Measure unit ID {$measureUnitId} not found.");
                    $targetMeasureUnitQuantity = $targetMeasureUnit->quantity ?? 1;
                    $transferredPieces = $this->stockTransferService->calculatePieces($quantity, $targetMeasureUnitQuantity);

                    // Check if field_values are present
                    $hasFieldValues = !empty($fieldValues[0]);

                    if ($hasFieldValues) {
                        // Field-valued product logic (unchanged)
                        $fieldValuesByStockId = [];
                        foreach ($fieldValues as $fieldValueSet) {
                            $purchaseStockProductId = $fieldValueSet[0]['purchase_stock_product_id'] ?? null;
                            if ($purchaseStockProductId) {
                                $fieldValuesByStockId[$purchaseStockProductId][] = $fieldValueSet;
                            }
                        }

                        $totalPieces = count($fieldValues);
                        if ($totalPieces != $transferredPieces) {
                            \Log::error('Quantity mismatch', [
                                'product_id' => $detail['product_id'],
                                'index' => $index,
                                'transferred_pieces' => $transferredPieces,
                                'total_pieces' => $totalPieces,
                            ]);
                            throw new \Exception("Requested quantity ({$transferredPieces}) does not match provided field value pieces ({$totalPieces}) for product {$detail['product_name']} at index {$index}.");
                        }

                        foreach ($fieldValuesByStockId as $purchaseStockProductId => $fieldValueSets) {
                            $transferResult = $this->transferProduct(
                                array_merge($detail, ['field_values' => $fieldValueSets]),
                                $validated['company_id'],
                                $validated['current_location'],
                                $validated['transfer_to'],
                                $measureUnitsCalc,
                                $item->id,
                                $index,
                                count($fieldValueSets)
                            );

                            $detailData = [
                                'stock_transfer_id' => $item->id,
                                'company_id' => $validated['company_id'],
                                'product_id' => $detail['product_id'],
                                'product_name' => $detail['product_name'],
                                'product_code' => $detail['product_code'],
                                'expiry_date' => $detail['expiry_date'] ?? null,
                                'mfd' => $detail['mfd'] ?? null,
                                'discount_amount' => $detail['discount_amount'] ?? 0,
                                'discount_percent' => $detail['discount_percent'] ?? 0,
                                'quantity' => count($fieldValueSets) / $targetMeasureUnitQuantity,
                                'purchase_type' => $detail['purchase_type'],
                                'measure_unit_id' => $detail['measure_unit_id'],
                                'price' => $detail['price'],
                                'amount' => $detail['amount'],
                                'purchase_stock_product_id' => $transferResult['purchase_stock_product_id'] ?? null,
                                'stock_adjustment_id' => $transferResult['stock_adjustment_id'] ?? null,
                                'stock_reconciliation_id' => $transferResult['stock_reconciliation_id'] ?? null,
                                'purchase_product_id' => $transferResult['purchase_product_id'] ?? null,
                                'stock_product_id' => $transferResult['stock_product_id'] ?? null,
                                'branch_id' => $validated['current_location'],
                            ];

                            // Create stock transfer detail
                            $stockTransferDetail = $item->stockTransferDetails()->create($detailData);

                            // Build field values for this detail
                            foreach ($fieldValueSets as $fieldValueGroup) {
                                foreach ($fieldValueGroup as $fieldValue) {
                                    $fieldValuesToCreate[] = [
                                        'stock_transfer_id' => $item->id,
                                        'stock_transfer_details_id' => $stockTransferDetail->id,
                                        'company_id' => $validated['company_id'],
                                        'branch_id' => $validated['current_location'],
                                        'product_id' => $detail['product_id'],
                                        'product_field_id' => $fieldValue['product_field_id'] ?? null,
                                        'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                                        'purchase_stock_product_id' => $fieldValue['purchase_stock_product_id'] ?? null,
                                        'purchase_stock_product_field_value_id' => $fieldValue['purchase_stock_product_field_value_id'] ?? null,
                                        'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                                        'stock_adjustment_id' => $fieldValue['stock_adjustment_id'] ?? null,
                                        'stock_reconciliation_id' => $fieldValue['stock_reconciliation_id'] ?? null,
                                        'quantity_index' => $fieldValue['quantity_index'] ?? 0,
                                        'quantity_type' => $fieldValue['quantity_type'] ?? null,
                                        'value' => $fieldValue['value'] ?? null,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ];
                                }
                            }
                        }
                    } else {
                        // Non-field-valued product logic
                        \Log::info('Processing non-field-valued product detail', [
                            'index' => $index,
                            'product_id' => $detail['product_id'],
                            'quantity' => $quantity,
                            'transferred_pieces' => $transferredPieces,
                        ]);

                        $transferResult = $this->transferProduct(
                            $detail,
                            $validated['company_id'],
                            $validated['current_location'],
                            $validated['transfer_to'],
                            $measureUnitsCalc,
                            $item->id,
                            $index,
                            $transferredPieces
                        );

                        $detailData = [
                            'stock_transfer_id' => $item->id,
                            'company_id' => $validated['company_id'],
                            'product_id' => $detail['product_id'],
                            'product_name' => $detail['product_name'],
                            'product_code' => $detail['product_code'],
                            'expiry_date' => $detail['expiry_date'] ?? null,
                            'mfd' => $detail['mfd'] ?? null,
                            'discount_amount' => $detail['discount_amount'] ?? 0,
                            'discount_percent' => $detail['discount_percent'] ?? 0,
                            'quantity' => $quantity,
                            'purchase_type' => $detail['purchase_type'],
                            'measure_unit_id' => $detail['measure_unit_id'],
                            'price' => $detail['price'],
                            'amount' => $detail['amount'],
                            'purchase_stock_product_id' => $transferResult['purchase_stock_product_id'] ?? null,
                            'stock_adjustment_id' => $transferResult['stock_adjustment_id'] ?? null,
                            'stock_reconciliation_id' => $transferResult['stock_reconciliation_id'] ?? null,
                            'purchase_product_id' => $transferResult['purchase_product_id'] ?? null,
                            'stock_product_id' => $transferResult['stock_product_id'] ?? null,
                            'branch_id' => $validated['current_location'],
                        ];

                        // Create stock transfer detail
                        $item->stockTransferDetails()->create($detailData);
                    }
                }

                // Insert field values
                if (!empty($fieldValuesToCreate)) {
                    StockTransferFieldValue::insert($fieldValuesToCreate);
                }

                return $item;
            });

            return response()->json($item->load('stockTransferDetails.fieldValues'), 201);
        } catch (\Exception $e) {
            \Log::error('Exception in StockTransfer::store', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    private function transferProduct($detail, $companyId, $branchId, $targetBranchId, $measureUnitsCalc, $stockTransferId, $index, $piecesToTransfer)
    {
        \Log::info('Starting transferProduct', [
            'product_id' => $detail['product_id'],
            'index' => $index,
            'quantity' => $detail['quantity'],
            'company_id' => $companyId,
            'source_branch_id' => $branchId,
            'target_branch_id' => $targetBranchId,
            'measure_unit_id' => $detail['measure_unit_id'],
            'field_values' => $detail['field_values'] ?? [],
            'pieces_to_transfer' => $piecesToTransfer,
        ]);

        $productId = $detail['product_id'];
        $productName = $detail['product_name'];
        $measureUnitId = $detail['measure_unit_id'];

        // Fetch target measure unit
        \Log::debug('Fetching target measure unit', ['measure_unit_id' => $measureUnitId]);
        $targetMeasureUnit = $measureUnitsCalc[$measureUnitId] ?? throw new \Exception("Measure unit ID {$measureUnitId} not found.");
        $targetMeasureUnitQuantity = (float) ($targetMeasureUnit->quantity ?? 1);

        // Normalize field_values
        $fieldValues = $detail['field_values'] ?? [];
        if (!empty($fieldValues) && !is_array($fieldValues[0])) {
            $fieldValues = [$fieldValues];
            \Log::debug('Normalized field_values to array of arrays', [
                'index' => $index,
                'normalized_field_values' => $fieldValues,
            ]);
        }

        $hasFieldValues = !empty($fieldValues) && !empty($fieldValues[0]);
        \Log::debug('Processed field values', [
            'has_field_values' => $hasFieldValues,
            'field_values_raw' => $fieldValues,
        ]);

        $result = [
            'purchase_stock_product_id' => null,
            'stock_adjustment_id' => null,
            'stock_reconciliation_id' => null,
            'purchase_product_id' => null,
            'stock_product_id' => null,
        ];

        if ($hasFieldValues) {
            \Log::info('Processing field-valued product transfer', [
                'product_id' => $productId,
                'index' => $index,
                'pieces_to_transfer' => $piecesToTransfer,
            ]);

            // Get the purchase_stock_product_id from the first field value set
            $purchaseStockProductId = $fieldValues[0][0]['purchase_stock_product_id'] ?? null;
            if (!$purchaseStockProductId) {
                \Log::error('Missing purchase_stock_product_id in field value set', [
                    'field_value_set' => $fieldValues[0],
                    'index' => $index,
                ]);
                throw new \Exception("Missing purchase_stock_product_id in field_values for product {$productName} at index {$index}.");
            }

            $psp = PurchaseStockProduct::where('id', $purchaseStockProductId)
                ->where('product_id', $productId)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->first();

            if (!$psp) {
                \Log::error('PurchaseStockProduct not found', [
                    'purchase_stock_product_id' => $purchaseStockProductId,
                    'product_id' => $productId,
                    'index' => $index,
                    'product_name' => $productName,
                ]);
                throw new \Exception("PurchaseStockProduct ID {$purchaseStockProductId} not found for product {$productName} (ID: {$productId}) at index {$index}.");
            }

            // Validate field values
            foreach ($fieldValues as $fieldValueSet) {
                foreach ($fieldValueSet as $fieldValue) {
                    $fieldValueId = $fieldValue['purchase_stock_product_field_value_id'] ?? null;
                    if (!$fieldValueId) {
                        \Log::error('Missing purchase_stock_product_field_value_id', [
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
                        \Log::error('PurchaseStockProductFieldValue not found', [
                            'purchase_stock_product_field_value_id' => $fieldValueId,
                            'purchase_stock_product_id' => $purchaseStockProductId,
                            'index' => $index,
                        ]);
                        throw new \Exception("PurchaseStockProductFieldValue ID {$fieldValueId} not found for purchase_stock_product_id {$purchaseStockProductId} at index {$index}.");
                    }
                }
            }

            $pspMuQty = $measureUnitsCalc[$psp->measure_unit_id]->quantity ?? 1;
            $regularPieces = $this->stockTransferService->calculatePieces(max($psp->quantity, 0), $pspMuQty);
            $freePieces = $this->stockTransferService->calculatePieces(max($psp->free_quantity ?? 0, 0), $pspMuQty);
            $totalAvailable = $regularPieces + $freePieces;

            \Log::debug('Checking stock availability', [
                'purchase_stock_product_id' => $psp->id,
                'regular_pieces' => $regularPieces,
                'free_pieces' => $freePieces,
                'total_available' => $totalAvailable,
                'to_transfer' => $piecesToTransfer,
                'source_quantity' => $psp->quantity,
                'source_free_quantity' => $psp->free_quantity,
                'source_measure_unit_id' => $psp->measure_unit_id,
                'source_measure_unit_quantity' => $pspMuQty,
            ]);

            if ($piecesToTransfer > $totalAvailable) {
                \Log::error('Insufficient stock', [
                    'purchase_stock_product_id' => $psp->id,
                    'index' => $index,
                    'to_transfer' => $piecesToTransfer,
                    'total_available' => $totalAvailable,
                ]);
                throw new \Exception("Insufficient stock in purchase_stock_product_id {$psp->id} for product {$productName} at index {$index}. Requested: {$piecesToTransfer}, Available: {$totalAvailable}.");
            }

            $toReduceRegular = min($piecesToTransfer, $regularPieces);
            $toReduceFree = $piecesToTransfer - $toReduceRegular;

            if ($toReduceFree > $freePieces) {
                \Log::error('Insufficient free quantity', [
                    'purchase_stock_product_id' => $psp->id,
                    'index' => $index,
                    'to_reduce_free' => $toReduceFree,
                    'free_pieces' => $freePieces,
                ]);
                throw new \Exception("Insufficient free quantity in purchase_stock_product_id {$psp->id} for product {$productName} at index {$index}.");
            }

            \Log::info('Updating source purchase stock product', [
                'purchase_stock_product_id' => $psp->id,
                'regular_pieces' => $toReduceRegular,
                'free_pieces' => $toReduceFree,
            ]);
            $oldQuantity = $psp->quantity;
            $oldFreeQuantity = $psp->free_quantity ?? 0;

            $psp->quantity = $this->stockTransferService->calculatePiecestoReduce($oldQuantity, $toReduceRegular, $pspMuQty);
            $psp->free_quantity = $this->stockTransferService->calculatePiecestoReduce($oldFreeQuantity, $toReduceFree, $pspMuQty);
            \Log::debug('Before saving source purchase stock product', [
                'purchase_stock_product_id' => $psp->id,
                'old_quantity' => $oldQuantity,
                'new_quantity' => $psp->quantity,
                'old_free_quantity' => $oldFreeQuantity,
                'new_free_quantity' => $psp->free_quantity,
            ]);

            try {
                $saved = $psp->save();
                if (!$saved) {
                    \Log::error('Source purchase stock product save failed', [
                        'purchase_stock_product_id' => $psp->id,
                        'quantity' => $psp->quantity,
                        'free_quantity' => $psp->free_quantity,
                    ]);
                    throw new \Exception("Failed to save source purchase stock product ID {$psp->id} at index {$index}.");
                }
                \Log::info('Saved source purchase stock product', [
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
                \Log::error('Failed to save source purchase stock product', [
                    'purchase_stock_product_id' => $psp->id,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            $result = [
                'purchase_stock_product_id' => $purchaseStockProductId,
                'stock_adjustment_id' => $fieldValues[0][0]['stock_adjustment_id'] ?? null,
                'stock_reconciliation_id' => $fieldValues[0][0]['stock_reconciliation_id'] ?? null,
                'purchase_product_id' => $fieldValues[0][0]['purchase_product_id'] ?? null,
                'stock_product_id' => $fieldValues[0][0]['stock_product_id'] ?? null,
            ];
        } else {
            // Non-field-valued logic (unchanged)
            \Log::info('Processing non-field-valued product transfer', [
                'product_id' => $productId,
                'index' => $index,
            ]);

            $purchaseStockProducts = PurchaseStockProduct::where('product_id', $productId)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->whereDoesntHave('fieldValues')
                ->orderBy('created_at')
                ->get();

            \Log::debug('Fetched non-field-valued stock', [
                'product_id' => $productId,
                'index' => $index,
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
                    'product_name' => $productName,
                ]);
                throw new \Exception("No stock found for product {$productName} (ID: {$productId}) at index {$index}.");
            }

            $remainingPieces = $piecesToTransfer;
            $firstPspId = null;

            foreach ($purchaseStockProducts as $psp) {
                if ($remainingPieces <= 0) {
                    \Log::debug('No more pieces to transfer', [
                        'purchase_stock_product_id' => $psp->id,
                    ]);
                    break;
                }

                $pspMuQty = (float) ($measureUnitsCalc[$psp->measure_unit_id]->quantity ?? 1);
                $regularPieces = $this->stockTransferService->calculatePieces(max($psp->quantity, 0), $pspMuQty);
                $freePieces = $this->stockTransferService->calculatePieces(max($psp->free_quantity ?? 0, 0), $pspMuQty);
                $totalAvailable = $regularPieces + $freePieces;

                \Log::debug('Checking non-field-valued stock availability', [
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
                    \Log::error('Insufficient free quantity', [
                        'purchase_stock_product_id' => $psp->id,
                        'index' => $index,
                        'to_reduce_free' => $toReduceFree,
                        'free_pieces' => $freePieces,
                    ]);
                    throw new \Exception("Insufficient free quantity in purchase_stock_product_id {$psp->id} for product {$productName} at index {$index}.");
                }

                \Log::info('Updating source purchase stock product', [
                    'purchase_stock_product_id' => $psp->id,
                    'regular_pieces' => $toReduceRegular,
                    'free_pieces' => $toReduceFree,
                ]);
                $oldQuantity = $psp->quantity;
                $oldFreeQuantity = $psp->free_quantity ?? 0;

                $psp->quantity = $this->stockTransferService->calculatePiecestoReduce($oldQuantity, $toReduceRegular, $pspMuQty);
                $psp->free_quantity = $this->stockTransferService->calculatePiecestoReduce($oldFreeQuantity, $toReduceFree, $pspMuQty);
                \Log::debug('Before saving source purchase stock product', [
                    'purchase_stock_product_id' => $psp->id,
                    'old_quantity' => $oldQuantity,
                    'new_quantity' => $psp->quantity,
                    'old_free_quantity' => $oldFreeQuantity,
                    'new_free_quantity' => $psp->free_quantity,
                ]);

                try {
                    $saved = $psp->save();
                    if (!$saved) {
                        \Log::error('Source purchase stock product save failed', [
                            'purchase_stock_product_id' => $psp->id,
                            'quantity' => $psp->quantity,
                            'free_quantity' => $psp->free_quantity,
                        ]);
                        throw new \Exception("Failed to save source purchase stock product ID {$psp->id} at index {$index}.");
                    }
                    \Log::info('Saved source purchase stock product', [
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
                    \Log::error('Failed to save source purchase stock product', [
                        'purchase_stock_product_id' => $psp->id,
                        'error' => $e->getMessage(),
                    ]);
                    throw $e;
                }

                $remainingPieces -= $toTransfer;
                if ($firstPspId === null) {
                    $firstPspId = $psp->id;
                }
            }

            if ($remainingPieces > 0) {
                \Log::error('Insufficient stock for non-field-valued product', [
                    'product_id' => $productId,
                    'index' => $index,
                    'remaining_pieces' => $remainingPieces,
                ]);
                throw new \Exception("Insufficient stock for product {$productName} (ID: {$productId}) at index {$index}. Remaining pieces: {$remainingPieces}.");
            }

            $result = [
                'purchase_stock_product_id' => $firstPspId,
                'stock_adjustment_id' => null,
                'stock_reconciliation_id' => null,
                'purchase_product_id' => null,
                'stock_product_id' => null,
            ];
        }

        \Log::info('Completed transferProduct', [
            'product_id' => $productId,
            'index' => $index,
            'result' => $result,
        ]);

        return $result;
    }




   public function update(Request $request, $id): JsonResponse
{
    try {
        Log::info('Starting stock transfer update process', [
            'stock_transfer_id' => $id,
            'request_data' => $request->all(),
            'user_id' => auth()->id(),
        ]);

        $stockTransfer = StockTransfer::where('id', $id)
            ->where('company_id', $request->company_id)
            ->where('branch_id', $request->branch_id)
            ->whereNull('deleted_at')
            ->firstOrFail();

        Log::debug('Fetched StockTransfer', [
            'stock_transfer_id' => $id,
            'stock_transfer' => $stockTransfer->toArray(),
        ]);

        $validator = Validator::make($request->all(), [
            'reference_no' => [
                'required',
                'string',
                'max:255',
                Rule::unique('stock_transfers')
                    ->where(fn($query) => $query->where('company_id', $request->company_id)
                        ->whereNull('deleted_at'))
                    ->ignore($id),
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
            'product_details.*.id' => 'nullable|integer|exists:stock_transfer_details,id',
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
            'product_details.*.field_values.*.*.product_field_id' => 'nullable|integer|exists:product_fields,id',
            'product_details.*.field_values.*.*.purchase_stock_product_field_value_id' => 'nullable|numeric',
            'product_details.*.field_values.*.*.purchase_product_id' => 'nullable|numeric',
            'product_details.*.field_values.*.*.stock_product_id' => 'nullable|numeric',
            'product_details.*.field_values.*.*.product_id' => 'nullable|numeric',
            'product_details.*.field_values.*.*.stock_adjustment_id' => 'nullable|numeric',
            'product_details.*.field_values.*.*.stock_reconciliation_id' => 'nullable|numeric',
            'product_details.*.field_values.*.*.value' => 'nullable|string|max:255',
            'product_details.*.field_values.*.*.quantity_index' => 'nullable|integer|min:0',
            'product_details.*.field_values.*.*.quantity_type' => 'nullable|string|in:regular,free',
            'product_details.*.field_values.*.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',
            'company_id' => 'required|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            Log::warning('Validation failed for stock transfer update', [
                'stock_transfer_id' => $id,
                'errors' => $validator->errors()->toArray(),
                'request_data' => $request->all(),
            ]);
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();
        Log::debug('Validated product details for update', [
            'stock_transfer_id' => $id,
            'product_details' => $validated['product_details'],
        ]);

        $item = DB::transaction(function () use ($validated, $stockTransfer, $id) {
            $validated['branch_id'] = $validated['current_location'];
            $validated['accept_status'] = $stockTransfer->accept_status; // Preserve existing status
            $productDetails = $validated['product_details'];
            unset($validated['product_details']);

            // Fetch measure units
            $measureUnitsCalc = MeasureUnit::where('company_id', $validated['company_id'])
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            Log::debug('Fetched measure units', [
                'stock_transfer_id' => $id,
                'measure_units' => $measureUnitsCalc->toArray(),
            ]);

            // Reverse existing stock changes by grouping pieces per psp_id
            $existingDetails = StockTransferDetails::where('stock_transfer_id', $stockTransfer->id)->get();

            $pspPieces = [];

            foreach ($existingDetails as $index => $existingDetail) {
                $detail = [
                    'product_id' => $existingDetail->product_id,
                    'product_name' => $existingDetail->product_name,
                    'quantity' => $existingDetail->quantity,
                    'free_quantity' => $existingDetail->free_quantity ?? 0,
                    'measure_unit_id' => $existingDetail->measure_unit_id,
                    'purchase_stock_product_id' => $existingDetail->purchase_stock_product_id,
                ];
                Log::debug('Calculating pieces for existing detail', [
                    'stock_transfer_id' => $id,
                    'stock_transfer_details_id' => $existingDetail->id,
                    'index' => $index,
                    'detail' => $detail,
                ]);

                $measureUnitQuantity = $measureUnitsCalc[$detail['measure_unit_id']]->quantity ?? 1;
                $toRestoreRegular = $this->stockTransferService->calculatePieces($detail['quantity'], $measureUnitQuantity);
                $toRestoreFree = $this->stockTransferService->calculatePieces($detail['free_quantity'], $measureUnitQuantity);

                $pspId = $detail['purchase_stock_product_id'];
                if ($pspId) {
                    $pspPieces[$pspId]['regular'] = ($pspPieces[$pspId]['regular'] ?? 0) + $toRestoreRegular;
                    $pspPieces[$pspId]['free'] = ($pspPieces[$pspId]['free'] ?? 0) + $toRestoreFree;
                }
            }

            Log::debug('Grouped pieces for reversal', [
                'stock_transfer_id' => $id,
                'psp_pieces' => $pspPieces,
            ]);

            // Add back grouped pieces to each psp
            foreach ($pspPieces as $pspId => $pieces) {
                $psp = PurchaseStockProduct::find($pspId);
                if (!$psp) {
                    Log::warning('PurchaseStockProduct not found for reverse', [
                        'purchase_stock_product_id' => $pspId,
                        'stock_transfer_id' => $id,
                    ]);
                    continue;
                }

                Log::debug('Before reversing PurchaseStockProduct', [
                    'purchase_stock_product_id' => $pspId,
                    'current_quantity' => $psp->quantity,
                    'current_free_quantity' => $psp->free_quantity ?? 0,
                    'pieces_to_restore' => $pieces,
                ]);

                $pspMuQty = $measureUnitsCalc[$psp->measure_unit_id]->quantity ?? 1;
                list($regularQty, $freeQty) = $this->stockTransferService->convertToTargetMeasureUnit(
                    $pieces['regular'],
                    $pieces['free'],
                    $pspMuQty
                );

                $psp->quantity += $regularQty;
                $psp->free_quantity = ($psp->free_quantity ?? 0) + $freeQty;
                $psp->save();

                Log::info('Reversed stock for PurchaseStockProduct', [
                    'purchase_stock_product_id' => $pspId,
                    'new_quantity' => $psp->quantity,
                    'new_free_quantity' => $psp->free_quantity,
                    'stock_transfer_id' => $id,
                ]);
            }

            // Delete existing details and field values
            Log::info('Deleting existing stock transfer field values and details', [
                'stock_transfer_id' => $id,
            ]);
            StockTransferFieldValue::where('stock_transfer_id', $stockTransfer->id)->delete();
            StockTransferDetails::where('stock_transfer_id', $stockTransfer->id)->delete();

            // Update StockTransfer
            Log::debug('Updating StockTransfer', [
                'stock_transfer_id' => $id,
                'validated_data' => $validated,
            ]);
            $stockTransfer->update($validated);

            $fieldValuesToCreate = [];

            foreach ($productDetails as $index => $detail) {
                Log::debug('Processing product detail in update', [
                    'stock_transfer_id' => $id,
                    'index' => $index,
                    'detail' => $detail,
                ]);

                // Normalize field_values
                $fieldValues = is_array($detail['field_values']) && isset($detail['field_values'][0]) && is_array($detail['field_values'][0]) && !isset($detail['field_values'][0]['product_field_id']) ? $detail['field_values'] : [$detail['field_values']];

                // Calculate pieces for validation
                $quantity = $detail['quantity'];
                $measureUnitId = $detail['measure_unit_id'];
                $targetMeasureUnit = $measureUnitsCalc[$measureUnitId] ?? throw new \Exception("Measure unit ID {$measureUnitId} not found.");
                $targetMeasureUnitQuantity = $targetMeasureUnit->quantity ?? 1;
                $transferredPieces = $this->stockTransferService->calculatePieces($quantity, $targetMeasureUnitQuantity);

                Log::debug('Calculated transferred pieces', [
                    'stock_transfer_id' => $id,
                    'index' => $index,
                    'quantity' => $quantity,
                    'measure_unit_id' => $measureUnitId,
                    'target_measure_unit_quantity' => $targetMeasureUnitQuantity,
                    'transferred_pieces' => $transferredPieces,
                ]);

                // Check if field_values are present
                $hasFieldValues = !empty($fieldValues[0]);

                if ($hasFieldValues) {
                    // Field-valued product logic
                    $fieldValuesByStockId = [];
                    foreach ($fieldValues as $fieldValueSet) {
                        $purchaseStockProductId = $fieldValueSet[0]['purchase_stock_product_id'] ?? null;
                        if ($purchaseStockProductId) {
                            $fieldValuesByStockId[$purchaseStockProductId][] = $fieldValueSet;
                        } else {
                            Log::warning('Missing purchase_stock_product_id in field value set', [
                                'stock_transfer_id' => $id,
                                'index' => $index,
                                'field_value_set' => $fieldValueSet,
                            ]);
                            continue;
                        }
                    }

                    $totalPieces = count($fieldValues);
                    if ($totalPieces != $transferredPieces) {
                        Log::error('Quantity mismatch in update', [
                            'product_id' => $detail['product_id'],
                            'index' => $index,
                            'transferred_pieces' => $transferredPieces,
                            'total_pieces' => $totalPieces,
                        ]);
                        throw new \Exception("Requested quantity ({$transferredPieces}) does not match provided field value pieces ({$totalPieces}) for product {$detail['product_name']} at index {$index}.");
                    }

                    foreach ($fieldValuesByStockId as $purchaseStockProductId => $fieldValueSets) {
                        $transferResult = $this->transferProduct(
                            array_merge($detail, ['field_values' => $fieldValueSets]),
                            $validated['company_id'],
                            $validated['current_location'],
                            $validated['transfer_to'],
                            $measureUnitsCalc,
                            $stockTransfer->id,
                            $index,
                            count($fieldValueSets)
                        );

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
                            'quantity' => count($fieldValueSets) / $targetMeasureUnitQuantity,
                            'purchase_type' => $detail['purchase_type'],
                            'measure_unit_id' => $detail['measure_unit_id'],
                            'price' => $detail['price'],
                            'amount' => $detail['amount'],
                            'purchase_stock_product_id' => $transferResult['purchase_stock_product_id'] ?? null,
                            'stock_adjustment_id' => $transferResult['stock_adjustment_id'] ?? null,
                            'stock_reconciliation_id' => $transferResult['stock_reconciliation_id'] ?? null,
                            'purchase_product_id' => $transferResult['purchase_product_id'] ?? null,
                            'stock_product_id' => $transferResult['stock_product_id'] ?? null,
                            'branch_id' => $validated['current_location'],
                        ];

                        // Create or update stock transfer detail
                        $stockTransferDetail = isset($detail['id'])
                            ? StockTransferDetails::updateOrCreate(
                                ['id' => $detail['id'], 'stock_transfer_id' => $stockTransfer->id],
                                $detailData
                            )
                            : $stockTransfer->stockTransferDetails()->create($detailData);

                        Log::debug('Created/Updated StockTransferDetail', [
                            'stock_transfer_id' => $id,
                            'stock_transfer_details_id' => $stockTransferDetail->id,
                            'detail_data' => $detailData,
                        ]);

                        // Build field values for this detail
                        foreach ($fieldValueSets as $fieldValueGroup) {
                            foreach ($fieldValueGroup as $fieldValue) {
                                $fieldValuesToCreate[] = [
                                    'stock_transfer_id' => $stockTransfer->id,
                                    'stock_transfer_details_id' => $stockTransferDetail->id,
                                    'company_id' => $validated['company_id'],
                                    'branch_id' => $validated['current_location'],
                                    'product_id' => $detail['product_id'],
                                    'product_field_id' => $fieldValue['product_field_id'] ?? null,
                                    'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                                    'purchase_stock_product_id' => $fieldValue['purchase_stock_product_id'] ?? null,
                                    'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                                    'stock_adjustment_id' => $fieldValue['stock_adjustment_id'] ?? null,
                                    'stock_reconciliation_id' => $fieldValue['stock_reconciliation_id'] ?? null,
                                    'quantity_index' => $fieldValue['quantity_index'] ?? 0,
                                    'quantity_type' => $fieldValue['quantity_type'] ?? null,
                                    'value' => $fieldValue['value'] ?? null,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                            }
                        }
                    }
                } else {
                    // Non-field-valued product logic
                    Log::info('Processing non-field-valued product detail in update', [
                        'index' => $index,
                        'product_id' => $detail['product_id'],
                        'quantity' => $quantity,
                        'transferred_pieces' => $transferredPieces,
                    ]);

                    $transferResult = $this->transferProduct(
                        $detail,
                        $validated['company_id'],
                        $validated['current_location'],
                        $validated['transfer_to'],
                        $measureUnitsCalc,
                        $stockTransfer->id,
                        $index,
                        $transferredPieces
                    );

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
                        'quantity' => $quantity,
                        'purchase_type' => $detail['purchase_type'],
                        'measure_unit_id' => $detail['measure_unit_id'],
                        'price' => $detail['price'],
                        'amount' => $detail['amount'],
                        'purchase_stock_product_id' => $transferResult['purchase_stock_product_id'] ?? null,
                        'stock_adjustment_id' => $transferResult['stock_adjustment_id'] ?? null,
                        'stock_reconciliation_id' => $transferResult['stock_reconciliation_id'] ?? null,
                        'purchase_product_id' => $transferResult['purchase_product_id'] ?? null,
                        'stock_product_id' => $transferResult['stock_product_id'] ?? null,
                        'branch_id' => $validated['current_location'],
                    ];

                    // Create or update stock transfer detail
                    $stockTransferDetail = isset($detail['id'])
                        ? StockTransferDetails::updateOrCreate(
                            ['id' => $detail['id'], 'stock_transfer_id' => $stockTransfer->id],
                            $detailData
                        )
                        : $stockTransfer->stockTransferDetails()->create($detailData);

                    Log::debug('Created/Updated StockTransferDetail', [
                        'stock_transfer_id' => $id,
                        'stock_transfer_details_id' => $stockTransferDetail->id,
                        'detail_data' => $detailData,
                    ]);
                }
            }

            // Insert field values
            if (!empty($fieldValuesToCreate)) {
                Log::debug('Inserting stock transfer field values in update', [
                    'stock_transfer_id' => $id,
                    'field_values_to_create' => $fieldValuesToCreate,
                ]);
                StockTransferFieldValue::insert($fieldValuesToCreate);
            }

            return $stockTransfer;
        });

        Log::info('Completed stock transfer update', [
            'stock_transfer_id' => $id,
            'updated_stock_transfer' => $item->toArray(),
        ]);

        return response()->json($item->load('stockTransferDetails.fieldValues'), 200);
    } catch (\Exception $e) {
        Log::error('Exception in StockTransfer::update', [
            'stock_transfer_id' => $id,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json(['error' => $e->getMessage()], 500);
    }
}

    private function reverseStockTransfer($stockTransferId, $detail, $companyId, $branchId, $index)
    {
        Log::info('Starting reverseStockTransfer', [
            'stock_transfer_id' => $stockTransferId,
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'index' => $index,
        ]);

        $productId = $detail['product_id'];
        $productName = $detail['product_name'];
        $quantity = (float) ($detail['quantity'] ?? 0);
        $freeQuantity = (float) ($detail['free_quantity'] ?? 0);
        $measureUnitId = $detail['measure_unit_id'];
        $purchaseStockProductId = $detail['purchase_stock_product_id'];

        Log::debug('Input detail for reverse', [
            'product_id' => $productId,
            'product_name' => $productName,
            'quantity' => $quantity,
            'free_quantity' => $freeQuantity,
            'measure_unit_id' => $measureUnitId,
            'purchase_stock_product_id' => $purchaseStockProductId,
        ]);

        if (!$purchaseStockProductId) {
            Log::warning('No purchase_stock_product_id provided for reverse', [
                'stock_transfer_id' => $stockTransferId,
                'product_id' => $productId,
                'index' => $index,
            ]);
            return;
        }

        // Fetch the PurchaseStockProduct
        $psp = PurchaseStockProduct::where('id', $purchaseStockProductId)
            ->where('product_id', $productId)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->first();

        Log::debug('Fetched PurchaseStockProduct', [
            'purchase_stock_product_id' => $purchaseStockProductId,
            'psp' => $psp ? $psp->toArray() : 'null',
        ]);

        if (!$psp) {
            Log::warning('PurchaseStockProduct not found for reverse', [
                'purchase_stock_product_id' => $purchaseStockProductId,
                'product_id' => $productId,
                'stock_transfer_id' => $stockTransferId,
                'index' => $index,
            ]);
            return;
        }

        // Fetch measure unit for quantity calculations
        $measureUnit = MeasureUnit::where('id', $measureUnitId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();
        $measureUnitQuantity = $measureUnit ? (float) ($measureUnit->quantity ?? 1) : 1;

        Log::debug('Fetched measureUnit', [
            'measure_unit_id' => $measureUnitId,
            'measureUnit' => $measureUnit ? $measureUnit->toArray() : 'null',
            'measureUnitQuantity' => $measureUnitQuantity,
        ]);

        // Calculate pieces to restore
        $toRestoreRegular = $this->stockTransferService->calculatePieces($quantity, $measureUnitQuantity);
        $toRestoreFree = $this->stockTransferService->calculatePieces($freeQuantity, $measureUnitQuantity);

        Log::debug('Calculated pieces to restore', [
            'toRestoreRegular' => $toRestoreRegular,
            'toRestoreFree' => $toRestoreFree,
            'quantity' => $quantity,
            'free_quantity' => $freeQuantity,
            'measure_unit_quantity' => $measureUnitQuantity,
        ]);

        // Get the measure unit quantity for the PurchaseStockProduct
        $pspMuQty = MeasureUnit::find($psp->measure_unit_id)->quantity ?? 1;

        Log::debug('Fetched PurchaseStockProduct measure unit', [
            'purchase_stock_product_id' => $psp->id,
            'psp_measure_unit_id' => $psp->measure_unit_id,
            'pspMuQty' => $pspMuQty,
        ]);

        Log::info('Reversing stock for PurchaseStockProduct', [
            'purchase_stock_product_id' => $psp->id,
            'current_quantity' => $psp->quantity,
            'current_free_quantity' => $psp->free_quantity ?? 0,
            'to_restore_regular' => $toRestoreRegular,
            'to_restore_free' => $toRestoreFree,
            'measure_unit_quantity' => $pspMuQty,
        ]);

        // Convert pieces to quantities in the PurchaseStockProduct's measure unit
        list($regularQty, $freeQty) = $this->stockTransferService->convertToTargetMeasureUnit(
            $toRestoreRegular,
            $toRestoreFree,
            $pspMuQty
        );

        Log::debug('Converted pieces to quantities', [
            'purchase_stock_product_id' => $psp->id,
            'regularQty' => $regularQty,
            'freeQty' => $freeQty,
            'toRestoreRegular' => $toRestoreRegular,
            'toRestoreFree' => $toRestoreFree,
            'pspMuQty' => $pspMuQty,
        ]);

        // Update PurchaseStockProduct quantities
        $oldQuantity = $psp->quantity;
        $oldFreeQuantity = $psp->free_quantity ?? 0;
        $psp->quantity += $regularQty;
        $psp->free_quantity = ($psp->free_quantity ?? 0) + $freeQty;

        Log::debug('Updated PurchaseStockProduct quantities before save', [
            'purchase_stock_product_id' => $psp->id,
            'old_quantity' => $oldQuantity,
            'new_quantity' => $psp->quantity,
            'old_free_quantity' => $oldFreeQuantity,
            'new_free_quantity' => $psp->free_quantity,
        ]);

        try {
            $saved = $psp->save();
            if (!$saved) {
                Log::error('Failed to save PurchaseStockProduct during reverse', [
                    'purchase_stock_product_id' => $psp->id,
                    'quantity' => $psp->quantity,
                    'free_quantity' => $psp->free_quantity,
                    'stock_transfer_id' => $stockTransferId,
                ]);
                throw new \Exception("Failed to save PurchaseStockProduct ID {$psp->id} during reverse at index {$index}.");
            }
            Log::info('Reversed stock for PurchaseStockProduct', [
                'purchase_stock_product_id' => $psp->id,
                'new_quantity' => $psp->quantity,
                'new_free_quantity' => $psp->free_quantity,
                'stock_transfer_id' => $stockTransferId,
            ]);
        } catch (\Exception $e) {
            Log::error('Exception during stock reversal', [
                'purchase_stock_product_id' => $psp->id,
                'stock_transfer_id' => $stockTransferId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        Log::info('Completed reverseStockTransfer', [
            'stock_transfer_id' => $stockTransferId,
            'index' => $index,
        ]);
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
            $item = StockTransfer::with('stockTransferDetails.fieldValues')->findOrFail($id);
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

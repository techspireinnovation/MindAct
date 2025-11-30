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
          
            return response()->json(['error' => 'Product not found'], 404);
        } catch (QueryException $e) {
         
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
          
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
          
            return response()->json(['error' => 'Product not found'], 404);
        } catch (QueryException $e) {

           
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
          
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
                'product_details.*.quantity' => 'required|numeric|min:0',
                'product_details.*.free_quantity' => 'nullable|numeric|min:0',
                'product_details.*.purchase_type' => 'required|string',
                'product_details.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'product_details.*.price' => 'required|numeric|min:0',
                'product_details.*.amount' => 'required|numeric|min:0',
                'product_details.*.field_values' => 'present|array',
                'product_details.*.field_values.*' => 'array|min:1',
                'product_details.*.field_values.*.*.product_field_id' => 'required_if:product_details.*.field_values,array|integer|exists:product_fields,id',
                'product_details.*.field_values.*.*.purchase_stock_product_field_value_id' => 'required_if:product_details.*.field_values,array|numeric',
                'product_details.*.field_values.*.*.purchase_product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_adjustment_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_reconciliation_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.value' => 'required_if:product_details.*.field_values,array|string|max:255',
                'product_details.*.field_values.*.*.quantity_index' => 'required_if:product_details.*.field_values,array|integer|min:0',
                'product_details.*.field_values.*.*.quantity_type' => 'required_if:product_details.*.field_values,array|string|in:regular,free',
                'product_details.*.field_values.*.*.purchase_stock_product_id' => 'required_if:product_details.*.field_values,array|integer|exists:purchase_stock_products,id',
                'company_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
           

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
                 

                    // Calculate pieces for validation
                    $regularQuantity = $detail['quantity'] ?? 0;
                    $freeQuantity = $detail['free_quantity'] ?? 0;
                    $measureUnitId = $detail['measure_unit_id'];
                    $targetMeasureUnit = $measureUnitsCalc[$measureUnitId] ?? throw new \Exception("Measure unit ID {$measureUnitId} not found.");
                    $targetMeasureUnitQuantity = $targetMeasureUnit->quantity ?? 1;
                    $regularPieces = $this->stockTransferService->calculatePieces($regularQuantity, $targetMeasureUnitQuantity);
                    $freePieces = $this->stockTransferService->calculatePieces($freeQuantity, $targetMeasureUnitQuantity);
                    $totalPieces = $regularPieces + $freePieces;

                    // Flatten field values
                    $fieldValuesFlat = $this->stockTransferService->flattenFieldValues($detail['field_values'], $index);

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
                                        'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                        'stock_product_id' => $fv['stock_product_id'] ?? null,
                                        'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                                        'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                        'purchase_stock_product_field_value_id' => $fv['purchase_stock_product_field_value_id'] ?? null,
                                    ];
                                })->unique(function ($fv) {
                                    return "{$fv['product_field_id']}:{$fv['value']}:{$fv['quantity_type']}";
                                })->values()->toArray();
                            })->toArray();
                        })->toArray();

                    $hasFieldValues = !empty($fieldValuesFlat);
                    $purchaseProductIds = array_keys($groupedFieldValues);
                    $requiresFieldValues = !empty($purchaseProductIds) && DB::table('purchase_stock_product_field_values')
                        ->whereIn('purchase_stock_product_id', $purchaseProductIds)
                        ->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['current_location'])
                        ->whereNull('deleted_at')
                        ->exists();

                    if ($hasFieldValues) {
                        // Count regular and free field value sets
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

                      

                        if (!$requiresFieldValues) {
                            throw new \Exception("Field values provided for product ID {$detail['product_id']} at index {$index}, but none required.");
                        }
                        if ($regularFieldValueSets != $regularPieces || $freeFieldValueSets != $freePieces) {
                            throw new \Exception("Field value sets (Regular: {$regularFieldValueSets}, Free: {$freeFieldValueSets}) must match pieces (Regular: {$regularPieces}, Free: {$freePieces}) at index {$index}.");
                        }

                        foreach ($groupedFieldValues as $purchaseStockProductId => $fvByIndex) {
                            $regularFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'regular')->toArray();
                            $freeFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'free')->toArray();

                            $requestedRegularPieces = count($regularFvByIndex);
                            $requestedFreePieces = count($freeFvByIndex);

                            // Convert field value counts to target measure unit quantities
                            [$allocateRegularQuantity, $allocateFreeQuantity] = $this->stockTransferService->convertToTargetMeasureUnit($requestedRegularPieces, $requestedFreePieces, $targetMeasureUnitQuantity);

                          

                            $transferResult = $this->transferProduct(
                                array_merge($detail, [
                                    'field_values' => array_merge(array_values($regularFvByIndex), array_values($freeFvByIndex)),
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                ]),
                                $validated['company_id'],
                                $validated['current_location'],
                                $validated['transfer_to'],
                                $measureUnitsCalc,
                                $item->id,
                                $index,
                                $requestedRegularPieces + $requestedFreePieces
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
                                'quantity' => $allocateRegularQuantity,
                                'free_quantity' => $allocateFreeQuantity,
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
                            foreach (array_merge($regularFvByIndex, $freeFvByIndex) as $fieldValueGroup) {
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
                                        'quantity_type' => $fieldValue['quantity_type'] ?? 'regular',
                                        'value' => $fieldValue['value'] ?? null,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ];
                                }
                            }
                        }
                    } else {
                        // Non-field-valued product logic (unchanged)
                       

                        $transferResult = $this->transferProduct(
                            array_merge($detail, ['quantity' => $regularQuantity, 'free_quantity' => $freeQuantity]),
                            $validated['company_id'],
                            $validated['current_location'],
                            $validated['transfer_to'],
                            $measureUnitsCalc,
                            $item->id,
                            $index,
                            $totalPieces
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
                            'quantity' => $regularQuantity,
                            'free_quantity' => $freeQuantity,
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
            
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function transferProduct($detail, $companyId, $branchId, $targetBranchId, $measureUnitsCalc, $stockTransferId, $index, $piecesToTransfer)
    {
        

        $productId = $detail['product_id'];
        $productName = $detail['product_name'];
        $measureUnitId = $detail['measure_unit_id'];
        $regularQuantity = $detail['quantity'] ?? 0;
        $freeQuantity = $detail['free_quantity'] ?? 0;

        // Fetch target measure unit
       
        $targetMeasureUnit = $measureUnitsCalc[$measureUnitId] ?? throw new \Exception("Measure unit ID {$measureUnitId} not found.");
        $targetMeasureUnitQuantity = (float) ($targetMeasureUnit->quantity ?? 1);

        // Calculate pieces
        $regularPieces = $this->stockTransferService->calculatePieces($regularQuantity, $targetMeasureUnitQuantity);
        $freePieces = $this->stockTransferService->calculatePieces($freeQuantity, $targetMeasureUnitQuantity);
        $totalPieces = $regularPieces + $freePieces;

        if ($totalPieces != $piecesToTransfer) {
           
            throw new \Exception("Total pieces to transfer ({$piecesToTransfer}) does not match calculated pieces (Regular: {$regularPieces}, Free: {$freePieces}) for product {$productName} at index {$index}.");
        }

        // Normalize field_values
        $fieldValues = $detail['field_values'] ?? [];
        if (!empty($fieldValues) && !is_array($fieldValues[0])) {
            $fieldValues = [$fieldValues];
           
        }

        $hasFieldValues = !empty($fieldValues) && !empty($fieldValues[0]);
       

        $result = [
            'purchase_stock_product_id' => null,
            'stock_adjustment_id' => null,
            'stock_reconciliation_id' => null,
            'purchase_product_id' => null,
            'stock_product_id' => null,
        ];

        if ($hasFieldValues) {
            

            // Group field values by purchase_stock_product_id
            $fieldValuesByStockId = [];
            foreach ($fieldValues as $fieldValueSet) {
                $purchaseStockProductId = $fieldValueSet[0]['purchase_stock_product_id'] ?? null;
                if ($purchaseStockProductId) {
                    $fieldValuesByStockId[$purchaseStockProductId][] = $fieldValueSet;
                }
            }

            $firstPspId = null;
            foreach ($fieldValuesByStockId as $purchaseStockProductId => $fieldValueSets) {
                $regularFieldValueSets = collect($fieldValueSets)->filter(fn($fvSet) => ($fvSet[0]['quantity_type'] ?? 'regular') === 'regular')->toArray();
                $freeFieldValueSets = collect($fieldValueSets)->filter(fn($fvSet) => ($fvSet[0]['quantity_type'] ?? 'regular') === 'free')->toArray();

                $regularPiecesForStock = count($regularFieldValueSets);
                $freePiecesForStock = count($freeFieldValueSets);
                $totalPiecesForStock = $regularPiecesForStock + $freePiecesForStock;

                if ($totalPiecesForStock == 0) {
                   
                    continue;
                }

                $psp = PurchaseStockProduct::where('id', $purchaseStockProductId)
                    ->where('product_id', $productId)
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$psp) {
                    
                    throw new \Exception("PurchaseStockProduct ID {$purchaseStockProductId} not found for product {$productName} (ID: {$productId}) at index {$index}.");
                }

                // Validate field values
                $existingFieldValues = $psp->fieldValues->groupBy('quantity_index')->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());
                foreach ($fieldValueSets as $fieldValueSet) {
                    $quantityIndex = $fieldValueSet[0]['quantity_index'] ?? 0;
                    foreach ($fieldValueSet as $fieldValue) {
                        $fieldValueId = $fieldValue['purchase_stock_product_field_value_id'] ?? null;
                        if (!$fieldValueId) {
                            
                            throw new \Exception("Missing purchase_stock_product_field_value_id for purchase_stock_product_id {$purchaseStockProductId} at index {$index}.");
                        }

                        $fieldValueRecord = PurchaseStockProductFieldValue::where('id', $fieldValueId)
                            ->where('purchase_stock_product_id', $purchaseStockProductId)
                            ->where('company_id', $companyId)
                            ->where('branch_id', $branchId)
                            ->whereNull('deleted_at')
                            ->first();

                        if (!$fieldValueRecord) {
                           
                            throw new \Exception("PurchaseStockProductFieldValue ID {$fieldValueId} not found for purchase_stock_product_id {$purchaseStockProductId} at index {$index}.");
                        }

                        // Validate field value set against existing
                        if (!isset($existingFieldValues[$quantityIndex]) || collect($fieldValueSet)->pluck('value', 'product_field_id')->toArray() != $existingFieldValues[$quantityIndex]) {
                          
                            throw new \Exception("Field values for quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseStockProductId} do not match at index {$index}.");
                        }
                    }
                }

                $pspMuQty = $measureUnitsCalc[$psp->measure_unit_id]->quantity ?? 1;
                $availableRegularPieces = $this->stockTransferService->calculatePieces(max($psp->quantity, 0), $pspMuQty);
                $availableFreePieces = $this->stockTransferService->calculatePieces(max($psp->free_quantity ?? 0, 0), $pspMuQty);
                $totalAvailable = $availableRegularPieces + $availableFreePieces;

               

                if ($regularPiecesForStock > $availableRegularPieces) {
                  
                    throw new \Exception("Insufficient regular stock in purchase_stock_product_id {$psp->id} for product {$productName} at index {$index}. Requested: {$regularPiecesForStock}, Available: {$availableRegularPieces}.");
                }

                if ($freePiecesForStock > $availableFreePieces) {
                 
                    throw new \Exception("Insufficient free stock in purchase_stock_product_id {$psp->id} for product {$productName} at index {$index}. Requested: {$freePiecesForStock}, Available: {$availableFreePieces}.");
                }

              
                $oldQuantity = $psp->quantity;
                $oldFreeQuantity = $psp->free_quantity ?? 0;

                $psp->quantity = $this->stockTransferService->calculatePiecestoReduce($oldQuantity, $regularPiecesForStock, $pspMuQty);
                $psp->free_quantity = $this->stockTransferService->calculatePiecestoReduce($oldFreeQuantity, $freePiecesForStock, $pspMuQty);
              

                try {
                    $saved = $psp->save();
                    if (!$saved) {
                      
                        throw new \Exception("Failed to save source purchase stock product ID {$psp->id} at index {$index}.");
                    }
                  
                    $updatedPsp = PurchaseStockProduct::find($psp->id);
                    if (!$updatedPsp) {
                        
                        throw new \Exception("Source purchase stock product ID {$psp->id} not found after save at index {$index}.");
                    }
                    
                } catch (\Exception $e) {
                   
                    throw $e;
                }

                if ($firstPspId === null) {
                    $firstPspId = $purchaseStockProductId;
                    $result = [
                        'purchase_stock_product_id' => $purchaseStockProductId,
                        'stock_adjustment_id' => $fieldValueSets[0][0]['stock_adjustment_id'] ?? null,
                        'stock_reconciliation_id' => $fieldValueSets[0][0]['stock_reconciliation_id'] ?? null,
                        'purchase_product_id' => $fieldValueSets[0][0]['purchase_product_id'] ?? null,
                        'stock_product_id' => $fieldValueSets[0][0]['stock_product_id'] ?? null,
                    ];
                }
            }
        } else {
            // Non-field-valued product logic (unchanged)
          

            $purchaseStockProducts = PurchaseStockProduct::where('product_id', $productId)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->whereDoesntHave('fieldValues')
                ->orderBy('created_at')
                ->get();

            

            if ($purchaseStockProducts->isEmpty()) {
              
                throw new \Exception("No stock found for product {$productName} (ID: {$productId}) at index {$index}.");
            }

            $remainingRegularPieces = $regularPieces;
            $remainingFreePieces = $freePieces;
            $firstPspId = null;

            foreach ($purchaseStockProducts as $psp) {
                if ($remainingRegularPieces <= 0 && $remainingFreePieces <= 0) {
                   
                    break;
                }

                $pspMuQty = (float) ($measureUnitsCalc[$psp->measure_unit_id]->quantity ?? 1);
                $availableRegularPieces = $this->stockTransferService->calculatePieces(max($psp->quantity, 0), $pspMuQty);
                $availableFreePieces = $this->stockTransferService->calculatePieces(max($psp->free_quantity ?? 0, 0), $pspMuQty);
                $totalAvailable = $availableRegularPieces + $availableFreePieces;

             

                if ($totalAvailable <= 0) {
                   
                    continue;
                }

                $toReduceRegular = min($remainingRegularPieces, $availableRegularPieces);
                $toReduceFree = min($remainingFreePieces, $availableFreePieces);

             
                $oldQuantity = $psp->quantity;
                $oldFreeQuantity = $psp->free_quantity ?? 0;

                $psp->quantity = $this->stockTransferService->calculatePiecestoReduce($oldQuantity, $toReduceRegular, $pspMuQty);
                $psp->free_quantity = $this->stockTransferService->calculatePiecestoReduce($oldFreeQuantity, $toReduceFree, $pspMuQty);
             

                try {
                    $saved = $psp->save();
                    if (!$saved) {
                       
                        throw new \Exception("Failed to save source purchase stock product ID {$psp->id} at index {$index}.");
                    }
                    
                    $updatedPsp = PurchaseStockProduct::find($psp->id);
                    if (!$updatedPsp) {
                        
                        throw new \Exception("Source purchase stock product ID {$psp->id} not found after save at index {$index}.");
                    }
                    
                } catch (\Exception $e) {
                    
                    throw $e;
                }

                $remainingRegularPieces -= $toReduceRegular;
                $remainingFreePieces -= $toReduceFree;
                if ($firstPspId === null) {
                    $firstPspId = $psp->id;
                }
            }

            if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
              
                throw new \Exception("Insufficient stock for product {$productName} (ID: {$productId}) at index {$index}. Remaining: Regular {$remainingRegularPieces}, Free {$remainingFreePieces}.");
            }

            $result = [
                'purchase_stock_product_id' => $firstPspId,
                'stock_adjustment_id' => null,
                'stock_reconciliation_id' => null,
                'purchase_product_id' => null,
                'stock_product_id' => null,
            ];
        }

      

        return $result;
    }





    public function update(Request $request, $id): JsonResponse
    {
        try {
          

            $stockTransfer = StockTransfer::where('id', $id)
                ->where('company_id', $request->company_id)
                ->where('branch_id', $request->branch_id)
                ->whereNull('deleted_at')
                ->firstOrFail();

          

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
                'product_details.*.quantity' => 'required|numeric|min:0',
                'product_details.*.free_quantity' => 'nullable|numeric|min:0',
                'product_details.*.purchase_type' => 'required|string',
                'product_details.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'product_details.*.price' => 'required|numeric|min:0',
                'product_details.*.amount' => 'required|numeric|min:0',
                'product_details.*.field_values' => 'present|array',
                'product_details.*.field_values.*' => 'array|min:1',
                'product_details.*.field_values.*.*.product_field_id' => 'required_if:product_details.*.field_values,array|integer|exists:product_fields,id',
                'product_details.*.field_values.*.*.purchase_stock_product_field_value_id' => 'required_if:product_details.*.field_values,array|numeric',
                'product_details.*.field_values.*.*.purchase_product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.product_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_adjustment_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.stock_reconciliation_id' => 'nullable|numeric',
                'product_details.*.field_values.*.*.value' => 'required_if:product_details.*.field_values,array|string|max:255',
                'product_details.*.field_values.*.*.quantity_index' => 'required_if:product_details.*.field_values,array|integer|min:0',
                'product_details.*.field_values.*.*.quantity_type' => 'required_if:product_details.*.field_values,array|string|in:regular,free',
                'product_details.*.field_values.*.*.purchase_stock_product_id' => 'required_if:product_details.*.field_values,array|integer|exists:purchase_stock_products,id',
                'company_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            

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

               ;

                // Reverse existing stock changes
                $existingDetails = $stockTransfer->stockTransferDetails()->get();
                foreach ($existingDetails as $existingDetail) {
                    $this->reverseStockTransfer($id, $existingDetail->toArray(), $validated['company_id'], $validated['branch_id'], $existingDetail->id);
                }

                // Delete existing details and field values
                
                StockTransferFieldValue::where('stock_transfer_id', $stockTransfer->id)->delete();
                StockTransferDetails::where('stock_transfer_id', $stockTransfer->id)->delete();

                // Update StockTransfer
                
                $stockTransfer->update($validated);

                $fieldValuesToCreate = [];

                foreach ($productDetails as $index => $detail) {
                  

                    // Calculate pieces for validation
                    $regularQuantity = $detail['quantity'] ?? 0;
                    $freeQuantity = $detail['free_quantity'] ?? 0;
                    $measureUnitId = $detail['measure_unit_id'];
                    $targetMeasureUnit = $measureUnitsCalc[$measureUnitId] ?? throw new \Exception("Measure unit ID {$measureUnitId} not found.");
                    $targetMeasureUnitQuantity = $targetMeasureUnit->quantity ?? 1;
                    $regularPieces = $this->stockTransferService->calculatePieces($regularQuantity, $targetMeasureUnitQuantity);
                    $freePieces = $this->stockTransferService->calculatePieces($freeQuantity, $targetMeasureUnitQuantity);
                    $totalPieces = $regularPieces + $freePieces;

                    // Flatten field values
                    $fieldValuesFlat = $this->stockTransferService->flattenFieldValues($detail['field_values'], $index);

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
                                        'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                        'stock_product_id' => $fv['stock_product_id'] ?? null,
                                        'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                                        'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                        'purchase_stock_product_field_value_id' => $fv['purchase_stock_product_field_value_id'] ?? null,
                                    ];
                                })->unique(function ($fv) {
                                    return "{$fv['product_field_id']}:{$fv['value']}:{$fv['quantity_type']}";
                                })->values()->toArray();
                            })->toArray();
                        })->toArray();

                    $hasFieldValues = !empty($fieldValuesFlat);
                    $purchaseProductIds = array_keys($groupedFieldValues);
                    $requiresFieldValues = !empty($purchaseProductIds) && DB::table('purchase_stock_product_field_values')
                        ->whereIn('purchase_stock_product_id', $purchaseProductIds)
                        ->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['current_location'])
                        ->whereNull('deleted_at')
                        ->exists();

                    if ($hasFieldValues) {
                        // Count regular and free field value sets
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

                    

                        if (!$requiresFieldValues) {
                            throw new \Exception("Field values provided for product ID {$detail['product_id']} at index {$index}, but none required.");
                        }
                        if ($regularFieldValueSets != $regularPieces || $freeFieldValueSets != $freePieces) {
                            throw new \Exception("Field value sets (Regular: {$regularFieldValueSets}, Free: {$freeFieldValueSets}) must match pieces (Regular: {$regularPieces}, Free: {$freePieces}) at index {$index}.");
                        }

                        foreach ($groupedFieldValues as $purchaseStockProductId => $fvByIndex) {
                            $regularFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'regular')->toArray();
                            $freeFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'free')->toArray();

                            $requestedRegularPieces = count($regularFvByIndex);
                            $requestedFreePieces = count($freeFvByIndex);

                            // Convert field value counts to target measure unit quantities
                            [$allocateRegularQuantity, $allocateFreeQuantity] = $this->stockTransferService->convertToTargetMeasureUnit($requestedRegularPieces, $requestedFreePieces, $targetMeasureUnitQuantity);

                          

                            $transferResult = $this->transferProduct(
                                array_merge($detail, [
                                    'field_values' => array_merge(array_values($regularFvByIndex), array_values($freeFvByIndex)),
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                ]),
                                $validated['company_id'],
                                $validated['current_location'],
                                $validated['transfer_to'],
                                $measureUnitsCalc,
                                $stockTransfer->id,
                                $index,
                                $requestedRegularPieces + $requestedFreePieces
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
                                'quantity' => $allocateRegularQuantity,
                                'free_quantity' => $allocateFreeQuantity,
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

                           

                            // Build field values for this detail
                            foreach (array_merge($regularFvByIndex, $freeFvByIndex) as $fieldValueGroup) {
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
                                        'purchase_stock_product_field_value_id' => $fieldValue['purchase_stock_product_field_value_id'] ?? null,
                                        'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                                        'stock_adjustment_id' => $fieldValue['stock_adjustment_id'] ?? null,
                                        'stock_reconciliation_id' => $fieldValue['stock_reconciliation_id'] ?? null,
                                        'quantity_index' => $fieldValue['quantity_index'] ?? 0,
                                        'quantity_type' => $fieldValue['quantity_type'] ?? 'regular',
                                        'value' => $fieldValue['value'] ?? null,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ];
                                }
                            }
                        }
                    } else {
                        // Non-field-valued product logic
                        

                        $transferResult = $this->transferProduct(
                            array_merge($detail, ['quantity' => $regularQuantity, 'free_quantity' => $freeQuantity]),
                            $validated['company_id'],
                            $validated['current_location'],
                            $validated['transfer_to'],
                            $measureUnitsCalc,
                            $stockTransfer->id,
                            $index,
                            $totalPieces
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
                            'quantity' => $regularQuantity,
                            'free_quantity' => $freeQuantity,
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

                       
                    }
                }

                // Insert field values
                if (!empty($fieldValuesToCreate)) {
                   
                    StockTransferFieldValue::insert($fieldValuesToCreate);
                }

                return $stockTransfer;
            });

           

            return response()->json($item->load('stockTransferDetails.fieldValues'), 200);
        } catch (\Exception $e) {
            
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function reverseStockTransfer($stockTransferId, $detail, $companyId, $branchId, $index)
    {
      

        $productId = $detail['product_id'];
        $productName = $detail['product_name'];
        $quantity = (float) ($detail['quantity'] ?? 0);
        $freeQuantity = (float) ($detail['free_quantity'] ?? 0);
        $measureUnitId = $detail['measure_unit_id'];
        $purchaseStockProductId = $detail['purchase_stock_product_id'];

       

        if (!$purchaseStockProductId) {
            
            return;
        }

        // Fetch the PurchaseStockProduct
        $psp = PurchaseStockProduct::where('id', $purchaseStockProductId)
            ->where('product_id', $productId)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->first();

       

        if (!$psp) {
           
            return;
        }

        // Fetch measure unit for quantity calculations
        $measureUnit = MeasureUnit::where('id', $measureUnitId)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->first();
        $measureUnitQuantity = $measureUnit ? (float) ($measureUnit->quantity ?? 1) : 1;

       

        // Calculate pieces to restore
        $toRestoreRegular = $this->stockTransferService->calculatePieces($quantity, $measureUnitQuantity);
        $toRestoreFree = $this->stockTransferService->calculatePieces($freeQuantity, $measureUnitQuantity);


       
        // Get the measure unit quantity for the PurchaseStockProduct
        $pspMuQty = MeasureUnit::find($psp->measure_unit_id)->quantity ?? 1;

       

      

        // Convert pieces to quantities in the PurchaseStockProduct's measure unit
        list($regularQty, $freeQty) = $this->stockTransferService->convertToTargetMeasureUnit(
            $toRestoreRegular,
            $toRestoreFree,
            $pspMuQty
        );

        

        // Update PurchaseStockProduct quantities
        $oldQuantity = $psp->quantity;
        $oldFreeQuantity = $psp->free_quantity ?? 0;
        $psp->quantity += $regularQty;
        $psp->free_quantity = ($psp->free_quantity ?? 0) + $freeQty;

     

        try {
            $saved = $psp->save();
            if (!$saved) {
              
                throw new \Exception("Failed to save PurchaseStockProduct ID {$psp->id} during reverse at index {$index}.");
            }
           
        } catch (\Exception $e) {
            
            throw $e;
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
            $item = StockTransfer::with('stockTransferDetails.fieldValues')->findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Transfer not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }



    public function acceptStockTransfer(Request $request, $stockTransferId): JsonResponse
    {
        try {
          
            $validator = Validator::make($request->all(), [
                'accept_status' => 'required|in:0,1',
               
            ]);

            if ($validator->fails()) {
               
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');

            if ($validated['accept_status'] !== 1) {
               
                return response()->json(['message' => 'Stock transfer not accepted, no changes made.'], 200);
            }

            $result = DB::transaction(function () use ($stockTransferId, $validated,$companyId, $branchId): array {
                // Fetch the stock transfer
                $stockTransfer = StockTransfer::with(['stockTransferDetails.fieldValues'])
                    ->where('id', $stockTransferId)
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->first();

                if (!$stockTransfer) {
                  
                    throw new \Exception("Stock transfer ID {$stockTransferId} not found.");
                }

                if ($stockTransfer->accept_status === '1') {
                   
                    throw new \Exception("Stock transfer ID {$stockTransferId} has already been accepted.");
                }

                // Validate transfer_to is a valid branch ID
                if (!$stockTransfer->transfer_to || !is_numeric($stockTransfer->transfer_to)) {
                   
                    throw new \Exception("Invalid or missing transfer_to in stock transfer ID {$stockTransferId}.");
                }

                $newPurchaseStockProducts = [];
                $newFieldValues = [];

                foreach ($stockTransfer->stockTransferDetails as $detail) {
                    // Copy all data from stock_transfer_details to purchase_stock_products
                    // Set branch_id to transfer_to, do not copy purchase_stock_product_id
                    $purchaseStockProductData = [
                        'stock_adjustment_id' => $detail->stock_adjustment_id,
                        'stock_reconciliation_id' => $detail->stock_reconciliation_id,
                        'branch_id' => $stockTransfer->transfer_to, // Set to transfer_to from stock_transfers
                        'mfd' => $detail->mfd,
                        'purchase_type' => $detail->purchase_type,
                        'purchase_product_id' => $detail->purchase_product_id,
                        'stock_product_id' => $detail->stock_product_id,
                        'purchase_id' => $detail->purchase_id,
                        'product_code' => $detail->product_code,
                        'expiry_date' => $detail->expiry_date,
                        'free_quantity' => $detail->free_quantity,
                        'discount_percent' => $detail->discount_percent,
                        'discount_amount' => $detail->discount_amount,
                        'is_vatable' => $detail->is_vatable ?? 0,
                        'measure_unit_id' => $detail->measure_unit_id,
                        'stock_transfer_id' => $detail->stock_transfer_id, // Keep the same as in stock_transfer_details
                        'company_id' => $detail->company_id,
                        'product_id' => $detail->product_id,
                        'product_name' => $detail->product_name,
                        'quantity' => $detail->quantity,
                        'unit' => $detail->unit,
                        'batch_no' => $detail->batch_no,
                        'price' => $detail->price,
                        'amount' => $detail->amount,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    // Create new PurchaseStockProduct (new id will be auto-generated)
                    $newPsp = PurchaseStockProduct::create($purchaseStockProductData);
                    $newPurchaseStockProducts[] = $newPsp;

                    // Handle field values if they exist - copy to purchase_stock_product_field_values
                    $fieldValues = $detail->fieldValues;
                    if ($fieldValues->isNotEmpty()) {
                       

                        foreach ($fieldValues as $fieldValue) {
                            $newFieldValues[] = [
                                // Do not copy purchase_stock_product_field_value_id, as it should be new
                                'stock_transfer_id' => $fieldValue->stock_transfer_id, // Keep the same
                              // Keep the same
                                'company_id' => $fieldValue->company_id,
                                'branch_id' => $stockTransfer->transfer_to, // Set to transfer_to from stock_transfers
                                'purchase_stock_product_id' => $newPsp->id, // Link to new PSP id
                                'purchase_product_id' => $fieldValue->purchase_product_id,
                                'stock_product_id' => $fieldValue->stock_product_id,
                                'stock_adjustment_id' => $fieldValue->stock_adjustment_id,
                                'stock_reconciliation_id' => $fieldValue->stock_reconciliation_id,
                                'product_field_id' => $fieldValue->product_field_id,
                                'quantity_index' => $fieldValue->quantity_index,
                                'quantity_type' => $fieldValue->quantity_type,
                                'product_id' => $fieldValue->product_id,
                                'value' => $fieldValue->value,
                                'created_at' => now(),
                                'updated_at' => now(),
                            ];
                        }
                    }
                }

                // Insert new field values in batch
                if (!empty($newFieldValues)) {
                    
                    PurchaseStockProductFieldValue::insert($newFieldValues);
                }

                // Update stock transfer accept_status
                $stockTransfer->accept_status = '1';
                $stockTransfer->save();

               

                return [
                    'stock_transfer' => $stockTransfer->fresh()->load('stockTransferDetails.fieldValues'),
                    'new_purchase_stock_products' => $newPurchaseStockProducts,
                ];
            });

            return response()->json([
                'message' => 'Stock transfer accepted and processed successfully.',
                'data' => $result['stock_transfer'],
            ], 200);
        } catch (\Exception $e) {
           
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function destroy($id): JsonResponse
    {
        try {
            $stockTransfer = StockTransfer::findOrFail($id);

            $usedIn = [];

            if ($stockTransfer->stockTransferDetailsUse()->exists()) {
                $usedIn[] = 'stock transfer details';
            }

            if (!empty($usedIn)) {
                return response()->json([
                    'error' => 'in_use',
                    'message' => 'Stock Transfer cannot be deleted because it is in use by: ' . implode(', ', $usedIn) . '.',
                    'used_in' => $usedIn
                ], 400);
            }

            $stockTransfer->delete();

            return response()->json([
                'success' => true,
                'message' => 'Stock Transfer deleted successfully!'
            ]);
        } catch (ModelNotFoundException $e) {
           
            return response()->json([
                'error' => 'not_found',
                'message' => 'Stock Transfer not found!'
            ], 404);
        } catch (QueryException $e) {
           
            return response()->json([
                'error' => 'query_error',
                'message' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
           
            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the Stock Transfer.'
            ], 500);
        }
    }


}

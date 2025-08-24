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
            dd($e->getMessage());
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
                'product_details.*.quantity' => 'required|numeric|min:0.01',
                'product_details.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                // 'product_details.*.batch_no' => 'required|string|max:255',
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
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $item = DB::transaction(function () use ($validated) {
                // Set branch_id to current_location (source branch)
                $validated['branch_id'] = $validated['current_location'];

                $productDetails = $validated['product_details'];
                unset($validated['product_details']);

                // Create the stock transfer record
                $item = StockTransfer::create($validated);

                // Fetch measure units for calculations
                $measureUnitsCalc = MeasureUnit::where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->get()
                    ->keyBy('id');

                // Process each product detail
                $details = [];
                foreach ($productDetails as $index => $detail) {
                    // Transfer product stock
                    $this->transferProduct($detail, $validated['company_id'], $validated['current_location'], $validated['transfer_to'], $measureUnitsCalc, $item->id, $index);

                    // Prepare stock transfer details
                    $detail['stock_transfer_id'] = $item->id;
                    $detail['company_id'] = $validated['company_id'];
                    unset($detail['field_values']); // Remove field_values from details
                    $details[] = $detail;
                }

                // Create stock transfer details
                $item->stockTransferDetails()->createMany($details);

                return $item;
            });

            return response()->json($item->load('stockTransferDetails'), 201);
        } catch (ModelNotFoundException $e) {
            \Log::error('ModelNotFoundException in StockTransfer::store: ' . $e->getMessage());
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            dd($e->getMessage());
            \Log::error('QueryException in StockTransfer::store: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Exception in StockTransfer::store: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    private function transferProduct($detail, $companyId, $branchId, $targetBranchId, $measureUnitsCalc, $stockTransferId, $index)
    {
        $productId = $detail['product_id'];
        $quantity = $detail['quantity'];
        $measureUnitId = $detail['measure_unit_id'];
        $price = $detail['price'];

        $measureUnit = MeasureUnit::where('id', $measureUnitId)->first();
        $measureUnitQuantity = $measureUnit->quantity ?? 1;

        $transferredPieces = $this->stockTransferService->calculatePieces($quantity, $measureUnitQuantity);

        $fieldValuesFlat = $this->stockTransferService->flattenFieldValues($detail['field_values'] ?? [], $index);
        $hasFieldValues = !empty($fieldValuesFlat);

        $allocations = [];

        if ($hasFieldValues) {
            // Handle field values logic (as previously implemented)
        } else {
            // Handle non-field-valued products with FIFO across multiple PurchaseStockProduct
            $purchaseStockProducts = PurchaseStockProduct::where('product_id', $productId)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->whereDoesntHave('fieldValues') // Only non-field-valued products
                ->orderBy('created_at') // FIFO
                ->get();

            if ($purchaseStockProducts->isEmpty()) {
                throw new \Exception("No stock found for product {$detail['product_name']} at index {$index}.");
            }

            $remainingPieces = $transferredPieces;

            foreach ($purchaseStockProducts as $psp) {
                if ($remainingPieces <= 0) {
                    break;
                }

                $regularPieces = $this->stockTransferService->calculatePieces($psp->quantity, $measureUnitQuantity);
                $freePieces = $this->stockTransferService->calculatePieces($psp->free_quantity ?? 0, $measureUnitQuantity);
                $totalAvailable = $regularPieces + $freePieces;

                if ($totalAvailable <= 0) {
                    continue;
                }

                $toTransfer = min($remainingPieces, $totalAvailable);
                $toReduceRegular = min($toTransfer, $regularPieces);
                $toReduceFree = $toTransfer - $toReduceRegular;

                if ($toReduceFree > $freePieces) {
                    throw new \Exception("Insufficient free quantity in purchase_stock_product_id {$psp->id} for product {$detail['product_name']} at index {$index}.");
                }

                $allocations[] = [
                    'purchase_stock_product_id' => $psp->id,
                    'regular_pieces' => $toReduceRegular,
                    'free_pieces' => $toReduceFree,
                    'field_values' => [],
                ];

                // Update source PurchaseStockProduct
                $psp->quantity -= $toReduceRegular / $measureUnitQuantity;
                $psp->free_quantity = ($psp->free_quantity ?? 0) - ($toReduceFree / $measureUnitQuantity);
                $psp->save();

                $remainingPieces -= $toTransfer;
            }

            if ($remainingPieces > 0) {
                throw new \Exception("Insufficient stock for product {$detail['product_name']} at index {$index}. Remaining pieces: {$remainingPieces}.");
            }
        }

        // Create new PurchaseStockProduct for target branch
        $newPsp = new PurchaseStockProduct([
            'branch_id' => $targetBranchId,
            'quantity' => $quantity,
            'free_quantity' => 0,
            'purchase_id' => null,
            'purchase_type' => 'inventory',
            'product_id' => $productId,
            'product_code' => 'code1212',
            'product_name' => $detail['product_name'],
            'company_id' => $companyId,
            'batch_no' => $detail['batch_no'] ?? null,
            'measure_unit_id' => $measureUnitId,
            'price' => $price,
        ]);
        $newPsp->save();

        // Transfer field values to new PurchaseStockProduct
        if ($hasFieldValues) {
            $newIndex = 1;
            foreach ($allocations as $allocation) {
                foreach ($allocation['field_values'] as $fvSet) {
                    foreach ($fvSet as $fv) {
                        PurchaseStockProductFieldValue::create([
                            'company_id' => $companyId,
                            'branch_id' => $targetBranchId,
                            'purchase_stock_product_id' => $newPsp->id,
                            'product_field_id' => $fv['product_field_id'],
                            'quantity_index' => $newIndex,
                            'product_id' => $productId,
                            'value' => $fv['value'],
                        ]);
                    }
                    $newIndex++;
                }
            }
        }
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

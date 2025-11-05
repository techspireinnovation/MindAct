<?php

namespace App\Http\Controllers;


use Pratiksh\Nepalidate\Services\NepaliDate;

use Anuzpandey\LaravelNepaliDate\LaravelNepaliDate;
use Pratiksh\Nepalidate\Services\EnglishDate;

use NepaliCalendar;
use App\Helpers\Helper;
use App\Models\MeasureUnit;
use App\Models\Product;
use App\Models\ProductList;
use App\Models\StockTransferFieldValue;
use App\Models\StockTransfer;
use App\Models\StockTransferDetails;
use App\Models\PurchaseStockProductReturn;
use App\Models\PurchaseStockProductReturnFieldValue;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseStockProduct;
use App\Models\PurchaseProductFieldValue;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\Sale;
use App\Models\SaleAdditional;
use App\Models\SaleProduct;
use App\Models\SalesReturnProduct;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SalesProductFieldValue;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

use Illuminate\Http\Request;

class PosStoreController extends SaleController
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer',
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
                'sale_products.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',
                'sale_products.*.purchase_product_id' => 'nullable',
                'sale_products.*.stock_product_id' => 'nullable',
                'sale_products.*.stock_reconciliation_id' => 'nullable',
                'sale_products.*.stock_adjustment_id' => 'nullable',
                'sale_products.*.stock_transfer_id' => 'nullable',
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
                'sale_products.*.field_values.*.*.purchase_stock_product_id' => 'required_if:field_values,array|integer|exists:purchase_stock_products,id',

                'sale_products.*.field_values.*.*.purchase_product_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_product_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_adjustment_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_reconciliation_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_transfer_id' => 'nullable',
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
            $validated['branch_id'] = $request->branch_id;

            Log::debug('Sale request validated', ['sale_products' => $validated['sale_products']]);



            $sale = DB::transaction(function () use ($validated) {
                $sale = Sale::create([
                    'company_id' => $validated['company_id'],
                    'branch_id' => $validated['branch_id'],
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
                    'round_off_amount' => $validated['round_off_amount'] ?? 0,
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
                        'branch_id' => $validated['branch_id'],
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
                                        'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
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
                        ->map(fn($fv) => "{$fv['purchase_stock_product_id']}:{$fv['quantity_index']}")
                        ->unique()
                        ->count();
                    $freeFieldValueSets = collect($fieldValuesFlat)
                        ->filter(fn($fv) => ($fv['quantity_type'] ?? 'regular') === 'free')
                        ->map(fn($fv) => "{$fv['purchase_stock_product_id']}:{$fv['quantity_index']}")
                        ->unique()
                        ->count();

                    $hasFieldValues = !empty($fieldValuesFlat);
                    $purchaseProductIds = array_keys($groupedFieldValues);
                    $requiresFieldValues = !empty($purchaseProductIds) && DB::table('purchase_stock_product_field_values')
                        ->whereIn('purchase_stock_product_id', $purchaseProductIds)
                        ->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['branch_id'])
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

                    $query = PurchaseStockProduct::where('product_id', $productId)
                        ->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['branch_id'])
                        ->whereNull('deleted_at')
                        ->with([

                            'purchaseStockProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id'])->with('measureUnit'),
                            'fieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id']),
                            'saleProducts' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id'])->with(['saleProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']), 'measureUnit'])
                        ]);

                    if ($hasFieldValues) {
                        $query->whereIn('id', $purchaseProductIds);
                    } elseif (isset($productData['purchase_stock_product_id'])) {
                        $query->where('id', $productData['purchase_stock_product_id']);
                    } else {
                        $query->whereNotExists(fn($subQuery) => $subQuery->select(DB::raw(1))
                            ->from('purchase_stock_product_field_values')
                            ->whereColumn('purchase_stock_product_id', 'purchase_stock_products.id')
                            ->where('company_id', $validated['company_id'])
                            ->where('branch_id', $validated['branch_id'])
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

                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $purchaseMeasureUnitQuantity, $validated['company_id'], $validated['branch_id']);

                            if ($totalAvailablePieces < 0) {
                                throw new \Exception("Negative stock for purchase_product_id {$purchaseProductId} at index {$index}.");
                            }

                            $existingFieldValues = $purchaseProduct->fieldValues->groupBy('quantity_index')->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());
                            $unavailableQuantityIndices = $this->getUnavailableQuantityIndices($purchaseProduct, $validated['company_id'], $validated['branch_id']);
                            $salesReturnedIndices = SaleReturnProductFieldValue::whereIn('sale_return_product_id', $purchaseProduct->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns->pluck('id')))
                                ->whereNull('deleted_at')
                                ->pluck('quantity_index')
                                ->toArray();
                            $unavailableQuantityIndices = array_diff($unavailableQuantityIndices, $salesReturnedIndices);

                            foreach ($fvByIndex as $quantityIndex => $fvSet) {
                                if (in_array($quantityIndex, $unavailableQuantityIndices) || !isset($existingFieldValues[$quantityIndex])) {
                                    throw new \Exception("Invalid quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseProductId} at index {$index}.");
                                }
                                if (in_array($quantityIndex, $usedQuantityIndexes[$purchaseProductId] ?? [])) {
                                    throw new \Exception("Duplicate quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseProductId} at index {$index}.");
                                }
                                if (collect($fvSet)->pluck('value', 'product_field_id')->toArray() != $existingFieldValues[$quantityIndex]) {
                                    Log::debug('Field value mismatch', [
                                        'index' => $index,
                                        'purchase_stock_product_id' => $purchaseProductId,
                                        'quantity_index' => $quantityIndex,
                                        'submitted' => collect($fvSet)->pluck('value', 'product_field_id')->toArray(),
                                        'existing' => $existingFieldValues[$quantityIndex]
                                    ]);
                                    throw new \Exception("Field values for quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseProductId} do not match at index {$index}.");
                                }
                                $usedQuantityIndexes[$purchaseProductId][] = $quantityIndex;
                            }

                            $regularFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'regular')->toArray();
                            $freeFvByIndex = collect($fvByIndex)->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'free')->toArray();

                            $requestedRegularPieces = count($regularFvByIndex);

                            $requestedFreePieces = count($freeFvByIndex);

                            $totalRequestedForThisProduct = $requestedRegularPieces + $requestedFreePieces;

                            if ($totalRequestedForThisProduct > $totalAvailablePieces) {
                                throw new \Exception("Insufficient stock for purchase_stock_product_id {$purchaseProductId} at index {$index}. Requested: {$totalRequestedForThisProduct}, Available: {$totalAvailablePieces}.");
                            }


                            [$allocateRegularQuantity, $allocateFreeQuantity] = $this->convertToTargetMeasureUnit($requestedRegularPieces, $requestedFreePieces, $targetMeasureUnitQuantity);

                            $allocations[] = [
                                'purchase_stock_product_id' => $purchaseProductId,
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
                                'purchase_stock_product_id' => $purchaseProductId,
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
                        static $globalStockAllocation = null;
                        if ($globalStockAllocation === null) {
                            $globalStockAllocation = collect();
                        }
                        $purchaseProduct = isset($productData['purchase_stock_product_id']) ? $purchaseProducts->firstWhere('id', $productData['purchase_stock_product_id']) : null;
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

                        // Initialize allocations for this product
                        $allocations = [];
                        $remainingRegularPieces = $regularPieces;
                        $remainingFreePieces = $freePieces;

                        $availablePurchaseProducts = $purchaseProducts->filter(function ($purchaseProduct) use ($globalStockAllocation, $validated, $measureUnitsCalc) {
                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;
                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $validated['company_id'], $validated['branch_id'], $measureUnitsCalc);

                            // Adjust available pieces based on previous allocations in this transaction
                            $allocatedPieces = $globalStockAllocation->get($purchaseProduct->id, 0);
                            $remainingPieces = $totalAvailablePieces - $allocatedPieces;

                            return $remainingPieces > 0;
                        })->sortBy('created_at'); // Ensure FIFO order

                        if ($availablePurchaseProducts->isEmpty()) {
                            throw new \Exception("No valid purchase products with available stock found for product ID {$productId} at index {$index}.");
                        }

                        foreach ($availablePurchaseProducts as $purchaseProduct) {
                            if ($remainingRegularPieces <= 0 && $remainingFreePieces <= 0) {
                                break;
                            }

                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $validated['company_id'], $validated['branch_id'], $measureUnitsCalc);
                            $allocatedPieces = $globalStockAllocation->get($purchaseProduct->id, 0);
                            $remainingAvailablePieces = $totalAvailablePieces - $allocatedPieces;

                            if ($remainingAvailablePieces <= 0) {
                                continue;
                            }

                            $totalRemainingPieces = $remainingRegularPieces + $remainingFreePieces;
                            $allocatePieces = min($totalRemainingPieces, $remainingAvailablePieces);
                            $allocateRegularPieces = min($remainingRegularPieces, $allocatePieces);
                            $allocateFreePieces = min($remainingFreePieces, $allocatePieces - $allocateRegularPieces);

                            if ($allocateRegularPieces > 0 || $allocateFreePieces > 0) {
                                [$allocateRegularQuantity, $allocateFreeQuantity] = $this->convertToTargetMeasureUnit($allocateRegularPieces, $allocateFreePieces, $targetMeasureUnitQuantity);

                                $allocations[] = [
                                    'purchase_stock_product_id' => $purchaseProduct->id,
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                    'field_values' => [],
                                    'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                    'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                                ];

                                // Update global stock allocation
                                $globalStockAllocation[$purchaseProduct->id] = ($globalStockAllocation->get($purchaseProduct->id, 0) + $allocateRegularPieces + $allocateFreePieces);

                                $remainingRegularPieces -= $allocateRegularPieces;
                                $remainingFreePieces -= $allocateFreePieces;

                                Log::debug('FIFO allocation', [
                                    'index' => $index,
                                    'purchase_stock_product_id' => $purchaseProduct->id,
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                    'remaining_regular_pieces' => $remainingRegularPieces,
                                    'remaining_free_pieces' => $remainingFreePieces,
                                    'global_allocated_pieces' => $globalStockAllocation[$purchaseProduct->id]
                                ]);
                            }
                        }

                        if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                            throw new \Exception("Insufficient stock for product ID {$productId} at index {$index}. Requested: {$totalRequestedPieces} pieces (Regular: {$regularPieces}, Free: {$freePieces}), Allocated: " . ($totalRequestedPieces - ($remainingRegularPieces + $remainingFreePieces)) . " pieces.");
                        }
                    }

                    foreach ($allocations as $allocation) {
                        $purchaseProduct = PurchaseStockProduct::findOrFail($allocation['purchase_stock_product_id']);
                        $saleProduct = $sale->saleProducts()->create([
                            'company_id' => $validated['company_id'],
                            'branch_id' => $validated['branch_id'],
                            'sale_id' => $sale->id,
                            'product_id' => $productId,
                            'purchase_stock_product_id' => $allocation['purchase_stock_product_id'],
                            'quantity' => $allocation['quantity'],
                            'free_quantity' => $allocation['free_quantity'],
                            'price' => $productData['price'],
                            'amount' => $productData['amount'],
                            'discount_percent' => $productData['discount_percent'] ?? 0,
                            'discount_amount' => $productData['discount_amount'] ?? 0,

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
                            'purchase_stock_product_id' => $allocation['purchase_stock_product_id'],
                            'quantity' => $allocation['quantity'],
                            'free_quantity' => $allocation['free_quantity']
                        ]);

                        if (!empty($allocation['field_values'])) {
                            foreach ($allocation['field_values'] as $fvSet) {
                                foreach ($fvSet as $fv) {
                                    DB::table('sales_product_field_values')->insert([
                                        'sale_product_id' => $saleProduct->id,
                                        'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                        'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                        'stock_product_id' => $fv['stock_product_id'] ?? null,
                                        'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                        'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                                        'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                                        'product_id' => $productId,
                                        'product_field_id' => $fv['product_field_id'],
                                        'value' => $fv['value'],
                                        'quantity_index' => $fv['quantity_index'],
                                        'quantity_type' => $fv['quantity_type'],
                                        'company_id' => $validated['company_id'],
                                        'branch_id' => $validated['branch_id'],
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
}

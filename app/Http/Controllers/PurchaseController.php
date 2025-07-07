<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\Product;
use App\Models\Purchase;

use App\Models\ProductList;
use App\Models\MeasureUnit;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductFieldValue;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{


    public function generateUniquePurchaseBillNumber(Request $request)
    {
        // Validate the request
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|integer|exists:companies,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid company_id',
                'errors' => $validator->errors()
            ], 400);
        }

        try {
            $prefix = 'PB';
            $date = now()->format('Ymd'); // e.g., 20250615
            $currentYear = now()->format('Y'); // e.g., 2025
            $companyId = $request->input('company_id');
            $maxAttempts = 100; // Prevent infinite loops

            // Start a database transaction
            return DB::transaction(function () use ($prefix, $date, $currentYear, $companyId, $maxAttempts) {
                // Fetch the latest bill for the company with the given prefix and current year
                $latestBill = Purchase::where('company_id', $companyId)
                    ->where('purchase_bill_number', 'like', "{$prefix}-{$currentYear}%")
                    ->orderByDesc('created_at')
                    ->lockForUpdate() // Lock the rows to prevent concurrent access
                    ->first();

                $nextSequence = '000001'; // Default sequence if no bill exists
                if ($latestBill && preg_match("/^PB-\d{8}-(\d{6})$/", $latestBill->purchase_bill_number, $matches)) {
                    $lastSequence = (int) $matches[1];
                    $nextSequence = str_pad($lastSequence + 1, 6, '0', STR_PAD_LEFT);
                }

                $attempt = 0;
                do {
                    $billNumber = "{$prefix}-{$date}-{$nextSequence}";
                    $existingBill = Purchase::where('purchase_bill_number', $billNumber)->exists();
                    if (!$existingBill) {
                        return response()->json([
                            'status' => 'success',
                            'purchase_bill_number' => $billNumber
                        ], 200);
                    }
                    // Increment sequence for the next attempt
                    $nextSequence = str_pad((int) $nextSequence + 1, 6, '0', STR_PAD_LEFT);
                    $attempt++;
                } while ($existingBill && $attempt < $maxAttempts);

                throw new \Exception('Unable to generate a unique bill number after multiple attempts');
            });
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to generate a bill number: ' . $e->getMessage()
            ], 500);
        }
    }

    public function index(Request $request): JsonResponse
    {
        $query = Purchase::query();

        if ($request->has('keywords')) {
            $query->where('ref_bill_number', 'LIKE', '%' . $request->input('keywords') . '%')->orWhere('purchase_bill_number', 'LIKE', '%' . $request->input('keywords') . '%')->orWhereHas('customer', function ($query) use ($request) {
                $query->where('party_name', 'LIKE', "%" . $request->input('keywords') . "%");
            });
        }

        return response()->json($query->paginate(50));
    }

    public function getRefBillNumber(Request $request)
    {
        try {
            if (!$request->has('company_id')) {
                return response()->json(['error' => 'Missing required parameter: company_id'], 422);
            }

            $companyId = $request->company_id;

            // Get reference bill numbers where at least one product has remaining quantity
            // Accounts for purchase quantity and free_quantity, minus returns and sales (including free quantities)
            // Adds back quantities from non-deleted sale product returns
            $billNumbers = Purchase::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->pluck('ref_bill_number');

            if ($billNumbers->isEmpty()) {
                return response()->json([]);
            }

            return response()->json($billNumbers);
        } catch (QueryException $e) {
            \Log::error('Database error in getRefBillNumber: ' . $e->getMessage());
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getRefBillNumber: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    public function getProducts(Request $request): JsonResponse
    {

        $company = $request->company_id;
        $names = Helper::getProductNames($company);


        return response()->json($names);
    }


    public function getProductDetailsByName(Request $request): JsonResponse
    {
        try {

            $name = $request->input('name');
            $company = $request->company_id;

            $productDetails = Helper::getProdutDetailsByName($name, $company);


            return response()->json($productDetails);
        } catch (ModelNotFoundEXception $e) {
            return response()->json(['errors' => 'Item Not Found!!'], 422);
        } catch (QueryException $e) {

            return response()->json(['errors' => 'Database error occurred!!'], 500);
        } catch (\EXception $e) {

            return response()->json(['errors' => 'An unexpected error occurred!!'], 500);
        }
    }





    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = Purchase::findOrFail($id);

            $validated = $request->validate([
                'ref_bill_number' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('purchases')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $item) {
                            return $query->where('company_id', $request->input('company_id', $item->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'customer_id' => 'required|exists:customers,id',
                'customer_name' => 'nullable|string|max:255',
                'roundoff_type' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                'company_id' => 'exists:companies,id',
                'address' => 'nullable|string|max:255',
                'customer_contact' => 'nullable|string|max:255',
                'document_number' => 'nullable|string|max:255',
                'discount_after_vat' => 'nullable|numeric',
                'purchase_bill_number' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('purchases')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $item) {
                            return $query->where('company_id', $request->input('company_id', $item->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'balance' => 'nullable|numeric',
                'invoice_date' => 'nullable|string|max:255',
                'invoice_date_bs' => 'nullable|string|max:255',
                'batch_no' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('purchases')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $item) {
                            return $query->where('company_id', $request->input('company_id', $item->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'remarks' => 'nullable|string|max:255',
                'store_id' => 'required|integer|exists:stores,id',
                'bank_id' => 'nullable|integer|exists:banks,id',
                'location_id' => 'required|integer|exists:locations,id',
                'discount_type' => 'nullable|in:percent,amount',
                'discount_value' => 'nullable|numeric',
                'sub_total_before_discount' => 'nullable|numeric',
                'taxable_amount' => 'nullable|numeric',
                'non_taxable_amount' => 'nullable|numeric',
                'roundoff_amount' => 'nullable|numeric',
                'total_amount' => 'nullable|numeric',
                'excise_duty' => 'nullable|numeric',
                'vat_percent' => 'nullable|numeric',
                'health_insurance' => 'nullable|numeric',
                'freight_amount' => 'nullable|numeric',
                'purchase_products' => 'nullable|array',
                'purchase_products.*.id' => [
                    'required',
                    'integer',
                    Rule::exists('purchase_products', 'id')->where(function ($query) use ($id) {
                        $query->where('purchase_id', $id);
                    }),
                ],
                'purchase_products.*.product_id' => 'required|integer|exists:products,id',
                'purchase_products.*.product_name' => 'nullable|string|max:255',
                'purchase_products.*.product_code' => 'nullable|string|max:255',
                'purchase_products.*.mfd' => 'nullable|string|max:255',
                'purchase_products.*.expiry_date' => 'nullable|date',
                'purchase_products.*.quantity' => 'required|string',
                'purchase_products.*.free_quantity' => 'nullable|string',
                'purchase_products.*.price' => 'required|numeric|min:0',
                'purchase_products.*.discount_percent' => 'nullable|numeric',
                'purchase_products.*.discount_amount' => 'nullable|numeric',
                'purchase_products.*.amount' => 'nullable|numeric',
                'purchase_products.*.is_vatable' => 'required|boolean',
                'purchase_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'purchase_products.*.field_values' => 'nullable|array',
                'purchase_products.*.field_values.*' => 'array',
                'purchase_products.*.field_values.*.*.id' => 'nullable|integer|exists:purchase_product_field_values,id',
                'purchase_products.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
                'purchase_products.*.field_values.*.*.value' => 'required|string|max:255',
                'purchase_products.*.field_values.*.*.quantity_type' => 'nullable|string|max:255',
            ]);

            Log::debug('Validated purchase_products data', [
                'purchase_products' => $validated['purchase_products'] ?? [],
            ]);

            $item = DB::transaction(function () use ($validated, $item) {
                // Update Purchase with all fillable fields
                $item->update([
                    'customer_id' => $validated['customer_id'],
                    'customer_name' => $validated['customer_name'] ?? null,
                    'pan_number' => $validated['pan_number'] ?? null,
                    'company_id' => $validated['company_id'],
                    'address' => $validated['address'] ?? null,
                    'customer_contact' => $validated['customer_contact'] ?? null,
                    'ref_bill_number' => $validated['ref_bill_number'],
                    'document_number' => $validated['document_number'] ?? null,
                    'discount_after_vat' => $validated['discount_after_vat'] ?? null,
                    'purchase_bill_number' => $validated['purchase_bill_number'],
                    'balance' => $validated['balance'] ?? null,
                    'invoice_date' => $validated['invoice_date'] ?? null,
                    'invoice_date_bs' => $validated['invoice_date_bs'] ?? null,
                    'batch_no' => $validated['batch_no'] ?? null,
                    'payment' => $validated['payment'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'store_id' => $validated['store_id'],
                    'bank_id' => $validated['bank_id'] ?? null,
                    'location_id' => $validated['location_id'],
                    'discount_type' => $validated['discount_type'] ?? null,
                    'discount_value' => $validated['discount_value'] ?? null,
                    'sub_total_before_discount' => $validated['sub_total_before_discount'] ?? null,
                    'taxable_amount' => $validated['taxable_amount'] ?? null,
                    'non_taxable_amount' => $validated['non_taxable_amount'] ?? null,
                    'roundoff_amount' => $validated['roundoff_amount'] ?? null,
                    'roundoff_type' => $validated['roundoff_type'] ?? null,
                    'total_amount' => $validated['total_amount'] ?? null,
                    'excise_duty' => $validated['excise_duty'] ?? null,
                    'vat_percent' => $validated['vat_percent'] ?? null,
                    'health_insurance' => $validated['health_insurance'] ?? null,
                    'freight_amount' => $validated['freight_amount'] ?? null,
                ]);

                // Initialize priceMetrics and fieldValuesToDelete
                $priceMetrics = [];
                $fieldValuesToDelete = [];

                if (isset($validated['purchase_products'])) {
                    foreach ($validated['purchase_products'] as $purchaseProductData) {
                        // Validate price against product purchase_rate
                        $product = Product::where('id', $purchaseProductData['product_id'])->first();
                        $originalProductPrice = $product ? $product->purchase_rate : null;

                        if ($originalProductPrice && $purchaseProductData['price'] > $originalProductPrice * 10) {
                            Log::warning('Price discrepancy detected', [
                                'product_id' => $purchaseProductData['product_id'],
                                'input_price' => $purchaseProductData['price'],
                                'original_purchase_rate' => $originalProductPrice,
                            ]);
                        }

                        $purchaseProductDataFiltered = array_filter($purchaseProductData, function ($key) {
                            return $key !== 'field_values';
                        }, ARRAY_FILTER_USE_KEY);

                        // Update existing PurchaseProduct
                        $purchaseProduct = PurchaseProduct::where('id', $purchaseProductData['id'])
                            ->where('purchase_id', $item->id)
                            ->firstOrFail();
                        $purchaseProduct->update(
                            array_merge($purchaseProductDataFiltered, [
                                'purchase_id' => $item->id,
                                'company_id' => $validated['company_id'],
                            ])
                        );

                        // Calculate price metrics
                        $purchaseProductsPrice = PurchaseProduct::where('product_id', $purchaseProduct->product_id)
                            ->orderBy('created_at', 'desc')
                            ->pluck('price');
                        $priceMetrics[$purchaseProduct->product_id] = [
                            'original_price' => $originalProductPrice ?? 0,
                            'latest_price' => $purchaseProductsPrice->first() ?? 0,
                            'min_price' => $purchaseProductsPrice->min() ?? 0,
                            'avg_price' => $purchaseProductsPrice->avg() ?? 0,
                        ];

                        Log::debug('Price calculations for product', [
                            'product_id' => $purchaseProduct->product_id,
                            'latest_price' => $priceMetrics[$purchaseProduct->product_id]['latest_price'],
                            'min_price' => $priceMetrics[$purchaseProduct->product_id]['min_price'],
                            'avg_price' => $priceMetrics[$purchaseProduct->product_id]['avg_price'],
                            'prices' => $purchaseProductsPrice->toArray(),
                        ]);

                        // Handle field values
                        if (isset($purchaseProductData['field_values'])) {
                            $processedFieldIds = [];
                            $existingFieldIds = PurchaseProductFieldValue::where('purchase_product_id', $purchaseProduct->id)
                                ->pluck('id')
                                ->toArray();
                            $existingFieldValues = PurchaseProductFieldValue::where('purchase_product_id', $purchaseProduct->id)
                                ->get()
                                ->keyBy('id');

                            Log::debug("Existing field IDs for purchase_product_id {$purchaseProduct->id}", $existingFieldIds);

                            // Get the highest existing quantity_index for this purchase_product_id
                            $maxQuantityIndex = PurchaseProductFieldValue::where('purchase_product_id', $purchaseProduct->id)
                                ->max('quantity_index') ?? -1;

                            foreach ($purchaseProductData['field_values'] as $fieldValueSet) {
                                $newQuantityIndex = $maxQuantityIndex + 1; // Assign new quantity_index for this sub-array
                                foreach ($fieldValueSet as $fieldValue) {
                                    Log::debug("Processing field_value for purchase_product_id {$purchaseProduct->id}", [
                                        'field_value' => $fieldValue,
                                    ]);

                                    if (isset($fieldValue['id']) && !empty($fieldValue['id'])) {
                                        $existingValue = $existingFieldValues->get($fieldValue['id']);
                                        if ($existingValue) {
                                            if ($existingValue->trashed()) {
                                                $existingValue->restore();
                                            }
                                            $existingValue->update([
                                                'product_field_id' => $fieldValue['product_field_id'],
                                                'value' => $fieldValue['value'],
                                                'quantity_type' => array_key_exists('quantity_type', $fieldValue) ? $fieldValue['quantity_type'] : $existingValue->quantity_type,
                                                'quantity_index' => $existingValue->quantity_index, // Preserve existing quantity_index
                                                'updated_at' => now(),
                                            ]);
                                            $processedFieldIds[] = $existingValue->id;
                                            Log::debug("Updated field value ID {$fieldValue['id']} for purchase_product_id {$purchaseProduct->id}");
                                        } else {
                                            Log::warning("Field value ID {$fieldValue['id']} not found for purchase_product_id {$purchaseProduct->id}");
                                            $newFieldValue = PurchaseProductFieldValue::create([
                                                'product_field_id' => $fieldValue['product_field_id'],
                                                'value' => $fieldValue['value'],
                                                'quantity_type' => array_key_exists('quantity_type', $fieldValue) ? $fieldValue['quantity_type'] : null,
                                                'product_id' => $purchaseProduct->product_id,
                                                'company_id' => $purchaseProduct->company_id,
                                                'purchase_product_id' => $purchaseProduct->id,
                                                'quantity_index' => $newQuantityIndex,
                                            ]);
                                            $processedFieldIds[] = $newFieldValue->id;
                                            Log::debug("Created new field value ID {$newFieldValue->id} for purchase_product_id {$purchaseProduct->id} with quantity_index {$newQuantityIndex}");
                                        }
                                    } else {
                                        $newFieldValue = PurchaseProductFieldValue::create([
                                            'product_field_id' => $fieldValue['product_field_id'],
                                            'value' => $fieldValue['value'],
                                            'quantity_type' => array_key_exists('quantity_type', $fieldValue) ? $fieldValue['quantity_type'] : null,
                                            'product_id' => $purchaseProduct->product_id,
                                            'company_id' => $purchaseProduct->company_id,
                                            'purchase_product_id' => $purchaseProduct->id,
                                            'quantity_index' => $newQuantityIndex,
                                        ]);
                                        $processedFieldIds[] = $newFieldValue->id;
                                        Log::debug("Created new field value ID {$newFieldValue->id} for purchase_product_id {$purchaseProduct->id} with quantity_index {$newQuantityIndex}");
                                    }
                                }
                                $maxQuantityIndex = $newQuantityIndex; // Update maxQuantityIndex after processing the sub-array
                            }

                            $unprocessedFieldIds = array_diff($existingFieldIds, $processedFieldIds);
                            if (!empty($unprocessedFieldIds)) {
                                $fieldValuesToDelete[] = [
                                    'purchase_product_id' => $purchaseProduct->id,
                                    'ids' => $unprocessedFieldIds,
                                ];
                            }
                        } else {
                            $existingFieldIds = PurchaseProductFieldValue::where('purchase_product_id', $purchaseProduct->id)
                                ->pluck('id')
                                ->toArray();
                            if (!empty($existingFieldIds)) {
                                $fieldValuesToDelete[] = [
                                    'purchase_product_id' => $purchaseProduct->id,
                                    'ids' => $existingFieldIds,
                                ];
                            }
                        }
                    }
                }

                foreach ($fieldValuesToDelete as $deleteSet) {
                    Log::debug("Deleting field values for purchase_product_id {$deleteSet['purchase_product_id']}", $deleteSet['ids']);
                    PurchaseProductFieldValue::where('purchase_product_id', $deleteSet['purchase_product_id'])
                        ->whereIn('id', $deleteSet['ids'])
                        ->delete();
                }

                return $item;
            });

            return response()->json([
                'message' => 'Purchase Updated Successfully!!',
                'data' => $item->load([
                    'purchaseProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index')->orderBy('product_field_id');
                    }
                ]),
            ], 200);
        } catch (ModelNotFoundException $e) {
            Log::error('Purchase not found: ' . $e->getMessage());
            return response()->json(['error' => 'Purchase not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database error during purchase update: ' . $e->getMessage());
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error during purchase update: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }



    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ref_bill_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('purchases')

                    ->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id', $request->company_id))
                            ->whereNull('deleted_at');
                    }),
            ],
            'customer_id' => 'required|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'pan_number' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'customer_contact' => 'nullable|string|max:255',
            'document_number' => 'nullable|string|max:255',
            'remarks' => 'nullable|string|max:255',
            'invoice_date' => 'nullable|string|max:255',
            'invoice_date_bs' => 'nullable|string|max:255',
            'batch_no' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('purchases')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id);
                }),
            ],
            'purchase_bill_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('purchases')

                    ->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id', $request->company_id))
                            ->whereNull('deleted_at');
                    }),
            ],
            'discount_type' => 'nullable|in:percent,amount',
            'discount_value' => 'nullable|numeric',
            'discount_after_vat' => 'nullable|numeric',
            'roundoff_amount' => 'nullable|numeric',
            'roundoff_type' => 'nullable|string|max:255',
            'sub_total_before_discount' => 'nullable|numeric',
            'taxable_amount' => 'nullable|numeric',
            'non_taxable_amount' => 'nullable|numeric',
            'total_amount' => 'nullable|numeric',
            'excise_duty' => 'nullable|numeric',
            'vat_percent' => 'nullable|numeric',
            'health_insurance' => 'nullable|numeric',
            'freight_amount' => 'nullable|numeric',
            'balance' => 'nullable|numeric',
            'payment' => 'nullable|array',
            'payment.cash' => 'nullable|numeric|min:0',
            'payment.credit' => 'nullable|numeric|min:0',
            'payment.bank' => 'nullable|numeric|min:0',
            'store_id' => 'nullable|integer|exists:stores,id',
            'bank_id' => 'nullable|integer|exists:banks,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'company_id' => 'required|integer|exists:companies,id',
            'purchase_products' => 'required|array',
            'purchase_products.*.product_id' => 'required|integer|exists:products,id',
            'purchase_products.*.product_name' => 'nullable|string|max:255',
            'purchase_products.*.product_code' => 'nullable|string|max:255',
            'purchase_products.*.hs_code' => 'nullable|string|max:255',
            'purchase_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
            'purchase_products.*.mfd' => 'nullable|string|max:255',
            'purchase_products.*.quantity' => 'required|numeric',
            'purchase_products.*.free_quantity' => 'nullable|numeric',
            'purchase_products.*.expiry_date' => 'nullable|string|max:255',
            'purchase_products.*.price' => 'nullable|numeric',
            'purchase_products.*.discount_percent' => 'nullable|numeric',
            'purchase_products.*.discount_amount' => 'nullable|numeric',
            'purchase_products.*.amount' => 'nullable|numeric',
            'purchase_products.*.is_vatable' => 'required|boolean',
            'purchase_products.*.field_values' => 'nullable|array',
            'purchase_products.*.field_values.*' => 'array',
            'purchase_products.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
            'purchase_products.*.field_values.*.*.value' => 'required|string|max:255',
            'purchase_products.*.field_values.*.*.quantity_type' => 'required|string|max:255',
        ]);

        try {
            $item = DB::transaction(function () use ($validated) {


                // Create Purchase
                $item = Purchase::create([
                    'customer_id' => $validated['customer_id'],
                    'customer_name' => $validated['customer_name'] ?? null,
                    'pan_number' => $validated['pan_number'] ?? null,
                    'company_id' => $validated['company_id'],
                    'address' => $validated['address'] ?? null,
                    'customer_contact' => $validated['customer_contact'] ?? null,
                    'ref_bill_number' => $validated['ref_bill_number'],
                    'document_number' => $validated['document_number'] ?? null,
                    'purchase_bill_number' => $validated['purchase_bill_number'],
                    'balance' => $validated['balance'] ?? null,
                    'invoice_date' => $validated['invoice_date'] ?? null,
                    'invoice_date_bs' => $validated['invoice_date_bs'] ?? null,
                    'batch_no' => $validated['batch_no'] ?? null,
                    'payment' => $validated['payment'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'store_id' => $validated['store_id'],
                    'bank_id' => $validated['bank_id'] ?? null,
                    'location_id' => $validated['location_id'],
                    'discount_type' => $validated['discount_type'] ?? null,
                    'discount_value' => $validated['discount_value'] ?? null,
                    'sub_total_before_discount' => $validated['sub_total_before_discount'] ?? null,
                    'taxable_amount' => $validated['taxable_amount'] ?? null,
                    'non_taxable_amount' => $validated['non_taxable_amount'] ?? null,
                    'roundoff_amount' => $validated['roundoff_amount'] ?? null,
                    'roundoff_type' => $validated['roundoff_type'] ?? null,
                    'total_amount' => $validated['total_amount'] ?? null,
                    'amount' => $validated['amount'] ?? null,
                    'excise_duty' => $validated['excise_duty'] ?? null,
                    'vat_percent' => $validated['vat_percent'] ?? null,
                    'health_insurance' => $validated['health_insurance'] ?? null,
                    'freight_amount' => $validated['freight_amount'] ?? null,
                    'discount_after_vat' => $validated['discount_after_vat'] ?? null,
                ]);


                // Create Purchase Products
                if (isset($validated['purchase_products'])) {


                    foreach ($validated['purchase_products'] as $purchaseProductData) {

                        $purchasedProduct = PurchaseProduct::where('product_id', $purchaseProductData['product_id'])
                            ->where('company_id', $validated['company_id'])
                            ->first();
                        if (!$purchasedProduct) {
                            $product = Product::find($purchaseProductData['product_id']);
                            if ($product) {
                                $product->purchase_status = 'purchased';
                                $product->save();
                            }
                        }

                        $productId = $purchaseProductData['product_id'] ?? null;
                        $hsCode = $purchaseProductData['hs_code'] ?? null;

                        if ($productId && $hsCode) {
                            ProductList::where('product_id', $productId)
                                ->where(function ($query) use ($hsCode) {
                                    $query->whereNull('hs_code')
                                        ->orWhere('hs_code', '!=', $hsCode);
                                })
                                ->update(['hs_code' => $hsCode]);
                        }


                        // Create PurchaseProduct using static create method
                        $purchaseProduct = PurchaseProduct::create([
                            'purchase_id' => $item->id, // Manually set the foreign key
                            'customer_id' => $validated['customer_id'],
                            'company_id' => $validated['company_id'],
                            'product_id' => $purchaseProductData['product_id'],
                            'product_name' => $purchaseProductData['product_name'] ?? null,
                            'product_code' => $purchaseProductData['product_code'] ?? null,
                            'expiry_date' => $purchaseProductData['expiry_date'] ?? null,
                            'mfd' => $purchaseProductData['mfd'] ?? null,
                            'quantity' => $purchaseProductData['quantity'],
                            'free_quantity' => $purchaseProductData['free_quantity'] ?? null,
                            'price' => $purchaseProductData['price'] ?? null,
                            'discount_percent' => $purchaseProductData['discount_percent'] ?? null,
                            'discount_amount' => $purchaseProductData['discount_amount'] ?? null,
                            'amount' => $purchaseProductData['amount'] ?? null,
                            'is_vatable' => $purchaseProductData['is_vatable'],
                            'measure_unit_id' => $purchaseProductData['measure_unit_id'],
                        ]);

                        // Create Field Values
                        if (!empty($purchaseProductData['field_values'])) {
                            $fieldValues = [];
                            foreach ($purchaseProductData['field_values'] as $quantityIndex => $fieldValueSet) {
                                foreach ($fieldValueSet as $fieldValue) {
                                    $fieldValues[] = [
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'value' => $fieldValue['value'],
                                        'product_id' => $purchaseProduct->product_id,
                                        'company_id' => $purchaseProduct->company_id,
                                        'purchase_product_id' => $purchaseProduct->id,
                                        'quantity_index' => $quantityIndex,
                                        'quantity_type' => $fieldValue['quantity_type'],
                                    ];
                                }
                            }
                            PurchaseProductFieldValue::insert($fieldValues);
                        }

                    }
                }



                return $item;
            });

            return response()->json([
                'message' => 'Purchase Created Successfully!!',
                'data' => $item->load('purchaseProducts', 'purchaseProducts.fieldValues'),
            ], 201);
        } catch (ModelNotFoundException $e) {
            Log::error('Model not found', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Resource not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }




    public function show($id): JsonResponse
    {
        try {
            $item = Purchase::with(['purchaseProducts.fieldValues.productField'])->findOrFail($id);
            $itemArray = $item->toArray();


            foreach ($itemArray['purchase_products'] as &$purchaseProduct) {

                $product = Product::find($purchaseProduct['product_id']);


                $productId = $product->id;

                $purchaseRateVat = $product->purchase_rate_vat ?? 0;

                $productMeasureUnitId = Product::where('id', $productId)->pluck('measure_unit_id')->toArray();
                $productListMeasureUnitId = ProductList::where('product_id', $productId)->pluck('measure_unit_id')->toArray();
                $mergedMeasureUnits = collect(array_merge($productMeasureUnitId, $productListMeasureUnitId))->unique()->filter()->values();

                $usedMeasureUnits = MeasureUnit::whereIn('id', $mergedMeasureUnits)
                    ->whereNull('deleted_at')
                    ->get(['id', 'name', 'quantity']);
                $purchaseProduct['measure_units'] = $usedMeasureUnits;
                $purchaseProduct['original_price'] = $purchaseRateVat;

                foreach ($purchaseProduct['field_values'] as &$fieldValue) {
                    if (isset($fieldValue['product_field'])) {
                        $fieldValue['name'] = $fieldValue['product_field']['name'];
                        $fieldValue['type'] = $fieldValue['product_field']['type'];
                        $fieldValue['values'] = $fieldValue['product_field']['values'];

                        unset($fieldValue['product_field']);
                    }
                }
            }

            return response()->json($itemArray);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {

            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            dd($e->getMessage());
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = Purchase::with('purchaseProducts.fieldValues')->findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Purchase deleted']);
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

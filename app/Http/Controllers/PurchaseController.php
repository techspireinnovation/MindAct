<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\MeasureUnit;
use App\Models\Product;
use App\Models\ProductList;
use App\Models\Purchase;
use App\Models\PurchaseStockProduct;
use App\Models\PurchaseProduct;
use App\Models\PurchaseStockProductFieldValue;
use App\Models\PurchaseProductFieldValue;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use App\Models\User;
use Illuminate\Support\Arr;

use Carbon\Carbon;
use Pratiksh\Nepalidate\Services\NepaliDate;


class PurchaseController extends Controller
{




    public function generateUniquePurchaseBillNumber(Request $request)
    {
        try {
            // Get current BS date
            $bsDate = NepaliDate::create(Carbon::now())->toBS();
            [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

            $currentBsYear = (int) $currentBsYear;
            $currentBsMonth = (int) $currentBsMonth;

            // Calculate fiscal year
            $fiscalYearStart = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;
            $fiscalYearEnd = $fiscalYearStart + 1;

            // Fiscal year code: last 2 digits of start + last 2 digits of end
            $fiscalYearCode = substr($fiscalYearStart, 2, 2) . substr($fiscalYearEnd, 2, 2);

            $branchId = $request->branch_id;

            if (!$branchId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Branch not provided.'
                ], 400);
            }

            // Get last purchase for this branch & fiscal year
            $lastPurchase = Purchase::where('branch_id', $branchId)
                ->where('purchase_bill_number', 'like', "P-{$fiscalYearCode}-{$branchId}-%")
                ->orderBy('id', 'desc')
                ->first();

            // Extract last 6 digits
            $lastNumber = 0;
            if ($lastPurchase && !empty($lastPurchase->purchase_bill_number)) {
                preg_match('/(\d{6})$/', $lastPurchase->purchase_bill_number, $matches);
                if (!empty($matches[1])) {
                    $lastNumber = (int) $matches[1];
                }
            }

            // Next running number
            $newNumber = str_pad($lastNumber + 1, 6, '0', STR_PAD_LEFT);

            // Final bill number with PB prefix
            $purchaseBillNumber = "P-{$fiscalYearCode}-{$branchId}-{$newNumber}";

            return response()->json([
                'status' => 'success',
                'purchase_bill_number' => $purchaseBillNumber
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error generating purchase bill number: ' . $e->getMessage()
            ], 400);
        }
    }




    public function index(Request $request): JsonResponse
    {
        $query = Purchase::query();

        // Filter by branch_id
        if ($request->has('branch_id')) {
            $query->where('branch_id', $request->branch_id);
        }

        // Optional: filter by keywordss
        if ($request->has('keywords')) {
            $query->where(function ($q) use ($request) {
                $q->where('ref_bill_number', 'LIKE', '%' . $request->input('keywords') . '%')
                    ->orWhere('purchase_bill_number', 'LIKE', '%' . $request->input('keywords') . '%')
                    ->orWhereHas('customer', function ($q2) use ($request) {
                        $q2->where('party_name', 'LIKE', '%' . $request->input('keywords') . '%');
                    });
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

            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred !'], 500);
        }
    }



    public function getProducts(Request $request): JsonResponse
    {

        $company = $request->company_id;
        $names = Helper::getProductNames($company);


        return response()->json($names);
    }


    private function getNextQuantityIndex($purchaseProductId, $productFieldId)
    {
        $maxIndex = PurchaseProductFieldValue::where('purchase_product_id', $purchaseProductId)
            ->where('product_field_id', $productFieldId)
            ->whereNull('deleted_at')
            ->max('quantity_index');

        return $maxIndex !== null ? $maxIndex + 1 : 0;
    }

    private function getNextStockQuantityIndex($purchaseProductId, $productFieldId)
    {
        $maxIndex = PurchaseStockProductFieldValue::where('purchase_product_id', $purchaseProductId)
            ->where('product_field_id', $productFieldId)
            ->whereNull('deleted_at')
            ->max('quantity_index');

        return $maxIndex !== null ? $maxIndex + 1 : 0;
    }





    public function getProductDetailsByName(Request $request): JsonResponse
    {
        try {

            $name = $request->input('name');
            $company = $request->company_id;
            $branch = $request->branch_id;

            $productDetails = Helper::getProdutDetailsByName($name, $company, $branch);


            return response()->json($productDetails);
        } catch (ModelNotFoundEXception $e) {
            return response()->json(['errors' => 'Item Not Found!!'], 422);
        } catch (QueryException $e) {

            return response()->json(['errors' => 'Database error occurred!!'], 500);
        } catch (\EXception $e) {

            return response()->json(['errors' => 'An unexpected error occurred!'], 500);
        }
    }



    public function update(Request $request, $id): JsonResponse
    {
        try {
            $purchase = Purchase::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'ref_bill_number' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('purchases')->ignore($id)
                        ->where(fn($q) => $q->where('company_id', $request->company_id)->whereNull('deleted_at'))
                ],
                'customer_id' => 'required|exists:customers,id',
                'customer_name' => 'nullable|string|max:255',
                'roundoff_type' => 'nullable|string|max:255',
                'pan_number' => 'nullable|numeric|digits:9',
                'company_id' => 'required|integer',
                'address' => 'nullable|string|max:255',
                'customer_contact' => 'nullable|string|max:255',
                'document_number' => 'nullable|string|max:255',
                'discount_after_vat' => 'nullable|numeric',
                'purchase_bill_number' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('purchases')->ignore($id)
                        ->where(fn($q) => $q->where('company_id', $request->company_id)->whereNull('deleted_at'))
                ],
                'balance' => 'nullable|numeric',
                'invoice_date' => 'nullable|string|max:255',
                'invoice_date_bs' => 'nullable|string|max:255',
                'batch_no' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('purchases')->ignore($id)
                        ->where(fn($q) => $q->where('company_id', $request->company_id)->whereNull('deleted_at'))
                ],
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank_name' => 'nullable|string',
                'payment.bank' => 'nullable|numeric|min:0',
                'purchase_type' => 'nullable|string',
                'remarks' => 'nullable|string|max:255',
                'store_id' => 'nullable|integer|exists:stores,id',
                'bank_id' => 'nullable|integer|exists:banks,id',
                'location_id' => 'nullable|integer|exists:locations,id',
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

                // Purchase Products
                'purchase_products' => 'required|array',
                'purchase_products.*.id' => ['nullable', 'integer', Rule::exists('purchase_products', 'id')->where('purchase_id', $id)],
                'purchase_products.*.product_id' => 'required|integer|exists:products,id',
                'purchase_products.*.product_name' => 'nullable|string|max:255',
                'purchase_products.*.branch_id' => 'nullable|integer|exists:branches,id',
                'purchase_products.*.purchase_type' => 'nullable|string',
                'purchase_products.*.product_code' => 'nullable|string|max:255',
                'purchase_products.*.hs_code' => 'nullable|string|max:255',
                'purchase_products.*.mfd' => 'nullable|string|max:255',
                'purchase_products.*.expiry_date' => 'nullable|string|max:255',
                'purchase_products.*.quantity' => 'required|numeric|min:0.01',
                'purchase_products.*.free_quantity' => 'nullable|numeric',
                'purchase_products.*.price' => 'nullable|numeric',
                'purchase_products.*.discount_percent' => 'nullable|numeric',
                'purchase_products.*.discount_amount' => 'nullable|numeric',
                'purchase_products.*.amount' => 'nullable|numeric',
                'purchase_products.*.is_vatable' => 'required|boolean',
                'purchase_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',

                // Field Values – fully nested
                'purchase_products.*.field_values' => 'nullable|array',
                'purchase_products.*.field_values.*' => 'array',
                'purchase_products.*.field_values.*.*.id' => 'nullable|integer|exists:purchase_product_field_values,id',
                'purchase_products.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
                'purchase_products.*.field_values.*.*.value' => 'required|string|max:255',
                'purchase_products.*.field_values.*.*.quantity_type' => 'required|string|max:255',
                'purchase_products.*.field_values.*.*.quantity_index' => 'nullable|integer',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()], 422);
            }

            $validated = $validator->validated();
            $companyId = $validated['company_id'];
            $branchId = $request->input('branch_id');

            $purchase = DB::transaction(function () use ($validated, $purchase, $companyId, $branchId) {

                // 1. Update main Purchase
                $purchase->update(Arr::except($validated, ['purchase_products']));

                $processedProductIds = [];

                foreach ($validated['purchase_products'] as $productData) {

                    // ── Update or Create PurchaseProduct ──
                    $purchaseProduct = isset($productData['id']) ? PurchaseProduct::find($productData['id']) : null;

                    if ($purchaseProduct && $purchaseProduct->purchase_id === $purchase->id) {
                        $purchaseProduct->update([
                            'product_id' => $productData['product_id'],
                            'product_name' => $productData['product_name'] ?? null,
                            'product_code' => $productData['product_code'] ?? null,
                            'quantity' => $productData['quantity'],
                            'free_quantity' => $productData['free_quantity'] ?? 0,
                            'price' => $productData['price'] ?? 0,
                            'discount_percent' => $productData['discount_percent'] ?? 0,
                            'discount_amount' => $productData['discount_amount'] ?? 0,
                            'amount' => $productData['amount'] ?? 0,
                            'is_vatable' => $productData['is_vatable'],
                            'measure_unit_id' => $productData['measure_unit_id'],
                            'branch_id' => $productData['branch_id'] ?? $branchId,
                            'purchase_type' => $productData['purchase_type'] ?? $validated['purchase_type'] ?? null,
                            'company_id' => $companyId,
                            'customer_id' => $validated['customer_id'],
                        ]);
                    } else {
                        $purchaseProduct = PurchaseProduct::create([
                            'purchase_id' => $purchase->id,
                            'product_id' => $productData['product_id'],
                            'product_name' => $productData['product_name'] ?? null,
                            'product_code' => $productData['product_code'] ?? null,
                            'quantity' => $productData['quantity'],
                            'free_quantity' => $productData['free_quantity'] ?? 0,
                            'price' => $productData['price'] ?? 0,
                            'discount_percent' => $productData['discount_percent'] ?? 0,
                            'discount_amount' => $productData['discount_amount'] ?? 0,
                            'amount' => $productData['amount'] ?? 0,
                            'is_vatable' => $productData['is_vatable'],
                            'measure_unit_id' => $productData['measure_unit_id'],
                            'branch_id' => $productData['branch_id'] ?? $branchId,
                            'purchase_type' => $productData['purchase_type'] ?? $validated['purchase_type'] ?? null,
                            'company_id' => $companyId,
                            'customer_id' => $validated['customer_id'],
                        ]);
                    }

                    $processedProductIds[] = $purchaseProduct->id;

                    // ── Update or Create PurchaseStockProduct ──
                    $stockProduct = PurchaseStockProduct::updateOrCreate(
                        ['purchase_product_id' => $purchaseProduct->id],
                        [
                            'purchase_id' => $purchase->id,
                            'company_id' => $companyId,
                            'branch_id' => $branchId,
                            'customer_id' => $validated['customer_id'],
                            'product_id' => $productData['product_id'],
                            'product_name' => $productData['product_name'] ?? null,
                            'product_code' => $productData['product_code'] ?? null,
                            'purchase_type' => $productData['purchase_type'] ?? $validated['purchase_type'] ?? null,
                            'quantity' => $productData['quantity'],
                            'free_quantity' => $productData['free_quantity'] ?? 0,
                            'price' => $productData['price'] ?? 0,
                            'discount_percent' => $productData['discount_percent'] ?? 0,
                            'discount_amount' => $productData['discount_amount'] ?? 0,
                            'amount' => $productData['amount'] ?? 0,
                            'is_vatable' => $productData['is_vatable'],
                            'measure_unit_id' => $productData['measure_unit_id'],
                        ]
                    );

                    // ── Handle PurchaseProductFieldValue ──
                    $keepFieldValueIds = [];

                    if (!empty($productData['field_values']) && is_array($productData['field_values'])) {
                        foreach ($productData['field_values'] as $fieldSet) {
                            foreach ($fieldSet as $field) {
                                if (isset($field['quantity_index'])) {
                                    $qIndex = (int) $field['quantity_index'];
                                } else {
                                    $qIndex = $this->getNextQuantityIndex(
                                        $purchaseProduct->id,
                                        $field['product_field_id']
                                    );
                                }
                                $fvData = [
                                    'product_field_id' => $field['product_field_id'],
                                    'value' => $field['value'],
                                    'quantity_type' => $field['quantity_type'],
                                    'quantity_index' => $qIndex,
                                    'product_id' => $purchaseProduct->product_id,
                                    'company_id' => $companyId,
                                    'branch_id' => $branchId,
                                    'purchase_product_id' => $purchaseProduct->id,
                                ];

                                if (!empty($field['id'])) {
                                    $fieldValue = PurchaseProductFieldValue::find($field['id']);
                                    if ($fieldValue && $fieldValue->purchase_product_id === $purchaseProduct->id) {
                                        $fieldValue->update($fvData);
                                        $keepFieldValueIds[] = $fieldValue->id;
                                        continue;
                                    }
                                }

                                $newFv = PurchaseProductFieldValue::create($fvData);
                                $keepFieldValueIds[] = $newFv->id;
                            }
                        }
                    }

                    // Delete removed field values
                    PurchaseProductFieldValue::where('purchase_product_id', $purchaseProduct->id)
                        ->whereNotIn('id', $keepFieldValueIds)
                        ->delete();

                    // ── Sync Stock Product Field Values ──
                    $existingStockFields = PurchaseStockProductFieldValue::where('purchase_stock_product_id', $stockProduct->id)
                        ->where('purchase_product_id', $purchaseProduct->id)
                        ->get()
                        ->keyBy(fn($item) => $item->purchase_product_id . '-' . $item->product_field_id . '-' . $item->quantity_index);

                    $keepStockFieldValueKeys = [];

                    if (!empty($productData['field_values']) && is_array($productData['field_values'])) {
                        foreach ($productData['field_values'] as $fieldSet) {
                            foreach ($fieldSet as $field) {
                                if (isset($field['quantity_index'])) {
                                    $qIndex = (int) $field['quantity_index'];
                                } else {
                                    $qIndex = $this->getNextStockQuantityIndex(
                                        $purchaseProduct->id,
                                        $field['product_field_id']
                                    );
                                }

                                $key = $purchaseProduct->id . '-' . $field['product_field_id'] . '-' . $qIndex;
                                $keepStockFieldValueKeys[] = $key;

                                $fvData = [
                                    'purchase_stock_product_id' => $stockProduct->id,
                                    'purchase_product_id' => $purchaseProduct->id,
                                    'product_id' => $purchaseProduct->product_id,
                                    'company_id' => $companyId,
                                    'branch_id' => $branchId,
                                    'product_field_id' => $field['product_field_id'],
                                    'value' => $field['value'],
                                    'quantity_type' => $field['quantity_type'],
                                    'quantity_index' => $qIndex,
                                ];

                                PurchaseStockProductFieldValue::updateOrCreate(
                                    [
                                        'purchase_stock_product_id' => $stockProduct->id,
                                        'purchase_product_id' => $purchaseProduct->id,
                                        'product_field_id' => $field['product_field_id'],
                                        'quantity_index' => $qIndex,
                                    ],
                                    $fvData
                                );
                            }
                        }
                    }

                    // Delete old stock field values not in request
                    foreach ($existingStockFields as $key => $stockField) {
                        if (!in_array($key, $keepStockFieldValueKeys)) {
                            $stockField->delete();
                        }
                    }


                }

                // ── Delete removed PurchaseProducts ──
                $removedProductIds = PurchaseProduct::where('purchase_id', $purchase->id)
                    ->whereNotIn('id', $processedProductIds)
                    ->pluck('id');

                if ($removedProductIds->isNotEmpty()) {
                    PurchaseProduct::whereIn('id', $removedProductIds)->delete();
                    PurchaseStockProduct::whereIn('purchase_product_id', $removedProductIds)->delete();
                    PurchaseProductFieldValue::whereIn('purchase_product_id', $removedProductIds)->delete();
                    PurchaseStockProductFieldValue::whereIn('purchase_product_id', $removedProductIds)->delete();
                }

                return $purchase->fresh();
            });

            return response()->json([
                'message' => 'Purchase Updated Successfully!!',
                'data' => $purchase->load([
                    'purchaseProducts.fieldValues' => fn($q) => $q->orderBy('quantity_index')->orderBy('product_field_id')
                ])
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Purchase not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database Error: ' . $e->getMessage());
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Something went wrong'], 500);
        }
    }




    public function store(Request $request): JsonResponse
    {

        $validator = Validator::make($request->all(), ([
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
            'pan_number' => 'nullable|numeric|digits:9',
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
            'purchase_type' => 'nullable|string',
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
            'payment.bank_name' => 'nullable|string',
            'store_id' => 'nullable|integer|exists:stores,id',
            'bank_id' => 'nullable|integer|exists:banks,id',
            'location_id' => 'nullable|integer|exists:locations,id',
            'company_id' => 'required|integer',
            'purchase_products' => 'required|array',
            'purchase_products.*.product_id' => 'required|integer|exists:products,id',
            'purchase_products.*.product_name' => 'nullable|string|max:255',
            'purchase_products.*.branch_id' => 'nullable|numeric|exists:branches,id',
            'purchase_products.*.purchase_type' => 'nullable|string',
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
        ]));
        if ($validator->fails()) {
            return response()->json(["error" => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        $companyId = $request->company_id;
        $branchId = $request->branch_id;

        try {
            $item = DB::transaction(function () use ($validated, $companyId, $branchId) {


                // Create Purchase

                $item = Purchase::create([
                    'customer_id' => $validated['customer_id'] ?? null,
                    'customer_name' => $validated['customer_name'] ?? null,
                    'pan_number' => $validated['pan_number'] ?? null,
                    'company_id' => $validated['company_id'],

                    'branch_id' => $branchId ?? null,
                    'address' => $validated['address'] ?? null,
                    'customer_contact' => $validated['customer_contact'] ?? null,
                    'ref_bill_number' => $validated['ref_bill_number'],
                    'document_number' => $validated['document_number'] ?? null,
                    'purchase_bill_number' => $validated['purchase_bill_number'],
                    'purchase_type' => $validated['purchase_type'] ?? null,
                    'balance' => $validated['balance'] ?? null,
                    'invoice_date' => $validated['invoice_date'] ?? null,
                    'invoice_date_bs' => $validated['invoice_date_bs'] ?? null,
                    'batch_no' => $validated['batch_no'] ?? null,
                    'payment' => $validated['payment'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'store_id' => $validated['store_id'] ?? null,
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




                if (isset($validated['purchase_products'])) {


                    foreach ($validated['purchase_products'] as $purchaseProductData) {


                        $purchasedProduct = PurchaseProduct::where('product_id', $purchaseProductData['product_id'])
                            ->where('company_id', $companyId)
                            ->where('branch_id', $branchId)
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

                        $purchaseProduct = PurchaseProduct::create([
                            'purchase_id' => $item->id,
                            'customer_id' => $validated['customer_id'],
                            'company_id' => $validated['company_id'],
                            'branch_id' => $branchId,
                            'purchase_type' => $validated['purchase_type'] ?? null,
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

                        $purchaseStockProduct = PurchaseStockProduct::create([
                            'purchase_id' => $item->id,
                            'purchase_product_id' => $purchaseProduct->id,
                            'customer_id' => $validated['customer_id'],
                            'company_id' => $validated['company_id'],
                            'branch_id' => $branchId,
                            'purchase_type' => $purchaseProductData['purchase_type'] ?? null,
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
                                        'branch_id' => $purchaseProduct->branch_id,
                                        'purchase_product_id' => $purchaseProduct->id,

                                        'quantity_index' => $quantityIndex,
                                        'quantity_type' => $fieldValue['quantity_type'],
                                    ];
                                }
                            }
                            PurchaseProductFieldValue::insert($fieldValues);
                        }


                        if (!empty($purchaseProductData['field_values'])) {
                            $fieldValues = [];
                            foreach ($purchaseProductData['field_values'] as $quantityIndex => $fieldValueSet) {
                                foreach ($fieldValueSet as $fieldValue) {
                                    $fieldValues[] = [
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'value' => $fieldValue['value'],
                                        'product_id' => $purchaseStockProduct->product_id,
                                        'company_id' => $purchaseStockProduct->company_id,
                                        'branch_id' => $purchaseStockProduct->branch_id,
                                        'purchase_product_id' => $purchaseProduct->id,
                                        'purchase_stock_product_id' => $purchaseStockProduct->id,
                                        'quantity_index' => $quantityIndex,
                                        'quantity_type' => $fieldValue['quantity_type'],
                                    ];
                                }
                            }
                            PurchaseStockProductFieldValue::insert($fieldValues);
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

            return response()->json(['error' => 'Resource not found'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {


            return response()->json(['error' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }


    public function getItemByBillNumber($billNumber): JsonResponse
    {
        try {
            $purchase = Purchase::where('purchase_bill_number', $billNumber)->firstOrFail();
            return $this->show($purchase->id);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);
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

            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {


            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {


            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    public function destroy($id): JsonResponse
    {
        try {
            $purchase = Purchase::findOrFail($id);

            if (
                $purchase->purchaseProductsUse()->exists() ||
                $purchase->purchaseReturnProductsUse()->exists() ||
                $purchase->purchaseReturnsUse()->exists()
            ) {
                return response()->json([
                    'error' => 'in_use',
                    'message' => 'Purchase cannot be deleted because it has related products or return records.'
                ], 400);
            }

            $purchase->delete();

            return response()->json([
                'success' => true,
                'message' => 'Purchase deleted successfully!'
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'not_found',
                'message' => 'Purchase not found!'
            ], 404);
        } catch (QueryException $e) {

            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the purchase.'
            ], 500);
        } catch (\Exception $e) {

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the purchase.'
            ], 500);
        }
    }




    public function filterbyBarcode(Request $request): JsonResponse
    {
        try {

            // Validate request
            $validator = Validator::make($request->all(), [
                'barcode' => 'required_without:product_unique_id',
                'product_unique_id' => 'required_without:barcode',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $productList = null;
            $product = null;

            // Search logic
            if ($request->filled('barcode')) {

                // Find product list by barcode
                $productList = ProductList::with([
                    'product.category',
                    'product.subCategory',
                    'product.brand',
                    'product.measureUnit',
                    'product.productType',
                    'product.location',
                    'product.productFieldValues',
                    'product.productLists.measureUnit'
                ])->where('barcode', $request->barcode)->first();

                if ($productList) {
                    $product = $productList->product;
                }
            } else {


                // Try different approaches to find the product
                $product = Product::with([
                    'category',
                    'subCategory',
                    'brand',
                    'measureUnit',
                    'productType',
                    'location',
                    'productFieldValues',
                    'productLists.measureUnit'
                ])->where('product_unique_id', $request->product_unique_id)->first();

                // If not found, try case-insensitive search
                if (!$product) {
                    $product = Product::with([
                        'category',
                        'subCategory',
                        'brand',
                        'measureUnit',
                        'productType',
                        'location',
                        'productFieldValues',
                        'productLists.measureUnit'
                    ])->whereRaw('LOWER(product_unique_id) = ?', [strtolower($request->product_unique_id)])->first();
                }

                // If still not found, try trimming whitespace
                if (!$product) {
                    $product = Product::with([
                        'category',
                        'subCategory',
                        'brand',
                        'measureUnit',
                        'productType',
                        'location',
                        'productFieldValues',
                        'productLists.measureUnit'
                    ])->where('product_unique_id', 'like', '%' . trim($request->product_unique_id) . '%')->first();
                }

                if ($product) {
                    // Get the first product list for this product
                    $productList = $product->productLists()->first();
                }
            }

            if (!$product) {


                // Provide more helpful error message
                return response()->json([
                    'error' => 'No products found',
                    'searched_value' => $request->filled('barcode') ? $request->barcode : $request->product_unique_id,
                    'search_type' => $request->filled('barcode') ? 'barcode' : 'product_unique_id'
                ], 404);
            }




            $data = [
                "id" => $product->id,
                "name" => $product->name,
                "product_unique_id" => $product->product_unique_id,
                "category_id" => $product->category_id ?? 0,
                "sub_category_id" => $product->sub_category_id ?? 0,
                "brand_id" => $product->brand_id ?? 0,
                "measure_unit_id" => $product->measure_unit_id,
                "purchase_rate" => $product->purchase_rate,
                "retail_sales_price" => $product->retail_sales_price,
                "wholesales_price" => $product->wholesales_price,
                "is_vatable" => $product->is_vatable,
                "product_type_id" => $product->product_type_id,
                "location_id" => $product->location_id,
                "is_active" => (bool) $product->is_active,
                "created_at" => $product->created_at,
                "updated_at" => $product->updated_at,
                "deleted_at" => $product->deleted_at,

                "primary_measure_unit" => $product->measureUnit ? [
                    "id" => $product->measureUnit->id,
                    "name" => $product->measureUnit->name,
                    "symbol" => $product->measureUnit->symbol,
                    "quantity" => $product->measureUnit->quantity,
                    "company_id" => $product->measureUnit->company_id,
                    "is_primary" => (bool) $product->measureUnit->is_primary,
                    "is_active" => (bool) $product->measureUnit->is_active,
                    "created_at" => $product->measureUnit->created_at,
                    "updated_at" => $product->measureUnit->updated_at,
                    "deleted_at" => $product->measureUnit->deleted_at,
                ] : null,

                "product_lists" => $product->productLists->map(function ($pl) {
                    return [
                        "id" => $pl->id,
                        "product_id" => $pl->product_id,
                        "measure_unit_id" => $pl->measure_unit_id,
                        "company_id" => $pl->company_id,
                        "quantity" => $pl->quantity,
                        "barcode" => $pl->barcode,
                        "price" => $pl->price,
                        "discount" => $pl->discount,
                        "final_price" => $pl->final_price,
                        "is_primary" => (bool) $pl->is_primary,
                        "primary_measure_unit_id" => $pl->primary_measure_unit_id,
                        "deleted_at" => $pl->deleted_at,
                        "created_at" => $pl->created_at,
                        "updated_at" => $pl->updated_at,
                    ];
                }),

                "product_field_values" => $product->productFieldValues ?? [],

                "measure_units" => $product->productLists->map(function ($pl) {
                    return $pl->measureUnit ? [
                        "id" => $pl->measureUnit->id,
                        "name" => $pl->measureUnit->name,
                        "measure_unit_quantity" => $pl->measureUnit->quantity,
                    ] : null;
                })->filter()->unique("id")->values(),

                "average_price" => $product->purchase_rate,
                "min_price" => $product->purchase_rate,
                "last_purchase_price" => $product->purchase_rate,

                // Add barcode from the product list if available
                "barcode" => $productList ? $productList->barcode : null,
            ];

            return response()->json([
                "message" => "Successful!!",
                "data" => $data
            ]);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }
}

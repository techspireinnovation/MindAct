<?php

namespace App\Http\Controllers;

use App\Models\ProductFieldValue;
use App\Helpers\Helper;
use App\Helpers\PurchaseReturnHelper;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\PurchaseProductFieldValue;
use App\Models\PurchaseReturnHistory;
use App\Models\SalesReturnProduct;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SalesProductFieldValue;
use App\Models\ProductList;

use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\SaleProduct;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseReturn;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReturnController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseReturn::query();
    
        if ($request->has('keywords')) {
            $query->where('ref_bill_number', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(50));
    }

    public function getPurchaseProductNames(Request $request): JsonResponse
    {
        try {
            if (!$request->has('company_id')) {
                return response()->json(['error' => 'Missing required parameter: company_id'], 422);
            }

            // Get unique product IDs with available quantities for return
            $productIds = PurchaseProduct::where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->whereRaw('
                    (
                        (purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) - 
                        COALESCE((
                            SELECT SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0))
                            FROM purchase_product_returns
                            WHERE purchase_product_returns.purchase_product_id = purchase_products.id
                            AND purchase_product_returns.deleted_at IS NULL
                        ), 0) - 
                        COALESCE((
                            SELECT SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0))
                            FROM sale_products
                            WHERE sale_products.purchase_product_id = purchase_products.id
                            AND sale_products.deleted_at IS NULL
                        ), 0) + 
                        COALESCE((
                            SELECT SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0))
                            FROM sales_return_products
                            WHERE sales_return_products.sale_product_id IN (
                                SELECT id FROM sale_products
                                WHERE sale_products.purchase_product_id = purchase_products.id
                                AND sale_products.deleted_at IS NULL
                            )
                            AND sales_return_products.deleted_at IS NULL
                        ), 0)
                    ) > 0
                ')
                ->pluck('product_id')
                ->unique()
                ->toArray();

            if (empty($productIds)) {
                return response()->json(['error' => 'No products with available quantities found'], 404);
            }

            // Get product names using the helper function
            $productNames = PurchaseReturnHelper::getPurchaseProductforPurchaseReturn($productIds, $request->company_id);

            if (isset($productNames['error'])) {
                return response()->json(['error' => $productNames['error']], 404);
            }

            return response()->json($productNames);
        } catch (QueryException $e) {
            \Log::error('Database error in getPurchaseProductNames: ' . $e->getMessage());
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getPurchaseProductNames: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function getPurchaseProductUniqueId(Request $request): JsonResponse
    {
        try {
            // Validate company_id
            if (!$request->has('company_id')) {
                return response()->json(['error' => 'Missing required parameter: company_id'], 422);
            }

            // Fetch product codes with available quantities
            $productCodes = PurchaseProduct::where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->whereRaw('
                    (
                        (purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) -
                        COALESCE((
                            SELECT SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0))
                            FROM purchase_product_returns
                            WHERE purchase_product_returns.purchase_product_id = purchase_products.id
                            AND purchase_product_returns.deleted_at IS NULL
                        ), 0) -
                        COALESCE((
                            SELECT SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0))
                            FROM sale_products
                            WHERE sale_products.purchase_product_id = purchase_products.id
                            AND sale_products.deleted_at IS NULL
                        ), 0) +
                        COALESCE((
                            SELECT SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0))
                            FROM sales_return_products
                            WHERE sales_return_products.sale_product_id IN (
                                SELECT id FROM sale_products
                                WHERE sale_products.purchase_product_id = purchase_products.id
                                AND sale_products.deleted_at IS NULL
                            )
                            AND sales_return_products.deleted_at IS NULL
                        ), 0)
                    ) > 0
                ')
                ->pluck('product_code')
                ->unique()
                ->toArray();

            // Check if no products are found
            if (empty($productCodes)) {
                return response()->json(['error' => 'No products with available quantities found'], 404);
            }

            // Get product details using the helper function
            $productDetails = PurchaseReturnHelper::getPurchaseProductforPurchaseReturnByPrductId($productCodes, $request->company_id);

            // Handle error response from helper
            if (isset($productDetails['error'])) {
                return response()->json(['error' => $productDetails['error']], 404);
            }

            return response()->json($productDetails);
        } catch (QueryException $e) {
            \Log::error('Database error in getPurchaseProductDetails: ' . $e->getMessage());
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getPurchaseProductDetails: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function getPurchaseProductBarcode(Request $request): JsonResponse
    {
        try {
            // Validate company_id
            if (!$request->has('company_id')) {
                return response()->json(['error' => 'Missing required parameter: company_id'], 422);
            }

            // Fetch product codes with available quantities
            $productIds = PurchaseProduct::where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->whereRaw('
                    (
                        (purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) -
                        COALESCE((
                            SELECT SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0))
                            FROM purchase_product_returns
                            WHERE purchase_product_returns.purchase_product_id = purchase_products.id
                            AND purchase_product_returns.deleted_at IS NULL
                        ), 0) -
                        COALESCE((
                            SELECT SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0))
                            FROM sale_products
                            WHERE sale_products.purchase_product_id = purchase_products.id
                            AND sale_products.deleted_at IS NULL
                        ), 0) +
                        COALESCE((
                            SELECT SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0))
                            FROM sales_return_products
                            WHERE sales_return_products.sale_product_id IN (
                                SELECT id FROM sale_products
                                WHERE sale_products.purchase_product_id = purchase_products.id
                                AND sale_products.deleted_at IS NULL
                            )
                            AND sales_return_products.deleted_at IS NULL
                        ), 0)
                    ) > 0
                ')
                ->pluck('product_id')
                ->unique()
                ->toArray();

            // Check if no products are found
            if (empty($productIds)) {
                return response()->json(['error' => 'No products with available quantities found'], 404);
            }

            // Get product details using the helper function
            $productDetails = PurchaseReturnHelper::getPurchaseProductforPurchaseReturnByBarcode($productIds, $request->company_id);

            // Handle error response from helper
            if (isset($productDetails['error'])) {
                return response()->json(['error' => $productDetails['error']], 404);
            }

            return response()->json($productDetails);
        } catch (QueryException $e) {
            \Log::error('Database error in getPurchaseProductDetails: ' . $e->getMessage());
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getPurchaseProductDetails: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function getProductDetailsByInput(Request $request): JsonResponse
    {
        try {
            // Validate input
            if (!$request->has('company_id')) {
                return response()->json(['error' => 'Missing required parameter: company_id'], 422);
            }

            if (!$request->hasAny(['product_code', 'product_name', 'barcode', 'purchase_bill_number'])) {
                return response()->json(['error' => 'At least one of product_code, product_name, barcode, or purchase_bill_number is required'], 422);
            }

            $companyId = $request->company_id;
            $productCode = $request->input('product_code');
            $productName = $request->input('product_name');
            $barcode = $request->input('barcode');
            $purchaseBillNumber = $request->input('purchase_bill_number');

            // Base query for PurchaseProduct with available quantities for purchase returns
            $query = PurchaseProduct::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereRaw('
                    (
                        (purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) -
                        COALESCE((
                            SELECT SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0))
                            FROM purchase_product_returns
                            WHERE purchase_product_returns.purchase_product_id = purchase_products.id
                            AND purchase_product_returns.deleted_at IS NULL
                            AND purchase_product_returns.company_id = ?
                        ), 0) -
                        COALESCE((
                            SELECT SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0))
                            FROM sale_products
                            WHERE sale_products.purchase_product_id = purchase_products.id
                            AND sale_products.deleted_at IS NULL
                            AND sale_products.company_id = ?
                        ), 0) +
                        COALESCE((
                            SELECT SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0))
                            FROM sales_return_products
                            WHERE sales_return_products.sale_product_id IN (
                                SELECT id FROM sale_products
                                WHERE sale_products.purchase_product_id = purchase_products.id
                                AND sale_products.deleted_at IS NULL
                                AND sale_products.company_id = ?
                            )
                            AND sales_return_products.deleted_at IS NULL
                            AND sales_return_products.company_id = ?
                        ), 0)
                    ) > 0
                ', [$companyId, $companyId, $companyId, $companyId]);

            // Apply filters
            if ($productCode) {
                $query->where('product_code', $productCode);
            }

            if ($productName) {
                $query->where(function ($q) use ($productName) {
                    $q->where('product_name', 'LIKE', "%{$productName}%")
                      ->orWhereHas('product', function ($q) use ($productName) {
                          $q->where('name', 'LIKE', "%{$productName}%");
                      });
                });
            }

            if ($barcode) {
                $query->whereIn('purchase_products.id', function ($subQuery) use ($barcode, $companyId) {
                    $subQuery->select('purchase_product_id')
                        ->from('purchase_product_field_values')
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->where('value', $barcode)
                        ->where('product_field_id', env('BARCODE_FIELD_ID', 1));
                });
            }

            if ($purchaseBillNumber) {
                $query->whereHas('purchase', function ($q) use ($purchaseBillNumber, $companyId) {
                    $q->where('purchase_bill_number', $purchaseBillNumber)
                      ->where('company_id', $companyId)
                      ->whereNull('deleted_at');
                });
            }

            // Ensure valid purchase relationship
            $query->whereHas('purchase', function ($q) use ($companyId) {
                $q->where('company_id', $companyId)->whereNull('deleted_at');
            });

            // Fetch purchase products with relationships
            $purchaseProducts = $query->with([
                'product' => function ($q) use ($companyId) {
                    $q->where('company_id', $companyId)->whereNull('deleted_at');
                },
                'purchase' => function ($q) use ($companyId) {
                    $q->whereNull('deleted_at')->where('company_id', $companyId);
                },
                'purchaseProductReturns' => function ($q) use ($companyId) {
                    $q->whereNull('deleted_at')->where('company_id', $companyId);
                },
                'fieldValues.productField' => function ($q) use ($companyId) {
                    $q->whereNull('deleted_at')->where('company_id', $companyId);
                },
                'saleProducts.saleProductReturns' => function ($q) use ($companyId) {
                    $q->whereNull('deleted_at')->where('company_id', $companyId);
                }
            ])->get();

            if ($purchaseProducts->isEmpty()) {
                return response()->json(['error' => 'No products found matching the criteria'], 404);
            }

            // Group products by purchase
            $purchases = $purchaseProducts->groupBy('purchase_id')->map(function ($products, $purchaseId) use ($companyId) {
                $purchase = $products->first()->purchase;

                // Skip if purchase is null
                if (is_null($purchase)) {
                    Log::warning('Null purchase found for purchase_id: ' . $purchaseId);
                    return null;
                }

                $purchaseProducts = $products->map(function ($purchaseProduct) use ($companyId) {
                    $totalPurchaseQuantity = $purchaseProduct->quantity + ($purchaseProduct->free_quantity ?? 0);
                    $totalReturned = $purchaseProduct->purchaseProductReturns->sum(fn($return) => $return->quantity + ($return->free_quantity ?? 0));
                    $totalSold = $purchaseProduct->saleProducts->sum(fn($saleProduct) => $saleProduct->quantity + ($saleProduct->free_quantity ?? 0));
                    $totalSaleReturns = $purchaseProduct->saleProducts->flatMap->saleProductReturns->sum(fn($return) => $return->quantity + ($return->free_quantity ?? 0));
                    $availableQuantity = $totalPurchaseQuantity - $totalReturned - $totalSold + $totalSaleReturns;

                    // Get unavailable quantity indices
                    $unavailableQuantityIndices = [];

                    // 1. Purchase-returned units
                    if ($purchaseProduct->purchaseProductReturns->isNotEmpty()) {
                        $returnIds = $purchaseProduct->purchaseProductReturns->pluck('id');
                        $unavailableQuantityIndices = array_merge(
                            $unavailableQuantityIndices,
                            PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $returnIds)
                                ->whereNull('deleted_at')
                                ->pluck('quantity_index')
                                ->toArray()
                        );
                    }

                    // 2. Sold units
                    $soldQuantityIndices = SalesProductFieldValue::whereIn('sale_product_id', 
                        $purchaseProduct->saleProducts->pluck('id'))
                        ->whereNull('deleted_at')
                        ->pluck('quantity_index')
                        ->toArray();
                    $unavailableQuantityIndices = array_merge($unavailableQuantityIndices, $soldQuantityIndices);

                    // 3. Sales-returned units
                    $saleReturnFieldValues = [];
                    $saleReturnedIndices = [];
                    if ($totalSaleReturns > 0) {
                        $saleReturnFieldValues = SaleReturnProductFieldValue::whereIn('sale_return_product_id', 
                            SalesReturnProduct::whereIn('sale_product_id', 
                                $purchaseProduct->saleProducts->pluck('id'))
                                ->whereNull('deleted_at')
                                ->pluck('id'))
                            ->whereNull('deleted_at')
                            ->get()
                            ->groupBy('quantity_index')
                            ->map(function ($group) {
                                return $group->map(function ($field) {
                                    return [
                                        'product_field_id' => $field->product_field_id,
                                        'value' => $field->value,
                                        'name' => $field->productField->name ?? '',
                                        'quantity_index' => $field->quantity_index
                                    ];
                                })->toArray();
                            })->toArray();
                        
                        $saleReturnedIndices = array_keys($saleReturnFieldValues);
                        $unavailableQuantityIndices = array_diff(
                            array_unique($unavailableQuantityIndices),
                            $saleReturnedIndices
                        );
                    }

                    // Group only available field values
                    $groupedFieldValues = [];
                    foreach ($purchaseProduct->fieldValues as $fieldValue) {
                        $quantityIndex = $fieldValue->quantity_index;
                        if (!in_array($quantityIndex, $unavailableQuantityIndices)) {
                            if (!isset($groupedFieldValues[$quantityIndex])) {
                                $groupedFieldValues[$quantityIndex] = [];
                            }
                            $groupedFieldValues[$quantityIndex][] = [
                                'product_field_id' => $fieldValue->product_field_id,
                                'name' => $fieldValue->productField->name ?? null,
                                'quantity_index' => $quantityIndex,
                                'value' => $fieldValue->value
                            ];
                        }
                    }

                    // Include sales-returned field values
                    foreach ($saleReturnedIndices as $quantityIndex) {
                        if (isset($saleReturnFieldValues[$quantityIndex]) && !isset($groupedFieldValues[$quantityIndex])) {
                            $groupedFieldValues[$quantityIndex] = $saleReturnFieldValues[$quantityIndex];
                        }
                    }

                    $barcode = $purchaseProduct->fieldValues
                        ->where('product_field_id', env('BARCODE_FIELD_ID', 1))
                        ->first()?->value;

                    return [
                        'id' => $purchaseProduct->id,
                        'product_id' => $purchaseProduct->product_id,
                        'purchase_code' => $purchaseProduct->purchase_code,
                        'product_code' => $purchaseProduct->product_code,
                        'product_name' => $purchaseProduct->product_name ?? ($purchaseProduct->product->name ?? 'N/A'),
                        'barcode' => $barcode,
                        'available_quantity' => $availableQuantity,
                        'field_values' => array_values($groupedFieldValues),
                        'quantity' => $purchaseProduct->quantity,
                        'free_quantity' => $purchaseProduct->free_quantity ?? 0,
                        'price' => $purchaseProduct->price,
                        'discount_percent' => $purchaseProduct->discount_percent,
                        'discount_amount' => $purchaseProduct->discount_amount,
                        'amount' => $purchaseProduct->amount,
                        'is_vatable' => (bool) $purchaseProduct->is_vatable,
                        'measure_unit_id' => $purchaseProduct->measure_unit_id,
                        'expiry_date' => $purchaseProduct->expiry_date,
                        'customer_id' => $purchaseProduct->customer_id,
                        'created_at' => $purchaseProduct->created_at ? $purchaseProduct->created_at->toIso8601String() : null,
                        'updated_at' => $purchaseProduct->updated_at ? $purchaseProduct->updated_at->toIso8601String() : null,
                        'deleted_at' => $purchaseProduct->deleted_at ? $purchaseProduct->deleted_at->toIso8601String() : null,
                        'remaining_quantity' => $availableQuantity
                    ];
                })->values();

                return [
                    'id' => $purchase->id,
                    'bank_id' => $purchase->bank_id,
                    'company_id' => $purchase->company_id,
                    'customer_id' => $purchase->customer_id,
                    'customer_name' => $purchase->customer_name,
                    'pan_number' => $purchase->pan_number,
                    'balance' => $purchase->balance,
                    'batch_no' => $purchase->batch_no,
                    'ref_bill_number' => $purchase->ref_bill_number,
                    'document_number' => $purchase->document_number,
                    'address' => $purchase->address,
                    'customer_contact' => $purchase->customer_contact,
                    'invoice_date' => $purchase->invoice_date,
                    'purchase_bill_number' => $purchase->purchase_bill_number,
                    'remarks' => $purchase->remarks,
                    'store_id' => $purchase->store_id,
                    'location_id' => $purchase->location_id,
                    'discount_type' => $purchase->discount_type,
                    'discount_value' => $purchase->discount_value,
                    'sub_total_before_discount' => $purchase->sub_total_before_discount,
                    'taxable_amount' => $purchase->taxable_amount,
                    'non_taxable_amount' => $purchase->non_taxable_amount,
                    'excise_duty' => $purchase->excise_duty,
                    'vat_percent' => $purchase->vat_percent,
                    'health_insurance' => $purchase->health_insurance,
                    'freight_amount' => $purchase->freight_amount,
                    'discount_after_vat' => $purchase->discount_after_vat,
                    'roundoff_amount' => $purchase->roundoff_amount,
                    'roundoff_type' => $purchase->roundoff_type,
                    'total_amount' => $purchase->total_amount,
                    'payment' => [
                        'cash' => $purchase->payment['cash'] ?? 0,
                        'credit' => $purchase->payment['credit'] ?? 0,
                        'bank' => $purchase->payment['bank'] ?? 0
                    ],
                    'created_at' => $purchase->created_at ? $purchase->created_at->toIso8601String() : null,
                    'updated_at' => $purchase->updated_at ? $purchase->updated_at->toIso8601String() : null,
                    'deleted_at' => $purchase->deleted_at ? $purchase->deleted_at->toIso8601String() : null,
                    'purchase_products' => $purchaseProducts
                ];
            })->filter()->values()->toArray();

            if (empty($purchases)) {
                return response()->json(['error' => 'No valid purchases found for the matching products'], 404);
            }

            return response()->json(['data' => $purchases]);
        } catch (ModelNotFoundException $e) {
            Log::error('Model not found in getProductDetailsByInput: ' . $e->getMessage());
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database error in getProductDetailsByInput: ' . $e->getMessage());
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in getProductDetailsByInput: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }


   public function storePurchaseReturnByInput(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer|exists:companies,id',
                'customer_id' => 'required|integer|exists:customers,id',
                'customer_name' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                'address' => 'nullable|string|max:255',
                'customer_contact' => 'nullable|string|max:255',
                'purchase_number' => 'nullable|string|max:255',
                'purchase_bill_numbers' => 'nullable|array',
                'purchase_bill_numbers.*' => 'nullable|string|max:255',
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|date',
                'remarks' => 'nullable|string|max:255',
                'reason' => 'required|string|in:damaged,defective,incorrect,expired,other',
                'store_id' => 'required|integer|exists:stores,id',
                'location_id' => 'required|integer|exists:locations,id',
                'balance' => 'nullable|numeric',
                'discount_type' => 'nullable|in:percent,amount',
                'discount_value' => 'nullable|numeric|min:0',
                'sub_total_before_discount' => 'nullable|numeric|min:0',
                'non_taxable_amount' => 'nullable|numeric|min:0',
                'taxable_amount' => 'nullable|numeric|min:0',
                'excise_duty' => 'nullable|numeric|min:0',
                'vat_percent' => 'nullable|numeric',
                'health_insurance' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'discount_after_vat' => 'nullable|numeric|min:0',
                'roundoff_amount' => 'nullable|numeric',
                'roundoff_type' => 'nullable|string|max:255',
                'total_amount' => 'nullable|numeric|min:0',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'product_code' => 'nullable|string',
                'product_name' => 'nullable|string',
                'barcode' => 'nullable|string',
                'return_entire_batch' => 'nullable|boolean',
                'purchase_return_products' => [
                    'required_unless:return_entire_batch,true',
                    'array',
                    'min:1',
                    function ($attribute, $value, $fail) use ($request) {
                        if (!$request->hasAny(['product_code', 'product_name', 'barcode']) && empty($value) && !($request->input('return_entire_batch') ?? false)) {
                            $fail('At least one of product_code, product_name, barcode, or purchase_return_products is required.');
                        }
                    },
                ],
                'purchase_return_products.*.purchase_product_id' => 'required|integer|exists:purchase_products,id',
                'purchase_return_products.*.product_id' => 'required|integer|exists:products,id',
                'purchase_return_products.*.product_name' => 'required|string|max:255',
                'purchase_return_products.*.purchase_product_code' => 'nullable|string|max:255',
                'purchase_return_products.*.mfd' => 'nullable|string|max:255',
                'purchase_return_products.*.customer_id' => 'nullable|integer|exists:customers,id',
                'purchase_return_products.*.quantity' => 'required|numeric|min:0',
                'purchase_return_products.*.free_quantity' => 'nullable|numeric|min:0',
                'purchase_return_products.*.price' => 'nullable|numeric|min:0',
                'purchase_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'purchase_return_products.*.discount_amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.is_vatable' => 'required|boolean',
                'purchase_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'purchase_return_products.*.expiry_date' => 'nullable|date',
                'purchase_return_products.*.field_values' => 'nullable|array',
                'purchase_return_products.*.field_values.*' => 'array|min:1',
                'purchase_return_products.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
                'purchase_return_products.*.field_values.*.*.value' => 'required|string|max:255',
                'purchase_return_products.*.field_values.*.*.quantity_index' => 'nullable|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $validated = $validator->validated();

            // Fetch purchase products to validate
            $purchaseProductIds = array_column($validated['purchase_return_products'] ?? [], 'purchase_product_id');
            $purchaseProducts = PurchaseProduct::whereIn('id', $purchaseProductIds)
                ->with([
                    'purchase' => function ($q) use ($validated) {
                        $q->where('company_id', $validated['company_id'])->whereNull('deleted_at');
                    },
                    'purchaseProductReturns' => function ($q) use ($validated) {
                        $q->whereNull('deleted_at')->where('company_id', $validated['company_id']);
                    },
                    'fieldValues' => function ($q) use ($validated) {
                        $q->whereNull('deleted_at')->where('company_id', $validated['company_id']);
                    },
                ])
                ->get();

            if ($purchaseProducts->isEmpty() && !($validated['return_entire_batch'] ?? false)) {
                return response()->json(['error' => 'No valid purchase products found'], 404);
            }

            // Validate purchase bill numbers for each purchase
            $purchaseIds = $purchaseProducts->pluck('purchase_id')->unique()->toArray();
            $purchases = Purchase::whereIn('id', $purchaseIds)->get()->keyBy('id');
            if (isset($validated['purchase_bill_numbers'])) {
                foreach ($validated['purchase_bill_numbers'] as $purchaseId => $billNumber) {
                    if (!isset($purchases[$purchaseId]) || ($billNumber && $billNumber !== $purchases[$purchaseId]->purchase_bill_number)) {
                        return response()->json(['error' => "Purchase bill number does not match for purchase ID {$purchaseId}"], 422);
                    }
                }
            }

            // Auto-fetch products for entire batch if specified
            if ($validated['return_entire_batch'] ?? false) {
                $validated['purchase_return_products'] = [];
                foreach ($purchases as $purchase) {
                    $products = $purchase->purchaseProducts()->get()->map(function ($product) {
                        $totalReturned = PurchaseProductReturn::where('purchase_product_id', $product->id)
                            ->whereNull('deleted_at')
                            ->sum('quantity');
                        $totalFreeReturned = PurchaseProductReturn::where('purchase_product_id', $product->id)
                            ->whereNull('deleted_at')
                            ->sum('free_quantity');
                        $quantityToReturn = $product->quantity - $totalReturned;
                        $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                            return $group->map(function ($field) {
                                return [
                                    'product_field_id' => $field->product_field_id,
                                    'value' => $field->value,
                                    'quantity_index' => $field->quantity_index
                                ];
                            })->toArray();
                        })->values()->toArray();
                        // Only include field_values for available units
                        $fieldValues = array_slice($fieldValues, 0, $quantityToReturn);
                        return [
                            'purchase_product_id' => $product->id,
                            'product_id' => $product->product_id,
                            'product_name' => $product->product_name,
                            'purchase_product_code' => $product->product_code,
                            'mfd' => $product->mfd,
                            'customer_id' => $product->customer_id,
                            'quantity' => $quantityToReturn,
                            'free_quantity' => $product->free_quantity - $totalFreeReturned,
                            'price' => $product->price,
                            'discount_percent' => $product->discount_percent,
                            'discount_amount' => $product->discount_amount,
                            'amount' => $product->amount,
                            'is_vatable' => $product->is_vatable,
                            'measure_unit_id' => $product->measure_unit_id,
                            'expiry_date' => $product->expiry_date,
                            'field_values' => $fieldValues
                        ];
                    })->filter(function ($product) {
                        return $product['quantity'] > 0 || $product['free_quantity'] > 0;
                    })->toArray();
                    $validated['purchase_return_products'] = array_merge($validated['purchase_return_products'], $products);
                }
                // Update purchaseProducts with new data
                $purchaseProductIds = array_column($validated['purchase_return_products'], 'purchase_product_id');
                $purchaseProducts = PurchaseProduct::whereIn('id', $purchaseProductIds)
                    ->with(['purchase', 'purchaseProductReturns', 'fieldValues'])
                    ->get();
            }

            // Validate quantities and field values
            foreach ($validated['purchase_return_products'] as $index => $productData) {
                $originalProduct = $purchaseProducts->firstWhere('id', $productData['purchase_product_id']);
                if (!$originalProduct) {
                    return response()->json(['error' => "Purchase product ID {$productData['purchase_product_id']} not found at index {$index}"], 404);
                }

                // Calculate available quantity
                $totalPurchaseQuantity = $originalProduct->quantity + ($originalProduct->free_quantity ?? 0);
                $totalReturned = $originalProduct->purchaseProductReturns->sum(fn($r) => $r->quantity + ($r->free_quantity ?? 0));
                $availableQuantity = $totalPurchaseQuantity - $totalReturned;

                // Check quantity
                if ($productData['quantity'] > $availableQuantity) {
                    return response()->json([
                        'error' => "Return quantity {$productData['quantity']} exceeds available quantity {$availableQuantity} for product ID {$productData['product_id']} at index {$index}"
                    ], 422);
                }

                // Check free quantity
                $totalFreeReturned = $originalProduct->purchaseProductReturns->sum('free_quantity');
                $availableFreeQuantity = ($originalProduct->free_quantity ?? 0) - $totalFreeReturned;
                if (($productData['free_quantity'] ?? 0) > $availableFreeQuantity) {
                    return response()->json([
                        'error' => "Free return quantity {$productData['free_quantity']} exceeds available free quantity {$availableFreeQuantity} for product ID {$productData['product_id']} at index {$index}"
                    ], 422);
                }

                // Validate field values if present
                if (isset($productData['field_values']) && !empty($productData['field_values']) && !($validated['return_entire_batch'] ?? false)) {
                    $hasFieldValues = $originalProduct->fieldValues->isNotEmpty();
                    $requiredFieldValues = $hasFieldValues ? $productData['quantity'] : 0;
                    if ($hasFieldValues && count($productData['field_values']) !== $requiredFieldValues) {
                        return response()->json([
                            'error' => "Field values count (" . count($productData['field_values']) . ") must match quantity ({$productData['quantity']}) for product ID {$productData['product_id']} at index {$index}"
                        ], 422);
                    }

                    // Get unavailable quantity indices
                    $unavailableQuantityIndices = [];
                    if ($originalProduct->purchaseProductReturns->isNotEmpty()) {
                        $returnIds = $originalProduct->purchaseProductReturns->pluck('id');
                        $unavailableQuantityIndices = array_merge(
                            $unavailableQuantityIndices,
                            PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $returnIds)
                                ->whereNull('deleted_at')
                                ->pluck('quantity_index')
                                ->toArray()
                        );
                    }

                    // Validate field values
                    $existingFieldValues = $originalProduct->fieldValues
                        ->groupBy('quantity_index')
                        ->map(function ($group) {
                            return $group->pluck('value', 'product_field_id')->toArray();
                        })->toArray();

                    foreach ($productData['field_values'] as $arrayIndex => $fieldValueSet) {
                        $quantityIndex = isset($fieldValueSet[0]['quantity_index']) ? $fieldValueSet[0]['quantity_index'] : $arrayIndex;
                        if (in_array($quantityIndex, $unavailableQuantityIndices) || !isset($existingFieldValues[$quantityIndex])) {
                            return response()->json([
                                'error' => "Invalid or already returned quantity_index {$quantityIndex} for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                        $providedFields = array_column($fieldValueSet, 'value', 'product_field_id');
                        if ($providedFields != $existingFieldValues[$quantityIndex]) {
                            return response()->json([
                                'error' => "Field values for quantity_index {$quantityIndex} do not match existing values for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                        foreach ($fieldValueSet as $fieldValue) {
                            if (isset($fieldValue['quantity_index']) && $fieldValue['quantity_index'] !== $quantityIndex) {
                                return response()->json([
                                    'error' => "Inconsistent quantity_index in field_values set {$arrayIndex} for product ID {$productData['product_id']} at index {$index}"
                                ], 422);
                            }
                        }
                    }

                    // Validate field value uniqueness
                    foreach ($productData['field_values'] as $setIndex => $fieldValueSet) {
                        $fieldIds = array_column($fieldValueSet, 'product_field_id');
                        if (count($fieldIds) !== count(array_unique($fieldIds))) {
                            return response()->json([
                                'error' => "Duplicate product_field_id found in field_values set {$setIndex} for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                    }
                }
            }

            Log::debug('Validated purchase return data', [
                'purchase_ids' => $purchaseIds,
                'purchase_return_products' => $validated['purchase_return_products'],
            ]);

            $items = DB::transaction(function () use ($validated, $purchases, $purchaseProducts) {
                $purchaseReturnData = array_filter($validated, function ($key) {
                    return !in_array($key, ['purchase_return_products', 'product_code', 'product_name', 'barcode', 'purchase_bill_numbers', 'return_entire_batch']);
                }, ARRAY_FILTER_USE_KEY);

                // Group products by purchase_id
                $productsByPurchase = collect($validated['purchase_return_products'])->groupBy(function ($product) use ($purchaseProducts) {
                    return $purchaseProducts->firstWhere('id', $product['purchase_product_id'])->purchase_id;
                });

                $createdItems = [];
                foreach ($productsByPurchase as $purchaseId => $products) {
                    $purchase = $purchases[$purchaseId];
                    $itemData = array_merge($purchaseReturnData, ['purchase_id' => $purchaseId]);
                    $item = PurchaseReturn::create($itemData);

                    $returnValue = 0;
                    foreach ($products as $productData) {
                        $productDataFiltered = array_filter($productData, function ($key) {
                            return $key !== 'field_values';
                        }, ARRAY_FILTER_USE_KEY);

                        $purchaseProductReturn = $item->purchaseReturnProducts()->create(
                            array_merge($productDataFiltered, [
                                'company_id' => $item->company_id,
                            ])
                        );

                        if (isset($productData['field_values']) && !empty($productData['field_values'])) {
                            $fieldValues = [];
                            foreach ($productData['field_values'] as $arrayIndex => $fieldValueSet) {
                                $quantityIndex = isset($fieldValueSet[0]['quantity_index']) ? $fieldValueSet[0]['quantity_index'] : $arrayIndex;
                                foreach ($fieldValueSet as $fieldValue) {
                                    $fieldValues[] = [
                                        'purchase_return_product_id' => $purchaseProductReturn->id,
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'value' => $fieldValue['value'],
                                        'product_id' => $purchaseProductReturn->product_id,
                                        'company_id' => $purchaseProductReturn->company_id,
                                        'quantity_index' => $quantityIndex,
                                        'created_at' => now(),
                                        'updated_at' => now()
                                    ];
                                }
                            }
                            Log::debug('Recording PurchaseReturnProductFieldValue', ['field_values' => $fieldValues]);
                            PurchaseReturnProductFieldValue::insert($fieldValues);
                        }

                        $returnValue += ($productData['quantity'] * ($productData['price'] ?? 0)) - ($productData['discount_amount'] ?? 0);
                    }

                    $purchase->balance -= $returnValue;
                    $purchase->save();

                    PurchaseReturnHistory::create([
                        'purchase_return_id' => $item->id,
                        'action' => 'created',
                        'data' => array_merge($itemData, ['purchase_return_products' => $products->toArray()])
                    ]);

                    $createdItems[] = $item->load(['purchaseReturnProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index')->orderBy('product_field_id');
                    }]);
                }

                return $createdItems;
            });

            return response()->json([
                'message' => 'Purchase Returns Created Successfully',
                'data' => $items
            ], 201);
        } catch (ModelNotFoundException $e) {
            Log::error('Purchase or related record not found: ' . $e->getMessage());
            return response()->json(['error' => 'Purchase or related record not found'], 404);
        } catch (\Exception $e) {
            Log::error('Error creating purchase return: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    public function updatePurchaseReturnByInput(Request $request, $id): JsonResponse
    {
        try {
            // Find the PurchaseReturn
            $purchaseReturn = PurchaseReturn::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer|exists:companies,id',
                'customer_id' => 'required|integer|exists:customers,id',
                'customer_name' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                'address' => 'nullable|string|max:255',
                'customer_contact' => 'nullable|string|max:255',
                'purchase_number' => 'nullable|string|max:255',
                'purchase_bill_numbers' => 'nullable|array',
                'purchase_bill_numbers.*' => 'nullable|string|max:255',
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|date',
                'remarks' => 'nullable|string|max:255',
                'reason' => 'required|string|in:damaged,defective,incorrect,expired,other',
                'store_id' => 'required|integer|exists:stores,id',
                'location_id' => 'required|integer|exists:locations,id',
                'balance' => 'nullable|numeric',
                'discount_type' => 'nullable|in:percent,amount',
                'discount_value' => 'nullable|numeric|min:0',
                'sub_total_before_discount' => 'nullable|numeric|min:0',
                'non_taxable_amount' => 'nullable|numeric|min:0',
                'taxable_amount' => 'nullable|numeric|min:0',
                'excise_duty' => 'nullable|numeric|min:0',
                'vat_percent' => 'nullable|numeric',
                'health_insurance' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'discount_after_vat' => 'nullable|numeric|min:0',
                'roundoff_amount' => 'nullable|numeric',
                'roundoff_type' => 'nullable|string|max:255',
                'total_amount' => 'nullable|numeric|min:0',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'product_code' => 'nullable|string',
                'product_name' => 'nullable|string',
                'barcode' => 'nullable|string',
                'return_entire_batch' => 'nullable|boolean',
                'purchase_return_products' => [
                    'required_unless:return_entire_batch,true',
                    'array',
                    'min:1',
                    function ($attribute, $value, $fail) use ($request) {
                        if (!$request->hasAny(['product_code', 'product_name', 'barcode']) && empty($value) && !($request->input('return_entire_batch') ?? false)) {
                            $fail('At least one of product_code, product_name, barcode, or purchase_return_products is required.');
                        }
                    },
                ],
                'purchase_return_products.*.purchase_product_id' => 'required|integer|exists:purchase_products,id',
                'purchase_return_products.*.product_id' => 'required|integer|exists:products,id',
                'purchase_return_products.*.product_name' => 'required|string|max:255',
                'purchase_return_products.*.purchase_product_code' => 'nullable|string|max:255',
                'purchase_return_products.*.mfd' => 'nullable|string|max:255',
                'purchase_return_products.*.customer_id' => 'nullable|integer|exists:customers,id',
                'purchase_return_products.*.quantity' => 'required|numeric|min:0',
                'purchase_return_products.*.free_quantity' => 'nullable|numeric|min:0',
                'purchase_return_products.*.price' => 'nullable|numeric|min:0',
                'purchase_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'purchase_return_products.*.discount_amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.is_vatable' => 'required|boolean',
                'purchase_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'purchase_return_products.*.expiry_date' => 'nullable|date',
                'purchase_return_products.*.field_values' => 'nullable|array',
                'purchase_return_products.*.field_values.*' => 'array|min:1',
                'purchase_return_products.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
                'purchase_return_products.*.field_values.*.*.value' => 'required|string|max:255',
                'purchase_return_products.*.field_values.*.*.quantity_index' => 'nullable|integer|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $validated = $validator->validated();

            // Fetch purchase products to validate
            $purchaseProductIds = array_column($validated['purchase_return_products'] ?? [], 'purchase_product_id');
            $purchaseProducts = PurchaseProduct::whereIn('id', $purchaseProductIds)
                ->with([
                    'purchase' => function ($q) use ($validated) {
                        $q->where('company_id', $validated['company_id'])->whereNull('deleted_at');
                    },
                    'purchaseProductReturns' => function ($q) use ($validated, $id) {
                        $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('purchase_return_id', '!=', $id);
                    },
                    'fieldValues' => function ($q) use ($validated) {
                        $q->whereNull('deleted_at')->where('company_id', $validated['company_id']);
                    },
                ])
                ->get();

            if ($purchaseProducts->isEmpty() && !($validated['return_entire_batch'] ?? false)) {
                return response()->json(['error' => 'No valid purchase products found'], 404);
            }

            // Validate purchase bill numbers for each purchase
            $purchaseIds = $purchaseProducts->pluck('purchase_id')->unique()->toArray();
            $purchases = Purchase::whereIn('id', $purchaseIds)->get()->keyBy('id');
            if (isset($validated['purchase_bill_numbers'])) {
                foreach ($validated['purchase_bill_numbers'] as $purchaseId => $billNumber) {
                    if (!isset($purchases[$purchaseId]) || ($billNumber && $billNumber !== $purchases[$purchaseId]->purchase_bill_number)) {
                        return response()->json(['error' => "Purchase bill number does not match for purchase ID {$purchaseId}"], 422);
                    }
                }
            }

            // Auto-fetch products for entire batch if specified
            if ($validated['return_entire_batch'] ?? false) {
                $validated['purchase_return_products'] = [];
                foreach ($purchases as $purchase) {
                    $products = $purchase->purchaseProducts()->get()->map(function ($product) use ($id) {
                        $totalReturned = PurchaseProductReturn::where('purchase_product_id', $product->id)
                            ->whereNull('deleted_at')
                            ->where('purchase_return_id', '!=', $id)
                            ->sum('quantity');
                        $totalFreeReturned = PurchaseProductReturn::where('purchase_product_id', $product->id)
                            ->whereNull('deleted_at')
                            ->where('purchase_return_id', '!=', $id)
                            ->sum('free_quantity');
                        $quantityToReturn = $product->quantity - $totalReturned;
                        $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                            return $group->map(function ($field) {
                                return [
                                    'product_field_id' => $field->product_field_id,
                                    'value' => $field->value,
                                    'quantity_index' => $field->quantity_index
                                ];
                            })->toArray();
                        })->values()->toArray();
                        $fieldValues = array_slice($fieldValues, 0, $quantityToReturn);
                        return [
                            'purchase_product_id' => $product->id,
                            'product_id' => $product->product_id,
                            'product_name' => $product->product_name,
                            'purchase_product_code' => $product->product_code,
                            'mfd' => $product->mfd,
                            'customer_id' => $product->customer_id,
                            'quantity' => $quantityToReturn,
                            'free_quantity' => $product->free_quantity - $totalFreeReturned,
                            'price' => $product->price,
                            'discount_percent' => $product->discount_percent,
                            'discount_amount' => $product->discount_amount,
                            'amount' => $product->amount,
                            'is_vatable' => $product->is_vatable,
                            'measure_unit_id' => $product->measure_unit_id,
                            'expiry_date' => $product->expiry_date,
                            'field_values' => $fieldValues
                        ];
                    })->filter(function ($product) {
                        return $product['quantity'] > 0 || $product['free_quantity'] > 0;
                    })->toArray();
                    $validated['purchase_return_products'] = array_merge($validated['purchase_return_products'], $products);
                }
                $purchaseProductIds = array_column($validated['purchase_return_products'], 'purchase_product_id');
                $purchaseProducts = PurchaseProduct::whereIn('id', $purchaseProductIds)
                    ->with(['purchase', 'purchaseProductReturns' => function ($q) use ($validated, $id) {
                        $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('purchase_return_id', '!=', $id);
                    }, 'fieldValues'])
                    ->get();
            }

            // Validate quantities and field values
            foreach ($validated['purchase_return_products'] as $index => $productData) {
                $originalProduct = $purchaseProducts->firstWhere('id', $productData['purchase_product_id']);
                if (!$originalProduct) {
                    return response()->json(['error' => "Purchase product ID {$productData['purchase_product_id']} not found at index {$index}"], 404);
                }

                // Calculate available quantity excluding current return
                $totalPurchaseQuantity = $originalProduct->quantity + ($originalProduct->free_quantity ?? 0);
                $totalReturned = $originalProduct->purchaseProductReturns->sum(fn($r) => $r->quantity + ($r->free_quantity ?? 0));
                $availableQuantity = $totalPurchaseQuantity - $totalReturned;

                if ($productData['quantity'] > $availableQuantity) {
                    return response()->json([
                        'error' => "Return quantity {$productData['quantity']} exceeds available quantity {$availableQuantity} for product ID {$productData['product_id']} at index {$index}"
                    ], 422);
                }

                $totalFreeReturned = $originalProduct->purchaseProductReturns->sum('free_quantity');
                $availableFreeQuantity = ($originalProduct->free_quantity ?? 0) - $totalFreeReturned;
                if (($productData['free_quantity'] ?? 0) > $availableFreeQuantity) {
                    return response()->json([
                        'error' => "Free return quantity {$productData['free_quantity']} exceeds available free quantity {$availableFreeQuantity} for product ID {$productData['product_id']} at index {$index}"
                    ], 422);
                }

                // Validate field values if present
                if (isset($productData['field_values']) && !empty($productData['field_values']) && !($validated['return_entire_batch'] ?? false)) {
                    $hasFieldValues = $originalProduct->fieldValues->isNotEmpty();
                    $requiredFieldValues = $hasFieldValues ? $productData['quantity'] : 0;
                    if ($hasFieldValues && count($productData['field_values']) !== $requiredFieldValues) {
                        return response()->json([
                            'error' => "Field values count (" . count($productData['field_values']) . ") must match quantity ({$productData['quantity']}) for product ID {$productData['product_id']} at index {$index}"
                        ], 422);
                    }

                    // Get unavailable quantity indices excluding current return
                    $unavailableQuantityIndices = [];
                    if ($originalProduct->purchaseProductReturns->isNotEmpty()) {
                        $returnIds = $originalProduct->purchaseProductReturns->pluck('id');
                        $unavailableQuantityIndices = array_merge(
                            $unavailableQuantityIndices,
                            PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $returnIds)
                                ->whereNull('deleted_at')
                                ->pluck('quantity_index')
                                ->toArray()
                        );
                    }

                    $existingFieldValues = $originalProduct->fieldValues
                        ->groupBy('quantity_index')
                        ->map(function ($group) {
                            return $group->pluck('value', 'product_field_id')->toArray();
                        })->toArray();

                    foreach ($productData['field_values'] as $arrayIndex => $fieldValueSet) {
                        $quantityIndex = isset($fieldValueSet[0]['quantity_index']) ? $fieldValueSet[0]['quantity_index'] : $arrayIndex;
                        if (in_array($quantityIndex, $unavailableQuantityIndices) || !isset($existingFieldValues[$quantityIndex])) {
                            return response()->json([
                                'error' => "Invalid or already returned quantity_index {$quantityIndex} for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                        $providedFields = array_column($fieldValueSet, 'value', 'product_field_id');
                        if ($providedFields != $existingFieldValues[$quantityIndex]) {
                            return response()->json([
                                'error' => "Field values for quantity_index {$quantityIndex} do not match existing values for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                        foreach ($fieldValueSet as $fieldValue) {
                            if (isset($fieldValue['quantity_index']) && $fieldValue['quantity_index'] !== $quantityIndex) {
                                return response()->json([
                                    'error' => "Inconsistent quantity_index in field_values set {$arrayIndex} for product ID {$productData['product_id']} at index {$index}"
                                ], 422);
                            }
                        }
                    }

                    foreach ($productData['field_values'] as $setIndex => $fieldValueSet) {
                        $fieldIds = array_column($fieldValueSet, 'product_field_id');
                        if (count($fieldIds) !== count(array_unique($fieldIds))) {
                            return response()->json([
                                'error' => "Duplicate product_field_id found in field_values set {$setIndex} for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                    }
                }
            }

            Log::debug('Validated purchase return update data', [
                'purchase_ids' => $purchaseIds,
                'purchase_return_products' => $validated['purchase_return_products'],
            ]);

            $items = DB::transaction(function () use ($validated, $purchases, $purchaseProducts, $purchaseReturn) {
                $purchaseReturnData = array_filter($validated, function ($key) {
                    return !in_array($key, ['purchase_return_products', 'product_code', 'product_name', 'barcode', 'purchase_bill_numbers', 'return_entire_batch']);
                }, ARRAY_FILTER_USE_KEY);

                // Group products by purchase_id
                $productsByPurchase = collect($validated['purchase_return_products'])->groupBy(function ($product) use ($purchaseProducts) {
                    return $purchaseProducts->firstWhere('id', $product['purchase_product_id'])->purchase_id;
                });

                // Calculate previous return value to reverse
                $previousReturnValue = $purchaseReturn->purchaseReturnProducts->sum(function ($product) {
                    return ($product->quantity * ($product->price ?? 0)) - ($product->discount_amount ?? 0);
                });

                // Restore previous balance
                $originalPurchase = Purchase::findOrFail($purchaseReturn->purchase_id);
                $originalPurchase->balance += $previousReturnValue;
                $originalPurchase->save();

                // Delete existing products and field values
                $purchaseReturn->purchaseReturnProducts()->each(function ($product) {
                    $product->fieldValues()->delete();
                    $product->delete();
                });

                // Update PurchaseReturn
                $purchaseReturn->update(array_merge($purchaseReturnData, ['purchase_id' => reset($purchaseIds)]));

                $createdItems = [];
                $newReturnValue = 0;
                foreach ($productsByPurchase as $purchaseId => $products) {
                    $purchase = $purchases[$purchaseId];
                    $itemData = array_merge($purchaseReturnData, ['purchase_id' => $purchaseId]);
                    if ($purchaseId != $purchaseReturn->purchase_id) {
                        $item = PurchaseReturn::create($itemData);
                    } else {
                        $item = $purchaseReturn;
                    }

                    foreach ($products as $productData) {
                        $productDataFiltered = array_filter($productData, function ($key) {
                            return $key !== 'field_values';
                        }, ARRAY_FILTER_USE_KEY);

                        $purchaseProductReturn = $item->purchaseReturnProducts()->create(
                            array_merge($productDataFiltered, [
                                'company_id' => $item->company_id,
                            ])
                        );

                        if (isset($productData['field_values']) && !empty($productData['field_values'])) {
                            $fieldValues = [];
                            foreach ($productData['field_values'] as $arrayIndex => $fieldValueSet) {
                                $quantityIndex = isset($fieldValueSet[0]['quantity_index']) ? $fieldValueSet[0]['quantity_index'] : $arrayIndex;
                                foreach ($fieldValueSet as $fieldValue) {
                                    $fieldValues[] = [
                                        'purchase_return_product_id' => $purchaseProductReturn->id,
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'value' => $fieldValue['value'],
                                        'product_id' => $purchaseProductReturn->product_id,
                                        'company_id' => $purchaseProductReturn->company_id,
                                        'quantity_index' => $quantityIndex,
                                        'created_at' => now(),
                                        'updated_at' => now()
                                    ];
                                }
                            }
                            Log::debug('Recording PurchaseReturnProductFieldValue', ['field_values' => $fieldValues]);
                            PurchaseReturnProductFieldValue::insert($fieldValues);
                        }

                        $newReturnValue += ($productData['quantity'] * ($productData['price'] ?? 0)) - ($productData['discount_amount'] ?? 0);
                    }

                    $purchase->balance -= $newReturnValue;
                    $purchase->save();

                    PurchaseReturnHistory::create([
                        'purchase_return_id' => $item->id,
                        'action' => 'updated',
                        'data' => array_merge($itemData, ['purchase_return_products' => $products->toArray()])
                    ]);

                    $createdItems[] = $item->load(['purchaseReturnProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index')->orderBy('product_field_id');
                    }]);
                }

                return $createdItems;
            });

            return response()->json([
                'message' => 'Purchase Return Updated Successfully',
                'data' => $items
            ], 200);
        } catch (ModelNotFoundException $e) {
            Log::error('Purchase return or related record not found: ' . $e->getMessage());
            return response()->json(['error' => 'Purchase return or related record not found'], 404);
        } catch (\Exception $e) {
            Log::error('Error updating purchase return: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }




   


    public function getAllPurchaseProductDetailsByName(Request $request):JsonResponse
    {
        try{


        }catch(ModelNotFoundException $e){
            return resoponse()->json(["error"=>"Item not Found!!"],404);
        }catch(QueryException $e){
            return response()->json(["error"=>"Database error occurred !!"],500);
        }catch(\Exception $e){
            return response()->json(["error"=>"An unexpected error occurred !!"],500);
        }

    }



    public function getPurchaseBillNumber(Request $request): JsonResponse
    {
        try {
            if (!$request->has('company_id')) {
                return response()->json(['error' => 'Missing required parameter: company_id'], 422);
            }

            $companyId = $request->company_id;

            // Get purchase bill numbers where at least one product has remaining quantity
            // Accounts for purchase quantity and free_quantity, minus returns and sales (including free quantities)
            // Adds back quantities from non-deleted sale product returns
            // Excludes purchases where all products are fully returned
            $billNumbers = Purchase::where('company_id', $companyId)
                ->whereHas('purchaseProducts', function ($query) {
                    $query->whereRaw('
                        (
                            (purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) - 
                            COALESCE((
                                SELECT SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0))
                                FROM purchase_product_returns
                                WHERE purchase_product_returns.purchase_product_id = purchase_products.id
                                AND purchase_product_returns.deleted_at IS NULL
                            ), 0) - 
                            COALESCE((
                                SELECT SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0))
                                FROM sale_products
                                WHERE sale_products.purchase_product_id = purchase_products.id
                                AND sale_products.deleted_at IS NULL
                            ), 0) + 
                            COALESCE((
                                SELECT SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0))
                                FROM sales_return_products
                                WHERE sales_return_products.sale_product_id IN (
                                    SELECT id FROM sale_products
                                    WHERE sale_products.purchase_product_id = purchase_products.id
                                    AND sale_products.deleted_at IS NULL
                                )
                                AND sales_return_products.deleted_at IS NULL
                            ), 0)
                        ) > 0
                    ');
                })
                ->whereNotExists(function ($query) {
                    // Exclude purchases where all products are fully returned
                    $query->select(DB::raw(1))
                        ->from('purchase_products')
                        ->whereColumn('purchase_products.purchase_id', 'purchases.id')
                        ->havingRaw('
                            SUM(
                                (purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) - 
                                COALESCE((
                                    SELECT SUM(purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0))
                                    FROM purchase_product_returns
                                    WHERE purchase_product_returns.purchase_product_id = purchase_products.id
                                    AND purchase_product_returns.deleted_at IS NULL
                                ), 0)
                            ) = 0
                        ');
                })
                ->pluck('purchase_bill_number');

            if ($billNumbers->isEmpty()) {
                return response()->json(['error' => 'No purchases with available products found'], 404);
            }

            return response()->json($billNumbers);
        } catch (QueryException $e) {
            \Log::error('Database error in getPurchaseBillNumber: ' . $e->getMessage());
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getPurchaseBillNumber: ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
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
                ->whereHas('purchaseProducts', function ($query) {
                    $query->whereRaw('(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) - COALESCE((
                        SELECT SUM(purchase_product_returns.quantity)
                        FROM purchase_product_returns
                        WHERE purchase_product_returns.purchase_product_id = purchase_products.id
                        AND purchase_product_returns.deleted_at IS NULL
                    ), 0) - COALESCE((
                        SELECT SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0))
                        FROM sale_products
                        WHERE sale_products.purchase_product_id = purchase_products.id
                        AND sale_products.deleted_at IS NULL
                    ), 0) + COALESCE((
                        SELECT SUM(sales_return_products.quantity)
                        FROM sales_return_products
                        WHERE sales_return_products.sale_product_id IN (
                            SELECT id FROM sale_products
                            WHERE sale_products.purchase_product_id = purchase_products.id
                            AND sale_products.deleted_at IS NULL
                        )
                        AND sales_return_products.deleted_at IS NULL
                    ), 0) > 0');
                })
                ->pluck('ref_bill_number');

            if ($billNumbers->isEmpty()) {
                return response()->json(['error' => 'No purchases with available products found'], 404);
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

 
public function getPurchaseByBillNumber(Request $request)
{
    try {
        if (!$request->has('purchase_bill_number') || !$request->has('company_id')) {
            return response()->json(['error' => 'Missing required parameters: purchase_bill_number, company_id'], 422);
        }

        // Retrieve purchase with products that have remaining quantity
        $purchase = Purchase::where('company_id', $request->company_id)
            ->where('purchase_bill_number', $request->purchase_bill_number)
            ->with([
                'purchaseProducts' => function ($query) {
                    $query->whereRaw('(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) - COALESCE((
                        SELECT SUM(purchase_product_returns.quantity)
                        FROM purchase_product_returns
                        WHERE purchase_product_returns.purchase_product_id = purchase_products.id
                        AND purchase_product_returns.deleted_at IS NULL
                    ), 0) - COALESCE((
                        SELECT SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0))
                        FROM sale_products
                        WHERE sale_products.purchase_product_id = purchase_products.id
                        AND sale_products.deleted_at IS NULL
                    ), 0) + COALESCE((
                        SELECT SUM(sales_return_products.quantity)
                        FROM sales_return_products
                        WHERE sales_return_products.sale_product_id IN (
                            SELECT id FROM sale_products
                            WHERE sale_products.purchase_product_id = purchase_products.id
                            AND sale_products.deleted_at IS NULL
                        )
                        AND sales_return_products.deleted_at IS NULL
                    ), 0) > 0')
                    ->with([
                        'fieldValues.productField',
                        'purchaseProductReturns' => function ($subQuery) {
                            $subQuery->whereNull('deleted_at');
                        }
                    ]);
                }
            ])
            ->first();

        if (!$purchase) {
            return response()->json(['error' => 'Purchase not found'], 404);
        }

        if (empty($purchase->purchaseProducts)) {
            return response()->json(['error' => 'No available products for this purchase'], 404);
        }

        $purchaseData = $purchase->toArray();
        foreach ($purchaseData['purchase_products'] as &$product) {
            // Calculate remaining quantity
            $totalPurchaseQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);
            $totalReturned = PurchaseProductReturn::where('purchase_product_id', $product['id'])
                ->whereNull('deleted_at')
                ->sum('quantity');
            $totalSold = SaleProduct::where('purchase_product_id', $product['id'])
                ->whereNull('deleted_at')
                ->sum(\DB::raw('quantity + COALESCE(free_quantity, 0)'));
            $totalSaleReturns = SalesReturnProduct::whereIn('sale_product_id', 
                SaleProduct::where('purchase_product_id', $product['id'])
                    ->whereNull('deleted_at')
                    ->pluck('id'))
                ->whereNull('deleted_at')
                ->sum('quantity');
            $product['remaining_quantity'] = $totalPurchaseQuantity - $totalReturned - $totalSold + $totalSaleReturns;

            $unavailableQuantityIndices = [];

            // 1. Purchase-returned units
            if (!empty($product['purchase_product_returns'])) {
                $returnIds = array_column($product['purchase_product_returns'], 'id');
                $unavailableQuantityIndices = array_merge(
                    $unavailableQuantityIndices,
                    PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $returnIds)
                        ->whereNull('deleted_at')
                        ->pluck('quantity_index')
                        ->toArray()
                );
            }

            // 2. Sold units
            $soldQuantityIndices = SalesProductFieldValue::whereIn('sale_product_id', 
                SaleProduct::where('purchase_product_id', $product['id'])
                    ->whereNull('deleted_at')
                    ->pluck('id'))
                ->whereNull('deleted_at')
                ->pluck('quantity_index')
                ->toArray();
            $unavailableQuantityIndices = array_merge($unavailableQuantityIndices, $soldQuantityIndices);

            // 3. Sales-returned units
            $saleReturnedIndices = [];
            if ($totalSaleReturns > 0) {
                $saleReturnFieldValues = SaleReturnProductFieldValue::whereIn('sale_return_product_id', 
                    SalesReturnProduct::whereIn('sale_product_id', 
                        SaleProduct::where('purchase_product_id', $product['id'])
                            ->whereNull('deleted_at')
                            ->pluck('id'))
                        ->whereNull('deleted_at')
                        ->pluck('id'))
                    ->whereNull('deleted_at')
                    ->get()
                    ->groupBy('quantity_index')
                    ->map(function ($group) {
                        return $group->map(function ($field) {
                            return [
                                'product_field_id' => $field->product_field_id,
                                'value' => $field->value,
                                'quantity_index' => $field->quantity_index
                            ];
                        })->toArray();
                    })->toArray();
                
                $saleReturnedIndices = array_keys($saleReturnFieldValues);
                $unavailableQuantityIndices = array_diff(
                    array_unique($unavailableQuantityIndices),
                    $saleReturnedIndices
                );
            }

            $groupedFieldValues = [];
            foreach ($product['field_values'] as $fieldValue) {
                $quantityIndex = $fieldValue['quantity_index'];
                if (in_array($quantityIndex, $unavailableQuantityIndices)) {
                    continue;
                }
                if (!isset($groupedFieldValues[$quantityIndex])) {
                    $groupedFieldValues[$quantityIndex] = [];
                }
                $groupedFieldValues[$quantityIndex][] = [
                    'product_field_id' => $fieldValue['product_field_id'],
                    'name' => $fieldValue['product_field']['name'] ?? null,
                    'quantity_index' => $quantityIndex,
                    'value' => $fieldValue['value']
                ];
            }

            // Override field_values for sales-returned units
            if (!empty($saleReturnedIndices)) {
                foreach ($saleReturnedIndices as $quantityIndex) {
                    if (isset($saleReturnFieldValues[$quantityIndex])) {
                        $groupedFieldValues[$quantityIndex] = $saleReturnFieldValues[$quantityIndex];
                    }
                }
            }

            $product['field_values'] = array_values($groupedFieldValues);
            unset($product['purchase_product_returns']);
        }

        // $purchaseData['purchase_products'] = array_filter($purchaseData['purchase_products'], function ($product) {
        //     return !empty($product['field_values']);
        // });

        if (empty($purchaseData['purchase_products'])) {
            return response()->json(['error' => 'No available products for this purchase'], 404);
        }

        return response()->json(['data' => $purchaseData]);
    } catch (QueryException $e) {
        \Log::error('Database error in getPurchaseByBillNumber: ' . $e->getMessage());
        return response()->json(['error' => 'A database error occurred'], 500);
    } catch (\Exception $e) {
        \Log::error('Unexpected error in getPurchaseByBillNumber: ' . $e->getMessage());
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    }
}


public function getPurchaseByRefBillNumber(Request $request)
{
    try {
        if (!$request->has('ref_bill_number') || !$request->has('company_id')) {
            return response()->json(['message' => 'Missing required parameters: ref_bill_number, company_id'], 422);
        }

        // Retrieve purchase with products that have remaining quantity
        $purchase = Purchase::where('company_id', $request->company_id)
            ->where('ref_bill_number', $request->ref_bill_number)
            ->with([
                'purchaseProducts' => function ($query) {
                    $query->whereRaw('(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) - COALESCE((
                        SELECT SUM(purchase_product_returns.quantity)
                        FROM purchase_product_returns
                        WHERE purchase_product_returns.purchase_product_id = purchase_products.id
                        AND purchase_product_returns.deleted_at IS NULL
                    ), 0) - COALESCE((
                        SELECT SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0))
                        FROM sale_products
                        WHERE sale_products.purchase_product_id = purchase_products.id
                        AND sale_products.deleted_at IS NULL
                    ), 0) + COALESCE((
                        SELECT SUM(sales_return_products.quantity)
                        FROM sales_return_products
                        WHERE sales_return_products.sale_product_id IN (
                            SELECT id FROM sale_products
                            WHERE sale_products.purchase_product_id = purchase_products.id
                            AND sale_products.deleted_at IS NULL
                        )
                        AND sales_return_products.deleted_at IS NULL
                    ), 0) > 0')
                    ->with([
                        'fieldValues.productField',
                        'purchaseProductReturns' => function ($subQuery) {
                            $subQuery->whereNull('deleted_at');
                        }
                    ]);
                }
            ])
            ->first();

        if (!$purchase) {
            return response()->json(['message' => 'Purchase not found'], 404);
        }

        if (empty($purchase->purchaseProducts)) {
            return response()->json(['message' => 'No available products for this purchase'], 404);
        }

        $purchaseData = $purchase->toArray();
        foreach ($purchaseData['purchase_products'] as &$product) {
            // Calculate remaining quantity
            $totalPurchaseQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);
            $totalReturned = PurchaseProductReturn::where('purchase_product_id', $product['id'])
                ->whereNull('deleted_at')
                ->sum('quantity');
            $totalSold = SaleProduct::where('purchase_product_id', $product['id'])
                ->whereNull('deleted_at')
                ->sum(\DB::raw('quantity + COALESCE(free_quantity, 0)'));
            $totalSaleReturns = SalesReturnProduct::whereIn('sale_product_id', 
                SaleProduct::where('purchase_product_id', $product['id'])
                    ->whereNull('deleted_at')
                    ->pluck('id'))
                ->whereNull('deleted_at')
                ->sum('quantity');
            $product['remaining_quantity'] = $totalPurchaseQuantity - $totalReturned - $totalSold + $totalSaleReturns;

            $unavailableQuantityIndices = [];

            // 1. Purchase-returned units
            if (!empty($product['purchase_product_returns'])) {
                $returnIds = array_column($product['purchase_product_returns'], 'id');
                $unavailableQuantityIndices = array_merge(
                    $unavailableQuantityIndices,
                    PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $returnIds)
                        ->whereNull('deleted_at')
                        ->pluck('quantity_index')
                        ->toArray()
                );
            }

            // 2. Sold units
            $soldQuantityIndices = SalesProductFieldValue::whereIn('sale_product_id', 
                SaleProduct::where('purchase_product_id', $product['id'])
                    ->whereNull('deleted_at')
                    ->pluck('id'))
                ->whereNull('deleted_at')
                ->pluck('quantity_index')
                ->toArray();
            $unavailableQuantityIndices = array_merge($unavailableQuantityIndices, $soldQuantityIndices);

            // 3. Sales-returned units
            $saleReturnedIndices = [];
            $saleReturnFieldValues = [];
            if ($totalSaleReturns > 0) {
                $saleReturnFieldValues = SaleReturnProductFieldValue::whereIn('sale_return_product_id', 
                    SalesReturnProduct::whereIn('sale_product_id', 
                        SaleProduct::where('purchase_product_id', $product['id'])
                            ->whereNull('deleted_at')
                            ->pluck('id'))
                        ->whereNull('deleted_at')
                        ->pluck('id'))
                    ->whereNull('deleted_at')
                    ->get()
                    ->groupBy('quantity_index')
                    ->map(function ($group) {
                        return $group->map(function ($field) {
                            return [
                                'product_field_id' => $field->product_field_id,
                                'value' => $field->value,
                                'quantity_index' => $field->quantity_index
                            ];
                        })->toArray();
                    })->toArray();
                
                $saleReturnedIndices = array_keys($saleReturnFieldValues);
                $unavailableQuantityIndices = array_diff(
                    array_unique($unavailableQuantityIndices),
                    $saleReturnedIndices
                );
            }

            $groupedFieldValues = [];
            foreach ($product['field_values'] as $fieldValue) {
                $quantityIndex = $fieldValue['quantity_index'];
                if (in_array($quantityIndex, $unavailableQuantityIndices)) {
                    continue;
                }
                if (!isset($groupedFieldValues[$quantityIndex])) {
                    $groupedFieldValues[$quantityIndex] = [];
                }
                $groupedFieldValues[$quantityIndex][] = [
                    'product_field_id' => $fieldValue['product_field_id'],
                    'name' => $fieldValue['product_field']['name'] ?? null,
                    'value' => $fieldValue['value']
                ];
            }

            // Override field_values for sales-returned units
            if (!empty($saleReturnedIndices)) {
                foreach ($saleReturnedIndices as $quantityIndex) {
                    if (isset($saleReturnFieldValues[$quantityIndex])) {
                        $groupedFieldValues[$quantityIndex] = array_map(function ($field) use ($product) {
                            // Fetch product field name dynamically
                            $productField = collect($product['field_values'])->firstWhere('product_field_id', $field['product_field_id']);
                            return [
                                'product_field_id' => $field['product_field_id'],
                                'name' => $productField['product_field']['name'] ?? null,
                                'value' => $field['value']
                            ];
                        }, $saleReturnFieldValues[$quantityIndex]);
                    }
                }
            }

            $product['field_values'] = array_values($groupedFieldValues);
            unset($product['purchase_product_returns']);
        }

        // $purchaseData['purchase_products'] = array_filter($purchaseData['purchase_products'], function ($product) {
        //     return !empty($product['field_values']);
        // });

        if (empty($purchaseData['purchase_products'])) {
            return response()->json(['message' => 'No available products for this purchase'], 404);
        }

        return response()->json(['data' => $purchaseData]);
    } catch (QueryException $e) {
        \Log::error('Database error in getPurchaseByRefBillNumber: ' . $e->getMessage());
        return response()->json(['message' => 'A database error occurred'], 500);
    } catch (\Exception $e) {
        \Log::error('Unexpected error in getPurchaseByRefBillNumber: ' . $e->getMessage());
        return response()->json(['message' => 'An unexpected error occurred'], 500);
    }
}




    public function getProductNames(Request $request)
    {
        try {
            $company = $request->company_id;
            $productNames = Helper::getPurchaseProductNames($company);
            
            
    
            return response()->json($productNames);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item Not Found!!'], 422);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error occurred!!'], 422);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 422);
        }
    }

    public function getPurchaseProductDetails(Request $request)
    {
        try {
            $name = $request->input('purchase_product_name');
             $company = $request->company_id;
            $productDetails = Helper::getPurchaseProductDetails($name,$company);
            
            
    
            return response()->json($productDetails);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item Not Found!!'], 422);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error occurred!!'], 422);
        } catch (\Exception $e) {
           \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 422);
        }
    }




    public function store(Request $request): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'purchase_id' => 'required|integer|exists:purchases,id',
            'customer_id' => 'required|integer|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'pan_number' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'customer_contact' => 'nullable|string|max:255',
            'purchase_number' => 'nullable|string|max:255',
            'purchase_bill_number' => 'required|string|max:255',
            'invoice_date' => 'nullable|date',
            'invoice_date_bs' => 'nullable|date',
            'remarks' => 'nullable|string|max:255',
            'reason' => 'required|string|in:damaged,defective,incorrect,expired,other',
            'store_id' => 'required|integer|exists:stores,id',
            'location_id' => 'required|integer|exists:locations,id',
            'company_id' => 'required|integer|exists:companies,id',
            'balance' => 'nullable|numeric',
            'discount_type' => 'nullable|in:percent,amount',
            'discount_value' => 'nullable|numeric|min:0',
            'sub_total_before_discount' => 'nullable|numeric|min:0',
            'non_taxable_amount' => 'nullable|numeric|min:0',
            'taxable_amount' => 'nullable|numeric|min:0',
            'excise_duty' => 'nullable|numeric|min:0',
            'vat_percent' => 'nullable|numeric',
            'health_insurance' => 'nullable|numeric|min:0',
            'freight_amount' => 'nullable|numeric|min:0',
            'discount_after_vat' => 'nullable|numeric|min:0',
            'roundoff_amount' => 'nullable|numeric',
            'roundoff_type' => 'nullable|string|max:255',
            'total_amount' => 'nullable|numeric|min:0',
            'payment' => 'nullable|array',
            'payment.cash' => 'nullable|numeric|min:0',
            'payment.credit' => 'nullable|numeric|min:0',
            'payment.bank' => 'nullable|numeric|min:0',
            'return_entire_batch' => 'nullable|boolean',
            'purchase_return_products' => 'required_unless:return_entire_batch,true|array|min:1',
            'purchase_return_products.*.purchase_product_id' => [
                'required',
                'integer',
                Rule::exists('purchase_products', 'id')->where(function ($query) use ($request) {
                    $query->where('purchase_id', $request->input('purchase_id'));
                }),
            ],
            'purchase_return_products.*.product_id' => 'required|integer|exists:products,id',
            'purchase_return_products.*.product_name' => 'required|string|max:255',
            'purchase_return_products.*.purchase_product_code' => 'nullable|string|max:255',
            'purchase_return_products.*.mfd' => 'nullable|string|max:255',
            'purchase_return_products.*.customer_id' => 'nullable|integer|exists:customers,id',
            'purchase_return_products.*.quantity' => 'required|numeric|min:0',
            'purchase_return_products.*.free_quantity' => 'nullable|numeric|min:0',
            'purchase_return_products.*.price' => 'nullable|numeric|min:0',
            'purchase_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'purchase_return_products.*.discount_amount' => 'nullable|numeric|min:0',
            'purchase_return_products.*.amount' => 'nullable|numeric|min:0',
            'purchase_return_products.*.is_vatable' => 'required|boolean',
            'purchase_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
            'purchase_return_products.*.expiry_date' => 'nullable|date',
            'purchase_return_products.*.field_values' => 'nullable|array',
            'purchase_return_products.*.field_values.*' => 'array|min:1',
            'purchase_return_products.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
            'purchase_return_products.*.field_values.*.*.value' => 'required|string|max:255',
            'purchase_return_products.*.field_values.*.*.quantity_index' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Fetch purchase
        $purchase = Purchase::findOrFail($validated['purchase_id']);

        // Validate purchase_bill_number consistency
        if ($validated['purchase_bill_number'] !== $purchase->purchase_bill_number) {
            return response()->json([
                'error' => 'Purchase bill number must match the purchase record\'s bill number'
            ], 422);
        }

        // Auto-fetch products for entire batch
        if ($validated['return_entire_batch'] ?? false) {
            $validated['purchase_return_products'] = $purchase->purchaseProducts()->get()->map(function ($product) {
                $totalReturned = PurchaseProductReturn::where('purchase_product_id', $product->id)
                    ->whereNull('deleted_at')
                    ->sum('quantity');
                $totalFreeReturned = PurchaseProductReturn::where('purchase_product_id', $product->id)
                    ->whereNull('deleted_at')
                    ->sum('free_quantity');
                $quantityToReturn = $product->quantity - $totalReturned;
                $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                    return $group->map(function ($field) {
                        return [
                            'product_field_id' => $field->product_field_id,
                            'value' => $field->value,
                            'quantity_index' => $field->quantity_index
                        ];
                    })->toArray();
                })->values()->toArray();
                // Only include field_values for available units
                $fieldValues = array_slice($fieldValues, 0, $quantityToReturn);
                return [
                    'purchase_product_id' => $product->id,
                    'product_id' => $product->product_id,
                    'product_name' => $product->product_name,
                    'purchase_product_code' => $product->product_code,
                    'mfd' => $product->mfd,
                    'customer_id' => $product->customer_id,
                    'quantity' => $quantityToReturn,
                    'free_quantity' => $product->free_quantity - $totalFreeReturned,
                    'price' => $product->price,
                    'discount_percent' => $product->discount_percent,
                    'discount_amount' => $product->discount_amount,
                    'amount' => $product->amount,
                    'is_vatable' => $product->is_vatable,
                    'measure_unit_id' => $product->measure_unit_id,
                    'expiry_date' => $product->expiry_date,
                    'field_values' => $fieldValues
                ];
            })->filter(function ($product) {
                return $product['quantity'] > 0 || $product['free_quantity'] > 0;
            })->toArray();
        }

        // Validate quantities and field_values
        foreach ($validated['purchase_return_products'] as $index => $productData) {
            $originalProduct = PurchaseProduct::where('id', $productData['purchase_product_id'])
                ->where('purchase_id', $validated['purchase_id'])
                ->firstOrFail();

            // Check available quantity
            $totalReturned = PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                ->whereNull('deleted_at')
                ->sum('quantity');
            $availableQuantity = $originalProduct->quantity - $totalReturned;
            if ($productData['quantity'] > $availableQuantity) {
                return response()->json([
                    'error' => "Return quantity {$productData['quantity']} exceeds available quantity {$availableQuantity} for product ID {$productData['product_id']} at index {$index}"
                ], 422);
            }

            // Check available free quantity
            $totalFreeReturned = PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                ->whereNull('deleted_at')
                ->sum('free_quantity');
            $availableFreeQuantity = $originalProduct->free_quantity - $totalFreeReturned;
            if (($productData['free_quantity'] ?? 0) > $availableFreeQuantity) {
                return response()->json([
                    'error' => "Free return quantity {$productData['free_quantity']} exceeds available free quantity {$availableFreeQuantity} for product ID {$productData['product_id']} at index {$index}"
                ], 422);
            }

            // Validate field_values count
            if (!($validated['return_entire_batch'] ?? false)) {
                $hasFieldValues = $originalProduct->fieldValues()->exists();
                $requiredFieldValues = $hasFieldValues ? $productData['quantity'] : 0;
                if ($hasFieldValues && (!isset($productData['field_values']) || count($productData['field_values']) !== $requiredFieldValues)) {
                    return response()->json([
                        'error' => "Field values count (" . (isset($productData['field_values']) ? count($productData['field_values']) : 0) . ") must match quantity ({$productData['quantity']}) for product ID {$productData['product_id']} at index {$index}"
                    ], 422);
                }

                // Validate field_values completeness and accuracy
                if (isset($productData['field_values'])) {
                    $existingFieldValues = PurchaseProductFieldValue::where('purchase_product_id', $productData['purchase_product_id'])
                        ->get()
                        ->groupBy('quantity_index')
                        ->map(function ($group) {
                            return $group->pluck('value', 'product_field_id')->toArray();
                        })->toArray();
                    $returnedIndices = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', 
                        PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                            ->whereNull('deleted_at')
                            ->pluck('id'))
                        ->pluck('quantity_index')
                        ->toArray();
                    foreach ($productData['field_values'] as $arrayIndex => $fieldValueSet) {
                        $quantityIndex = isset($fieldValueSet[0]['quantity_index']) ? $fieldValueSet[0]['quantity_index'] : $arrayIndex;
                        if (!isset($existingFieldValues[$quantityIndex]) || in_array($quantityIndex, $returnedIndices)) {
                            return response()->json([
                                'error' => "Invalid or already returned quantity_index {$quantityIndex} for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                        $providedFields = array_column($fieldValueSet, 'value', 'product_field_id');
                        if ($providedFields != $existingFieldValues[$quantityIndex]) {
                            return response()->json([
                                'error' => "Field values for quantity_index {$quantityIndex} do not match existing values for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                        // Ensure consistent quantity_index within set
                        foreach ($fieldValueSet as $fieldValue) {
                            if (isset($fieldValue['quantity_index']) && $fieldValue['quantity_index'] !== $quantityIndex) {
                                return response()->json([
                                    'error' => "Inconsistent quantity_index in field_values set {$arrayIndex} for product ID {$productData['product_id']} at index {$index}"
                                ], 422);
                            }
                        }
                    }
                }
            }

            // Validate field_values uniqueness
            if (isset($productData['field_values'])) {
                foreach ($productData['field_values'] as $setIndex => $fieldValueSet) {
                    $fieldIds = array_column($fieldValueSet, 'product_field_id');
                    if (count($fieldIds) !== count(array_unique($fieldIds))) {
                        return response()->json([
                            'error' => "Duplicate product_field_id found in field_values set {$setIndex} for product ID {$productData['product_id']} at index {$index}"
                        ], 422);
                    }
                }
            }
        }

        Log::debug('Validated purchase return data', [
            'purchase_id' => $validated['purchase_id'],
            'purchase_return_products' => $validated['purchase_return_products'] ?? [],
        ]);

        $item = DB::transaction(function () use ($validated) {
            $purchaseReturnData = array_filter($validated, function ($key) {
                return !in_array($key, ['purchase_return_products', 'return_entire_batch']);
            }, ARRAY_FILTER_USE_KEY);

            $item = PurchaseReturn::create($purchaseReturnData);

            $returnValue = 0;
            foreach ($validated['purchase_return_products'] as $productData) {
                $productDataFiltered = array_filter($productData, function ($key) {
                    return $key !== 'field_values';
                }, ARRAY_FILTER_USE_KEY);

                $purchaseProductReturn = $item->purchaseReturnProducts()->create(
                    array_merge($productDataFiltered, [
                        'company_id' => $item->company_id,
                    ])
                );

                if (isset($productData['field_values'])) {
                    $fieldValues = [];
                    foreach ($productData['field_values'] as $arrayIndex => $fieldValueSet) {
                        $quantityIndex = isset($fieldValueSet[0]['quantity_index']) ? $fieldValueSet[0]['quantity_index'] : $arrayIndex;
                        foreach ($fieldValueSet as $fieldValue) {
                            $fieldValues[] = [
                                'purchase_return_product_id' => $purchaseProductReturn->id,
                                'product_field_id' => $fieldValue['product_field_id'],
                                'value' => $fieldValue['value'],
                                'product_id' => $purchaseProductReturn->product_id,
                                'company_id' => $purchaseProductReturn->company_id,
                                'quantity_index' => $quantityIndex,
                                'created_at' => now(),
                                'updated_at' => now()
                            ];
                        }
                    }
                    Log::debug('Recording PurchaseReturnProductFieldValue', ['field_values' => $fieldValues]);
                    PurchaseReturnProductFieldValue::insert($fieldValues);
                }

                $returnValue += ($productData['quantity'] * ($productData['price'] ?? 0)) - ($productData['discount_amount'] ?? 0);
            }

            $purchase = Purchase::findOrFail($item->purchase_id);
            $purchase->balance -= $returnValue;
            $purchase->save();

            PurchaseReturnHistory::create([
                'purchase_return_id' => $item->id,
                'action' => 'created',
                'data' => $validated
            ]);

            return $item;
        });

        return response()->json([
            'message' => 'Purchase Return Created Successfully',
            'data' => $item->load(['purchaseReturnProducts.fieldValues' => function ($query) {
                $query->orderBy('quantity_index')->orderBy('product_field_id');
            }])
        ], 201);
    } catch (ModelNotFoundException $e) {
        Log::error('Purchase or related record not found: ' . $e->getMessage());
        return response()->json(['error' => 'Purchase or related record not found'], 404);
    } catch (\Exception $e) {
        Log::error('Error creating purchase return: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 422);
    }
}
     
    public function update(Request $request, $id): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'purchase_id' => 'required|integer|exists:purchases,id',
            'customer_id' => 'required|integer|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'pan_number' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'customer_contact' => 'nullable|string|max:255',
            'purchase_number' => 'nullable|string|max:255',
            'purchase_bill_number' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('purchase_returns', 'purchase_bill_number')->ignore($id)->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->input('company_id'));
                }),
            ],
            'invoice_date' => 'nullable|date',
            'invoice_date_bs' => 'nullable|date',
            'remarks' => 'nullable|string|max:255',
            'reason' => 'required|string|in:damaged,defective,incorrect,expired,other',
            'store_id' => 'required|integer|exists:stores,id',
            'location_id' => 'required|integer|exists:locations,id',
            'company_id' => 'required|integer|exists:companies,id',
            'balance' => 'nullable|numeric',
            'discount_type' => 'nullable|in:percent,amount',
            'discount_value' => 'nullable|numeric|min:0',
            'sub_total_before_discount' => 'nullable|numeric|min:0',
            'non_taxable_amount' => 'nullable|numeric|min:0',
            'taxable_amount' => 'nullable|numeric|min:0',
            'excise_duty' => 'nullable|numeric|min:0',
            'vat_percent' => 'nullable|numeric',
            'health_insurance' => 'nullable|numeric|min:0',
            'freight_amount' => 'nullable|numeric|min:0',
            'discount_after_vat' => 'nullable|numeric|min:0',
            'roundoff_amount' => 'nullable|numeric',
            'roundoff_type' => 'nullable|string|max:255',
            'total_amount' => 'nullable|numeric|min:0',
            'payment' => 'nullable|array',
            'payment.cash' => 'nullable|numeric|min:0',
            'payment.credit' => 'nullable|numeric|min:0',
            'payment.bank' => 'nullable|numeric|min:0',
            'return_entire_batch' => 'nullable|boolean',
            'purchase_return_products' => 'required_unless:return_entire_batch,true|array|min:1',
            'purchase_return_products.*.id' => [
                'nullable',
                'integer',
                Rule::exists('purchase_product_returns', 'id')->where(function ($query) use ($id) {
                    $query->where('purchase_return_id', $id);
                }),
            ],
            'purchase_return_products.*.purchase_product_id' => [
                'required',
                'integer',
                Rule::exists('purchase_products', 'id')->where(function ($query) use ($request) {
                    $query->where('purchase_id', $request->input('purchase_id'));
                }),
            ],
            'purchase_return_products.*.product_id' => 'required|integer|exists:products,id',
            'purchase_return_products.*.product_name' => 'required|string|max:255',
            'purchase_return_products.*.purchase_product_code' => 'nullable|string|max:255',
            'purchase_return_products.*.mfd' => 'nullable|string|max:255',
            'purchase_return_products.*.customer_id' => 'nullable|integer|exists:customers,id',
            'purchase_return_products.*.quantity' => 'required|numeric|min:0',
            'purchase_return_products.*.free_quantity' => 'nullable|numeric|min:0',
            'purchase_return_products.*.price' => 'nullable|numeric|min:0',
            'purchase_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'purchase_return_products.*.discount_amount' => 'nullable|numeric|min:0',
            'purchase_return_products.*.amount' => 'nullable|numeric|min:0',
            'purchase_return_products.*.is_vatable' => 'required|boolean',
            'purchase_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
            'purchase_return_products.*.expiry_date' => 'nullable|date',
            'purchase_return_products.*.field_values' => 'nullable|array',
            'purchase_return_products.*.field_values.*' => 'array|min:1',
            'purchase_return_products.*.field_values.*.*.id' => [
                'nullable',
                'integer',
                Rule::exists('purchase_return_product_field_values', 'id')->where(function ($query) use ($request, $id) {
                    $index = array_key_first($request->input('purchase_return_products'));
                    $purchaseProductReturnId = $request->input("purchase_return_products.$index.id");
                    if ($purchaseProductReturnId) {
                        $query->where('purchase_return_product_id', $purchaseProductReturnId);
                    }
                }),
            ],
            'purchase_return_products.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
            'purchase_return_products.*.field_values.*.*.value' => 'required|string|max:255',
            'purchase_return_products.*.field_values.*.*.quantity_index' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();

        // Fetch purchase
        $purchase = Purchase::findOrFail($validated['purchase_id']);

        // Validate purchase_bill_number consistency
        if ($validated['purchase_bill_number'] && $validated['purchase_bill_number'] !== $purchase->purchase_bill_number) {
            return response()->json([
                'error' => 'Purchase bill number must match the purchase record\'s bill number'
            ], 422);
        }

        // Auto-fetch products for entire batch
        if ($validated['return_entire_batch'] ?? false) {
            $validated['purchase_return_products'] = $purchase->purchaseProducts()->get()->map(function ($product) {
                $totalReturned = PurchaseProductReturn::where('purchase_product_id', $product->id)
                    ->whereNull('deleted_at')
                    ->sum('quantity');
                $totalFreeReturned = PurchaseProductReturn::where('purchase_product_id', $product->id)
                    ->whereNull('deleted_at')
                    ->sum('free_quantity');
                $quantityToReturn = $product->quantity - $totalReturned;
                $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                    return $group->map(function ($field) {
                        return [
                            'product_field_id' => $field->product_field_id,
                            'value' => $field->value,
                            'quantity_index' => $field->quantity_index
                        ];
                    })->toArray();
                })->values()->toArray();
                $fieldValues = array_slice($fieldValues, 0, $quantityToReturn);
                return [
                    'purchase_product_id' => $product->id,
                    'product_id' => $product->product_id,
                    'product_name' => $product->product_name,
                    'purchase_product_code' => $product->product_code,
                    'mfd' => $product->mfd,
                    'customer_id' => $product->customer_id,
                    'quantity' => $quantityToReturn,
                    'free_quantity' => $product->free_quantity - $totalFreeReturned,
                    'price' => $product->price,
                    'discount_percent' => $product->discount_percent,
                    'discount_amount' => $product->discount_amount,
                    'amount' => $product->amount,
                    'is_vatable' => $product->is_vatable,
                    'measure_unit_id' => $product->measure_unit_id,
                    'expiry_date' => $product->expiry_date,
                    'field_values' => $fieldValues
                ];
            })->filter(function ($product) {
                return $product['quantity'] > 0 || $product['free_quantity'] > 0;
            })->toArray();
        }

        // Validate quantities and field_values
        foreach ($validated['purchase_return_products'] as $index => $productData) {
            $originalProduct = PurchaseProduct::where('id', $productData['purchase_product_id'])
                ->where('purchase_id', $validated['purchase_id'])
                ->firstOrFail();

            // Check available quantity
            $totalReturned = PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                ->whereNull('deleted_at')
                ->where('id', '!=', $productData['id'] ?? 0)
                ->sum('quantity');
            $availableQuantity = $originalProduct->quantity - $totalReturned;
            if ($productData['quantity'] > $availableQuantity) {
                return response()->json([
                    'error' => "Return quantity {$productData['quantity']} exceeds available quantity {$availableQuantity} for product ID {$productData['product_id']} at index {$index}"
                ], 422);
            }

            // Check available free quantity
            $totalFreeReturned = PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                ->whereNull('deleted_at')
                ->where('id', '!=', $productData['id'] ?? 0)
                ->sum('free_quantity');
            $availableFreeQuantity = $originalProduct->free_quantity - $totalFreeReturned;
            if (($productData['free_quantity'] ?? 0) > $availableFreeQuantity) {
                return response()->json([
                    'error' => "Free return quantity {$productData['free_quantity']} exceeds available free quantity {$availableFreeQuantity} for product ID {$productData['product_id']} at index {$index}"
                ], 422);
            }

            // Validate field_values count
            if (!($validated['return_entire_batch'] ?? false)) {
                $hasFieldValues = $originalProduct->fieldValues()->exists();
                $requiredFieldValues = $hasFieldValues ? $productData['quantity'] : 0;
                if ($hasFieldValues && (!isset($productData['field_values']) || count($productData['field_values']) !== $requiredFieldValues)) {
                    return response()->json([
                        'error' => "Field values count (" . (isset($productData['field_values']) ? count($productData['field_values']) : 0) . ") must match quantity ({$productData['quantity']}) for product ID {$productData['product_id']} at index {$index}"
                    ], 422);
                }

                // Validate field_values completeness and accuracy
                if (isset($productData['field_values'])) {
                    $existingFieldValues = PurchaseProductFieldValue::where('purchase_product_id', $productData['purchase_product_id'])
                        ->get()
                        ->groupBy('quantity_index')
                        ->map(function ($group) {
                            return $group->pluck('value', 'product_field_id')->toArray();
                        })->toArray();
                    $returnedIndices = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', 
                        PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                            ->whereNull('deleted_at')
                            ->where('id', '!=', $productData['id'] ?? 0)
                            ->pluck('id'))
                        ->pluck('quantity_index')
                        ->toArray();
                    foreach ($productData['field_values'] as $arrayIndex => $fieldValueSet) {
                        $quantityIndex = isset($fieldValueSet[0]['quantity_index']) ? $fieldValueSet[0]['quantity_index'] : $arrayIndex;
                        if (!isset($existingFieldValues[$quantityIndex]) || in_array($quantityIndex, $returnedIndices)) {
                            return response()->json([
                                'error' => "Invalid or already returned quantity_index {$quantityIndex} for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                        $providedFields = array_column($fieldValueSet, 'value', 'product_field_id');
                        if ($providedFields != $existingFieldValues[$quantityIndex]) {
                            return response()->json([
                                'error' => "Field values for quantity_index {$quantityIndex} do not match existing values for product ID {$productData['product_id']} at index {$index}"
                            ], 422);
                        }
                        // Ensure consistent quantity_index within set
                        foreach ($fieldValueSet as $fieldValue) {
                            if (isset($fieldValue['quantity_index']) && $fieldValue['quantity_index'] !== $quantityIndex) {
                                return response()->json([
                                    'error' => "Inconsistent quantity_index in field_values set {$arrayIndex} for product ID {$productData['product_id']} at index {$index}"
                                ], 422);
                            }
                        }
                    }
                }
            }

            // Validate field_values uniqueness
            if (isset($productData['field_values'])) {
                foreach ($productData['field_values'] as $setIndex => $fieldValueSet) {
                    $fieldIds = array_column($fieldValueSet, 'product_field_id');
                    if (count($fieldIds) !== count(array_unique($fieldIds))) {
                        return response()->json([
                            'error' => "Duplicate product_field_id found in field_values set {$setIndex} for product ID {$productData['product_id']} at index {$index}"
                        ], 422);
                    }
                }
            }
        }

        Log::debug('Validated purchase return update data', [
            'purchase_id' => $validated['purchase_id'],
            'purchase_return_products' => $validated['purchase_return_products'] ?? [],
        ]);

        $item = DB::transaction(function () use ($validated, $id) {
            $item = PurchaseReturn::findOrFail($id);

            $purchaseReturnData = array_filter($validated, function ($key) {
                return !in_array($key, ['purchase_return_products', 'return_entire_batch']);
            }, ARRAY_FILTER_USE_KEY);
            $item->update($purchaseReturnData);

            if (isset($validated['purchase_return_products'])) {
                $existingProductIds = $item->purchaseReturnProducts()->withTrashed()->pluck('id')->toArray();
                $incomingProductIds = collect($validated['purchase_return_products'])
                    ->pluck('id')
                    ->filter()
                    ->toArray();

                $productsToDelete = array_diff($existingProductIds, $incomingProductIds);
                if (!empty($productsToDelete)) {
                    Log::debug("Soft deleting purchase_return_products", ['ids' => $productsToDelete]);
                    PurchaseProductReturn::whereIn('id', $productsToDelete)->delete();
                }

                $returnValue = 0;
                foreach ($validated['purchase_return_products'] as $productData) {
                    $productDataFiltered = array_filter($productData, function ($key) {
                        return $key !== 'field_values';
                    }, ARRAY_FILTER_USE_KEY);

                    if (isset($productData['id'])) {
                        $purchaseProductReturn = PurchaseProductReturn::where('id', $productData['id'])
                            ->where('purchase_return_id', $item->id)
                            ->withTrashed()
                            ->firstOrFail();

                        if ($purchaseProductReturn->trashed()) {
                            $purchaseProductReturn->restore();
                            Log::debug("Restored purchase_return_product_id {$purchaseProductReturn->id}");
                        }

                        $purchaseProductReturn->update(
                            array_merge($productDataFiltered, [
                                'purchase_return_id' => $item->id,
                                'company_id' => $item->company_id,
                            ])
                        );
                    } else {
                        $purchaseProductReturn = $item->purchaseReturnProducts()->create(
                            array_merge($productDataFiltered, [
                                'purchase_return_id' => $item->id,
                                'company_id' => $item->company_id,
                            ])
                        );
                    }

                    if (isset($productData['field_values'])) {
                        $processedFieldIds = [];

                        $existingFieldIds = PurchaseReturnProductFieldValue::where('purchase_return_product_id', $purchaseProductReturn->id)
                            ->withTrashed()
                            ->pluck('id')
                            ->toArray();

                        Log::debug("Existing field IDs for purchase_return_product_id {$purchaseProductReturn->id}", ['ids' => $existingFieldIds]);

                        foreach ($productData['field_values'] as $arrayIndex => $fieldValueSet) {
                            if (count($fieldValueSet) > 0) {
                                $quantityIndex = isset($fieldValueSet[0]['quantity_index']) ? $fieldValueSet[0]['quantity_index'] : $arrayIndex;
                                foreach ($fieldValueSet as $fieldValue) {
                                    Log::debug("Processing field_value for purchase_return_product_id {$purchaseProductReturn->id}", [
                                        'field_value' => $fieldValue,
                                        'quantity_index' => $quantityIndex,
                                    ]);

                                    if (isset($fieldValue['id']) && !empty($fieldValue['id'])) {
                                        $existingValue = PurchaseReturnProductFieldValue::where('id', $fieldValue['id'])
                                            ->where('purchase_return_product_id', $purchaseProductReturn->id)
                                            ->withTrashed()
                                            ->first();

                                        if ($existingValue) {
                                            if ($existingValue->trashed()) {
                                                $existingValue->restore();
                                                Log::debug("Restored field value ID {$fieldValue['id']} for purchase_return_product_id {$purchaseProductReturn->id}");
                                            }
                                            $existingValue->update([
                                                'product_field_id' => $fieldValue['product_field_id'],
                                                'value' => $fieldValue['value'],
                                                'quantity_index' => $quantityIndex,
                                                'updated_at' => now(),
                                            ]);
                                            $processedFieldIds[] = $existingValue->id;
                                            Log::debug("Updated field value ID {$fieldValue['id']} for purchase_return_product_id {$purchaseProductReturn->id}");
                                        } else {
                                            Log::warning("Field value ID {$fieldValue['id']} not found for purchase_return_product_id {$purchaseProductReturn->id}");
                                            $newFieldValue = PurchaseReturnProductFieldValue::create([
                                                'purchase_return_product_id' => $purchaseProductReturn->id,
                                                'product_field_id' => $fieldValue['product_field_id'],
                                                'value' => $fieldValue['value'],
                                                'product_id' => $purchaseProductReturn->product_id,
                                                'company_id' => $purchaseProductReturn->company_id,
                                                'quantity_index' => $quantityIndex,
                                                'created_at' => now(),
                                                'updated_at' => now(),
                                            ]);
                                            $processedFieldIds[] = $newFieldValue->id;
                                            Log::debug("Created new field value ID {$newFieldValue->id} for purchase_return_product_id {$purchaseProductReturn->id}");
                                        }
                                    } else {
                                        $newFieldValue = PurchaseReturnProductFieldValue::create([
                                            'purchase_return_product_id' => $purchaseProductReturn->id,
                                            'product_field_id' => $fieldValue['product_field_id'],
                                            'value' => $fieldValue['value'],
                                            'product_id' => $purchaseProductReturn->product_id,
                                            'company_id' => $purchaseProductReturn->company_id,
                                            'quantity_index' => $quantityIndex,
                                            'created_at' => now(),
                                            'updated_at' => now(),
                                        ]);
                                        $processedFieldIds[] = $newFieldValue->id;
                                        Log::debug("Created new field value ID {$newFieldValue->id} for purchase_return_product_id {$purchaseProductReturn->id}");
                                    }
                                }
                            }
                        }

                        $unprocessedFieldIds = array_diff($existingFieldIds, $processedFieldIds);
                        if (!empty($unprocessedFieldIds)) {
                            Log::debug("Soft deleting field values for purchase_return_product_id {$purchaseProductReturn->id}", ['ids' => $unprocessedFieldIds]);
                            PurchaseReturnProductFieldValue::where('purchase_return_product_id', $purchaseProductReturn->id)
                                ->whereIn('id', $unprocessedFieldIds)
                                ->delete();
                        }
                    } else {
                        $existingFieldIds = PurchaseReturnProductFieldValue::where('purchase_return_product_id', $purchaseProductReturn->id)
                            ->withTrashed()
                            ->pluck('id')
                            ->toArray();
                        if (!empty($existingFieldIds)) {
                            Log::debug("Soft deleting all field values for purchase_return_product_id {$purchaseProductReturn->id}", ['ids' => $existingFieldIds]);
                            PurchaseReturnProductFieldValue::where('purchase_return_product_id', $purchaseProductReturn->id)
                                ->whereIn('id', $existingFieldIds)
                                ->delete();
                        }
                    }

                    $returnValue += ($productData['quantity'] * ($productData['price'] ?? 0)) - ($productData['discount_amount'] ?? 0);
                }
            } else {
                $existingProductIds = $item->purchaseReturnProducts()->withTrashed()->pluck('id')->toArray();
                if (!empty($existingProductIds)) {
                    Log::debug("Soft deleting all purchase_return_products for purchase_return_id {$item->id}", ['ids' => $existingProductIds]);
                    PurchaseProductReturn::whereIn('id', $existingProductIds)->delete();
                }
            }

            // Update purchase balance
            $purchase = Purchase::findOrFail($item->purchase_id);
            $previousReturnValue = PurchaseProductReturn::where('purchase_return_id', $item->id)
                ->whereNull('deleted_at')
                ->sum(DB::raw('(quantity * price) - discount_amount'));
            $newReturnValue = $returnValue;
            $purchase->balance += $previousReturnValue - $newReturnValue;
            $purchase->save();

            PurchaseReturnHistory::create([
                'purchase_return_id' => $item->id,
                'action' => 'updated',
                'data' => $validated
            ]);

            return $item;
        });

        return response()->json([
            'message' => 'Purchase Return Updated Successfully',
            'data' => $item->load(['purchaseReturnProducts.fieldValues' => function ($query) {
                $query->orderBy('quantity_index')->orderBy('product_field_id');
            }])
        ], 200);
    } catch (ModelNotFoundException $e) {
        Log::error('Purchase return not found: ' . $e->getMessage());
        return response()->json(['error' => 'Purchase return not found'], 404);
    } catch (QueryException $e) {
        Log::error('Database error during purchase return update: ' . $e->getMessage());
        return response()->json(['error' => 'A database error occurred'], 500);
    } catch (\Exception $e) {
        Log::error('Unexpected error during purchase return update: ' . $e->getMessage());
        return response()->json(['error' => $e->getMessage()], 422);
    }
}

    

    public function show($id): JsonResponse
    {
        try {
            $item = PurchaseReturn::with(['PurchaseProductReturn'])->findOrFail($id);
            return response()->json($item->load('PurchaseProductReturn'));
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

    public function destroy($id): JsonResponse
    {
        try {
            $item = PurchaseReturn::with('PurchaseProductReturn.PurchaseReturnProductFieldValue')->findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Purchase Return deleted']);
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

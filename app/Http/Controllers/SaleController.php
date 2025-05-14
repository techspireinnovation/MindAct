<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Helpers\Helper;
use App\Models\SaleProduct;
use App\Models\SaleAdditional;
use App\Models\SalesReturnProduct;
use App\Models\SalesProductFieldValue;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    protected function generateUniqueInvoiceNumber(string $fiscalYear): string
{
    // Prefix for the invoice number, e.g., "INV"
    $prefix = 'INV';

    // Format: INV-YYYY-XXXXXX (e.g., INV-2024-000001)
    // Extract the start year from fiscal year (e.g., "2024-25" -> "2024")
    $year = substr($fiscalYear, 0, 4);

    // Lock the sales table to prevent race conditions
    return DB::transaction(function () use ($prefix, $year) {
        // Find the latest invoice number for the given fiscal year
        $latestInvoice = Sale::where('invoice_number', 'like', "{$prefix}-{$year}-%")
            ->orderBy('invoice_number', 'desc')
            ->first();

        // Extract the sequence number from the latest invoice or start at 0
        $sequence = 0;
        if ($latestInvoice && preg_match("/{$prefix}-{$year}-(\d+)/", $latestInvoice->invoice_number, $matches)) {
            $sequence = (int)$matches[1];
        }

        // Increment the sequence
        $newSequence = $sequence + 1;

        // Format the new invoice number with leading zeros (e.g., 000001)
        $formattedSequence = str_pad($newSequence, 6, '0', STR_PAD_LEFT);

        // Construct the new invoice number
        $newInvoiceNumber = "{$prefix}-{$year}-{$formattedSequence}";

        // Double-check uniqueness (in case of concurrent transactions)
        while (Sale::where('invoice_number', $newInvoiceNumber)->exists()) {
            $newSequence++;
            $formattedSequence = str_pad($newSequence, 6, '0', STR_PAD_LEFT);
            $newInvoiceNumber = "{$prefix}-{$year}-{$formattedSequence}";
        }

        return $newInvoiceNumber;
    });
}
   

    public function index(Request $request): JsonResponse
    {
        $query = Sale::query();
    
        if ($request->has('keywords')) {
            $query->where('invoice_number', 'LIKE', '%' . $request->input('keywords') . '%');
        }
        return response()->json($query->paginate(10));
    }
       public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'customer_id' => 'required|exists:customers,id',
                'salesman_id' => 'required|exists:salesmen,id',
                'invoice_number' => 'nullable|string|max:255|unique:sales,invoice_number',
                'document_number' => 'nullable|string|max:255',
                'batch_no' => 'nullable|string|max:255',
                'balance' => 'nullable|numeric',
                'invoice_date' => 'nullable|date',
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
                'total_amount' => 'nullable|numeric|min:0',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
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
                'sale_products.*.product_id' => 'required|exists:products,id',
                'sale_products.*.quantity' => 'required|numeric|min:0',
                'sale_products.*.free_quantity' => 'nullable|numeric|min:0',
                'sale_products.*.price' => 'required|numeric|min:0',
                'sale_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sale_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sale_products.*.is_vatable' => 'nullable|boolean',
                'sale_products.*.measure_unit_id' => 'required|exists:measure_units,id',
                'sale_products.*.field_values' => 'nullable|array',
                'sale_products.*.field_values.*' => 'array|min:1',
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
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $validated = $validator->validated();

            // Calculate fiscal year
            $date = $request->invoice_date ? Carbon::parse($request->invoice_date) : now();
            $fiscal_year_start = Carbon::create($date->year, 7, 16);
            $fiscalYear = $date->lessThan($fiscal_year_start)
                ? ($date->year - 1) . '-' . substr($date->year, 2, 2)
                : $date->year . '-' . substr($date->year + 1, 2, 2);

            // Generate unique invoice number if not provided
            $validated['invoice_number'] = $request->input('invoice_number') ?? $this->generateUniqueInvoiceNumber($fiscalYear);

            // Handle batch processing
            $hasBatchIdentifier = isset($validated['purchase_id']) || isset($validated['purchase_bill_number']) || isset($validated['batch_no_sale']);
            $sellEntireBatch = $validated['sell_entire_batch'] ?? false;

            if ($sellEntireBatch || $hasBatchIdentifier) {
                // Require at least one batch identifier if sell_entire_batch is true
                if ($sellEntireBatch && !$hasBatchIdentifier) {
                    return response()->json(['error' => 'At least one batch identifier (purchase_id, purchase_bill_number, or batch_no_sale) is required when sell_entire_batch is true'], 422);
                }

                $purchaseProducts = collect();

                // Fetch purchase products, checking purchase_id or purchase_bill_number first
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
                    // Sell entire batch: map all purchase products to sale_products
                    $validated['sale_products'] = $purchaseProducts->map(function ($product) {
                        $productModel = Product::find($product->product_id);
                        $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                            return $group->map(function ($field) {
                                return [
                                    'product_field_id' => $field->product_field_id,
                                    'value' => $field->value,
                                ];
                            })->toArray();
                        })->values()->toArray();

                        return [
                            'product_id' => $product->product_id,
                            'quantity' => $product->quantity,
                            'free_quantity' => $product->free_quantity ?? 0,
                            'price' => $productModel->price ?? $product->price,
                            'discount_percent' => $product->discount_percent ?? 0,
                            'discount_amount' => $product->discount_amount ?? 0,
                            'is_vatable' => $productModel->is_vatable ?? $product->is_vatable,
                            'measure_unit_id' => $product->measure_unit_id,
                            'field_values' => $fieldValues,
                        ];
                    })->toArray();
                } elseif (isset($validated['sale_products']) && !empty($validated['sale_products'])) {
                    // Sell specific products: validate they belong to the purchase
                    $purchaseProductIds = $purchaseProducts->pluck('product_id')->toArray();
                    foreach ($validated['sale_products'] as $index => $saleProduct) {
                        if (!in_array($saleProduct['product_id'], $purchaseProductIds)) {
                            return response()->json([
                                'error' => "Product ID {$saleProduct['product_id']} at index {$index} does not belong to the specified purchase or batch"
                            ], 422);
                        }
                        $purchaseProduct = $purchaseProducts->firstWhere('product_id', $saleProduct['product_id']);
                        $requestedQuantity = $saleProduct['quantity'] + ($saleProduct['free_quantity'] ?? 0);
                        $availablePurchaseQuantity = $purchaseProduct->quantity + ($purchaseProduct->free_quantity ?? 0);
                        if ($requestedQuantity > $availablePurchaseQuantity) {
                            return response()->json([
                                'error' => "Requested quantity for product ID {$saleProduct['product_id']} at index {$index} exceeds purchased quantity ({$availablePurchaseQuantity})"
                            ], 422);
                        }
                    }
                } else {
                    return response()->json(['error' => 'sale_products is required when sell_entire_batch is false and a purchase or batch identifier is provided'], 422);
                }
            }

            // Validate field values and stock
            foreach ($validated['sale_products'] as $index => $product) {
                $productId = $product['product_id'];
                $quantity = $product['quantity'];

                // Validate field_values, but skip quantity matching for sell_entire_batch
                $hasFieldValues = PurchaseProduct::where('product_id', $productId)
                    ->whereHas('fieldValues')
                    ->exists();
                if ($hasFieldValues && !$sellEntireBatch && (!isset($product['field_values']) || count($product['field_values']) !== $quantity)) {
                    return response()->json([
                        'error' => "Field values count (" . (isset($product['field_values']) ? count($product['field_values']) : 0) . ") must match quantity ({$quantity}) for product ID {$productId} at index {$index}"
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

                // Check stock
                $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);
                $purchasedQuantity = PurchaseProduct::where('product_id', $productId)
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->sum('quantity');
                $purchaseReturnedQuantity = PurchaseProductReturn::where('product_id', $productId)
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->sum('quantity');
                $soldQuantity = SaleProduct::where('product_id', $productId)
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->sum('quantity');
                $salesReturnedQuantity = SalesReturnProduct::where('product_id', $productId)
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->sum('quantity');

                $availableQuantity = ($purchasedQuantity - $purchaseReturnedQuantity) - ($soldQuantity - $salesReturnedQuantity);

                if ($requestedQuantity > $availableQuantity) {
                    return response()->json([
                        'error' => "Insufficient stock for product ID {$productId} at index {$index}. Available: {$availableQuantity}, Requested: {$requestedQuantity}"
                    ], 422);
                }
            }

            $sale = DB::transaction(function () use ($validated) {
                $sale = Sale::create($validated);

                foreach ($validated['sale_products'] as $product) {
                    $product['company_id'] = $validated['company_id'];
                    $product['sale_id'] = $sale->id;
                    $productModel = Product::find($product['product_id']);
                    $product['product_code'] = $productModel->product_unique_id ?? null;
                    $product['product_name'] = $productModel->name ?? null;
                    $product['name'] = $productModel->name ?? null;
                    $product['amount'] = ($product['quantity'] * $product['price']) - ($product['discount_amount'] ?? 0);

                    $saleProduct = $sale->saleProducts()->create($product);

                    if (!empty($product['field_values'])) {
                        $fieldValues = [];
                        foreach ($product['field_values'] as $quantityIndex => $fieldValueSet) {
                            foreach ($fieldValueSet as $fieldValue) {
                                $fieldValues[] = [
                                    'company_id' => $validated['company_id'],
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'product_id' => $saleProduct->product_id,
                                    'sale_product_id' => $saleProduct->id,
                                    'quantity_index' => $quantityIndex,
                                    'value' => $fieldValue['value'],
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                            }
                        }
                        SalesProductFieldValue::insert($fieldValues);
                    }
                }

                if (isset($validated['sale_additionals'])) {
                    $saleAdditionals = $validated['sale_additionals'];
                    $saleAdditionals['company_id'] = $validated['company_id'];
                    $saleAdditionals['sale_id'] = $sale->id;
                    if (isset($validated['purchase_bill_number'])) {
                        $saleAdditionals['purchase_bill_number'] = $validated['purchase_bill_number'];
                    }
                    $sale->saleAdditionals()->create($saleAdditionals);
                }

                return $sale;
            });

            return response()->json([
                'message' => 'Sale created successfully',
                'data' => $sale->load([
                    'saleProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index')->orderBy('product_field_id');
                    },
                    'saleAdditionals'
                ])
            ], 201);
        } catch (ModelNotFoundException $e) {
            Log::error($e);
            return response()->json(['error' => 'Resource not found'], 404);
        } catch (QueryException $e) {
            Log::error($e);
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }



 

public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'customer_id' => 'required|exists:customers,id',
                'salesman_id' => 'required|exists:salesmen,id',
                'invoice_number' => ['nullable', 'string', 'max:255', Rule::unique('sales', 'invoice_number')->ignore($id)],
                'document_number' => 'nullable|string|max:255',
                'batch_no' => 'nullable|string|max:255',
                'balance' => 'nullable|numeric',
                'invoice_date' => 'nullable|date',
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
                'total_amount' => 'nullable|numeric|min:0',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
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
                'sale_products.*.quantity' => 'required|numeric|min:0',
                'sale_products.*.free_quantity' => 'nullable|numeric|min:0',
                'sale_products.*.price' => 'required|numeric|min:0',
                'sale_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sale_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sale_products.*.is_vatable' => 'nullable|boolean',
                'sale_products.*.measure_unit_id' => 'required|exists:measure_units,id',
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
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $validated = $validator->validated();

            // Calculate fiscal year
            $newInvoiceDate = $request->invoice_date ? Carbon::parse($request->invoice_date) : now();
            $fiscal_year_start = Carbon::create($newInvoiceDate->year, 7, 16);
            $fiscalYearNew = $newInvoiceDate->lessThan($fiscal_year_start)
                ? ($newInvoiceDate->year - 1) . '-' . substr($newInvoiceDate->year, 2, 2)
                : $newInvoiceDate->year . '-' . substr($newInvoiceDate->year + 1, 2, 2);

            // Generate unique invoice number if not provided
            if (!isset($validated['invoice_number'])) {
                $validated['invoice_number'] = $this->generateUniqueInvoiceNumber($fiscalYearNew);
            }

            // Handle batch processing
            $hasBatchIdentifier = isset($validated['purchase_id']) || isset($validated['purchase_bill_number']) || isset($validated['batch_no_sale']);
            $sellEntireBatch = $validated['sell_entire_batch'] ?? false;

            if ($sellEntireBatch || $hasBatchIdentifier) {
                // Require at least one batch identifier if sell_entire_batch is true
                if ($sellEntireBatch && !$hasBatchIdentifier) {
                    return response()->json(['error' => 'At least one batch identifier (purchase_id, purchase_bill_number, or batch_no_sale) is required when sell_entire_batch is true'], 422);
                }

                $purchaseProducts = collect();

                // Fetch purchase products, checking purchase_id or purchase_bill_number first
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
                    // Sell entire batch: map all purchase products to sale_products
                    $validated['sale_products'] = $purchaseProducts->map(function ($product) {
                        $productModel = Product::find($product->product_id);
                        $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                            return $group->map(function ($field) {
                                return [
                                    'product_field_id' => $field->product_field_id,
                                    'value' => $field->value,
                                ];
                            })->toArray();
                        })->values()->toArray();

                        return [
                            'product_id' => $product->product_id,
                            'quantity' => $product->quantity,
                            'free_quantity' => $product->free_quantity ?? 0,
                            'price' => $productModel->price ?? $product->price,
                            'discount_percent' => $product->discount_percent ?? 0,
                            'discount_amount' => $product->discount_amount ?? 0,
                            'is_vatable' => $productModel->is_vatable ?? $product->is_vatable,
                            'measure_unit_id' => $product->measure_unit_id,
                            'field_values' => $fieldValues,
                        ];
                    })->toArray();
                } elseif (isset($validated['sale_products']) && !empty($validated['sale_products'])) {
                    // Sell specific products: validate they belong to the purchase
                    $purchaseProductIds = $purchaseProducts->pluck('product_id')->toArray();
                    foreach ($validated['sale_products'] as $index => $saleProduct) {
                        if (!in_array($saleProduct['product_id'], $purchaseProductIds)) {
                            return response()->json([
                                'error' => "Product ID {$saleProduct['product_id']} at index {$index} does not belong to the specified purchase or batch"
                            ], 422);
                        }
                        $purchaseProduct = $purchaseProducts->firstWhere('product_id', $saleProduct['product_id']);
                        $requestedQuantity = $saleProduct['quantity'] + ($saleProduct['free_quantity'] ?? 0);
                        $availablePurchaseQuantity = $purchaseProduct->quantity + ($purchaseProduct->free_quantity ?? 0);
                        if ($requestedQuantity > $availablePurchaseQuantity) {
                            return response()->json([
                                'error' => "Requested quantity for product ID {$saleProduct['product_id']} at index {$index} exceeds purchased quantity ({$availablePurchaseQuantity})"
                            ], 422);
                        }
                    }
                } else {
                    return response()->json(['error' => 'sale_products is required when sell_entire_batch is false and a purchase or batch identifier is provided'], 422);
                }
            }

            // Validate field values and stock
            foreach ($validated['sale_products'] as $index => $product) {
                $productId = $product['product_id'];
                $quantity = $product['quantity'];

                // Validate field_values, but skip quantity matching for sell_entire_batch
                $hasFieldValues = PurchaseProduct::where('product_id', $productId)
                    ->whereHas('fieldValues')
                    ->exists();
                if ($hasFieldValues && !$sellEntireBatch && (!isset($product['field_values']) || count($product['field_values']) !== $quantity)) {
                    return response()->json([
                        'error' => "Field values count (" . (isset($product['field_values']) ? count($product['field_values']) : 0) . ") must match quantity ({$quantity}) for product ID {$productId} at index {$index}"
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

                // Check stock
                $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);
                $purchasedQuantity = PurchaseProduct::where('product_id', $productId)
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->sum('quantity');
                $purchaseReturnedQuantity = PurchaseProductReturn::where('product_id', $productId)
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->sum('quantity');
                $soldQuantity = SaleProduct::where('product_id', $productId)
                    ->where('company_id', $validated['company_id'])
                    ->where('sale_id', '!=', $id)
                    ->whereNull('deleted_at')
                    ->sum('quantity');
                $salesReturnedQuantity = SalesReturnProduct::where('product_id', $productId)
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->sum('quantity');

                $availableQuantity = ($purchasedQuantity - $purchaseReturnedQuantity) - ($soldQuantity - $salesReturnedQuantity);

                if (isset($product['id'])) {
                    $existingProduct = SaleProduct::where('id', $product['id'])->where('sale_id', $id)->first();
                    if ($existingProduct) {
                        $existingQuantity = $existingProduct->quantity + ($existingProduct->free_quantity ?? 0);
                        $availableQuantity += $existingQuantity;
                    }
                }

                if ($requestedQuantity > $availableQuantity) {
                    return response()->json([
                        'error' => "Insufficient stock for product ID {$productId} at index {$index}. Available: {$availableQuantity}, Requested: {$requestedQuantity}"
                    ], 422);
                }
            }

            $sale = DB::transaction(function () use ($validated, $id) {
                $sale = Sale::findOrFail($id);
                $sale->update($validated);

                $existingProductIds = $sale->saleProducts()->withTrashed()->pluck('id')->toArray();
                $incomingProductIds = collect($validated['sale_products'])->pluck('id')->filter()->toArray();
                $productsToDelete = array_diff($existingProductIds, $incomingProductIds);
                if (!empty($productsToDelete)) {
                    SaleProduct::whereIn('id', $productsToDelete)->delete();
                }

                foreach ($validated['sale_products'] as $product) {
                    $product['company_id'] = $validated['company_id'];
                    $product['sale_id'] = $sale->id;
                    $productModel = Product::find($product['product_id']);
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
                        }
                    } else {
                        $saleProduct = $sale->saleProducts()->create($product);
                    }

                    if (isset($product['field_values'])) {
                        $processedFieldIds = [];
                        $existingFieldIds = SalesProductFieldValue::where('sale_product_id', $saleProduct->id)->withTrashed()->pluck('id')->toArray();

                        foreach ($product['field_values'] as $quantityIndex => $fieldValueSet) {
                            foreach ($fieldValueSet as $fieldValue) {
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
                                            'quantity_index' => $quantityIndex,
                                            'updated_at' => now(),
                                        ]);
                                        $processedFieldIds[] = $existingValue->id;
                                    }
                                } else {
                                    $newFieldValue = SalesProductFieldValue::create([
                                        'company_id' => $validated['company_id'],
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'product_id' => $saleProduct->product_id,
                                        'sale_product_id' => $saleProduct->id,
                                        'quantity_index' => $quantityIndex,
                                        'value' => $fieldValue['value'],
                                    ]);
                                    $processedFieldIds[] = $newFieldValue->id;
                                }
                            }
                        }

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
            Log::error($e);
            return response()->json(['error' => 'Sale not found'], 404);
        } catch (QueryException $e) {
            Log::error($e);
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }



    public function show($id): JsonResponse
    {
        try {
            $item = Sale::with('saleProducts')->findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



public function getSalesByCustomer(Request $request): JsonResponse
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
        dd($e->getMessage());
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
        dd($e->getMessage());
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
        dd($e->getMessage());
        return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
    }
}


}
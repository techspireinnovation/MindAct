<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\MeasureUnit;
use App\Models\Product;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductFieldValue;
use App\Models\Sale;
use App\Models\SaleProduct;
use App\Models\SaleReturnAdditional;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SalesProductFieldValue;
use App\Models\SalesReturn;
use App\Models\SalesReturnProduct;
use Carbon\Carbon;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SalesReturnController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $query = SalesReturn::with("customer:id,party_name");

        if ($request->has('keywords')) {
            $query->where('invoice_number', 'LIKE', '%' . $request->input('keywords') . '%')->orWhere('ref_bill_no', 'LIKE', '%' . $request->input('keywords') . '%')->orWhere('customer_name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(100));
    }


    protected function generateUniqueInvoiceNumber(string $fiscalYear): string
    {
        $prefix = 'SRET-INV';
        $year = substr($fiscalYear, 0, 4);

        return DB::transaction(function () use ($prefix, $year) {
            $latestInvoice = SalesReturn::where('invoice_number', 'like', "{$prefix}-{$year}-%")
                ->orderBy('invoice_number', 'desc')
                ->first();

            $sequence = 0;
            if ($latestInvoice && preg_match("/{$prefix}-{$year}-(\d+)/", $latestInvoice->invoice_number, $matches)) {
                $sequence = (int) $matches[1];
            }

            $newSequence = $sequence + 1;
            $formattedSequence = str_pad($newSequence, 6, '0', STR_PAD_LEFT);
            $newInvoiceNumber = "{$prefix}-{$year}-{$formattedSequence}";

            while (SalesReturn::where('invoice_number', $newInvoiceNumber)->exists()) {
                $newSequence++;
                $formattedSequence = str_pad($newSequence, 6, '0', STR_PAD_LEFT);
                $newInvoiceNumber = "{$prefix}-{$year}-{$formattedSequence}";
            }

            return $newInvoiceNumber;
        });
    }




    public function getSaleByInvoiceNumber(Request $request): JsonResponse
    {
        try {
            // Validate required parameters
            $validator = Validator::make($request->all(), [
                'invoice_number' => 'required|string|max:255',
                'company_id' => 'required|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            $invoiceNumber = $request->invoice_number;
            $companyId = $request->company_id;

            // Fetch measure units for all relevant products
            $measureUnits = MeasureUnit::where('company_id', $companyId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->select(['id', 'name', 'quantity'])
                ->get()
                ->keyBy('id');

            // Fetch sale with products and field values
            $sale = Sale::where('company_id', $companyId)
                ->where('invoice_number', $invoiceNumber)
                ->with([
                    'saleProducts' => function ($query) use ($companyId) {
                        $query->select([
                            'sale_products.id',
                            'sale_products.sale_id',
                            'sale_products.product_id',
                            'sale_products.measure_unit_id',
                            'sale_products.quantity',
                            'sale_products.amount',
                            'sale_products.free_quantity',
                            'sale_products.purchase_product_id',
                            'sale_products.price',
                            'sale_products.is_vatable',
                            'sale_products.expiry_date',
                            'products.name as product_name',
                            'products.product_unique_id as product_code',
                        ])
                            ->join('products', 'sale_products.product_id', '=', 'products.id')
                            ->where('sale_products.company_id', $companyId)
                            ->whereNull('sale_products.deleted_at');
                    },
                    'saleProducts.fieldValues' => function ($query) use ($companyId) {
                        $query->select([
                            'sales_product_field_values.sale_product_id',
                            'sales_product_field_values.product_field_id',
                            'sales_product_field_values.quantity_index',
                            'sales_product_field_values.value',
                            'product_fields.name',
                        ])
                            ->join('product_fields', 'sales_product_field_values.product_field_id', '=', 'product_fields.id')
                            ->where('sales_product_field_values.company_id', $companyId)
                            ->whereNull('sales_product_field_values.deleted_at');
                    },
                ])
                ->select([
                    'id',
                    'company_id',
                    'customer_id',
                    'customer_name',
                    'invoice_number',
                    'pan_number',
                    'balance',
                    'batch_no',
                    'ref_number',
                    'document_number',
                    'customer_address',
                    'contact_number',
                    'invoice_date',
                    'invoice_date_bs',
                    'bank_id',
                    'remarks',
                    'store_id',
                    'location_id',
                    'discount',
                    'sub_total_before_discount',
                    'taxable_amount',
                    'non_taxable_amount',
                    'excise_duty',
                    'health_insurance',
                    'freight_charge',
                    'discount_after_vat',
                    'round_off_amount',
                    'roundoff_type',
                    'total_amount',
                    'payment',
                    'is_vatable',
                    'is_mail_notify',
                    'is_whatsapp_notify',
                    'abvt',
                    'created_at',
                    'updated_at',
                    'deleted_at',
                ])
                ->first();

            if (!$sale) {
                Log::warning('Sale not found for invoice number', [
                    'invoice_number' => $invoiceNumber,
                    'company_id' => $companyId,
                ]);
                return response()->json(['error' => 'Sale not found or not eligible for return'], 404);
            }

            if ($sale->saleProducts->isEmpty()) {
                Log::warning('No available products for sale', [
                    'invoice_number' => $invoiceNumber,
                    'company_id' => $companyId,
                    'sale_id' => $sale->id,
                    'sale_products' => $sale->saleProducts->toArray(),
                ]);
                return response()->json(['error' => 'No available products for this sale'], 404);
            }

            // Fetch sales return products
            $salesReturnProducts = SalesReturnProduct::whereIn('sale_product_id', $sale->saleProducts->pluck('id'))
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get();

            // Log sales return products for debugging
            Log::info('Sales return products', [
                'invoice_number' => $invoiceNumber,
                'company_id' => $companyId,
                'sales_return_products' => $salesReturnProducts->toArray(),
            ]);

            // Aggregate product data
            $products = [];
            $productIds = $sale->saleProducts->pluck('product_id')->unique()->toArray();

            foreach ($sale->saleProducts as $saleProduct) {
                $productId = $saleProduct->product_id;
                $measureUnitId = $saleProduct->measure_unit_id ?? null;
                $measureUnit = isset($measureUnits[$measureUnitId]) ? [
                    'id' => $measureUnits[$measureUnitId]->id,
                    'name' => $measureUnits[$measureUnitId]->name,
                    'quantity' => $measureUnits[$measureUnitId]->quantity ?? 1,
                ] : [
                    'id' => null,
                    'name' => 'null',
                    'quantity' => 1,
                ];

                Log::debug('Processing sale product measure unit', [
                    'sale_product_id' => $saleProduct->id,
                    'measure_unit' => $measureUnit,
                ]);

                // Initialize product entry
                if (!isset($products[$productId])) {
                    $products[$productId] = [
                        'product_id' => $productId,
                        'product_name' => $saleProduct->product_name,
                        'product_code' => $saleProduct->product_code,
                        'min_price' => $saleProduct->price,
                        'amount' => $saleProduct->amount,
                        'is_vatable' => (bool) $saleProduct->is_vatable,
                        'measure_unit_id' => $measureUnit['id'],
                        'measure_unit_name' => $measureUnit['name'],
                        'measure_unit_quantity' => $measureUnit['quantity'],
                        'purchased_quantity' => 0,
                        'return_quantity' => 0,
                        'sale_quantity' => 0,
                        'sales_return_quantity' => 0,
                        'available_quantity' => 0,
                        'expiry_dates' => [],
                        'field_values' => [],
                        'sale_products' => [],
                    ];
                }

                // Calculate sale quantities in pieces
                $measureUnitQuantity = $measureUnit['quantity'];
                $saleTotal = ($saleProduct->quantity + ($saleProduct->free_quantity ?? 0)) * $measureUnitQuantity;

                // Fetch returned quantities for this sale product
                $fieldValueQuantities = [];
                $returnedIndices = [];
                if ($saleProduct->fieldValues->isNotEmpty()) {
                    $quantityIndices = $saleProduct->fieldValues->pluck('quantity_index')->unique();
                    foreach ($quantityIndices as $quantityIndex) {
                        $returnProducts = SalesReturnProduct::where('sale_product_id', $saleProduct->id)
                            ->where('company_id', $companyId)
                            ->whereNull('deleted_at')
                            ->whereHas('fieldValues', function ($query) use ($companyId, $saleProduct, $quantityIndex) {
                                $query->where('sale_product_id', $saleProduct->id)
                                    ->where('quantity_index', $quantityIndex)
                                    ->where('company_id', $companyId)
                                    ->whereNull('deleted_at');
                            })
                            ->get();

                        $returned = 0;
                        $returnMeasureUnitId = null; // Initialize default value
                        $returnMeasureUnitQuantity = 1; // Default quantity if no returns
                        foreach ($returnProducts as $returnProduct) {
                            $returnMeasureUnitId = $returnProduct->measure_unit_id ?? null;
                            $returnMeasureUnitQuantity = isset($measureUnits[$returnMeasureUnitId]) ? $measureUnits[$returnMeasureUnitId]->quantity : 1;
                            $returned += ($returnProduct->quantity + ($returnProduct->free_quantity ?? 0)) * $returnMeasureUnitQuantity;
                        }

                        $fieldValueQuantities[$quantityIndex] = $returned;
                        if ($returned > 0) {
                            $returnedIndices[] = $quantityIndex;
                        }
                        Log::info('Returned quantity for sale product', [
                            'sale_product_id' => $saleProduct->id,
                            'quantity_index' => $quantityIndex,
                            'returned' => $returned,
                            'measure_unit_id' => $returnMeasureUnitId,
                            'measure_unit_quantity' => $returnMeasureUnitQuantity,
                        ]);
                    }
                } else {
                    $returnProducts = SalesReturnProduct::where('sale_product_id', $saleProduct->id)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->get();

                    $returned = 0;
                    $returnMeasureUnitId = null; // Initialize default value
                    $returnMeasureUnitQuantity = 1; // Default quantity if no returns
                    foreach ($returnProducts as $returnProduct) {
                        $returnMeasureUnitId = $returnProduct->measure_unit_id ?? null;
                        $returnMeasureUnitQuantity = isset($measureUnits[$returnMeasureUnitId]) ? $measureUnits[$returnMeasureUnitId]->quantity : 1;
                        $returned += ($returnProduct->quantity + ($returnProduct->free_quantity ?? 0)) * $returnMeasureUnitQuantity;
                    }

                    $fieldValueQuantities[0] = $returned;
                    if ($returned > 0) {
                        $returnedIndices[] = 0;
                    }
                    Log::info('Returned quantity for sale product (no field values)', [
                        'sale_product_id' => $saleProduct->id,
                        'returned' => $returned,
                        'measure_unit_id' => $returnMeasureUnitId,
                        'measure_unit_quantity' => $returnMeasureUnitQuantity,
                    ]);
                }

                // Update product quantities
                $returnTotal = array_sum($fieldValueQuantities);
                $availableQuantity = $saleTotal - $returnTotal;
                Log::info('Quantity calculation for sale product', [
                    'sale_product_id' => $saleProduct->id,
                    'sale_total' => $saleTotal,
                    'return_total' => $returnTotal,
                    'available_quantity' => $availableQuantity,
                    'measure_unit_quantity' => $measureUnitQuantity,
                ]);

                $products[$productId]['sale_quantity'] += $saleTotal;
                $products[$productId]['sales_return_quantity'] += $returnTotal;
                $products[$productId]['available_quantity'] += $availableQuantity;

                if ($saleProduct->expiry_date && !in_array($saleProduct->expiry_date, $products[$productId]['expiry_dates'])) {
                    $products[$productId]['expiry_dates'][] = $saleProduct->expiry_date;
                }

                // Add field values only for unreturned quantities
                if ($saleProduct->fieldValues->isNotEmpty()) {
                    foreach ($saleProduct->fieldValues as $fv) {
                        if (!in_array($fv->quantity_index, $returnedIndices)) {
                            $products[$productId]['field_values'][] = [
                                'sale_product_id' => $saleProduct->id,
                                'purchase_product_id' => $saleProduct->purchase_product_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->name,
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index,
                            ];
                            Log::info('Added eligible field value', [
                                'sale_product_id' => $saleProduct->id,
                                'quantity_index' => $fv->quantity_index,
                                'product_field_id' => $fv->product_field_id,
                                'value' => $fv->value,
                            ]);
                        }
                    }
                }

                // Add sale product details only if available_quantity > 0
                if ($availableQuantity > 0) {
                    $products[$productId]['sale_products'][] = [
                        'sale_product_id' => $saleProduct->id,
                        'sale_id' => $saleProduct->sale_id,
                        'product_id' => $saleProduct->product_id,
                        'product_name' => $saleProduct->product_name,
                        'product_code' => $saleProduct->product_code,
                        'quantity' => $saleProduct->quantity,
                        'free_quantity' => $saleProduct->free_quantity ?? 0,
                        'price' => $saleProduct->price,
                        'is_vatable' => (bool) $saleProduct->is_vatable,
                        'measure_unit_id' => $measureUnit['id'],
                        'measure_unit_name' => $measureUnit['name'],
                        'measure_unit_quantity' => $measureUnit['quantity'],
                        'available_quantity' => $availableQuantity,
                        'return_quantity' => $returnTotal,
                        'sale_quantity' => $saleTotal,
                        'sales_return_quantity' => $returnTotal,
                        'expiry_date' => $saleProduct->expiry_date,
                        'purchase_product_id' => $saleProduct->purchase_product_id,
                    ];
                }
            }

            // Calculate purchased quantities
            foreach ($productIds as $productId) {
                if (!isset($products[$productId])) {
                    continue;
                }

                $purchasedTotal = PurchaseProduct::where('product_id', $productId)
                    ->where('purchase_products.company_id', $companyId)
                    ->whereNull('purchase_products.deleted_at')
                    ->join('measure_units', 'purchase_products.measure_unit_id', '=', 'measure_units.id')
                    ->where('measure_units.company_id', $companyId)
                    ->whereNull('measure_units.deleted_at')
                    ->sum(DB::raw('(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)'));

                $products[$productId]['purchased_quantity'] = (int) ($purchasedTotal ?? 0);
                $products[$productId]['return_quantity'] = 0;

                Log::info('Purchased quantity calculated', [
                    'product_id' => $productId,
                    'purchased_quantity' => $purchasedTotal,
                ]);
            }

            // Log aggregated product data before filtering
            Log::info('Aggregated products before filtering', [
                'invoice_number' => $invoiceNumber,
                'company_id' => $companyId,
                'products' => $products,
            ]);

            // Filter out products with no available quantity
            $products = array_filter($products, function ($product) {
                Log::info('Filtering product', [
                    'product_id' => $product['product_id'],
                    'available_quantity' => $product['available_quantity'],
                ]);
                return $product['available_quantity'] > 0;
            });

            if (empty($products)) {
                Log::warning('No available products after processing', [
                    'invoice_number' => $invoiceNumber,
                    'company_id' => $companyId,
                    'sale_id' => $sale->id,
                ]);
                return response()->json(['error' => 'No available products for this sale'], 404);
            }

            // Prepare sale data according to Sale model
            $saleData = [
                'id' => $sale->id,
                'company_id' => $sale->company_id,
                'customer_id' => $sale->customer_id,
                'bank_id' => $sale->bank_id,
                'customer_name' => $sale->customer_name,
                'customer_address' => $sale->customer_address,
                'credit_days' => $sale->credit_days,
                'balance' => $sale->balance,
                'invoice_number' => $sale->invoice_number,
                'batch_no' => $sale->batch_no,
                'invoice_date' => $sale->invoice_date,
                'invoice_date_bs' => $sale->invoice_date_bs,
                'document_number' => $sale->document_number,
                'contact_number' => $sale->contact_number,
                'ref_number' => $sale->ref_number,
                'pan_number' => $sale->pan_number,
                'remarks' => $sale->remarks,
                'store_id' => $sale->store_id,
                'location_id' => $sale->location_id,
                'salesman_id' => $sale->salesman_id,
                'sub_total_before_discount' => $sale->sub_total_before_discount,
                'discount' => $sale->discount,
                'non_taxable_amount' => $sale->non_taxable_amount,
                'taxable_amount' => $sale->taxable_amount,
                'excise_duty' => $sale->excise_duty,
                'health_insurance' => $sale->health_insurance,
                'freight_charge' => $sale->freight_charge,
                'discount_after_vat' => $sale->discount_after_vat,
                'round_off_amount' => $sale->round_off_amount,
                'roundoff_type' => $sale->roundoff_type,
                'total_amount' => $sale->total_amount,
                'payment' => $sale->payment,
                'note' => $sale->note,
                'is_vatable' => $sale->is_vatable,
                'is_mail_notify' => $sale->is_mail_notify,
                'is_whatsapp_notify' => $sale->is_whatsapp_notify,
                'abvt' => $sale->abvt,
                'created_at' => $sale->created_at->toIso8601String(),
                'updated_at' => $sale->updated_at->toIso8601String(),
                'deleted_at' => $sale->deleted_at ? $sale->deleted_at->toIso8601String() : null,
                'products' => array_values($products),
            ];

            return response()->json([
                'message' => 'Sale details retrieved successfully',
                'data' => $saleData,
            ]);
        } catch (QueryException $e) {
            Log::error('Database error in getSaleByInvoiceNumber', [
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in getSaleByInvoiceNumber', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function listAvailableInvoiceNumbers(Request $request): JsonResponse
    {
        try {
            // Validate company_id
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $companyId = $request->input('company_id');

            // Fetch all active measure units for the company
            $measureUnits = MeasureUnit::where('company_id', $companyId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->select(['id', 'name', 'quantity'])
                ->get()
                ->keyBy('id');

            // Get invoice numbers where at least one product has remaining quantity
            $invoiceNumbers = Sale::where('sales.company_id', $companyId)
                ->whereNotNull('sales.invoice_number')
                ->where('sales.invoice_number', '!=', '')
                ->whereHas('saleProducts', function ($query) use ($companyId, $measureUnits) {
                    $query->leftJoin('measure_units as sale_mu', function ($join) use ($companyId) {
                        $join->on('sale_products.measure_unit_id', '=', 'sale_mu.id')
                            ->where('sale_mu.company_id', $companyId)
                            ->where('sale_mu.is_active', 1)
                            ->whereNull('sale_mu.deleted_at');
                    })
                        ->where('sale_products.company_id', $companyId)
                        ->whereNull('sale_products.deleted_at')
                        ->whereRaw('
                        (
                            (sale_products.quantity + COALESCE(sale_products.free_quantity, 0)) * COALESCE(sale_mu.quantity, 1)
                        ) - COALESCE((
                            SELECT SUM(
                                (srp.quantity + COALESCE(srp.free_quantity, 0)) * COALESCE(return_mu.quantity, 1)
                            )
                            FROM sales_return_products srp
                            LEFT JOIN measure_units return_mu ON srp.measure_unit_id = return_mu.id
                            AND return_mu.company_id = ?
                            AND return_mu.is_active = 1
                            AND return_mu.deleted_at IS NULL
                            WHERE srp.sale_product_id = sale_products.id
                            AND srp.company_id = ?
                            AND srp.deleted_at IS NULL
                        ), 0) > 0.0001
                    ', [
                            $companyId, // return_mu.company_id
                            $companyId  // srp.company_id
                        ]);
                })
                ->distinct()
                ->pluck('sales.invoice_number')
                ->toArray();

            // Log for debugging
            Log::info('Available invoice numbers retrieved', [
                'company_id' => $companyId,
                'invoice_numbers' => $invoiceNumbers,
                'measure_units_count' => count($measureUnits),
            ]);

            if (empty($invoiceNumbers)) {
                return response()->json(['error' => 'No sales with available products found'], 404);
            }

            return response()->json([
                'message' => 'Available invoice numbers with remaining quantities retrieved successfully',
                'data' => $invoiceNumbers
            ], 200);
        } catch (QueryException $e) {
            Log::error('Database error in listAvailableInvoiceNumbers', [
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in listAvailableInvoiceNumbers', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function getSaleByRefNumber(Request $request): JsonResponse
    {
        try {
            // Validate required parameters
            if (!$request->has('ref_number') || !$request->has('company_id')) {
                return response()->json(['error' => 'Missing required parameters: ref_number, company_id'], 422);
            }

            // Fetch sale with associated products, filtering for remaining quantities
            $sale = Sale::where('company_id', $request->company_id)
                ->where('ref_number', $request->ref_number)
                ->with([
                    'saleProducts' => function ($query) {
                        $query->whereRaw('(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)) - COALESCE((
                            SELECT SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0))
                            FROM sales_return_products
                            WHERE sales_return_products.sale_product_id = sale_products.id
                            AND sales_return_products.deleted_at IS NULL
                        ), 0) > 0')
                            ->with([
                                'fieldValues.productField',
                                'saleProductReturns' => function ($subQuery) {
                                    $subQuery->whereNull('deleted_at');
                                }
                            ]);
                    }
                ])
                ->first();

            // Return 404 if sale not found
            if (!$sale) {
                Log::warning('Sale not found for ref number', [
                    'ref_number' => $request->ref_number,
                    'company_id' => $request->company_id,
                ]);
                return response()->json(['error' => 'Sale not found'], 404);
            }

            // If no products with remaining quantity, return not found
            if (empty($sale->saleProducts)) {
                Log::warning('No available products for sale', [
                    'ref_number' => $request->ref_number,
                    'company_id' => $request->company_id,
                    'sale_id' => $sale->id,
                ]);
                return response()->json(['error' => 'No available products for this sale'], 404);
            }

            // Transform field_values and calculate remaining quantities
            $saleData = $sale->toArray();
            foreach ($saleData['sale_products'] as &$product) {
                // Calculate remaining quantity
                $totalReturned = SalesReturnProduct::where('sale_product_id', $product['id'])
                    ->whereNull('deleted_at')
                    ->sum(DB::raw('quantity + COALESCE(free_quantity, 0)'));
                $product['remaining_quantity'] = ($product['quantity'] + ($product['free_quantity'] ?? 0)) - $totalReturned;

                // Include purchase_product_id
                $product['purchase_product_id'] = $product['purchase_product_id'] ?? null;

                // Get quantity indices of already returned field_values
                $unavailableQuantityIndices = [];
                if (!empty($product['sale_product_returns'])) {
                    $returnIds = array_column($product['sale_product_returns'], 'id');
                    $unavailableQuantityIndices = SaleReturnProductFieldValue::whereIn('sale_return_product_id', $returnIds)
                        ->whereNull('deleted_at')
                        ->pluck('quantity_index')
                        ->toArray();
                    $unavailableQuantityIndices = array_unique($unavailableQuantityIndices);
                }

                // Group field_values by quantity_index, excluding returned ones
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
                $product['field_values'] = array_values($groupedFieldValues);

                // Clean up sale_product_returns from the response
                unset($product['sale_product_returns']);
            }

            // Filter out products with no field_values or no remaining quantity
            $saleData['sale_products'] = array_filter($saleData['sale_products'], function ($product) {
                return !empty($product['field_values']) && $product['remaining_quantity'] > 0;
            });

            // If no products remain after filtering, return not found
            if (empty($saleData['sale_products'])) {
                Log::warning('No available products after filtering', [
                    'ref_number' => $request->ref_number,
                    'company_id' => $request->company_id,
                    'sale_id' => $sale->id,
                ]);
                return response()->json(['error' => 'No available products for this sale'], 404);
            }

            return response()->json(['data' => $saleData]);
        } catch (QueryException $e) {
            Log::error('Database error in getSaleByRefNumber', [
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);
            // dd($e->getMessage());
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in getSaleByRefNumber', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    public function listAvailableRefNumbers(Request $request): JsonResponse
    {
        try {
            // Validate company_id
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $companyId = $request->input('company_id');

            // Get ref numbers where at least one product has remaining quantity
            $refNumbers = Sale::where('company_id', $companyId)
                ->whereNotNull('ref_number')
                ->where('ref_number', '!=', '')
                ->whereHas('saleProducts', function ($query) {
                    $query->whereRaw('(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)) - COALESCE((
                        SELECT SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0))
                        FROM sales_return_products
                        WHERE sales_return_products.sale_product_id = sale_products.id
                        AND sales_return_products.deleted_at IS NULL
                    ), 0) > 0');
                })
                ->distinct()
                ->pluck('ref_number')
                ->toArray();

            if (empty($refNumbers)) {
                return response()->json(['error' => 'No sales with available products found'], 404);
            }

            return response()->json([
                'message' => 'Available reference numbers with remaining quantities retrieved successfully',
                'data' => $refNumbers
            ], 200);
        } catch (QueryException $e) {
            Log::error('Database error in listAvailableRefNumbers', [
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in listAvailableRefNumbers', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }




    public function getSaleProductNames(Request $request): JsonResponse
    {
        try {
            // Validate inputs
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $companyId = $request->input('company_id');

            Log::debug('Input parameters for sale product names', [
                'company_id' => $companyId,
            ]);

            // Authentication and authorization
            if (!auth()->check()) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $user = auth()->user();
            $userCompanyId = optional($user->company)->company_id;
            if ($userCompanyId != $companyId) {
                return response()->json(['error' => 'Unauthorized access to company resources'], 403);
            }

            // Fetch measure units
            $measureUnits = MeasureUnit::where('company_id', $companyId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->select(['id', 'name', 'quantity'])
                ->get()
                ->keyBy('id');

            Log::info('Measure units fetched', [
                'company_id' => $companyId,
                'measure_units' => $measureUnits->toArray(),
            ]);

            // Fetch sales with products
            $sales = Sale::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->with([
                    'saleProducts' => function ($query) use ($companyId) {
                        $query->select([
                            'sale_products.id',
                            'sale_products.sale_id',
                            'sale_products.product_id',
                            'sale_products.measure_unit_id',
                            'sale_products.quantity',
                            'sale_products.free_quantity',
                            'products.name as product_name',
                        ])
                            ->join('products', 'sale_products.product_id', '=', 'products.id')
                            ->where('sale_products.company_id', $companyId)
                            ->whereNull('sale_products.deleted_at')
                            ->whereNull('products.deleted_at');
                    },
                ])
                ->select(['id', 'company_id'])
                ->get();

            if ($sales->isEmpty()) {
                Log::warning('No sales found', ['company_id' => $companyId]);
                return response()->json(['message' => 'No sale products with available quantities found', 'data' => []], 404);
            }

            // Fetch sales return products
            $saleProductIds = $sales->pluck('saleProducts.*.id')->flatten()->unique()->toArray();
            $salesReturnProducts = SalesReturnProduct::whereIn('sale_product_id', $saleProductIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->select([
                    'id',
                    'sale_product_id',
                    'quantity',
                    'free_quantity',
                    'measure_unit_id',
                ])
                ->get();

            Log::info('Sales return products fetched', [
                'sale_product_ids' => $saleProductIds,
                'sales_return_products' => $salesReturnProducts->toArray(),
            ]);

            // Aggregate products
            $products = [];
            foreach ($sales as $sale) {
                if ($sale->saleProducts->isEmpty()) {
                    Log::warning('No available products for sale', [
                        'sale_id' => $sale->id,
                        'company_id' => $companyId,
                    ]);
                    continue;
                }

                foreach ($sale->saleProducts as $saleProduct) {
                    $productId = $saleProduct->product_id;
                    // Use sale product's measure unit or default if missing
                    $measureUnitId = $saleProduct->measure_unit_id ?? null;
                    $measureUnit = isset($measureUnits[$measureUnitId]) ? [
                        'id' => $measureUnits[$measureUnitId]->id,
                        'name' => $measureUnits[$measureUnitId]->name,
                        'quantity' => $measureUnits[$measureUnitId]->quantity ?? 1,
                    ] : [
                        'id' => null,
                        'name' => 'null',
                        'quantity' => 1,
                    ];
                    $measureUnitQuantity = $measureUnit['quantity'];

                    if (!isset($measureUnits[$measureUnitId])) {
                        Log::warning('Measure unit not found for sale product, using default', [
                            'sale_product_id' => $saleProduct->id,
                            'measure_unit_id' => $saleProduct->measure_unit_id,
                        ]);
                    }

                    // Initialize product entry
                    if (!isset($products[$productId])) {
                        $products[$productId] = [
                            'product_id' => $productId,
                            'product_name' => $saleProduct->product_name,
                            'sale_quantity' => 0,
                            'sales_return_quantity' => 0,
                            'available_quantity' => 0,
                        ];
                    }

                    // Calculate sale quantity
                    $saleTotal = ($saleProduct->quantity + ($saleProduct->free_quantity ?? 0)) * $measureUnitQuantity;

                    // Calculate returned quantity
                    $returnProducts = $salesReturnProducts->where('sale_product_id', $saleProduct->id);
                    $returned = 0;
                    $returnMeasureUnitId = null;
                    $returnMeasureUnitQuantity = 1;
                    foreach ($returnProducts as $returnProduct) {
                        $returnMeasureUnitId = $returnProduct->measure_unit_id ?? null;
                        $returnMeasureUnitQuantity = isset($measureUnits[$returnMeasureUnitId]) ? $measureUnits[$returnMeasureUnitId]->quantity : 1;
                        $returnQuantity = ($returnProduct->quantity + ($returnProduct->free_quantity ?? 0)) * $returnMeasureUnitQuantity;
                        $returned += $returnQuantity;

                        Log::info('Processing return product', [
                            'sale_product_id' => $saleProduct->id,
                            'return_product_id' => $returnProduct->id,
                            'return_quantity' => $returnQuantity,
                            'measure_unit_id' => $returnMeasureUnitId,
                            'measure_unit_quantity' => $returnMeasureUnitQuantity,
                        ]);
                    }

                    if ($returned >= $saleTotal) {
                        Log::warning('Return quantity equals or exceeds sale quantity', [
                            'sale_product_id' => $saleProduct->id,
                            'sale_total' => $saleTotal,
                            'return_total' => $returned,
                        ]);
                    }

                    Log::info('Returned quantity for sale product', [
                        'sale_product_id' => $saleProduct->id,
                        'returned' => $returned,
                        'measure_unit_id' => $returnMeasureUnitId,
                        'measure_unit_quantity' => $returnMeasureUnitQuantity,
                    ]);

                    // Calculate available quantity
                    $returnTotal = round($returned, 2);
                    $saleTotal = round($saleTotal, 2);
                    $availableQuantity = max(0, round($saleTotal - $returnTotal, 2));

                    Log::info('Quantity calculation for sale product', [
                        'sale_product_id' => $saleProduct->id,
                        'sale_total' => $saleTotal,
                        'return_total' => $returnTotal,
                        'available_quantity' => $availableQuantity,
                    ]);

                    $products[$productId]['sale_quantity'] += $saleTotal;
                    $products[$productId]['sales_return_quantity'] += $returnTotal;
                    $products[$productId]['available_quantity'] += $availableQuantity;
                }
            }

            // Filter products with available quantity
            $products = array_filter($products, function ($product) {
                Log::info('Filtering product', [
                    'product_id' => $product['product_id'],
                    'available_quantity' => $product['available_quantity'],
                ]);
                return $product['available_quantity'] > 0;
            });

            if (empty($products)) {
                Log::warning('No available products after processing', ['company_id' => $companyId]);
                return response()->json(['message' => 'No sale products with available quantities found', 'data' => []], 404);
            }

            // Prepare response
            $response = array_map(function ($product) {
                return [
                    'key' => $product['product_id'],
                    'value' => $product['product_name'],
                ];
            }, array_values($products));

            Log::info('Final response prepared', [
                'product_count' => count($products),
                'product_ids' => array_column($products, 'product_id'),
            ]);

            return response()->json([
                'message' => 'Data Received Successfully !!',
                'data' => $response,
            ], 200);

        } catch (QueryException $e) {
            Log::error('Database query error in getSaleProductNames', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in getSaleProductNames', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    public function getAvailableProductsForSalesReturn(Request $request): JsonResponse
    {
        try {
            // Validate inputs
            $validator = Validator::make($request->all(), [
                'product_id' => 'nullable|integer|exists:products,id',
                'product_name' => 'nullable|string|max:255',
                'company_id' => 'required|integer|exists:companies,id',
                'sale_id' => 'nullable|integer|exists:sales,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $productId = $request->input('product_id');
            $productCode = $request->input('product_code');
            $productName = trim(strtolower($request->input('product_name')));
            $companyId = $request->input('company_id');
            $saleId = $request->input('sale_id');

            Log::debug('Input parameters for sales return', [
                'product_id' => $productId,
                'product_name' => $productName,
                'product_code' => $productCode,
                'company_id' => $companyId,
                'sale_id' => $saleId,
            ]);

            if (!$productId && !$productCode && !$productName && !$saleId) {
                return response()->json(['error' => 'At least one of product_id, product_name, or sale_id is required'], 422);
            }

            // Authentication and authorization
            if (!auth()->check()) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $user = auth()->user();
            $userCompanyId = optional($user->company)->company_id;
            if ($userCompanyId != $companyId) {
                return response()->json(['error' => 'Unauthorized access to company resources'], 403);
            }

            // Fetch measure units
            $measureUnits = MeasureUnit::where('company_id', $companyId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->select(['id', 'name', 'quantity'])
                ->get()
                ->keyBy('id');

            Log::info('Measure units fetched', [
                'company_id' => $companyId,
                'measure_units' => $measureUnits->toArray(),
            ]);

            // Fetch sales with products and field values
            $salesQuery = Sale::where('company_id', $companyId)
                ->whereNull('deleted_at');

            if ($saleId) {
                $salesQuery->where('id', $saleId);
            }

            $sales = $salesQuery->with([
                'saleProducts' => function ($query) use ($companyId, $productId, $productName, $productCode) {
                    $query->select([
                        'sale_products.id',
                        'sale_products.sale_id',
                        'sale_products.product_id',
                        'sale_products.measure_unit_id',
                        'sale_products.quantity',
                        'sale_products.amount',
                        'sale_products.free_quantity',
                        'sale_products.purchase_product_id',
                        'sale_products.price',
                        'sale_products.is_vatable',
                        'sale_products.expiry_date',
                        'products.name as product_name',
                        'products.product_unique_id as product_code',
                    ])
                        ->join('products', 'sale_products.product_id', '=', 'products.id')
                        ->where('sale_products.company_id', $companyId)
                        ->whereNull('sale_products.deleted_at')
                        ->whereNull('products.deleted_at');

                    if ($productId) {
                        $query->where('sale_products.product_id', $productId);
                    }

                    if ($productName) {
                        $query->whereRaw('LOWER(products.name) LIKE ?', ["%{$productName}%"]);
                    }

                    if ($productCode) {
                        $query->where('products.product_unique_id', $productCode);
                    }
                },
                'saleProducts.fieldValues' => function ($query) use ($companyId) {
                    $query->select([
                        'sales_product_field_values.sale_product_id',
                        'sales_product_field_values.product_field_id',
                        'sales_product_field_values.quantity_index',
                        'sales_product_field_values.value',
                        'product_fields.name',
                    ])
                        ->join('product_fields', 'sales_product_field_values.product_field_id', '=', 'product_fields.id')
                        ->where('sales_product_field_values.company_id', $companyId)
                        ->whereNull('sales_product_field_values.deleted_at')
                        ->whereNull('product_fields.deleted_at');
                },
            ])
                ->select([
                    'id',
                    'company_id',
                    'customer_id',
                    'customer_name',
                    'invoice_number',
                    'invoice_date',
                    'total_amount',
                    'is_vatable',
                ])
                ->get();

            if ($sales->isEmpty()) {
                Log::warning('No sales found', [
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'company_id' => $companyId,
                    'sale_id' => $saleId,
                ]);
                return response()->json(['message' => 'No products available for sales return', 'data' => []], 404);
            }

            // Fetch sales return products
            $saleProductIds = $sales->pluck('saleProducts.*.id')->flatten()->unique()->toArray();
            $salesReturnProducts = SalesReturnProduct::whereIn('sale_product_id', $saleProductIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->select([
                    'id',
                    'sale_product_id',
                    'quantity',
                    'free_quantity',
                    'measure_unit_id',
                    'company_id',
                ])
                ->get();

            Log::info('Sales return products fetched', [
                'sale_product_ids' => $saleProductIds,
                'sales_return_products' => $salesReturnProducts->toArray(),
            ]);

            // Fetch return field values for comparison
            $returnFieldValues = DB::table('sale_return_product_field_values')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereIn('sale_product_id', $saleProductIds)
                ->select([
                    'sale_product_id',
                    'product_field_id',
                    'quantity_index',
                    'value',
                ])
                ->get()
                ->groupBy('sale_product_id');

            // Aggregate products across all sales
            $products = [];

            foreach ($sales as $sale) {
                if ($sale->saleProducts->isEmpty()) {
                    Log::warning('No available products for sale', [
                        'sale_id' => $sale->id,
                        'invoice_number' => $sale->invoice_number,
                        'company_id' => $companyId,
                    ]);
                    continue;
                }

                foreach ($sale->saleProducts as $saleProduct) {
                    $productId = $saleProduct->product_id;
                    // Use sale product's measure unit or default if missing
                    $measureUnitId = $saleProduct->measure_unit_id ?? null;
                    $measureUnit = isset($measureUnits[$measureUnitId]) ? [
                        'id' => $measureUnits[$measureUnitId]->id,
                        'name' => $measureUnits[$measureUnitId]->name,
                        'quantity' => $measureUnits[$measureUnitId]->quantity ?? 1,
                    ] : [
                        'id' => null,
                        'name' => 'null',
                        'quantity' => 1,
                    ];
                    $measureUnitQuantity = $measureUnit['quantity'];

                    if (!isset($measureUnits[$measureUnitId])) {
                        Log::warning('Measure unit not found for sale product, using default', [
                            'sale_product_id' => $saleProduct->id,
                            'measure_unit_id' => $saleProduct->measure_unit_id,
                        ]);
                    }

                    // Initialize product entry
                    if (!isset($products[$productId])) {
                        $products[$productId] = [
                            'product_id' => $productId,
                            'product_name' => $saleProduct->product_name,
                            'product_code' => $saleProduct->product_code,
                            'min_price' => $saleProduct->price,
                            'amount' => $saleProduct->amount,
                            'is_vatable' => (bool) $saleProduct->is_vatable,
                            'measure_unit_id' => $saleProduct->measure_unit_id ?? null,
                            'measure_unit_name' => $measureUnit['name'],
                            'measure_unit_quantity' => $measureUnitQuantity,
                            'purchased_quantity' => 0,
                            'return_quantity' => 0,
                            'sale_quantity' => 0,
                            'sales_return_quantity' => 0,
                            'available_quantity' => 0,
                            'expiry_dates' => [],
                            'field_values' => [],
                            'sale_products' => [],
                        ];
                    }

                    // Update min_price if lower
                    if ($saleProduct->price < $products[$productId]['min_price']) {
                        $products[$productId]['min_price'] = $saleProduct->price;
                    }

                    // Calculate sale quantity
                    $saleTotal = ($saleProduct->quantity + ($saleProduct->free_quantity ?? 0)) * $measureUnitQuantity;

                    // Calculate returned quantity
                    $returnProducts = $salesReturnProducts->where('sale_product_id', $saleProduct->id);
                    $returned = 0;
                    $lastReturnMeasureUnitId = null;
                    $lastReturnMeasureUnitQuantity = 1;

                    foreach ($returnProducts as $returnProduct) {
                        $returnMeasureUnitId = $returnProduct->measure_unit_id ?? null;
                        $returnMeasureUnitQuantity = isset($measureUnits[$returnMeasureUnitId]) ? $measureUnits[$returnMeasureUnitId]->quantity : 1;
                        $returnQuantity = ($returnProduct->quantity + ($returnProduct->free_quantity ?? 0)) * $returnMeasureUnitQuantity;
                        $returned += $returnQuantity;
                        $lastReturnMeasureUnitId = $returnMeasureUnitId;
                        $lastReturnMeasureUnitQuantity = $returnMeasureUnitQuantity;

                        Log::info('Processing return product', [
                            'sale_product_id' => $saleProduct->id,
                            'return_product_id' => $returnProduct->id,
                            'return_quantity' => $returnQuantity,
                            'measure_unit_id' => $returnMeasureUnitId,
                            'measure_unit_quantity' => $returnMeasureUnitQuantity,
                        ]);
                    }

                    // Warn if returns exceed sales
                    if ($returned >= $saleTotal) {
                        Log::warning('Return quantity equals or exceeds sale quantity for sale product', [
                            'sale_product_id' => $saleProduct->id,
                            'sale_total' => $saleTotal,
                            'return_total' => $returned,
                        ]);
                    }

                    Log::info('Returned quantity for sale product', [
                        'sale_product_id' => $saleProduct->id,
                        'returned' => $returned,
                        'measure_unit_id' => $lastReturnMeasureUnitId,
                        'measure_unit_quantity' => $lastReturnMeasureUnitQuantity,
                        'return_products' => $returnProducts->toArray(),
                    ]);

                    // Determine returned quantity indices for field values
                    $returnedIndices = [];
                    $saleProductReturnFieldValues = $returnFieldValues[$saleProduct->id] ?? collect([]);
                    if ($saleProduct->fieldValues->isNotEmpty()) {
                        $quantityIndices = $saleProduct->fieldValues->pluck('quantity_index')->unique();
                        foreach ($quantityIndices as $quantityIndex) {
                            $saleFieldValues = $saleProduct->fieldValues->where('quantity_index', $quantityIndex)
                                ->pluck('value', 'product_field_id')
                                ->toArray();

                            $isReturned = true;
                            foreach ($saleFieldValues as $fieldId => $value) {
                                $returnMatch = $saleProductReturnFieldValues->firstWhere(function ($rfv) use ($fieldId, $value, $quantityIndex) {
                                    return $rfv->product_field_id == $fieldId &&
                                        $rfv->value == $value &&
                                        $rfv->quantity_index == $quantityIndex;
                                });

                                if (!$returnMatch) {
                                    $isReturned = false;
                                    break;
                                }
                            }

                            if ($isReturned) {
                                $returnedIndices[] = $quantityIndex;
                            }
                        }
                    } else {
                        if ($returned > 0) {
                            $returnedIndices[] = 0;
                        }
                    }

                    // Calculate available quantity
                    $returnTotal = round($returned, 2);
                    $saleTotal = round($saleTotal, 2);
                    $availableQuantity = max(0, round($saleTotal - $returnTotal, 2));

                    Log::info('Quantity calculation for sale product', [
                        'sale_product_id' => $saleProduct->id,
                        'sale_total' => $saleTotal,
                        'return_total' => $returnTotal,
                        'available_quantity' => $availableQuantity,
                        'measure_unit_quantity' => $measureUnitQuantity,
                        'sale_product' => $saleProduct->toArray(),
                    ]);

                    $products[$productId]['sale_quantity'] += $saleTotal;
                    $products[$productId]['sales_return_quantity'] += $returnTotal;
                    $products[$productId]['return_quantity'] += $returnTotal;
                    $products[$productId]['available_quantity'] += $availableQuantity;

                    if ($saleProduct->expiry_date && !in_array($saleProduct->expiry_date, $products[$productId]['expiry_dates'])) {
                        $products[$productId]['expiry_dates'][] = $saleProduct->expiry_date;
                    }

                    // Add field values for unreturned quantities
                    if ($saleProduct->fieldValues->isNotEmpty()) {
                        foreach ($saleProduct->fieldValues as $fv) {
                            if (!in_array($fv->quantity_index, $returnedIndices)) {
                                $products[$productId]['field_values'][] = [
                                    'sale_product_id' => $saleProduct->id,
                                    'purchase_product_id' => $saleProduct->purchase_product_id,
                                    'product_field_id' => $fv->product_field_id,
                                    'name' => $fv->name,
                                    'value' => $fv->value,
                                    'quantity_index' => $fv->quantity_index,
                                ];
                                Log::info('Added eligible field value', [
                                    'sale_product_id' => $saleProduct->id,
                                    'quantity_index' => $fv->quantity_index,
                                    'product_field_id' => $fv->product_field_id,
                                    'value' => $fv->value,
                                ]);
                            }
                        }
                    }

                    // Add sale product details
                    $products[$productId]['sale_products'][] = [
                        'sale_product_id' => $saleProduct->id,
                        'sale_id' => $saleProduct->sale_id,
                        'invoice_number' => $sale->invoice_number,
                        'invoice_date' => $sale->invoice_date,
                        'product_id' => $saleProduct->product_id,
                        'product_name' => $saleProduct->product_name,
                        'product_code' => $saleProduct->product_code,
                        'quantity' => $saleProduct->quantity,
                        'free_quantity' => $saleProduct->free_quantity ?? 0,
                        'price' => $saleProduct->price,
                        'is_vatable' => (bool) $saleProduct->is_vatable,
                        'measure_unit_id' => $saleProduct->measure_unit_id,
                        'measure_unit_name' => $measureUnit['name'],
                        'measure_unit_quantity' => $measureUnitQuantity,
                        'available_quantity' => $availableQuantity,
                        'return_quantity' => $returnTotal,
                        'sale_quantity' => $saleTotal,
                        'sales_return_quantity' => $returnTotal,
                        'expiry_date' => $saleProduct->expiry_date,
                        'purchase_product_id' => $saleProduct->purchase_product_id,
                    ];
                }
            }

            // Calculate purchased quantity
            foreach ($products as $productId => &$product) {
                $purchasedTotal = PurchaseProduct::where('product_id', $productId)
                    ->where('purchase_products.company_id', $companyId)
                    ->whereNull('purchase_products.deleted_at')
                    ->join('measure_units', 'purchase_products.measure_unit_id', '=', 'measure_units.id')
                    ->where('measure_units.company_id', $companyId)
                    ->whereNull('measure_units.deleted_at')
                    ->sum(DB::raw('(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) * measure_units.quantity'));

                $product['purchased_quantity'] = (int) ($purchasedTotal ?? 0);
                Log::info('Purchased quantity calculated', [
                    'product_id' => $productId,
                    'purchased_quantity' => $purchasedTotal,
                ]);

                Log::info('Total quantities for product', [
                    'product_id' => $productId,
                    'sale_quantity' => $product['sale_quantity'],
                    'sales_return_quantity' => $product['sales_return_quantity'],
                    'return_quantity' => $product['return_quantity'],
                    'available_quantity' => $product['available_quantity'],
                    'sale_products' => array_column($product['sale_products'], 'sale_product_id'),
                ]);
            }

            // Filter products with available quantity
            $products = array_filter($products, function ($product) {
                Log::info('Filtering product', [
                    'product_id' => $product['product_id'],
                    'available_quantity' => $product['available_quantity'],
                ]);
                return $product['available_quantity'] > 0;
            });

            if (empty($products)) {
                Log::warning('No available products after processing', [
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'company_id' => $companyId,
                    'sale_id' => $saleId,
                ]);
                return response()->json(['message' => 'No products available for sales return', 'data' => []], 404);
            }

            // Prepare response
            $response = [
                'message' => 'Product details retrieved successfully',
                'data' => [
                    [
                        'products' => array_values($products),
                    ],
                ],
            ];

            Log::info('Final response prepared', [
                'product_count' => count($products),
                'product_ids' => array_keys($products),
                'sale_product_ids' => array_merge(...array_map(function ($product) {
                    return array_column($product['sale_products'], 'sale_product_id');
                }, $products)),
            ]);

            return response()->json($response);

        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Database query error in getAvailableProductsForSalesReturn', [
                'product_id' => $productId,
                'product_name' => $productName,
                'company_id' => $companyId,
                'sale_id' => $saleId,
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);
            return response()->json(['error' => 'Database query error'], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in getAvailableProductsForSalesReturn', [
                'product_id' => $productId,
                'product_name' => $productName,
                'company_id' => $companyId,
                'sale_id' => $saleId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function storeItemWise(Request $request): JsonResponse
    {
        try {
            // Define validation rules
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'customer_id' => 'nullable|exists:customers,id',
                'salesman_id' => 'nullable|exists:salesmen,id',
                'sale_id' => 'nullable|exists:sales,id',
                'invoice_number' => 'nullable|string|max:255|unique:sales_returns,invoice_number',
                'document_number' => 'nullable|string|max:255',
                'batch_no' => 'nullable|string|max:255|unique:sales_returns,batch_no',
                'balance' => 'nullable|numeric|min:0',
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reason' => 'required|string|in:damaged,defective,incorrect,expired,other',
                'store_id' => 'nullable|exists:stores,id',
                'location_id' => 'nullable|exists:locations,id',
                'return_bill_no' => 'nullable|string|max:255',
                'excise_duty' => 'nullable|numeric|min:0',
                'health_insurance' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'sub_total_before_discount' => 'nullable|numeric|min:0',
                'taxable_amount' => 'nullable|numeric|min:0',
                'non_taxable_amount' => 'nullable|numeric|min:0',
                'discount_after_vat' => 'nullable|numeric|min:0',
                'total_amount' => 'nullable|numeric|min:0',
                'round_of_amount' => 'nullable|numeric',
                'roundoff_type' => 'nullable|string|max:255',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'payment_type' => 'nullable|string|in:cash,credit,bank',
                'return_entire_all' => 'nullable|boolean',
                'return_entire_sale' => 'nullable|boolean',
                'return_entire_batch' => 'nullable|boolean',
                'sale_product_ids' => 'nullable|array',
                'sale_product_ids.*' => 'integer|exists:sale_products,id',
                'sales_return_products' => [
                    Rule::requiredIf(function () use ($request) {
                        return !($request->input('return_entire_all', false) ||
                            $request->input('return_entire_sale', false) ||
                            !empty($request->input('sale_product_ids', [])));
                    }),
                    'array',
                    'min:1',
                ],
                'sales_return_products.*.sale_product_id' => 'nullable|integer|exists:sale_products,id',
                'sales_return_products.*.purchase_product_id' => 'nullable|integer|exists:purchase_products,id',
                'sales_return_products.*.product_id' => 'nullable|exists:products,id',
                'sales_return_products.*.product_name' => 'nullable|string|max:255',
                'sales_return_products.*.barcode' => 'nullable|string|max:255',
                'sales_return_products.*.quantity' => 'required|numeric',
                'sales_return_products.*.free_quantity' => 'nullable|numeric',
                'sales_return_products.*.price' => 'nullable|numeric|min:0',
                'sales_return_products.*.amount' => 'nullable|numeric|min:0',
                'sales_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sales_return_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sales_return_products.*.is_vatable' => 'nullable|boolean',
                'sales_return_products.*.measure_unit_id' => 'required|exists:measure_units,id',
                'sales_return_products.*.batch_no' => 'nullable|string|max:255',
                'sales_return_products.*.mfd' => 'nullable|string|max:255',
                'sales_return_products.*.expiry_date' => 'nullable|string|max:255',
                'sales_return_products.*.field_values' => 'nullable|array',
                'sales_return_products.*.field_values.*' => 'array|min:1',
                'sales_return_products.*.field_values.*.*.purchase_product_id' => 'nullable|integer|exists:purchase_products,id',
                'sales_return_products.*.field_values.*.*.sale_product_id' => 'required_if:sales_return_products.*.field_values,exists|integer|exists:sale_products,id',
                'sales_return_products.*.field_values.*.*.product_field_id' => 'required_if:sales_return_products.*.field_values,exists|integer|exists:product_fields,id',
                'sales_return_products.*.field_values.*.*.value' => 'required_if:sales_return_products.*.field_values,exists|string|max:255',
                'sales_return_products.*.field_values.*.*.quantity_index' => 'required_if:sales_return_products.*.field_values,exists|integer|min:0',
                'return_additionals_sale' => 'nullable|array',
                'return_additionals_sale.place' => 'nullable|string|max:255',
                'return_additionals_sale.transport' => 'nullable|string|max:255',
                'return_additionals_sale.vehicle_number' => 'nullable|string|max:255',
                'return_additionals_sale.vehicle_name' => 'nullable|string|max:255',
                'return_additionals_sale.driver_name' => 'nullable|string|max:255',
                'return_additionals_sale.return_code' => 'required_if:return_additionals_sale,exists|string|max:255',
                'return_additionals_sale.driver_contact_number' => 'nullable|string|max:255',
                'return_additionals_sale.return_date' => 'nullable|date',
                'return_additionals_sale.return_time' => 'nullable|date_format:H:i:s',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()->toArray()
                ], 422);
            }

            /** @var array $validated */
            $validated = $validator->validated();
            $validated['return_entire_all'] = $validated['return_entire_all'] ?? $validated['return_entire_sale'] ?? false;
            Log::info('Initial validated sales_return_products', ['sales_return_products' => $validated['sales_return_products'] ?? []]);

            // Fetch all sale products based on product criteria from sales_return_products
            $productIds = array_filter(array_column($validated['sales_return_products'] ?? [], 'product_id'));
            $productNames = array_filter(array_column($validated['sales_return_products'] ?? [], 'product_name'));
            $barcodes = array_filter(array_column($validated['sales_return_products'] ?? [], 'barcode'));

            if (empty($productIds) && empty($productNames) && empty($barcodes)) {
                return response()->json(['error' => 'At least one of product_id, product_name, or barcode must be provided in sales_return_products'], 422);
            }

            $saleProductQuery = SaleProduct::with('fieldValues')
                ->whereHas('sale', function ($query) use ($validated) {
                    $query->where('company_id', $validated['company_id']);
                })
                ->when(!empty($productIds), function ($query) use ($productIds) {
                    $query->whereIn('product_id', $productIds);
                })
                ->when(!empty($productNames), function ($query) use ($productNames) {
                    $query->where(function ($q) use ($productNames) {
                        foreach ($productNames as $name) {
                            $q->orWhere('name', 'like', '%' . $name . '%');
                        }
                    });
                })
                ->when(!empty($barcodes), function ($query) use ($barcodes) {
                    $query->whereIn('product_code', $barcodes);
                })
                ->when(!empty($validated['sale_id']), function ($query) use ($validated) {
                    $query->where('sale_id', $validated['sale_id']);
                })
                ->orderBy('id');

            $allSaleProducts = $saleProductQuery->get();
            if ($allSaleProducts->isEmpty()) {
                Log::error('No sale products found', [
                    'product_ids' => $productIds,
                    'product_names' => $productNames,
                    'barcodes' => $barcodes,
                    'company_id' => $validated['company_id'],
                    'sale_id' => $validated['sale_id'] ?? null,
                ]);
                return response()->json(['error' => 'No sale products found for the provided criteria'], 404);
            }

            // Select a sale for return context (e.g., for customer_id, store_id)
            $sale = Sale::where('company_id', $validated['company_id'])
                ->whereIn('id', $allSaleProducts->pluck('sale_id')->unique())
                ->orderBy('created_at', 'desc')
                ->first();
            if (!$sale) {
                return response()->json(['error' => 'No valid sale found'], 404);
            }
            $validated['sale_id'] = $sale->id;

            // Helper to get available quantity in primary measure unit
            $getAvailableQuantity = function (SaleProduct $saleProduct, int $companyId): float {
                $measureUnit = MeasureUnit::find($saleProduct->measure_unit_id);
                $conversionFactor = $measureUnit->quantity ?? 1;
                $totalSold = ($saleProduct->quantity + ($saleProduct->free_quantity ?? 0)) * $conversionFactor;

                $returned = SalesReturnProduct::where('sale_product_id', $saleProduct->id)
                    ->where('sales_return_products.company_id', $companyId)
                    ->whereNull('sales_return_products.deleted_at')
                    ->join('measure_units', 'sales_return_products.measure_unit_id', '=', 'measure_units.id')
                    ->sum(DB::raw('(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)'));

                $available = max(0, $totalSold - $returned);
                Log::debug('Available quantity', [
                    'sale_product_id' => $saleProduct->id,
                    'available' => $available,
                ]);

                return $available;
            };

            // Helper to group field values by quantity index
            $getFieldValuesGroupedByQuantityIndex = function ($fieldValues): array {
                return $fieldValues->groupBy('quantity_index')->map(function ($group) {
                    return $group->map(function ($fieldValue) {
                        return [
                            'sale_product_id' => $fieldValue->sale_product_id,
                            'product_field_id' => $fieldValue->product_field_id,
                            'value' => $fieldValue->value,
                            'quantity_index' => $fieldValue->quantity_index,
                        ];
                    })->toArray();
                })->values()->toArray();
            };

            // Calculate fiscal year
            $date = $validated['invoice_date'] ? Carbon::parse($validated['invoice_date']) : now();
            $fiscalYearStart = Carbon::create($date->year, 7, 1);
            $fiscalYear = $date->lessThan($fiscalYearStart)
                ? ($date->year - 1) . '-' . substr($date->year, 2, 2)
                : $date->year . '-' . substr($date->year + 1, 2, 2);

            // Generate unique invoice number
            $prefix = 'RET';
            $currentYear = explode('-', $fiscalYear)[0];
            $maxAttempts = 100;
            $attempt = 0;
            do {
                $latestReturn = SalesReturn::where('invoice_number', 'like', "{$prefix}-{$currentYear}%")
                    ->orderByDesc('created_at')
                    ->lockForUpdate()
                    ->first();
                $nextSequence = '000001';
                if ($latestReturn && preg_match("/^RET-\d{4}-(\d{6})$/", $latestReturn->invoice_number, $matches)) {
                    $nextSequence = str_pad((int) $matches[1] + 1, 6, '0', STR_PAD_LEFT);
                }
                $invoiceNumber = "{$prefix}-{$currentYear}-{$nextSequence}";
                $existingInvoice = SalesReturn::where('invoice_number', $invoiceNumber)->exists();
                $attempt++;
            } while ($existingInvoice && $attempt < $maxAttempts);

            if ($existingInvoice) {
                return response()->json(['error' => 'Unable to generate a unique invoice number'], 500);
            }
            $validated['invoice_number'] = $invoiceNumber;

            // Handle return modes
            $returnEntireAll = $validated['return_entire_all'];
            $useSaleProductIds = isset($validated['sale_product_ids']) && !empty($validated['sale_product_ids']);
            $salesReturnProducts = [];

            if ($returnEntireAll) {
                $saleProducts = $allSaleProducts->filter(function ($saleProduct) use ($validated) {
                    return $saleProduct->sale_id == $validated['sale_id'];
                });
                if ($saleProducts->isEmpty()) {
                    Log::warning('No sale products found for sale', ['sale_id' => $validated['sale_id']]);
                    return response()->json(['error' => 'No products found in this sale'], 404);
                }

                $salesReturnProducts = $saleProducts->map(function ($saleProduct) use ($validated, $getAvailableQuantity, $getFieldValuesGroupedByQuantityIndex) {
                    $availableQuantity = $getAvailableQuantity($saleProduct, $validated['company_id']);
                    if ($availableQuantity < 0.0001) {
                        return null;
                    }

                    $fieldValues = $getFieldValuesGroupedByQuantityIndex($saleProduct->fieldValues);
                    $measureUnit = MeasureUnit::find($saleProduct->measure_unit_id);
                    $conversionFactor = $measureUnit->quantity ?? 1;
                    $quantity = floor($availableQuantity / $conversionFactor);
                    $freeQuantity = ($availableQuantity / $conversionFactor) - $quantity;

                    return [
                        'sale_product_id' => $saleProduct->id,
                        'purchase_product_id' => $saleProduct->purchase_product_id,
                        'product_id' => $saleProduct->product_id,
                        'quantity' => $quantity,
                        'free_quantity' => $freeQuantity,
                        'price' => $saleProduct->price,
                        'amount' => $saleProduct->amount,
                        'discount_percent' => $saleProduct->discount_percent ?? 0,
                        'discount_amount' => $saleProduct->discount_amount ?? 0,
                        'is_vatable' => $saleProduct->is_vatable,
                        'measure_unit_id' => $saleProduct->measure_unit_id,
                        'batch_no' => $saleProduct->batch_no,
                        'mfd' => $saleProduct->mfd,
                        'expiry_date' => $saleProduct->expiry_date,
                        'field_values' => $fieldValues,
                    ];
                })->filter()->values()->toArray();

                if (empty($salesReturnProducts)) {
                    return response()->json(['error' => 'All products in this sale have already been returned'], 422);
                }
            } elseif ($useSaleProductIds) {
                $saleProducts = SaleProduct::whereIn('id', $validated['sale_product_ids'])
                    ->with('fieldValues')
                    ->whereHas('sale', function ($query) use ($validated) {
                        $query->where('company_id', $validated['company_id']);
                    })
                    ->orderBy('created_at')
                    ->get();

                if ($saleProducts->isEmpty()) {
                    Log::warning('No sale products found for provided IDs', ['sale_product_ids' => $validated['sale_product_ids']]);
                    return response()->json(['error' => 'No valid sale products found for provided IDs'], 404);
                }

                $salesReturnProducts = $saleProducts->map(function ($saleProduct) use ($validated, $getAvailableQuantity, $getFieldValuesGroupedByQuantityIndex) {
                    $availableQuantity = $getAvailableQuantity($saleProduct, $validated['company_id']);
                    if ($availableQuantity < 0.0001) {
                        return null;
                    }

                    $fieldValues = $getFieldValuesGroupedByQuantityIndex($saleProduct->fieldValues);
                    $measureUnit = MeasureUnit::find($saleProduct->measure_unit_id);
                    $conversionFactor = $measureUnit->quantity ?? 1;
                    $quantity = floor($availableQuantity / $conversionFactor);
                    $freeQuantity = ($availableQuantity / $conversionFactor) - $quantity;

                    return [
                        'sale_product_id' => $saleProduct->id,
                        'purchase_product_id' => $saleProduct->purchase_product_id,
                        'product_id' => $saleProduct->product_id,
                        'quantity' => $quantity,
                        'free_quantity' => $freeQuantity,
                        'price' => $saleProduct->price,
                        'amount' => $saleProduct->amount,
                        'discount_percent' => $saleProduct->discount_percent ?? 0,
                        'discount_amount' => $saleProduct->discount_amount ?? 0,
                        'is_vatable' => $saleProduct->is_vatable,
                        'measure_unit_id' => $saleProduct->measure_unit_id,
                        'batch_no' => $saleProduct->batch_no,
                        'mfd' => $saleProduct->mfd,
                        'expiry_date' => $saleProduct->expiry_date,
                        'field_values' => $fieldValues,
                    ];
                })->filter()->values()->toArray();

                if (empty($salesReturnProducts)) {
                    return response()->json(['error' => 'All specified products have already been returned'], 422);
                }
            } else {
                $saleProducts = $allSaleProducts;
                foreach ($validated['sales_return_products'] as $index => $product) {
                    // Validate product criteria for each sales_return_product
                    if (empty($product['product_id']) && empty($product['product_name']) && empty($product['barcode'])) {
                        return response()->json(['error' => "At least one of product_id, product_name, or barcode must be provided for sales_return_products at index {$index}"], 422);
                    }

                    $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);
                    $productId = $product['product_id'];
                    $measureUnitId = $product['measure_unit_id'];

                    // Filter sale products for this specific product
                    $filteredSaleProducts = $saleProducts->filter(function ($saleProduct) use ($product, $productId) {
                        return ($productId && $saleProduct->product_id == $productId) ||
                            (!empty($product['product_name']) && str_contains(strtolower($saleProduct->name), strtolower($product['product_name']))) ||
                            (!empty($product['barcode']) && $saleProduct->product_code == $product['barcode']);
                    });

                    if ($filteredSaleProducts->isEmpty()) {
                        return response()->json(['error' => "No sale products found for product criteria at index {$index}"], 404);
                    }

                    // Validate measure unit
                    /** @var MeasureUnit|null $returnMeasureUnit */
                    $returnMeasureUnit = MeasureUnit::where('id', $measureUnitId)
                        ->where('company_id', $validated['company_id'])
                        ->first();
                    if (!$returnMeasureUnit) {
                        return response()->json(['error' => "Invalid measure unit ID {$measureUnitId} at index {$index}"], 422);
                    }
                    $returnConversionFactor = !empty($product['field_values']) ? 1 : ($returnMeasureUnit->quantity ?? 1);
                    $requestedQuantityPrimary = $requestedQuantity * $returnConversionFactor;

                    // Collect available sale products
                    $availableSaleProducts = [];
                    $totalAvailablePrimary = 0;
                    foreach ($filteredSaleProducts as $saleProduct) {
                        $saleMeasureUnit = MeasureUnit::find($saleProduct->measure_unit_id);
                        $saleConversionFactor = $saleMeasureUnit->quantity ?? 1;
                        $availablePrimary = $getAvailableQuantity($saleProduct, $validated['company_id']);
                        if ($availablePrimary > 0) {
                            $availableSaleProducts[] = [
                                'sale_product' => $saleProduct,
                                'available' => $availablePrimary,
                                'sale_conversion_factor' => $saleConversionFactor,
                            ];
                            $totalAvailablePrimary += $availablePrimary;
                        }
                    }

                    if ($requestedQuantityPrimary > $totalAvailablePrimary) {
                        return response()->json([
                            'error' => "Insufficient quantity for product at index {$index}. Requested: {$requestedQuantityPrimary}, Available: {$totalAvailablePrimary}",
                        ], 422);
                    }

                    // Validate field values
                    $validSaleProductIds = array_map(fn($asp) => $asp['sale_product']['id'], $availableSaleProducts);
                    if (!empty($product['field_values'])) {
                        if (count($product['field_values']) !== (int) $product['quantity']) {
                            return response()->json([
                                'error' => "Field values count (" . count($product['field_values']) . ") must equal quantity ({$product['quantity']}) at index {$index}",
                            ], 422);
                        }

                        foreach ($product['field_values'] as $setIndex => $fieldValueSet) {
                            $fieldIds = array_column($fieldValueSet, 'product_field_id');
                            if (count($fieldIds) !== count(array_unique($fieldIds))) {
                                return response()->json(['error' => "Duplicate product_field_id in field_values set {$setIndex} at index {$index}"], 422);
                            }

                            $saleProductIds = array_unique(array_column($fieldValueSet, 'sale_product_id'));
                            if (count($saleProductIds) !== 1 || !in_array($saleProductIds[0], $validSaleProductIds)) {
                                return response()->json(['error' => "Invalid sale_product_id in field_values set {$setIndex} at index {$index}"], 422);
                            }

                            $fieldValueSaleProductId = $saleProductIds[0];
                            $quantityIndex = $fieldValueSet[0]['quantity_index'];
                            $availableFieldValues = SalesProductFieldValue::where('sale_product_id', $fieldValueSaleProductId)
                                ->where('company_id', $validated['company_id'])
                                ->whereNull('deleted_at')
                                ->get()
                                ->groupBy('quantity_index');

                            if (!isset($availableFieldValues[$quantityIndex])) {
                                return response()->json(['error' => "Invalid quantity_index {$quantityIndex} in field_values set {$setIndex} at index {$index}"], 422);
                            }

                            foreach ($fieldValueSet as $fieldValue) {
                                if (isset($fieldValue['purchase_product_id'])) {
                                    $fieldPurchaseProductId = $fieldValue['purchase_product_id'];
                                    $purchaseProduct = PurchaseProduct::where('id', $fieldPurchaseProductId)
                                        ->where('company_id', $validated['company_id'])
                                        ->where('product_id', $productId)
                                        ->first();
                                    if (!$purchaseProduct || $filteredSaleProducts->firstWhere('id', $fieldValueSaleProductId)->purchase_product_id !== $fieldPurchaseProductId) {
                                        return response()->json(['error' => "Invalid purchase_product_id in field_values set {$setIndex} at index {$index}"], 422);
                                    }
                                }

                                if (
                                    !$availableFieldValues[$quantityIndex]->contains(
                                        fn($item) => $item->product_field_id == $fieldValue['product_field_id'] && $item->value == $fieldValue['value']
                                    )
                                ) {
                                    return response()->json(['error' => "Invalid field value in set {$setIndex} at index {$index}"], 422);
                                }
                            }
                        }
                    }

                    // Allocate quantities across sale products
                    $remainingRequestedPrimary = $requestedQuantityPrimary;
                    $fieldValues = $product['field_values'] ?? [];

                    if (!empty($fieldValues)) {
                        foreach ($fieldValues as $fieldValueSet) {
                            $saleProductId = $fieldValueSet[0]['sale_product_id'];
                            $avail = collect($availableSaleProducts)->firstWhere('sale_product.id', $saleProductId);
                            if (!$avail || $remainingRequestedPrimary <= 0) {
                                continue;
                            }

                            $saleProduct = $avail['sale_product'];
                            $availablePrimary = $avail['available'];
                            $returnQuantityPrimary = min(1.0, $availablePrimary, $remainingRequestedPrimary);
                            $returnQuantity = $returnQuantityPrimary / $returnConversionFactor;
                            $quantityForThisProduct = floor($returnQuantity);
                            $freeQuantity = $returnQuantity - $quantityForThisProduct;
                            $remainingRequestedPrimary -= $returnQuantityPrimary;

                            if ($quantityForThisProduct < 0.0001 && $freeQuantity < 0.0001) {
                                continue;
                            }

                            $salesReturnProducts[] = [
                                'sale_product_id' => $saleProduct->id,
                                'purchase_product_id' => $product['purchase_product_id'] ?? $saleProduct->purchase_product_id,
                                'product_id' => $saleProduct->product_id,
                                'quantity' => $quantityForThisProduct,
                                'free_quantity' => $freeQuantity,
                                'price' => $product['price'] ?? $saleProduct->price,
                                'amount' => $product['amount'] ?? $saleProduct->amount,
                                'discount_percent' => $product['discount_percent'] ?? 0,
                                'discount_amount' => $product['discount_amount'] ?? 0,
                                'is_vatable' => $product['is_vatable'] ?? $saleProduct->is_vatable,
                                'measure_unit_id' => $measureUnitId,
                                'batch_no' => $product['batch_no'] ?? $saleProduct->batch_no,
                                'mfd' => $product['mfd'] ?? $saleProduct->mfd,
                                'expiry_date' => $product['expiry_date'] ?? $saleProduct->expiry_date,
                                'field_values' => [$fieldValueSet],
                            ];
                        }
                    } else {
                        // Sort available sale products by sale_product_id (ascending) for FIFO
                        usort($availableSaleProducts, fn($a, $b) => $a['sale_product']->id <=> $b['sale_product']->id);

                        foreach ($availableSaleProducts as $avail) {
                            $saleProduct = $avail['sale_product'];
                            $availablePrimary = $avail['available'];
                            if ($remainingRequestedPrimary <= 0 || $availablePrimary <= 0) {
                                continue;
                            }

                            $saleMeasureUnit = MeasureUnit::find($saleProduct->measure_unit_id);
                            $saleConversionFactor = $saleMeasureUnit->quantity ?? 1;
                            $compatible = ($saleMeasureUnit->id == $measureUnitId || $saleConversionFactor == $returnConversionFactor);

                            $returnQuantityPrimary = min($availablePrimary, $remainingRequestedPrimary);
                            $returnQuantity = $returnQuantityPrimary / ($compatible ? $saleConversionFactor : $returnConversionFactor);
                            $quantityForThisProduct = floor($returnQuantity);
                            $freeQuantity = $returnQuantity - $quantityForThisProduct;
                            $remainingRequestedPrimary -= $returnQuantityPrimary;

                            if ($quantityForThisProduct < 0.0001 && $freeQuantity < 0.0001) {
                                continue;
                            }

                            $salesReturnProducts[] = [
                                'sale_product_id' => $saleProduct->id,
                                'purchase_product_id' => $product['purchase_product_id'] ?? $saleProduct->purchase_product_id,
                                'product_id' => $saleProduct->product_id,
                                'quantity' => $quantityForThisProduct,
                                'free_quantity' => $freeQuantity,
                                'price' => $product['price'] ?? $saleProduct->price,
                                'amount' => $product['amount'] ?? $saleProduct->amount,
                                'discount_percent' => $product['discount_percent'] ?? 0,
                                'discount_amount' => $product['discount_amount'] ?? 0,
                                'is_vatable' => $product['is_vatable'] ?? $saleProduct->is_vatable,
                                'measure_unit_id' => $compatible ? $saleProduct->measure_unit_id : $measureUnitId,
                                'batch_no' => $product['batch_no'] ?? $saleProduct->batch_no,
                                'mfd' => $product['mfd'] ?? $saleProduct->mfd,
                                'expiry_date' => $product['expiry_date'] ?? $saleProduct->expiry_date,
                                'field_values' => $product['field_values'] ?? [],
                            ];
                        }
                    }

                    if ($remainingRequestedPrimary > 0) {
                        return response()->json([
                            'error' => "Could not allocate sufficient quantity for product at index {$index}. Requested: {$requestedQuantityPrimary}, Allocated: " . ($requestedQuantityPrimary - $remainingRequestedPrimary),
                        ], 422);
                    }
                }
            }

            $validated['sales_return_products'] = $salesReturnProducts;
            if (empty($salesReturnProducts)) {
                return response()->json(['error' => 'No valid products available for return'], 422);
            }

            // Validate return quantities and field values
            foreach ($validated['sales_return_products'] as $index => &$product) {
                $saleProductId = $product['sale_product_id'];
                $productId = $product['product_id'];
                $purchaseProductId = $product['purchase_product_id'] ?? null;
                $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);
                $measureUnitId = $product['measure_unit_id'];

                // Validate sale product
                /** @var SaleProduct|null $saleProduct */
                $saleProduct = SaleProduct::with('fieldValues')
                    ->where('id', $saleProductId)
                    ->whereIn('sale_id', $allSaleProducts->pluck('sale_id')->all())
                    ->first();
                if (!$saleProduct) {
                    return response()->json(['error' => "Sale product ID {$saleProductId} at index {$index} does not belong to a valid sale"], 422);
                }

                // Validate measure unit
                $returnMeasureUnit = MeasureUnit::where('id', $measureUnitId)
                    ->where('company_id', $validated['company_id'])
                    ->first();
                if (!$returnMeasureUnit) {
                    return response()->json(['error' => "Invalid measure unit ID {$measureUnitId} at index {$index}"], 422);
                }

                $returnConversionFactor = !empty($product['field_values']) ? 1 : ($returnMeasureUnit->quantity ?? 1);
                $requestedQuantityPrimary = $requestedQuantity * $returnConversionFactor;
                $availableQuantityPrimary = $getAvailableQuantity($saleProduct, $validated['company_id']);
                if ($requestedQuantityPrimary > $availableQuantityPrimary) {
                    return response()->json([
                        'error' => "Cannot return more than available for sale product ID {$saleProductId} at index {$index}. Available: {$availableQuantityPrimary}, Requested: {$requestedQuantityPrimary}",
                    ], 422);
                }

                // Validate purchase product_id
                if ($purchaseProductId) {
                    $purchaseProduct = PurchaseProduct::where('id', $purchaseProductId)
                        ->where('company_id', $validated['company_id'])
                        ->where('product_id', $productId)
                        ->first();
                    if (!$purchaseProduct || $saleProduct->purchase_product_id !== $purchaseProductId) {
                        return response()->json(['error' => "Invalid purchase product ID {$purchaseProductId} at index {$index}"], 422);
                    }
                }

                // Validate field_values
                if (!empty($product['field_values'])) {
                    $validSaleProductIds = $saleProducts->pluck('id')->all();
                    foreach ($product['field_values'] as $setIndex => $fieldValueSet) {
                        $fieldIds = array_column($fieldValueSet, 'product_field_id');
                        if (count($fieldIds) !== count(array_unique($fieldIds))) {
                            return response()->json(['error' => "Duplicate product_field_id in field_values set {$setIndex} at index {$index}"], 422);
                        }

                        $saleProductIds = array_unique(array_column($fieldValueSet, 'sale_product_id'));
                        if (count($saleProductIds) !== 1 || $saleProductIds[0] !== $saleProductId) {
                            return response()->json(['error' => "Invalid sale_product_id in field_values set {$setIndex} at index {$index}"], 422);
                        }

                        if (!in_array($saleProductIds[0], $validSaleProductIds)) {
                            return response()->json(['error' => "Invalid sale_product_id {$saleProductIds[0]} in field_values set {$setIndex} at index {$index}"], 422);
                        }

                        $quantityIndex = $fieldValueSet[0]['quantity_index'];
                        $availableFieldValues = SalesProductFieldValue::where('sale_product_id', $saleProductId)
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at')
                            ->get()
                            ->groupBy('quantity_index');

                        if (!isset($availableFieldValues[$quantityIndex])) {
                            return response()->json(['error' => "Invalid quantity_index {$quantityIndex} at index {$setIndex} in field_values {$index}"], 422);
                        }

                        foreach ($fieldValueSet as $fieldValue) {
                            if (isset($fieldValue['purchase_product_id'])) {
                                $fieldPurchaseProductId = $fieldValue['purchase_product_id'];
                                $purchaseProduct = PurchaseProduct::where('id', $fieldPurchaseProductId)
                                    ->where('company_id', $validated['company_id'])
                                    ->where('product_id', $productId)
                                    ->first();
                                if (!$purchaseProduct || $saleProduct->purchase_product_id !== $fieldPurchaseProductId) {
                                    return response()->json(['error' => "Invalid purchase_product_id {$fieldPurchaseProductId} in field_values set {$setIndex} at index {$index}"], 422);
                                }
                            }

                            if (
                                !$availableFieldValues[$quantityIndex]->contains(
                                    fn($item) => $item->product_field_id == $fieldValue['product_field_id'] && $item->value == $fieldValue['value']
                                )
                            ) {
                                return response()->json(['error' => "Invalid field value in set {$setIndex} at index {$index}"], 422);
                            }
                        }
                    }
                }
            }

            // Set sale-related fields
            $validated['batch_no'] = $validated['batch_no'] ?? ($sale->batch_no ? $sale->batch_no . '-RETURN' : null);
            $validated['customer_id'] = $validated['customer_id'] ?? $sale->customer_id;
            $validated['salesman_id'] = $validated['salesman_id'] ?? $sale->salesman_id;
            $validated['store_id'] = $validated['store_id'] ?? $sale->store_id;
            $validated['location_id'] = $validated['location_id'] ?? $sale->location_id;

            // Prepare sales return additionals
            $salesReturnAdditionalsData = $validated['return_additionals_sale'] ?? null;
            if (!$salesReturnAdditionalsData && $sale->saleAdditionals->isNotEmpty()) {
                $saleAdditional = $sale->saleAdditionals->first();
                $salesReturnAdditionalsData = [
                    'place' => $saleAdditional->place ?? null,
                    'transport' => $saleAdditional->transport ?? null,
                    'vehicle_number' => $saleAdditional->vehicle_number ?? null,
                    'vehicle_name' => $saleAdditional->vehicle_name ?? null,
                    'driver_name' => $saleAdditional->driver_name ?? null,
                    'return_code' => 'RET-' . now()->format('YmdHis'),
                    'driver_contact_number' => $saleAdditional->driver_contact_number ?? null,
                    'return_date' => now()->toDateString(),
                    'return_time' => now()->toTimeString(),
                ];
            } elseif (!$salesReturnAdditionalsData) {
                $salesReturnAdditionalsData = [
                    'return_code' => 'RET-' . now()->format('YmdHis'),
                    'return_date' => now()->toDateString(),
                    'return_time' => now()->toTimeString(),
                ];
            }

            // Create sales return
            /** @var SalesReturn $salesReturn */
            $salesReturn = DB::transaction(function () use ($validated, $salesReturnAdditionalsData, $getAvailableQuantity, $allSaleProducts) {
                SaleProduct::whereIn('id', $allSaleProducts->pluck('id'))->lockForUpdate()->get();

                // Verify quantities
                $allocatedQuantities = [];
                foreach ($validated['sales_return_products'] as $index => $product) {
                    $saleProductId = $product['sale_product_id'];
                    $saleProduct = SaleProduct::find($saleProductId);
                    if (!$saleProduct) {
                        throw new \Exception("Sale product ID {$saleProductId} not found at index {$index}");
                    }
                    $returnMeasureUnit = MeasureUnit::find($product['measure_unit_id']);
                    $returnConversionFactor = !empty($product['field_values']) ? 1 : ($returnMeasureUnit->quantity ?? 1);
                    $requestedQuantityPrimary = ($product['quantity'] + ($product['free_quantity'] ?? 0)) * $returnConversionFactor;

                    $allocatedQuantities[$saleProductId] = ($allocatedQuantities[$saleProductId] ?? 0) + $requestedQuantityPrimary;
                    $availableQuantityPrimary = $getAvailableQuantity($saleProduct, $validated['company_id']);
                    if ($allocatedQuantities[$saleProductId] > $availableQuantityPrimary) {
                        throw new \Exception("Insufficient quantity for sale product ID {$saleProductId} at index {$index}. Available: {$availableQuantityPrimary}, Requested: {$allocatedQuantities[$saleProductId]}");
                    }
                }

                $salesReturnData = array_intersect_key($validated, array_flip((new SalesReturn)->getFillable()));
                $salesReturn = SalesReturn::create($salesReturnData);

                foreach ($validated['sales_return_products'] as $index => $product) {
                    $product['company_id'] = $validated['company_id'];
                    $product['sales_return_id'] = $salesReturn->id;
                    $saleProduct = SaleProduct::find($product['sale_product_id']);
                    $product['product_code'] = substr($saleProduct->code ?? $saleProduct->product_code ?? '', 0, 255);
                    $product['product_name'] = $saleProduct->name ?? null;

                    /** @var SalesReturnProduct $salesReturnProduct */
                    $salesReturnProduct = $salesReturn->salesReturnProducts()->create($product);

                    if (!empty($product['field_values'])) {
                        $fieldValues = [];
                        foreach ($product['field_values'] as $fieldValueSet) {
                            $quantityIndex = $fieldValueSet[0]['quantity_index'];
                            foreach ($fieldValueSet as $fieldValue) {
                                $fieldValues[] = [
                                    'company_id' => $validated['company_id'],
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'product_id' => $salesReturnProduct->product_id,
                                    'sale_return_product_id' => $salesReturnProduct->id,
                                    'sale_product_id' => $fieldValue['sale_product_id'],
                                    'quantity_index' => $quantityIndex,
                                    'value' => $fieldValue['value'],
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                            }
                        }
                        SaleReturnProductFieldValue::insert($fieldValues);
                        Log::debug('Field values created', ['sale_return_product_id' => $salesReturnProduct->id]);
                    }
                }

                SaleReturnAdditional::create([
                    'company_id' => $validated['company_id'],
                    'sales_return_id' => $salesReturn->id,
                    'place' => $salesReturnAdditionalsData['place'] ?? null,
                    'transport' => $salesReturnAdditionalsData['transport'] ?? null,
                    'vehicle_number' => $salesReturnAdditionalsData['vehicle_number'] ?? null,
                    'vehicle_name' => $salesReturnAdditionalsData['vehicle_name'] ?? null,
                    'driver_name' => $salesReturnAdditionalsData['driver_name'] ?? null,
                    'return_code' => $salesReturnAdditionalsData['return_code'],
                    'driver_contact_number' => $salesReturnAdditionalsData['driver_contact_number'] ?? null,
                    'return_date' => $salesReturnAdditionalsData['return_date'] ?? null,
                    'return_time' => $salesReturnAdditionalsData['return_time'] ?? null,
                ]);

                return $salesReturn;
            });

            return response()->json([
                'message' => 'Sales return created successfully',
                'data' => $salesReturn->load([
                    'salesReturnProducts.fieldValues' => fn($query) => $query->orderBy('quantity_index')->orderBy('product_field_id'),
                    'salesReturnAdditional',
                ]),
            ], 201);
        } catch (ModelNotFoundException $e) {
            Log::error('Model not found', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Resource not found: ' . $e->getMessage()], 404);
        } catch (QueryException $e) {
            Log::error('Database error', ['error' => $e->getMessage(), 'sql' => $e->getSql()]);
            return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error', ['error' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            // Define validation rules
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'customer_id' => 'nullable|exists:customers,id',
                'customer_name' => 'required|string|max:255',
                'salesman_id' => 'nullable|exists:salesmen,id',
                'sale_id' => 'nullable|exists:sales,id',
                'sale_invoice_number' => 'required|string|max:255',
                'invoice_number' => 'nullable|string|max:255|unique:sales_returns,invoice_number',
                'document_number' => 'nullable|string|max:255',
                'ref_bill_no' => 'nullable|string|max:255',
                'batch_no' => 'nullable|string|max:255|unique:sales_returns,batch_no',
                'balance' => 'nullable|numeric|min:0',
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reason' => 'required|string|in:damaged,defective,incorrect,expired,other',
                'store_id' => 'nullable|exists:stores,id',
                'location_id' => 'nullable|exists:locations,id',
                'excise_duty' => 'nullable|numeric|min:0',
                'health_insurance' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'discount_after_vat' => 'nullable|numeric|min:0',
                'total_amount' => 'nullable|numeric|min:0',
                'round_of_amount' => 'nullable|numeric',
                'roundoff_type' => 'nullable|string|max:255',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'payment_type' => 'nullable|string|in:cash,credit,bank',
                'return_entire_all' => 'nullable|boolean',
                'return_entire_sale' => 'nullable|boolean',
                'return_entire_batch' => 'nullable|boolean',
                'sale_product_ids' => 'nullable|array',
                'sale_product_ids.*' => 'integer|exists:sale_products,id',
                'sales_return_products' => [
                    Rule::requiredIf(function () use ($request) {
                        return !($request->input('return_entire_all', false) ||
                            $request->input('return_entire_sale', false) ||
                            !empty($request->input('sale_product_ids', [])));
                    }),
                    'array',
                    'min:1',
                ],
                'sales_return_products.*.sale_product_id' => 'nullable|integer|exists:sale_products,id',
                'sales_return_products.*.purchase_product_id' => 'nullable|integer|exists:purchase_products,id',
                'sales_return_products.*.product_id' => 'required|exists:products,id',
                'sales_return_products.*.quantity' => 'required|numeric',
                'sales_return_products.*.free_quantity' => 'nullable|numeric',
                'sales_return_products.*.price' => 'nullable|numeric|min:0',
                'sales_return_products.*.amount' => 'nullable|numeric|min:0',
                'sales_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sales_return_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sales_return_products.*.is_vatable' => 'nullable|boolean',
                'sales_return_products.*.measure_unit_id' => 'required|exists:measure_units,id',
                'sales_return_products.*.batch_no' => 'nullable|string|max:255',
                'sales_return_products.*.mfd' => 'nullable|string|max:255',
                'sales_return_products.*.expiry_date' => 'nullable|string|max:255',
                'sales_return_products.*.field_values' => 'nullable|array',
                'sales_return_products.*.field_values.*' => 'array|min:1',
                'sales_return_products.*.field_values.*.*.purchase_product_id' => 'nullable|integer|exists:purchase_products,id',
                'sales_return_products.*.field_values.*.*.sale_product_id' => 'required_if:sales_return_products.*.field_values,exists|integer|exists:sale_products,id',
                'sales_return_products.*.field_values.*.*.product_field_id' => 'required_if:sales_return_products.*.field_values,exists|integer|exists:product_fields,id',
                'sales_return_products.*.field_values.*.*.value' => 'required_if:sales_return_products.*.field_values,exists|string|max:255',
                'sales_return_products.*.field_values.*.*.quantity_index' => 'required_if:sales_return_products.*.field_values,exists|integer|min:0',
                'return_additionals_sale' => 'nullable|array',
                'return_additionals_sale.place' => 'nullable|string|max:255',
                'return_additionals_sale.transport' => 'nullable|string|max:255',
                'return_additionals_sale.vehicle_number' => 'nullable|string|max:255',
                'return_additionals_sale.vehicle_name' => 'nullable|string|max:255',
                'return_additionals_sale.driver_name' => 'nullable|string|max:255',
                'return_additionals_sale.return_code' => 'required_if:return_additionals_sale,exists|string|max:255',
                'return_additionals_sale.driver_contact_number' => 'nullable|string|max:255',
                'return_additionals_sale.return_date' => 'nullable|date',
                'return_additionals_sale.return_time' => 'nullable|date_format:H:i:s',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()->toArray()
                ], 422);
            }

            $validated = $validator->validated();
            $validated['return_entire_all'] = $validated['return_entire_all'] ?? $validated['return_entire_sale'] ?? false;
            Log::info('Initial validated sales_return_products', ['sales_return_products' => $validated['sales_return_products'] ?? []]);

            // Fetch sale
            $sale = Sale::with(['saleProducts.fieldValues', 'saleAdditionals'])
                ->where('invoice_number', $validated['sale_invoice_number'])
                ->where('company_id', $validated['company_id'])
                ->first();
            if (!$sale) {
                Log::error('Sale not found', [
                    'sale_invoice_number' => $validated['sale_invoice_number'],
                    'company_id' => $validated['company_id'],
                ]);
                return response()->json(['error' => 'Sale not found for the provided sale_invoice_number'], 404);
            }
            $validated['sale_id'] = $sale->id;

            // Validate sale_id
            if (isset($request['sale_id']) && $validated['sale_id'] !== $request['sale_id']) {
                return response()->json(['error' => 'Provided sale_id does not match sale_invoice_number'], 422);
            }

            // Helper to get available quantity in primary measure unit (e.g., Pieces)
            $getAvailableQuantity = function ($saleProduct, $companyId) {
                if (!$saleProduct) {
                    Log::error('Invalid sale product in getAvailableQuantity', ['company_id' => $companyId]);
                    return 0;
                }

                $measureUnit = MeasureUnit::find($saleProduct->measure_unit_id);
                $conversionFactor = $measureUnit->quantity ?? 1; // Sale unit to primary unit (e.g., 12 for Box)
                $totalSold = ($saleProduct->quantity + ($saleProduct->free_quantity ?? 0)) * $conversionFactor;

                $returned = SalesReturnProduct::where('sale_product_id', $saleProduct->id)
                    ->where('sales_return_products.company_id', $companyId)
                    ->whereNull('sales_return_products.deleted_at')
                    ->join('measure_units', 'sales_return_products.measure_unit_id', '=', 'measure_units.id')
                    ->sum(DB::raw('(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)'));

                $available = max(0, $totalSold - $returned);

                Log::debug('Available quantity calculation', [
                    'sale_product_id' => $saleProduct->id,
                    'product_id' => $saleProduct->product_id,
                    'measure_unit_id' => $saleProduct->measure_unit_id,
                    'company_id' => $companyId,
                    'conversion_factor' => $conversionFactor,
                    'total_sold' => $totalSold,
                    'returned' => $returned,
                    'available' => $available,
                ]);

                return $available; // Return in primary measure unit (e.g., Pieces)
            };

            // Helper to group field values by quantity_index
            $getFieldValuesGroupedByQuantityIndex = function ($fieldValues) {
                $grouped = $fieldValues->groupBy('quantity_index')->map(function ($group) {
                    return $group->map(function ($item) {
                        return [
                            'sale_product_id' => $item->sale_product_id,
                            'purchase_product_id' => $item->purchase_product_id,
                            'product_field_id' => $item->product_field_id,
                            'name' => $item->name,
                            'value' => $item->value,
                            'quantity_index' => $item->quantity_index,
                        ];
                    })->toArray();
                })->values()->toArray();
                return $grouped;
            };

            // Calculate fiscal year
            $date = $request->invoice_date ? Carbon::parse($request->invoice_date) : now();
            $fiscal_year_start = Carbon::create($date->year, 7, 1);
            $fiscalYear = $date->lessThan($fiscal_year_start)
                ? ($date->year - 1) . '-' . substr($date->year, 2, 2)
                : $date->year . '-' . substr($date->year + 1, 2, 2);

            // Generate unique invoice number
            $validated['invoice_number'] = $request->input('invoice_number') ?? $this->generateUniqueInvoiceNumber($fiscalYear);

            // Handle return modes
            $returnEntireAll = $validated['return_entire_all'];
            $useSaleProductIds = isset($validated['sale_product_ids']) && !empty($validated['sale_product_ids']);

            if ($returnEntireAll) {
                $saleProducts = $sale->saleProducts()->with('fieldValues')
                    ->whereHas('sale', function ($query) use ($validated) {
                        $query->where('company_id', $validated['company_id']);
                    })
                    ->orderBy('id')
                    ->get();
                if ($saleProducts->isEmpty()) {
                    Log::warning('No sale products found for sale', [
                        'sale_id' => $sale->id,
                        'sale_invoice_number' => $validated['sale_invoice_number'],
                        'company_id' => $validated['company_id'],
                    ]);
                    return response()->json(['error' => 'No products found in this sale'], 404);
                }

                Log::debug('Sale products for return_entire_all', [
                    'sale_id' => $sale->id,
                    'sale_products' => $saleProducts->map(function ($sp) {
                        return [
                            'id' => $sp->id,
                            'product_id' => $sp->product_id,
                            'measure_unit_id' => $sp->measure_unit_id,
                            'quantity' => $sp->quantity,
                            'free_quantity' => $sp->free_quantity,
                        ];
                    })->toArray(),
                ]);

                $validated['sales_return_products'] = $saleProducts->map(function ($saleProduct) use ($validated, $getAvailableQuantity, $getFieldValuesGroupedByQuantityIndex) {
                    $availableQuantity = $getAvailableQuantity($saleProduct, $validated['company_id']);
                    if ($availableQuantity < 0.0001) {
                        Log::warning('No quantity available for return', [
                            'sale_product_id' => $saleProduct->id,
                            'product_id' => $saleProduct->product_id,
                            'available_quantity' => $availableQuantity,
                        ]);
                        return null;
                    }

                    $fieldValues = $getFieldValuesGroupedByQuantityIndex($saleProduct->fieldValues);

                    $measureUnit = MeasureUnit::find($saleProduct->measure_unit_id);
                    $conversionFactor = $measureUnit->quantity ?? 1;
                    $quantity = floor($availableQuantity / $conversionFactor);
                    $freeQuantity = ($availableQuantity / $conversionFactor) - $quantity;

                    return [
                        'sale_product_id' => $saleProduct->id,
                        'purchase_product_id' => $saleProduct->purchase_product_id,
                        'product_id' => $saleProduct->product_id,
                        'quantity' => $quantity,
                        'free_quantity' => $freeQuantity,
                        'price' => $saleProduct->price,
                        'discount_percent' => $saleProduct->discount_percent ?? 0,
                        'discount_amount' => $saleProduct->discount_amount ?? 0,
                        'is_vatable' => $saleProduct->is_vatable,
                        'measure_unit_id' => $saleProduct->measure_unit_id,
                        'batch_no' => $saleProduct->batch_no ?? null,
                        'mfd' => $saleProduct->mfd,
                        'expiry_date' => $saleProduct->expiry_date,
                        'field_values' => $fieldValues,
                    ];
                })->filter()->values()->toArray();

                if (empty($validated['sales_return_products'])) {
                    return response()->json(['error' => 'All products in this sale have already been returned'], 422);
                }
            } elseif ($useSaleProductIds) {
                $saleProducts = SaleProduct::whereIn('id', $validated['sale_product_ids'])
                    ->with('fieldValues')
                    ->where('sale_id', $validated['sale_id'])
                    ->whereHas('sale', function ($query) use ($validated) {
                        $query->where('company_id', $validated['company_id']);
                    })
                    ->orderBy('created_at')
                    ->get();

                if ($saleProducts->isEmpty()) {
                    Log::warning('No sale products found for provided IDs', [
                        'sale_id' => $validated['sale_id'],
                        'sale_product_ids' => $validated['sale_product_ids'],
                    ]);
                    return response()->json(['error' => 'No valid sale products found for provided IDs'], 404);
                }

                Log::debug('Sale products for useSaleProductIds', [
                    'sale_id' => $sale->id,
                    'sale_products' => $saleProducts->map(function ($sp) {
                        return [
                            'id' => $sp->id,
                            'product_id' => $sp->product_id,
                            'measure_unit_id' => $sp->measure_unit_id,
                            'quantity' => $sp->quantity,
                            'free_quantity' => $sp->free_quantity,
                        ];
                    })->toArray(),
                ]);

                $validated['sales_return_products'] = $saleProducts->map(function ($saleProduct) use ($validated, $getAvailableQuantity, $getFieldValuesGroupedByQuantityIndex) {
                    $availableQuantity = $getAvailableQuantity($saleProduct, $validated['company_id']);
                    if ($availableQuantity < 0.0001) {
                        Log::warning('No quantity available for return', [
                            'sale_product_id' => $saleProduct->id,
                            'product_id' => $saleProduct->product_id,
                            'available_quantity' => $availableQuantity,
                        ]);
                        return null;
                    }

                    $fieldValues = $getFieldValuesGroupedByQuantityIndex($saleProduct->fieldValues);

                    $measureUnit = MeasureUnit::find($saleProduct->measure_unit_id);
                    $conversionFactor = $measureUnit->quantity ?? 1;
                    $quantity = floor($availableQuantity / $conversionFactor);
                    $freeQuantity = ($availableQuantity / $conversionFactor) - $quantity;

                    Log::info('Return specific products calculation', [
                        'sale_product_id' => $saleProduct->id,
                        'product_id' => $saleProduct->product_id,
                        'measure_unit_id' => $saleProduct->measure_unit_id,
                        'available_quantity' => $availableQuantity,
                        'quantity' => $quantity,
                        'free_quantity' => $freeQuantity,
                    ]);

                    return [
                        'sale_product_id' => $saleProduct->id,
                        'purchase_product_id' => $saleProduct->purchase_product_id,
                        'product_id' => $saleProduct->product_id,
                        'quantity' => $quantity,
                        'free_quantity' => $freeQuantity,
                        'price' => $saleProduct->price,
                        'amount' => $saleProduct->amount,
                        'discount_percent' => $saleProduct->discount_percent ?? 0,
                        'discount_amount' => $saleProduct->discount_amount ?? 0,
                        'is_vatable' => $saleProduct->is_vatable ?? false,
                        'measure_unit_id' => $saleProduct->measure_unit_id,
                        'batch_no' => $saleProduct->batch_no ?? null,
                        'mfd' => $saleProduct->mfd,
                        'expiry_date' => $saleProduct->expiry_date,
                        'field_values' => $fieldValues,
                    ];
                })->filter()->values()->toArray();

                if (empty($validated['sales_return_products'])) {
                    return response()->json(['error' => 'All specified products have already been returned'], 422);
                }
            } else {
                $saleProducts = $sale->saleProducts()->with('fieldValues')
                    ->whereHas('sale', function ($query) use ($validated) {
                        $query->where('company_id', $validated['company_id']);
                    })
                    ->orderBy('id')
                    ->get();
                if ($saleProducts->isEmpty()) {
                    Log::warning('No sale products found for sale', [
                        'sale_id' => $sale->id,
                        'sale_invoice_number' => $validated['sale_invoice_number'],
                        'company_id' => $validated['company_id'],
                    ]);
                    return response()->json(['error' => 'No products found in this sale'], 404);
                }

                Log::debug('Sale products found', [
                    'sale_id' => $sale->id,
                    'sale_products' => $saleProducts->map(function ($sp) {
                        return [
                            'id' => $sp->id,
                            'product_id' => $sp->product_id,
                            'measure_unit_id' => $sp->measure_unit_id,
                            'quantity' => $sp->quantity,
                            'free_quantity' => $sp->free_quantity,
                        ];
                    })->toArray(),
                ]);

                $salesReturnProducts = [];
                $usedQuantityIndices = []; // Track used quantity indices to avoid duplication
                foreach ($validated['sales_return_products'] as $index => $product) {
                    $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);
                    $productId = $product['product_id'];
                    $measureUnitId = $product['measure_unit_id'];

                    // Fetch return measure unit
                    $returnMeasureUnit = MeasureUnit::where('id', $measureUnitId)
                        ->where('company_id', $validated['company_id'])
                        ->first();
                    if (!$returnMeasureUnit) {
                        return response()->json([
                            'error' => "Invalid measure unit ID {$measureUnitId} at index {$index}",
                        ], 422);
                    }
                    // Use conversion factor 1 for products with field_values
                    $returnConversionFactor = !empty($product['field_values']) ? 1 : ($returnMeasureUnit->quantity ?? 1);
                    // Requested quantity in primary measure unit (e.g., Pieces)
                    $requestedQuantityPrimary = $requestedQuantity * $returnConversionFactor;

                    Log::debug('Processing manual quantity for product', [
                        'index' => $index,
                        'product_id' => $productId,
                        'quantity' => $product['quantity'],
                        'free_quantity' => $product['free_quantity'] ?? 0,
                        'measure_unit_id' => $measureUnitId,
                        'return_conversion_factor' => $returnConversionFactor,
                        'requested_quantity_primary' => $requestedQuantityPrimary,
                    ]);

                    // Collect available sale products
                    $availableSaleProducts = [];
                    $totalAvailablePrimary = 0;
                    foreach ($saleProducts as $saleProduct) {
                        if ($saleProduct->product_id == $productId) {
                            $saleMeasureUnit = MeasureUnit::find($saleProduct->measure_unit_id);
                            $saleConversionFactor = $saleMeasureUnit->quantity ?? 1; // e.g., 12 for Box
                            $availablePrimary = $getAvailableQuantity($saleProduct, $validated['company_id']); // In primary unit
                            if ($availablePrimary > 0) {
                                $availableSaleProducts[] = [
                                    'sale_product' => $saleProduct,
                                    'available' => $availablePrimary,
                                    'sale_conversion_factor' => $saleConversionFactor,
                                ];
                                $totalAvailablePrimary += $availablePrimary;
                            }
                        }
                    }

                    Log::debug('Total available quantity', [
                        'product_id' => $productId,
                        'measure_unit_id' => $measureUnitId,
                        'total_available_primary' => $totalAvailablePrimary,
                        'available_sale_products' => array_map(function ($asp) {
                            return [
                                'sale_product_id' => $asp['sale_product']->id,
                                'available' => $asp['available'],
                            ];
                        }, $availableSaleProducts),
                    ]);

                    if ($requestedQuantityPrimary > $totalAvailablePrimary) {
                        return response()->json([
                            'error' => "Insufficient available quantity for product ID {$productId} at index {$index}. Requested: {$requestedQuantityPrimary}, Available: {$totalAvailablePrimary}. All quantities may have been returned.",
                        ], 422);
                    }

                    // Allocation loop
                    $remainingRequestedPrimary = $requestedQuantityPrimary; // e.g., 2.0
                    $fieldValues = $product['field_values'] ?? [];

                    // Validate available sale products
                    $validSaleProductIds = array_map(fn($asp) => $asp['sale_product']->id, $availableSaleProducts);

                    // Handle allocation with field values
                    if (!empty($fieldValues)) {
                        // Validate field value sale_product_ids
                        $fieldValueSaleProductIds = array_unique(array_map(fn($set) => $set[0]['sale_product_id'], $fieldValues));
                        if (array_diff($fieldValueSaleProductIds, $validSaleProductIds)) {
                            Log::error('Invalid sale_product_ids in field_values', ['field_value_sale_product_ids' => $fieldValueSaleProductIds, 'valid_sale_product_ids' => $validSaleProductIds]);
                            return response()->json([
                                'error' => "Field values contain invalid sale_product_ids for product ID {$productId} at index {$index}",
                            ], 422);
                        }

                        // Allocate one unit per field value set
                        foreach ($fieldValues as $fieldValueSet) {
                            $saleProductId = $fieldValueSet[0]['sale_product_id'];
                            $avail = collect($availableSaleProducts)->firstWhere('sale_product.id', $saleProductId);
                            if (!$avail || $avail['available'] <= 0 || $remainingRequestedPrimary <= 0) {
                                Log::warning('Skipping allocation due to unavailable product or fulfilled request', ['sale_product_id' => $saleProductId, 'available' => $avail['available'] ?? 0, 'remaining' => $remainingRequestedPrimary]);
                                continue;
                            }

                            $saleProduct = $avail['sale_product'];
                            $availablePrimary = $avail['available'];
                            $saleConversionFactor = $avail['sale_conversion_factor'];

                            // Allocate 1 unit (since field_values count = quantity)
                            $quantityIndex = $fieldValueSet[0]['quantity_index'];
                            if (in_array($quantityIndex, $usedQuantityIndices)) {
                                Log::warning('Duplicate quantity_index detected', ['quantity_index' => $quantityIndex, 'sale_product_id' => $saleProductId]);
                                continue; // Skip if quantity_index is already used
                            }
                            $usedQuantityIndices[] = $quantityIndex;

                            $returnQuantityPrimary = min(1.0, $availablePrimary, $remainingRequestedPrimary);
                            $returnQuantity = $returnQuantityPrimary / $returnConversionFactor; // e.g., 1.0 / 1 = 1.0
                            $quantityForThisProduct = floor($returnQuantity); // e.g., 1.0
                            $freeQuantity = $returnQuantity - $quantityForThisProduct; // e.g., 0.0
                            $remainingRequestedPrimary -= $returnQuantityPrimary;

                            if ($quantityForThisProduct < 0.0001 && $freeQuantity < 0.0001) {
                                continue;
                            }

                            $salesReturnProducts[] = [
                                'sale_product_id' => $saleProduct->id,
                                'purchase_product_id' => $saleProduct->purchase_product_id,
                                'product_id' => $saleProduct->product_id,
                                'quantity' => $quantityForThisProduct,
                                'free_quantity' => $freeQuantity,
                                'price' => $product['price'],
                                'amount' => $product['amount'],
                                'discount_percent' => $product['discount_percent'] ?? 0,
                                'discount_amount' => $product['discount_amount'] ?? 0,
                                'is_vatable' => $product['is_vatable'] ?? $saleProduct->is_vatable,
                                'measure_unit_id' => $measureUnitId,
                                'batch_no' => $product['batch_no'] ?? null,
                                'mfd' => $product['mfd'] ?? $saleProduct->mfd,
                                'expiry_date' => $product['expiry_date'] ?? $saleProduct->expiry_date,
                                'field_values' => [$fieldValueSet], // Store only the relevant field value set
                            ];

                            Log::info('Manual quantity allocation with field values', [
                                'sale_product_id' => $saleProduct->id,
                                'product_id' => $saleProduct->product_id,
                                'measure_unit_id' => $measureUnitId,
                                'available_primary' => $availablePrimary,
                                'quantity_allocated' => $quantityForThisProduct,
                                'free_quantity' => $freeQuantity,
                                'remaining_requested_primary' => $remainingRequestedPrimary,
                                'field_values' => $fieldValueSet,
                            ]);
                        }
                    } else {
                        // Fallback for no field values
                        foreach ($availableSaleProducts as $avail) {
                            $saleProduct = $avail['sale_product'];
                            $availablePrimary = $avail['available'];
                            $saleConversionFactor = $avail['sale_conversion_factor'];
                            if ($remainingRequestedPrimary <= 0 || $availablePrimary <= 0) {
                                continue;
                            }

                            // Allocate in primary measure unit
                            $returnQuantityPrimary = min($availablePrimary, $remainingRequestedPrimary);
                            // Convert back to return measure unit
                            $returnQuantity = $returnQuantityPrimary / $returnConversionFactor;
                            $quantityForThisProduct = floor($returnQuantity);
                            $freeQuantity = $returnQuantity - $quantityForThisProduct;
                            $remainingRequestedPrimary -= $returnQuantityPrimary;

                            if ($quantityForThisProduct < 0.0001 && $freeQuantity < 0.0001) {
                                continue;
                            }

                            $salesReturnProducts[] = [
                                'sale_product_id' => $saleProduct->id,
                                'purchase_product_id' => $saleProduct->purchase_product_id,
                                'product_id' => $saleProduct->product_id,
                                'quantity' => $quantityForThisProduct,
                                'free_quantity' => $freeQuantity,
                                'price' => $product['price'],
                                'amount' => $product['amount'],
                                'discount_percent' => $product['discount_percent'] ?? 0,
                                'discount_amount' => $product['discount_amount'] ?? 0,
                                'is_vatable' => $product['is_vatable'] ?? $saleProduct->is_vatable,
                                'measure_unit_id' => $measureUnitId, // Use input measure unit
                                'batch_no' => $product['batch_no'] ?? null,
                                'mfd' => $product['mfd'] ?? $saleProduct->mfd,
                                'expiry_date' => $product['expiry_date'] ?? $saleProduct->expiry_date,
                                'field_values' => $product['field_values'],
                            ];

                            Log::info('Manual quantity allocation', [
                                'sale_product_id' => $saleProduct->id,
                                'product_id' => $saleProduct->product_id,
                                'measure_unit_id' => $measureUnitId,
                                'available_primary' => $availablePrimary,
                                'quantity_allocated' => $quantityForThisProduct,
                                'free_quantity' => $freeQuantity,
                                'remaining_requested_primary' => $remainingRequestedPrimary,
                            ]);
                        }
                    }

                    // Debugging: Log all allocations
                    Log::info('Final sales return products', ['sales_return_products' => $salesReturnProducts]);

                    if ($remainingRequestedPrimary > 0) {
                        return response()->json([
                            'error' => "Could not allocate sufficient quantity for product ID {$productId} at index {$index}. Requested: {$requestedQuantityPrimary}, Allocated: " . ($requestedQuantityPrimary - $remainingRequestedPrimary),
                        ], 422);
                    }
                }

                $validated['sales_return_products'] = $salesReturnProducts;

                Log::debug('Sales return products after allocation', [
                    'sales_return_products' => $validated['sales_return_products'],
                ]);

                if (empty($validated['sales_return_products'])) {
                    return response()->json(['error' => 'No valid products available for return'], 422);
                }
            }

            // Set sale-related fields
            $validated['batch_no'] = $validated['batch_no'] ?? ($sale->batch_no ? $sale->batch_no . '-RETURN' : null);
            $validated['customer_id'] = $validated['customer_id'] ?? $sale->customer_id;
            $validated['salesman_id'] = $validated['salesman_id'] ?? $sale->salesman_id;
            $validated['store_id'] = $validated['store_id'] ?? $sale->store_id;
            $validated['location_id'] = $validated['location_id'] ?? $sale->location_id;

            // Validate return quantities and field values
            foreach ($validated['sales_return_products'] as $index => &$product) {
                Log::debug('Validating sales return product at index ' . $index, ['product' => $product]);
                $saleProductId = $product['sale_product_id'];
                $productId = $product['product_id'];
                $purchaseProductId = $product['purchase_product_id'];
                $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);

                $measureUnitId = $product['measure_unit_id'];

                // Validate sale_product_id
                $saleProduct = SaleProduct::with('fieldValues')
                    ->where('id', $saleProductId)
                    ->where('sale_id', $validated['sale_id'])
                    ->whereHas('sale', function ($query) use ($validated) {
                        $query->where('company_id', $validated['company_id']);
                    })
                    ->first();
                if (!$saleProduct) {
                    return response()->json([
                        'error' => "Sale product ID {$saleProductId} at index {$index} does not belong to sale ID {$validated['sale_id']}",
                    ], 422);
                }

                // Validate measure unit
                $returnMeasureUnit = MeasureUnit::where('id', $measureUnitId)
                    ->where('company_id', $validated['company_id'])
                    ->first();
                if (!$returnMeasureUnit) {
                    return response()->json([
                        'error' => "Invalid measure unit ID {$measureUnitId} at index {$index}",
                    ], 422);
                }

                // Use conversion factor 1 for products with field_values
                $returnConversionFactor = !empty($product['field_values']) ? 1 : ($returnMeasureUnit->quantity ?? 1);
                $requestedQuantityPrimary = $requestedQuantity * $returnConversionFactor;

                // Check available quantity in primary measure unit
                $availableQuantityPrimary = $getAvailableQuantity($saleProduct, $validated['company_id']);
                if ($requestedQuantityPrimary > $availableQuantityPrimary) {
                    return response()->json([
                        'error' => "Cannot return more than available for sale product ID {$saleProductId} at index {$index}. Available: {$availableQuantityPrimary}, Requested: {$requestedQuantityPrimary}. Product may have been fully returned.",
                    ], 422);
                }

                // Validate purchase_product_id
                if ($purchaseProductId) {
                    $purchaseProduct = PurchaseProduct::where('id', $purchaseProductId)
                        ->where('company_id', $validated['company_id'])
                        ->where('product_id', $productId)
                        ->first();
                    if (!$purchaseProduct) {
                        return response()->json([
                            'error' => "Purchase product ID {$purchaseProductId} at index {$index} is invalid for product ID {$productId}",
                        ], 422);
                    }
                    if ($saleProduct->purchase_product_id !== $purchaseProductId) {
                        return response()->json([
                            'error' => "Purchase product ID {$purchaseProductId} at index {$index} does not match sale product ID {$saleProductId} (expected {$saleProduct->purchase_product_id})",
                        ], 422);
                    }
                }

                // Validate field_values
                $hasFieldValues = SalesProductFieldValue::where('sale_product_id', $saleProductId)
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->exists();

                if (!($returnEntireAll || $useSaleProductIds) && !empty($product['field_values'])) {
                    if (count($product['field_values']) !== (int) $product['quantity']) {
                        return response()->json([
                            'error' => "Field values count (" . count($product['field_values']) . ") must equal quantity ({$product['quantity']}) for product ID {$productId} at index {$index}",
                        ], 422);
                    }
                }

                if (!empty($product['field_values'])) {
                    $validSaleProductIds = SaleProduct::where('sale_id', $validated['sale_id'])
                        ->where('product_id', $productId)
                        ->pluck('id')
                        ->toArray();

                    foreach ($product['field_values'] as $setIndex => $fieldValueSet) {
                        $fieldIds = array_column($fieldValueSet, 'product_field_id');
                        if (count($fieldIds) !== count(array_unique($fieldIds))) {
                            return response()->json([
                                'error' => "Duplicate product_field_id found in field_values set {$setIndex} for product ID {$productId} at index {$index}",
                            ], 422);
                        }

                        $saleProductIds = array_unique(array_column($fieldValueSet, 'sale_product_id'));
                        if (count($saleProductIds) !== 1) {
                            return response()->json([
                                'error' => "Multiple sale_product_ids found in field_values set {$setIndex} for product ID {$productId} at index {$index}",
                            ], 422);
                        }
                        $fieldValueSaleProductId = $saleProductIds[0];
                        if (!in_array($fieldValueSaleProductId, $validSaleProductIds)) {
                            return response()->json([
                                'error' => "Invalid sale_product_id {$fieldValueSaleProductId} in field_values set {$setIndex} for product ID {$productId} at index {$index}",
                            ], 422);
                        }
                        if ($fieldValueSaleProductId !== $saleProductId) {
                            return response()->json([
                                'error' => "Sale product ID {$fieldValueSaleProductId} in field_values set {$setIndex} does not match sale product ID {$saleProductId} at index {$index}",
                            ], 422);
                        }

                        foreach ($fieldValueSet as $fieldValue) {
                            if (isset($fieldValue['purchase_product_id'])) {
                                $fieldPurchaseProductId = $fieldValue['purchase_product_id'];
                                $purchaseProduct = PurchaseProduct::where('id', $fieldPurchaseProductId)
                                    ->where('company_id', $validated['company_id'])
                                    ->where('product_id', $productId)
                                    ->first();
                                if (!$purchaseProduct) {
                                    return response()->json([
                                        'error' => "Invalid purchase_product_id {$fieldPurchaseProductId} in field_values set {$setIndex} for product ID {$productId} at index {$index}",
                                    ], 422);
                                }
                                if ($saleProduct->purchase_product_id !== $fieldPurchaseProductId) {
                                    return response()->json([
                                        'error' => "Purchase product ID {$fieldPurchaseProductId} in field_values set {$setIndex} does not match sale product ID {$saleProductId} (expected {$saleProduct->purchase_product_id}) at index {$index}",
                                    ], 422);
                                }
                            }
                        }
                    }
                }
            }

            // Prepare sales return additionals
            $salesReturnAdditionalsData = $validated['return_additionals_sale'] ?? null;
            if (!$salesReturnAdditionalsData && $sale->saleAdditionals->isNotEmpty()) {
                $saleAdditional = $sale->saleAdditionals->first();
                $salesReturnAdditionalsData = [
                    'place' => $saleAdditional->place ?? null,
                    'transport' => $saleAdditional->transport ?? null,
                    'vehicle_number' => $saleAdditional->vehicle_number ?? null,
                    'vehicle_name' => $saleAdditional->vehicle_name ?? null,
                    'driver_name' => $saleAdditional->driver_name ?? null,
                    'return_code' => 'RET-' . now()->format('YmdHis'),
                    'driver_contact_number' => $saleAdditional->driver_contact_number ?? null,
                    'return_date' => now()->toDateString(),
                    'return_time' => now()->toTimeString(),
                ];
            } elseif (!$salesReturnAdditionalsData) {
                $salesReturnAdditionalsData = [
                    'return_code' => 'RET-' . now()->format('YmdHis'),
                    'return_date' => now()->toDateString(),
                    'return_time' => now()->toTimeString(),
                ];
            }

            $salesReturnData = array_intersect_key($validated, array_flip((new SalesReturn)->getFillable()));

            $salesReturn = DB::transaction(function () use ($salesReturnData, $validated, $salesReturnAdditionalsData, $getAvailableQuantity) {
                // Lock sale_products to prevent concurrent modifications
                SaleProduct::where('sale_id', $validated['sale_id'])
                    ->lockForUpdate()
                    ->get();

                // Track allocated quantities per sale_product_id
                $allocatedQuantities = [];
                foreach ($validated['sales_return_products'] as $index => $product) {
                    $saleProductId = $product['sale_product_id'];
                    $saleProduct = SaleProduct::find($saleProductId);
                    if (!$saleProduct) {
                        throw new \Exception("Sale product ID {$saleProductId} not found at index {$index}");
                    }
                    $returnMeasureUnit = MeasureUnit::find($product['measure_unit_id']);
                    $returnConversionFactor = $returnMeasureUnit->quantity ?? 1;
                    $requestedQuantityPrimary = ($product['quantity'] + ($product['free_quantity'] ?? 0)) * $returnConversionFactor;

                    if (!isset($allocatedQuantities[$saleProductId])) {
                        $allocatedQuantities[$saleProductId] = 0;
                    }
                    $allocatedQuantities[$saleProductId] += $requestedQuantityPrimary;

                    $availableQuantityPrimary = $getAvailableQuantity($saleProduct, $validated['company_id']);
                    if ($allocatedQuantities[$saleProductId] > $availableQuantityPrimary) {
                        throw new \Exception("Insufficient available quantity for sale product ID {$saleProductId} at index {$index}. Available: {$availableQuantityPrimary}, Requested: {$allocatedQuantities[$saleProductId]}. Product may have been fully returned.");
                    }
                }

                $salesReturn = SalesReturn::create($salesReturnData);

                foreach ($validated['sales_return_products'] as $index => $product) {
                    $product['company_id'] = $validated['company_id'];
                    $product['sales_return_id'] = $salesReturn->id;
                    $saleProduct = SaleProduct::find($product['sale_product_id']);
                    $product['product_code'] = $saleProduct->code ?? $saleProduct->product_code ?? null;
                    $product['product_name'] = $saleProduct->name ?? null;

                    // Fetch available field values from original sale
                    $availableFieldValues = SalesProductFieldValue::withoutGlobalScopes()
                        ->select([
                            'quantity_index',
                            'product_field_id',
                            'value',
                            'sale_product_id',
                        ])
                        ->where('company_id', $validated['company_id'])
                        ->whereNull('deleted_at')
                        ->where('sale_product_id', $product['sale_product_id'])
                        ->get();

                    Log::info('Available field values for sales return', [
                        'sale_product_id' => $product['sale_product_id'],
                        'product_id' => $product['product_id'],
                        'field_values' => $availableFieldValues->toArray(),
                    ]);

                    if (isset($product['product_code']) && strlen($product['product_code']) > 255) {
                        $product['product_code'] = substr($product['product_code'], 0, 255);
                        Log::warning('Truncated product code for sales return product', [
                            'product_id' => $product['product_id'],
                            'original_length' => strlen($product['product_code']),
                            'truncated_code' => $product['product_code'],
                        ]);
                    }

                    $salesReturnProduct = $salesReturn->salesReturnProducts()->create($product);

                    if (!empty($product['field_values'])) {
                        $fieldValues = [];
                        $selectedQuantityIndices = [];
                        foreach ($product['field_values'] as $fieldValueSet) {
                            $quantityIndex = $fieldValueSet[0]['quantity_index'] ?? null;
                            $saleProductId = $fieldValueSet[0]['sale_product_id'];

                            $matched = false;
                            foreach ($availableFieldValues->groupBy('quantity_index') as $availIndex => $availableGroup) {
                                if ($availIndex != $quantityIndex || in_array($availIndex, $selectedQuantityIndices)) {
                                    continue;
                                }
                                $matchesAllFields = true;
                                foreach ($fieldValueSet as $fieldValue) {
                                    $found = $availableGroup->contains(function ($item) use ($fieldValue) {
                                        return $item->product_field_id == $fieldValue['product_field_id'] &&
                                            $item->value == $fieldValue['value'];
                                    });
                                    if (!$found) {
                                        $matchesAllFields = false;
                                        break;
                                    }
                                }
                                if ($matchesAllFields) {
                                    foreach ($fieldValueSet as $fieldValue) {
                                        $fieldValues[] = [
                                            'company_id' => $validated['company_id'],
                                            'product_field_id' => $fieldValue['product_field_id'],
                                            'product_id' => $salesReturnProduct->product_id,
                                            'sale_return_product_id' => $salesReturnProduct->id,
                                            'sale_product_id' => $saleProductId,
                                            'quantity_index' => $quantityIndex,
                                            'value' => $fieldValue['value'],
                                            'created_at' => now(),
                                            'updated_at' => now(),
                                        ];
                                    }
                                    $selectedQuantityIndices[] = $quantityIndex;
                                    $matched = true;
                                    break;
                                }
                            }
                            if (!$matched) {
                                Log::error('Field values mismatch for sales return', [
                                    'sale_product_id' => $saleProductId,
                                    'product_id' => $product['product_id'],
                                    'quantity_index' => $quantityIndex,
                                    'field_values_set' => $fieldValueSet,
                                    'available_field_values' => $availableFieldValues->toArray(),
                                ]);
                                throw new \Exception("Provided field values (quantity_index: {$quantityIndex}) do not match available values for sale product ID {$saleProductId} at index {$index}. Available quantity indices: " . implode(', ', $availableFieldValues->pluck('quantity_index')->unique()->toArray()));
                            }
                        }

                        if (count($selectedQuantityIndices) > (int) $product['quantity']) {
                            throw new \Exception("Number of field value sets (" . count($selectedQuantityIndices) . ") exceeds requested quantity (" . $product['quantity'] . ") for sale product ID {$saleProductId} at index {$index}");
                        }

                        if (!empty($fieldValues)) {
                            SaleReturnProductFieldValue::insert($fieldValues);
                            Log::debug('SaleReturnProductFieldValues created', [
                                'sale_return_product_id' => $salesReturnProduct->id,
                                'sale_product_id' => $saleProductId,
                                'field_values' => $fieldValues,
                            ]);
                        }
                    }
                }

                SaleReturnAdditional::create([
                    'company_id' => $validated['company_id'],
                    'sales_return_id' => $salesReturn->id,
                    'place' => $salesReturnAdditionalsData['place'] ?? null,
                    'transport' => $salesReturnAdditionalsData['transport'] ?? null,
                    'vehicle_number' => $salesReturnAdditionalsData['vehicle_number'] ?? null,
                    'vehicle_name' => $salesReturnAdditionalsData['vehicle_name'] ?? null,
                    'driver_name' => $salesReturnAdditionalsData['driver_name'] ?? null,
                    'return_code' => $salesReturnAdditionalsData['return_code'],
                    'driver_contact_number' => $salesReturnAdditionalsData['driver_contact_number'] ?? null,
                    'return_date' => $salesReturnAdditionalsData['return_date'],
                    'return_time' => $salesReturnAdditionalsData['return_time'],
                ]);

                return $salesReturn;
            });

            return response()->json([
                'message' => 'Sales Return created successfully',
                'data' => $salesReturn->load([
                    'salesReturnProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index')->orderBy('product_field_id');
                    },
                    'salesReturnAdditional',
                ]),
            ], 201);
        } catch (ModelNotFoundException $e) {
            Log::error('Model not found during sales return creation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Resource not found: ' . $e->getMessage()], 404);
        } catch (QueryException $e) {
            Log::error('Database error during sales return creation', [
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);
            return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error during sales return creation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }


    private function getFieldValuesGroupedByQuantityIndex($fieldValues)
    {
        return $fieldValues->groupBy('quantity_index')->map(function ($group) {
            return $group->map(function ($field) {
                return [
                    'product_field_id' => $field->product_field_id,
                    'purchase_product_id' => $field->sale_product_id ?? null,
                    'value' => $field->value,
                    'quantity_index' => $field->quantity_index,
                    'sale_product_id' => $field->sale_product_id ?? null,
                ];
            })->toArray();
        })->values()->toArray();
    }



    public function update(Request $request, $id): JsonResponse
    {
        try {
            $salesReturn = SalesReturn::with(['salesReturnProducts.fieldValues', 'salesReturnAdditional'])
                ->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'customer_id' => 'required|exists:customers,id',
                'salesman_id' => 'nullable|exists:salesmen,id',
                'sale_id' => ['nullable', 'integer', Rule::exists('sales', 'id')->where('company_id', $request->input('company_id'))],
                'invoice_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('sales_returns', 'invoice_number')->ignore($id),
                ],
                'document_number' => 'nullable|string|max:255',
                'batch_no' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('sales_returns', 'batch_no')->ignore($id),
                ],
                'balance' => 'nullable|numeric',
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reason' => 'required|string|in:damaged,defective,incorrect,expired,other',
                'store_id' => 'required|exists:stores,id',
                'location_id' => 'required|exists:locations,id',
                'excise_duty' => 'nullable|numeric|min:0',
                'health_insurance' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'discount_after_vat' => 'nullable|numeric|min:0',
                'total_amount' => 'nullable|numeric|min:0',
                'round_of_amount' => 'nullable|numeric',
                'roundoff_type' => 'nullable|string|max:255',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'payment_type' => 'nullable|string|in:cash,credit,bank',
                'return_entire_sale' => 'nullable|boolean',
                'return_entire_batch' => 'nullable|boolean',
                'sale_product_ids' => 'nullable|array',
                'sale_product_ids.*' => ['integer', Rule::exists('sale_products', 'id')->where('sale_id', $salesReturn->sale_id)],
                'sales_return_products' => [
                    Rule::requiredIf(function () use ($request) {
                        return !($request->input('return_entire_sale', false) ||
                            $request->input('return_entire_batch', false) ||
                            !empty($request->input('sale_product_ids', [])));
                    }),
                    'array',
                    'min:1',
                ],
                'sales_return_products.*.id' => ['nullable', 'integer', Rule::exists('sales_return_products', 'id')->where('sales_return_id', $id)],
                'sales_return_products.*.sale_product_id' => ['required', 'integer', Rule::exists('sale_products', 'id')->where('sale_id', $salesReturn->sale_id)],
                'sales_return_products.*.purchase_product_id' => 'required|integer|exists:purchase_products,id',
                'sales_return_products.*.product_id' => 'required|exists:products,id',
                'sales_return_products.*.quantity' => 'required|numeric|min:0',
                'sales_return_products.*.free_quantity' => 'nullable|numeric|min:0',
                'sales_return_products.*.price' => 'required|numeric|min:0',
                'sales_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sales_return_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sales_return_products.*.is_vatable' => 'nullable|boolean',
                'sales_return_products.*.measure_unit_id' => 'required|exists:measure_units,id',
                'sales_return_products.*.batch_no' => 'required|string|max:255',
                'sales_return_products.*.mfd' => 'nullable|string|max:255',
                'sales_return_products.*.expiry_date' => 'nullable|string|max:255',
                'sales_return_products.*.field_values' => 'nullable|array',
                'sales_return_products.*.field_values.*' => 'array|min:1',
                'sales_return_products.*.field_values.*.*.id' => ['nullable', 'integer', Rule::exists('sale_return_product_field_values', 'id')],
                'sales_return_products.*.field_values.*.*.product_field_id' => 'required|integer|exists:product_fields,id',
                'sales_return_products.*.field_values.*.*.value' => 'required|string|max:255',
                'sales_return_additionals' => 'nullable|array',
                'sales_return_additionals.place' => 'nullable|string|max:255',
                'sales_return_additionals.transport' => 'nullable|string|max:255',
                'sales_return_additionals.vehicle_number' => 'nullable|string|max:255',
                'sales_return_additionals.vehicle_name' => 'nullable|string|max:255',
                'sales_return_additionals.driver_name' => 'nullable|string|max:255',
                'sales_return_additionals.return_code' => 'required_with:sales_return_additionals|string|max:255',
                'sales_return_additionals.driver_contact_number' => 'nullable|string|max:255',
                'sales_return_additionals.return_date' => 'nullable|date',
                'sales_return_additionals.return_time' => 'nullable|date_format:H:i:s',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            Log::debug('Initial validated sales_return_products', ['sales_return_products' => $validated['sales_return_products'] ?? []]);

            // Initialize control flags
            $returnEntireBatch = $validated['return_entire_batch'] ?? false;
            $returnEntireSale = $validated['return_entire_sale'] ?? false;
            $useSaleProductIds = isset($validated['sale_product_ids']) && !empty($validated['sale_product_ids']);

            // Use existing sale_id if not provided
            $validated['sale_id'] = $validated['sale_id'] ?? $salesReturn->sale_id;
            $sale = Sale::with(['saleProducts.fieldValues', 'saleAdditionals'])
                ->where('company_id', $validated['company_id'])
                ->findOrFail($validated['sale_id']);

            // Load sale products for reuse if needed
            $saleProducts = null;
            if ($returnEntireBatch || $returnEntireSale || $useSaleProductIds) {
                $query = $returnEntireBatch || $returnEntireSale
                    ? $sale->saleProducts()->with('fieldValues')
                    : SaleProduct::whereIn('id', $validated['sale_product_ids'])->with('fieldValues')->where('sale_id', $validated['sale_id']);
                $saleProducts = $query->get()->keyBy('id');
                if ($saleProducts->isEmpty()) {
                    return response()->json(['error' => 'No valid sale products found'], 422);
                }
            }

            // Calculate fiscal year
            $date = $request->invoice_date ? Carbon::parse($request->invoice_date) : now();
            $fiscal_year_start = Carbon::create($date->year, 7, 16);
            $fiscalYear = $date->lessThan($fiscal_year_start)
                ? ($date->year - 1) . '-' . substr($date->year, 2, 2)
                : $date->year . '-' . substr($date->year + 1, 2, 2);

            // Generate unique invoice number if not provided
            $validated['invoice_number'] = $request->input('invoice_number') ?? $this->generateUniqueInvoiceNumber($fiscalYear);

            // Handle return_entire_batch or return_entire_sale
            if ($returnEntireBatch || $returnEntireSale) {
                $validated['sales_return_products'] = $saleProducts->map(function ($product) use ($validated, $salesReturn) {
                    $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                        return $group->map(function ($field) {
                            return [
                                'product_field_id' => $field->product_field_id,
                                'value' => $field->value,
                            ];
                        })->toArray();
                    })->values()->toArray();

                    // Calculate remaining quantities
                    $totalAvailable = $product->quantity + ($product->free_quantity ?? 0);
                    $returned = SalesReturnProduct::where('sale_product_id', $product->id)
                        ->where('company_id', $validated['company_id'])
                        ->whereNull('deleted_at')
                        ->where('sales_return_id', '!=', $salesReturn->id)
                        ->selectRaw('SUM(quantity + COALESCE(free_quantity, 0)) as total')
                        ->first();
                    $remainingTotal = max(0, $totalAvailable - ($returned->total ?? 0));

                    // Distribute remaining units
                    $remainingQuantity = min($product->quantity, $remainingTotal);
                    $remainingFreeQuantity = $remainingTotal > $product->quantity ? $remainingTotal - $product->quantity : 0;

                    Log::info('Update return calculation', [
                        'sale_product_id' => $product->id,
                        'total_available' => $totalAvailable,
                        'returned_total' => $returned->total ?? 0,
                        'remaining_quantity' => $remainingQuantity,
                        'remaining_free_quantity' => $remainingFreeQuantity,
                    ]);

                    return [
                        'sale_product_id' => $product->id,
                        'purchase_product_id' => $product->purchase_product_id,
                        'product_id' => $product->product_id,
                        'quantity' => $remainingQuantity,
                        'free_quantity' => $remainingFreeQuantity,
                        'price' => $product->price,
                        'discount_percent' => $product->discount_percent ?? 0,
                        'discount_amount' => $product->discount_amount ?? 0,
                        'is_vatable' => $product->is_vatable,
                        'measure_unit_id' => $product->measure_unit_id,
                        'batch_no' => $product->batch_no,
                        'mfd' => $product->mfd,
                        'expiry_date' => $product->expiry_date,
                        'field_values' => $fieldValues,
                    ];
                })->filter(function ($product) {
                    return $product['quantity'] > 0 || $product['free_quantity'] > 0;
                })->values()->toArray();

                if (empty($validated['sales_return_products'])) {
                    return response()->json(['error' => 'No remaining products available to return'], 422);
                }
            } elseif ($useSaleProductIds) {
                $validated['sales_return_products'] = $saleProducts->map(function ($product) use ($validated, $salesReturn) {
                    $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                        return $group->map(function ($field) {
                            return [
                                'product_field_id' => $field->product_field_id,
                                'value' => $field->value,
                            ];
                        })->toArray();
                    })->values()->toArray();

                    // Calculate remaining quantities
                    $totalAvailable = $product->quantity + ($product->free_quantity ?? 0);
                    $returned = SalesReturnProduct::where('sale_product_id', $product->id)
                        ->where('company_id', $validated['company_id'])
                        ->whereNull('deleted_at')
                        ->where('sales_return_id', '!=', $salesReturn->id)
                        ->selectRaw('SUM(quantity + COALESCE(free_quantity, 0)) as total')
                        ->first();
                    $remainingTotal = max(0, $totalAvailable - ($returned->total ?? 0));

                    // Distribute remaining units
                    $remainingQuantity = min($product->quantity, $remainingTotal);
                    $remainingFreeQuantity = $remainingTotal > $product->quantity ? $remainingTotal - $product->quantity : 0;

                    Log::info('Update specific products calculation', [
                        'sale_product_id' => $product->id,
                        'total_available' => $totalAvailable,
                        'returned_total' => $returned->total ?? 0,
                        'remaining_quantity' => $remainingQuantity,
                        'remaining_free_quantity' => $remainingFreeQuantity,
                    ]);

                    return [
                        'sale_product_id' => $product->id,
                        'purchase_product_id' => $product->purchase_product_id,
                        'product_id' => $product->product_id,
                        'quantity' => $remainingQuantity,
                        'free_quantity' => $remainingFreeQuantity,
                        'price' => $product->price,
                        'discount_percent' => $product->discount_percent ?? 0,
                        'discount_amount' => $product->discount_amount ?? 0,
                        'is_vatable' => $product->is_vatable,
                        'measure_unit_id' => $product->measure_unit_id,
                        'batch_no' => $product->batch_no,
                        'mfd' => $product->mfd,
                        'expiry_date' => $product->expiry_date,
                        'field_values' => $fieldValues,
                    ];
                })->filter(function ($product) {
                    return $product['quantity'] > 0 || $product['free_quantity'] > 0;
                })->values()->toArray();

                if (empty($validated['sales_return_products'])) {
                    return response()->json(['error' => 'No remaining products available to return for the specified product IDs'], 422);
                }
            }

            // Set sale-related fields
            $validated['batch_no'] = $validated['batch_no'] ?? ($sale->batch_no ? $sale->batch_no . '-RETURN' : null);
            $validated['customer_id'] = $validated['customer_id'] ?? $sale->customer_id;
            $validated['salesman_id'] = $validated['salesman_id'] ?? $sale->salesman_id;
            $validated['store_id'] = $validated['store_id'] ?? $sale->store_id;
            $validated['location_id'] = $validated['location_id'] ?? $sale->location_id;

            // Validate return quantities and field values
            foreach ($validated['sales_return_products'] as $index => $product) {
                Log::debug('Processing sales return product at index ' . $index, ['product' => $product]);
                $saleProductId = $product['sale_product_id'];
                $productId = $product['product_id'];
                $purchaseProductId = $product['purchase_product_id'];
                $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);

                // Validate sale_product_id and sale_id consistency
                $saleProduct = $saleProducts && $saleProducts->has($saleProductId)
                    ? $saleProducts->get($saleProductId)
                    : SaleProduct::with('fieldValues')
                        ->where('id', $saleProductId)
                        ->where('sale_id', $validated['sale_id'])
                        ->first();
                if (!$saleProduct) {
                    return response()->json([
                        'error' => "Sale product ID {$saleProductId} at index {$index} does not belong to sale ID {$validated['sale_id']}"
                    ], 422);
                }

                // Validate purchase_product_id
                $purchaseProduct = PurchaseProduct::where('id', $purchaseProductId)
                    ->where('company_id', $validated['company_id'])
                    ->where('product_id', $productId)
                    ->first();
                if (!$purchaseProduct) {
                    return response()->json([
                        'error' => "Purchase product ID {$purchaseProductId} at index {$index} is invalid for product ID {$productId}"
                    ], 422);
                }

                // Check available quantity to return
                $availableToReturn = $saleProduct->quantity + ($saleProduct->free_quantity ?? 0);
                $returned = SalesReturnProduct::where('sale_product_id', $saleProductId)
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->where('sales_return_id', '!=', $salesReturn->id)
                    ->selectRaw('SUM(quantity + COALESCE(free_quantity, 0)) as total')
                    ->first();
                $availableToReturn -= ($returned->total ?? 0);

                // Adjust for existing product quantity if updating
                if (isset($product['id'])) {
                    $existingProduct = SalesReturnProduct::where('id', $product['id'])
                        ->where('sales_return_id', $salesReturn->id)
                        ->whereNull('deleted_at')
                        ->first();
                    if ($existingProduct) {
                        $existingQuantity = $existingProduct->quantity + ($existingProduct->free_quantity ?? 0);
                        $availableToReturn += $existingQuantity;
                    }
                }

                if ($requestedQuantity > $availableToReturn) {
                    return response()->json([
                        'error' => "Cannot return more than sold for sale product ID {$saleProductId} at index {$index}. Available: {$availableToReturn}, Requested: {$requestedQuantity}"
                    ], 422);
                }

                // Validate field_values
                $hasFieldValues = PurchaseProductFieldValue::where('purchase_product_id', $purchaseProductId)->exists();
                if (
                    $hasFieldValues && !($returnEntireBatch || $returnEntireSale || $useSaleProductIds) &&
                    (!isset($product['field_values']) || count($product['field_values']) !== $product['quantity'])
                ) {
                    return response()->json([
                        'error' => "Field values count (" . (isset($product['field_values']) ? count($product['field_values']) : 0) . ") must match quantity ({$product['quantity']}) for product ID {$productId} at index {$index}"
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
            }

            // Prepare sales return additionals
            $salesReturnAdditionalsData = $validated['sales_return_additionals'] ?? null;
            if (!$salesReturnAdditionalsData && $sale->saleAdditionals) {
                $salesReturnAdditionalsData = [
                    'place' => $sale->saleAdditionals->place,
                    'transport' => $sale->saleAdditionals->transport,
                    'vehicle_number' => $sale->saleAdditionals->vehicle_number,
                    'vehicle_name' => $sale->saleAdditionals->vehicle_name,
                    'driver_name' => $sale->saleAdditionals->driver_name,
                    'return_code' => 'RETURN' . now()->format('YmdHis'),
                    'driver_contact_number' => $sale->saleAdditionals->driver_contact_number,
                    'return_date' => now()->toDateString(),
                    'return_time' => now()->toTimeString(),
                ];
            } elseif (!$salesReturnAdditionalsData) {
                $salesReturnAdditionalsData = [
                    'return_code' => 'RETURN' . now()->format('YmdHis'),
                    'return_date' => now()->toDateString(),
                    'return_time' => now()->toTimeString(),
                ];
            }

            $salesReturnData = array_intersect_key($validated, array_flip((new SalesReturn)->getFillable()));

            $salesReturn = DB::transaction(function () use ($salesReturn, $salesReturnData, $validated, $salesReturnAdditionalsData, $returnEntireBatch, $returnEntireSale, $useSaleProductIds, $saleProducts) {
                // Update sales return
                $salesReturn->update($salesReturnData);

                // Handle sales return products
                $existingProductIds = $salesReturn->salesReturnProducts()->withTrashed()->pluck('id')->toArray();
                $incomingProductIds = collect($validated['sales_return_products'])->pluck('id')->filter()->toArray();
                $productsToDelete = array_diff($existingProductIds, $incomingProductIds);
                if (!empty($productsToDelete)) {
                    SalesReturnProduct::whereIn('id', $productsToDelete)->each(function ($product) {
                        $product->fieldValues()->delete();
                        $product->delete();
                    });
                }

                foreach ($validated['sales_return_products'] as $index => $product) {
                    $product['company_id'] = $validated['company_id'];
                    $product['sales_return_id'] = $salesReturn->id;
                    $productModel = Product::find($product['product_id']);
                    $product['product_code'] = $productModel->product_unique_id ?? null;
                    $product['product_name'] = $productModel->name ?? null;

                    // Fetch sale product for field values
                    $saleProduct = $saleProducts && $saleProducts->has($product['sale_product_id'])
                        ? $saleProducts->get($product['sale_product_id'])
                        : SaleProduct::with('fieldValues')
                            ->where('id', $product['sale_product_id'])
                            ->where('sale_id', $validated['sale_id'])
                            ->first();
                    if (!$saleProduct) {
                        throw new \Exception("Sale product ID {$product['sale_product_id']} not found for index {$index}");
                    }

                    // Fetch available field values to validate
                    $availableFieldValues = PurchaseProductFieldValue::withoutGlobalScopes()
                        ->select([
                            'purchase_product_field_values.quantity_index',
                            'purchase_product_field_values.product_field_id',
                            'purchase_product_field_values.value',
                        ])
                        ->join('purchase_products', 'purchase_product_field_values.purchase_product_id', '=', 'purchase_products.id')
                        ->leftJoin('sale_products', function ($join) use ($validated, $product) {
                            $join->on('purchase_products.id', '=', 'sale_products.purchase_product_id')
                                ->whereNull('sale_products.deleted_at')
                                ->where('sale_products.company_id', $validated['company_id'])
                                ->where('sale_products.id', '=', $product['sale_product_id']);
                        })
                        ->leftJoin('sales_product_field_values', function ($join) use ($validated, $product) {
                            $join->on('sale_products.id', '=', 'sales_product_field_values.sale_product_id')
                                ->on('purchase_product_field_values.quantity_index', '=', 'sales_product_field_values.quantity_index')
                                ->whereNull('sales_product_field_values.deleted_at')
                                ->where('sales_product_field_values.company_id', $validated['company_id']);
                        })
                        ->where('purchase_product_field_values.purchase_product_id', $product['purchase_product_id'])
                        ->whereNull('purchase_product_field_values.deleted_at')
                        ->where('purchase_product_field_values.company_id', $validated['company_id'])
                        ->whereNotNull('sales_product_field_values.id')
                        ->groupBy([
                            'purchase_product_field_values.quantity_index',
                            'purchase_product_field_values.product_field_id',
                            'purchase_product_field_values.value',
                        ])
                        ->get();

                    Log::debug('Available field values for sales return update', [
                        'purchase_product_id' => $product['purchase_product_id'],
                        'sale_product_id' => $product['sale_product_id'],
                        'field_values' => $availableFieldValues->toArray(),
                    ]);

                    // Create or update sales return product
                    if (isset($product['id'])) {
                        $salesReturnProduct = SalesReturnProduct::where('id', $product['id'])
                            ->where('sales_return_id', $salesReturn->id)
                            ->withTrashed()
                            ->first();
                        if ($salesReturnProduct) {
                            if ($salesReturnProduct->trashed()) {
                                $salesReturnProduct->restore();
                            }
                            $salesReturnProduct->update($product);
                        } else {
                            $salesReturnProduct = $salesReturn->salesReturnProducts()->create($product);
                        }
                    } else {
                        $salesReturnProduct = $salesReturn->salesReturnProducts()->create($product);
                    }

                    // Handle field values
                    if (!empty($product['field_values']) && !($returnEntireBatch || $returnEntireSale || $useSaleProductIds)) {
                        $fieldValues = [];
                        $selectedQuantityIndices = [];
                        foreach ($product['field_values'] as $fieldValueSet) {
                            $matched = false;
                            foreach ($availableFieldValues as $availableFieldValue) {
                                $matchesAllFields = true;
                                foreach ($fieldValueSet as $fieldValue) {
                                    $found = $availableFieldValues->contains(function ($item) use ($fieldValue, $availableFieldValue) {
                                        return $item->product_field_id == $fieldValue['product_field_id'] &&
                                            $item->value == $fieldValue['value'] &&
                                            $item->quantity_index == $availableFieldValue->quantity_index;
                                    });
                                    if (!$found) {
                                        $matchesAllFields = false;
                                        break;
                                    }
                                }
                                if ($matchesAllFields && !in_array($availableFieldValue->quantity_index, $selectedQuantityIndices)) {
                                    foreach ($fieldValueSet as $fieldValue) {
                                        $fieldValues[] = [
                                            'company_id' => $validated['company_id'],
                                            'product_field_id' => $fieldValue['product_field_id'],
                                            'product_id' => $salesReturnProduct->product_id,
                                            'sale_return_product_id' => $salesReturnProduct->id,
                                            'quantity_index' => $availableFieldValue->quantity_index,
                                            'value' => $fieldValue['value'],
                                            'created_at' => now(),
                                            'updated_at' => now(),
                                        ];
                                    }
                                    $selectedQuantityIndices[] = $availableFieldValue->quantity_index;
                                    $matched = true;
                                    break;
                                }
                            }
                            if (!$matched) {
                                Log::warning('Field values mismatch for sales return update', [
                                    'purchase_product_id' => $product['purchase_product_id'],
                                    'sale_product_id' => $product['sale_product_id'],
                                    'field_value_set' => $fieldValueSet,
                                    'available_field_values' => $availableFieldValues->toArray(),
                                ]);
                                throw new \Exception("Provided field values do not match available stock for sale product ID {$product['sale_product_id']} at index {$index}");
                            }
                        }

                        if (count($selectedQuantityIndices) > $product['quantity']) {
                            throw new \Exception("Number of selected field value sets (" . count($selectedQuantityIndices) . ") exceeds requested quantity ({$product['quantity']}) for sale product ID {$product['sale_product_id']} at index {$index}");
                        }

                        // Delete existing field values and insert new ones
                        SaleReturnProductFieldValue::where('sale_return_product_id', $salesReturnProduct->id)->delete();
                        if (!empty($fieldValues)) {
                            SaleReturnProductFieldValue::insert($fieldValues);
                            Log::debug('SaleReturnProductFieldValue updated', [
                                'sale_return_product_id' => $salesReturnProduct->id,
                                'field_values' => $fieldValues,
                            ]);
                        }
                    } elseif ($returnEntireBatch || $returnEntireSale || $useSaleProductIds) {
                        // Copy field values from sale product
                        $saleFieldValues = $saleProduct->fieldValues->groupBy('quantity_index')->take($product['quantity'])->values();
                        $fieldValues = [];
                        foreach ($saleFieldValues as $quantityIndex => $fieldValueSet) {
                            foreach ($fieldValueSet as $fieldValue) {
                                $fieldValues[] = [
                                    'company_id' => $validated['company_id'],
                                    'product_field_id' => $fieldValue->product_field_id,
                                    'product_id' => $salesReturnProduct->product_id,
                                    'sale_return_product_id' => $salesReturnProduct->id,
                                    'quantity_index' => $fieldValue->quantity_index,
                                    'value' => $fieldValue->value,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                            }
                        }
                        SaleReturnProductFieldValue::where('sale_return_product_id', $salesReturnProduct->id)->delete();
                        if (!empty($fieldValues)) {
                            SaleReturnProductFieldValue::insert($fieldValues);
                            Log::debug('SaleReturnProductFieldValue copied from sale', [
                                'sale_return_product_id' => $salesReturnProduct->id,
                                'field_values' => $fieldValues,
                            ]);
                        }
                    } else {
                        SaleReturnProductFieldValue::where('sale_return_product_id', $salesReturnProduct->id)->delete();
                    }
                }

                // Update or create sales return additionals
                $salesReturn->salesReturnAdditional()->updateOrCreate(
                    ['sales_return_id' => $salesReturn->id, 'company_id' => $validated['company_id']],
                    [
                        'place' => $salesReturnAdditionalsData['place'] ?? null,
                        'transport' => $salesReturnAdditionalsData['transport'] ?? null,
                        'vehicle_number' => $salesReturnAdditionalsData['vehicle_number'] ?? null,
                        'vehicle_name' => $salesReturnAdditionalsData['vehicle_name'] ?? null,
                        'driver_name' => $salesReturnAdditionalsData['driver_name'] ?? null,
                        'return_code' => $salesReturnAdditionalsData['return_code'],
                        'driver_contact_number' => $salesReturnAdditionalsData['driver_contact_number'] ?? null,
                        'return_date' => $salesReturnAdditionalsData['return_date'],
                        'return_time' => $salesReturnAdditionalsData['return_time'],
                    ]
                );

                return $salesReturn;
            });

            return response()->json([
                'message' => 'Sales Return updated successfully',
                'data' => $salesReturn->load([
                    'salesReturnProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index')->orderBy('product_field_id');
                    },
                    'salesReturnAdditional'
                ])
            ], 200);
        } catch (ModelNotFoundException $e) {
            Log::error('Model not found during sales return update', [
                'sales_return_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Sales Return or related resource not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database error during sales return update', [
                'sales_return_id' => $id,
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (ValidationException $e) {
            Log::warning('Validation failed during sales return update', [
                'sales_return_id' => $id,
                'errors' => $e->errors(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Unexpected error during sales return update', [
                'sales_return_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'Error occurred: ' . $e->getMessage()], 422);
        }
    }




    /**
     * Generate a unique invoice number for the fiscal year.
     */


    public function show($id): JsonResponse
    {
        try {
            $salesReturn = SalesReturn::with('salesReturnProducts')->findOrFail($id);
            return response()->json($salesReturn);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Sales Return not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error'], 500);
        }
    }


    public function destroy($id): JsonResponse
    {
        try {
            $salesReturn = SalesReturn::findOrFail($id);
            $salesReturn->delete();
            return response()->json(['message' => 'Sales Return deleted']);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Sales Return not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error'], 500);
        }


    }

    public function getSalesReturnByProduct(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|integer|exists:products,id',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $productId = $request->input('product_id');
            $companyId = $request->input('company_id');

            $sales = Helper::getSalesReturnByProductId($productId, $companyId);

            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales return found for the specified product'], 404);
            }

            return response()->json([
                'message' => 'Sales Return retrieved successfully',
                'data' => $sales
            ], 200);

        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }


    public function getSalesReturnByCustomer(Request $request): JsonResponse
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

            $sales = Helper::getSalesReturnByCustomer($customerID, $companyId);

            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales returns found for the specified customer'], 404);
            }

            return response()->json([
                'message' => 'Sales Return retrieved successfully',
                'data' => $sales
            ], 200);

        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }


    public function getSalesReturnByBatch(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'batch_no' => 'required|exists:sales_returns,batch_no',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $batchNo = $request->input('batch_no');
            $companyId = $request->input('company_id');

            $sales = Helper::getSalesReturnByBatch($batchNo, $companyId);

            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales return found for the specified batch'], 404);
            }

            return response()->json([
                'message' => 'Sales retrieved successfully',
                'data' => $sales
            ], 200);

        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }

    public function getAllExpiryDates(): JsonResponse
    {
        $expiryDates = SalesReturnProduct::select('expiry_date')
            ->distinct()
            ->orderBy('expiry_date', 'asc')
            ->pluck('expiry_date');

        return response()->json([
            'message' => 'Expiry dates retrieved successfully',
            'data' => $expiryDates
        ], 200);
    }


    public function getSalesReturnByExpiryDate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'expiry_date' => 'required|exists:sales_return_products,expiry_date',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $expiryDate = $request->input('expiry_date');
            $companyId = $request->input('company_id');

            $sales = Helper::getSalesReturnByExpiryDate($expiryDate, $companyId);

            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales returns found for the specified Expiry Date'], 404);
            }

            return response()->json([
                'message' => 'Sales Return retrieved successfully',
                'data' => $sales
            ], 200);

        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }

}

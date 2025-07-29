<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\MeasureUnit;
use App\Models\Product;
use App\Models\ProductList;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductFieldValue;
use App\Models\Sale;
use App\Models\SaleProduct;
use App\Models\SaleReturnAdditional;
use App\Models\SaleReturnProductFieldValue;
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

            // Fetch measure units
            $measureUnits = MeasureUnit::where('company_id', $companyId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->select(['id', 'name', 'quantity'])
                ->get()
                ->keyBy('id');

            // Fetch sale with products and field values
            $sale = Sale::where('company_id', $companyId)
                ->where('invoice_number', $invoiceNumber)
                ->whereNull('deleted_at')
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
                            ->whereNull('sale_products.deleted_at')
                            ->whereNull('products.deleted_at');
                    },
                    'saleProducts.fieldValues' => function ($query) use ($companyId) {
                        $query->select([
                            'sales_product_field_values.sale_product_id',
                            'sales_product_field_values.product_field_id',
                            'sales_product_field_values.quantity_index',
                            'sales_product_field_values.quantity_type',
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
                return response()->json(['error' => 'Sale not found'], 404);
            }

            // Fetch sales return products
            $salesReturnProducts = SalesReturnProduct::whereIn('sale_product_id', $sale->saleProducts->pluck('id'))
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->with([
                    'fieldValues' => function ($query) use ($companyId) {
                        $query->select([
                            'sale_return_product_field_values.sale_return_product_id',
                            'sale_return_product_field_values.quantity_index',
                            'sale_return_product_field_values.product_field_id',
                            'sale_return_product_field_values.value',
                            'product_fields.name',
                        ])
                            ->join('product_fields', 'sale_return_product_field_values.product_field_id', '=', 'product_fields.id')
                            ->where('sale_return_product_field_values.company_id', $companyId)
                            ->whereNull('sale_return_product_field_values.deleted_at')
                            ->whereNull('product_fields.deleted_at');
                    },
                ])
                ->get();

            // Build a set of returned field values for filtering
            $returnedFieldValues = [];
            foreach ($salesReturnProducts as $returnProduct) {
                $saleProductId = $returnProduct->sale_product_id;
                foreach ($returnProduct->fieldValues as $fv) {
                    $key = $saleProductId . '-' . $fv->quantity_index . '-' . $fv->product_field_id;
                    $returnedFieldValues[$key] = true;
                    Log::debug('Added returned field value', [
                        'sale_product_id' => $saleProductId,
                        'quantity_index' => $fv->quantity_index,
                        'product_field_id' => $fv->product_field_id,
                        'value' => $fv->value,
                    ]);
                }
            }

            // Aggregate product data
            $products = [];
            $productIds = $sale->saleProducts->pluck('product_id')->unique()->toArray();

            foreach ($sale->saleProducts as $saleProduct) {
                $productId = $saleProduct->product_id;
                $productMeasureUnit = Product::where('id', $productId)->first();
                $productMeasureUnitId = $productMeasureUnit->measure_unit_id ?? null;
                $productMeasureUnitLists = ProductList::where('product_id', $productId)->pluck('measure_unit_id')->toArray();
                $measureunitsLists = collect(
                    array_unique(
                        array_merge(
                            $productMeasureUnitId ? [$productMeasureUnitId] : [], // wrap in array if not null
                            $productMeasureUnitLists
                        )
                    )
                );
                $usedMeasureUnits = MeasureUnit::whereIn('id', $measureunitsLists)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->get(['id', 'name', 'quantity']);

                $primarymeasureUnitId = ProductList::where('product_id', $productId)
                    ->where('is_primary', 1)
                    ->pluck('measure_unit_id')
                    ->first();

                if (!$primarymeasureUnitId) {
                    $primarymeasureUnitId = ProductList::where('product_id', $productId) // Fixed: Changed 'id' to 'product_id'
                        ->orderBy('created_at', 'asc')
                        ->pluck('measure_unit_id')
                        ->first();
                }
                $primaryMeasureUnitquantity = MeasureUnit::where('id', $primarymeasureUnitId)->pluck('quantity')->first();
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

                // Calculate quantities in pieces
                $measureUnitQuantity = $measureUnit['quantity'];

                $regularQuantity = $saleProduct->quantity ?? 0;
                $regularquantityInt = floor($regularQuantity);
                $decimalRegularQuantity = $regularQuantity - $regularquantityInt;
                $regularDecimal = (string) $decimalRegularQuantity;
                $regulardecimalPieces = $regularDecimal > 0 ? (int) str_replace('.', '', (string) $regularDecimal) : 0;
                $quantityInPieces = ($regularquantityInt * $measureUnitQuantity) + $regulardecimalPieces;

                $freeQuantity = $saleProduct->free_quantity ?? 0;
                $freeQuantityInt = floor($freeQuantity);
                $freequantityDecimal = $freeQuantity - $freeQuantityInt;
                $freeDecimal = (string) $freequantityDecimal;
                $freedecimalPieces = $freeDecimal > 0 ? (int) str_replace('.', '', (string) $freeDecimal) : 0;
                $freeQuantityInPieces = ($freeQuantityInt * $measureUnitQuantity) + $freedecimalPieces;
                $saleTotal = $quantityInPieces + $freeQuantityInPieces;

                // Calculate return quantities for this sale product
                $returnQuantityInPieces = 0;
                $returnFreeQuantityInPieces = 0;

                $returnTotal = 0;
                $returnProductsForSale = $salesReturnProducts->where('sale_product_id', $saleProduct->id);
                if ($returnProductsForSale->isNotEmpty()) {
                    foreach ($returnProductsForSale as $returnProduct) {
                        $returnMeasureUnitId = $returnProduct->measure_unit_id ?? $measureUnitId;
                        $returnMeasureUnitQuantity = isset($measureUnits[$returnMeasureUnitId]) ? $measureUnits[$returnMeasureUnitId]->quantity : 1;

                        $returnQuantity = $returnProduct->quantity ?? 0;
                        $returnQuantityInt = floor($returnQuantity);
                        $returnQuantityDecimal = $returnQuantity - $returnQuantityInt;
                        $quantityDecimal = (string) $returnQuantityDecimal;
                        $returnQuantityDecimal = $quantityDecimal > 0 ? (int) str_replace('.', '', (string) $quantityDecimal) : 0;
                        $returnQuantityInPieces = ($returnQuantityInt * $returnMeasureUnitQuantity) + $returnQuantityDecimal;

                        $returnFreeQuantity = $returnProduct->free_quantity ?? 0;
                        $returnFreeQuantityInt = floor($returnFreeQuantity);
                        $returnFreeQuantityDecimal = $returnFreeQuantity - $returnFreeQuantityInt;
                        $freeDecimal = (string) $returnFreeQuantityDecimal;
                        $freedecimalPieces = $freeDecimal > 0 ? (int) str_replace('.', '', (string) $freeDecimal) : 0;
                        $returnFreeQuantityInPieces = ($returnFreeQuantityInt * $returnMeasureUnitQuantity) + $freedecimalPieces;

                        $returnTotal += $returnQuantityInPieces + $returnFreeQuantityInPieces;
                    }
                    Log::debug('Processing sales return products', [
                        'sale_product_id' => $saleProduct->id,
                        'return_product_count' => $returnProductsForSale->count(),
                        'return_field_values' => $returnProductsForSale->flatMap->fieldValues->map->only(['quantity_index', 'product_field_id', 'value'])->toArray(),
                    ]);
                }

                $availableQuantity = $saleTotal - $returnTotal;
                $regularQuantityAvailableForSalesReturn = $quantityInPieces - $returnQuantityInPieces;
                $freeQuantityAvailableForSalesReturn = $freeQuantityInPieces - $returnFreeQuantityInPieces;

                // Initialize or update product entry with totals
                if (!isset($products[$productId])) {
                    $products[$productId] = [
                        'product_id' => $productId,
                        'product_name' => $saleProduct->product_name,
                        'product_code' => $saleProduct->product_code,
                        'min_price' => $saleProduct->price,
                        'amount' => $saleProduct->amount,
                        'is_vatable' => (bool) $saleProduct->is_vatable,
                        'used_measure_units' => $usedMeasureUnits,
                        'measure_unit_id' => $primarymeasureUnitId,
                        'measure_unit_quantity' => $primaryMeasureUnitquantity,
                        'purchased_quantity' => 0,
                        'return_quantity' => 0,
                        'sale_quantity' => 0,
                        'sales_return_quantity' => 0,
                        'available_quantity' => 0,
                        'regular_quantity_available' => 0,
                        'free_quantity_available' => 0,
                        'expiry_dates' => [],
                        'field_values' => [],
                        'sale_products' => [],
                    ];
                }

                // Aggregate totals at product level
                $products[$productId]['sale_quantity'] += $saleTotal;
                $products[$productId]['return_quantity'] += $returnTotal;
                $products[$productId]['sales_return_quantity'] += $returnTotal;
                $products[$productId]['available_quantity'] += $availableQuantity;
                $products[$productId]['regular_quantity_available'] += $regularQuantityAvailableForSalesReturn;
                $products[$productId]['free_quantity_available'] += $freeQuantityAvailableForSalesReturn;


                if ($saleProduct->expiry_date && !in_array($saleProduct->expiry_date, $products[$productId]['expiry_dates'])) {
                    $products[$productId]['expiry_dates'][] = $saleProduct->expiry_date;
                }

                // Add field values only if not present in sale_return_product_field_values
                if ($saleProduct->fieldValues->isNotEmpty()) {
                    foreach ($saleProduct->fieldValues as $fv) {
                        $key = $saleProduct->id . '-' . $fv->quantity_index . '-' . $fv->product_field_id;
                        if (!isset($returnedFieldValues[$key])) {
                            $products[$productId]['field_values'][] = [
                                'sale_product_id' => $saleProduct->id,
                                'purchase_product_id' => $saleProduct->purchase_product_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->name,
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index,
                                'quantity_type' => $fv->quantity_type,
                            ];
                            Log::info('Added eligible field value', [
                                'sale_product_id' => $saleProduct->id,
                                'quantity_index' => $fv->quantity_index,
                                'product_field_id' => $fv->product_field_id,
                                'value' => $fv->value,
                                'quantity_type' => $fv->quantity_type,
                            ]);
                        } else {
                            Log::info('Excluded returned field value', [
                                'sale_product_id' => $saleProduct->id,
                                'quantity_index' => $fv->quantity_index,
                                'product_field_id' => $fv->product_field_id,
                                'value' => $fv->value,
                                'quantity_type' => $fv->quantity_type,
                            ]);
                        }
                    }
                }

                // Add sale product details only if available quantity is >= 1
                if ($availableQuantity >= 1) {
                    $products[$productId]['sale_products'][] = [
                        'sale_product_id' => $saleProduct->id,
                        'sale_id' => $saleProduct->sale_id,
                        'product_id' => $saleProduct->product_id,
                        'product_name' => $saleProduct->product_name,
                        'product_code' => $saleProduct->product_code,
                        'quantity_in_pieces' => $quantityInPieces,
                        'free_quantity_in_pieces' => $freeQuantityInPieces,
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

            // Filter products where available_quantity is >= 1 at product level
            $filteredProducts = [];
            foreach ($products as $product) {
                if ($product['available_quantity'] >= 1) {
                    $filteredProducts[] = $product;
                }
            }

            // Calculate purchased quantities in pieces
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
            }
            $paymentData = [
                'cash' => $sale->payment['cash'] ?? null,
                'credit' => $sale->payment['credit'] ?? null,
                'bank' => $sale->payment['bank'] ?? null,
            ];

            // Prepare sale data
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
                'invoice_date_bs' => $sale->invoice_date_bs->toDateString(),
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
                'payment' => $paymentData,
                'note' => $sale->note,
                'is_vatable' => $sale->is_vatable,
                'is_mail_notify' => $sale->is_mail_notify,
                'is_whatsapp_notify' => $sale->is_whatsapp_notify,
                'abvt' => $sale->abvt,
                'created_at' => $sale->created_at->toIso8601String(),
                'updated_at' => $sale->updated_at->toIso8601String(),
                'deleted_at' => $sale->deleted_at ? $sale->deleted_at->toIso8601String() : null,
                'products' => array_values($filteredProducts),
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
            dd($e->getMessage());
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

            $purchaseType = $request->input('purchase_type');

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
                    'saleProducts' => function ($query) use ($companyId, $purchaseType) {
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
                            ->join('purchase_products', 'sale_products.purchase_product_id', '=', 'purchase_products.id')
                            ->join('purchases', 'purchase_products.purchase_id', '=', 'purchases.id')
                            ->where('sale_products.company_id', $companyId)
                            ->whereNull('sale_products.deleted_at')
                            ->whereNull('products.deleted_at')
                            ->whereNull('purchase_products.deleted_at')
                            ->whereNull('purchases.deleted_at')
                            ->where('purchases.purchase_type', $purchaseType);
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
                    $regularQuantity = $saleProduct->quantity ?? 0;
                    $freeQuantity = $saleProduct->free_quantity ?? 0;
                    $saleRegular = $this->calculatePieces($regularQuantity, $measureUnitQuantity);
                    $saleFree = $this->calculatePieces($freeQuantity, $measureUnitQuantity);
                    $saleTotal = $saleRegular + $saleFree;

                    // Calculate returned quantity
                    $returnProducts = $salesReturnProducts->where('sale_product_id', $saleProduct->id);
                    $returned = 0;
                    foreach ($returnProducts as $returnProduct) {
                        $returnMeasureUnitId = $returnProduct->measure_unit_id ?? null;
                        $returnMeasureUnitQuantity = isset($measureUnits[$returnMeasureUnitId]) ? $measureUnits[$measureUnitId]->quantity : 1;
                        $regularQuantity = $returnProduct->quantity ?? 0;
                        $freeQuantity = $returnProduct->free_quantity ?? 0;

                        $returnRegularQuantity = $this->calculatePieces($regularQuantity, $returnMeasureUnitQuantity);
                        $freeReturnQuantity = $this->calculatePieces($freeQuantity, $returnMeasureUnitQuantity);
                        $returnQuantity = $returnRegularQuantity + $freeReturnQuantity;

                        $returned += $returnQuantity; // Accumulate the returned quantity

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
                        continue; // Skip this sale product
                    }

                    // Calculate available quantity
                    $returnTotal = round($returned, 2);
                    $saleTotal = round($saleTotal, 2);
                    $availableQuantity = max(0, round($saleTotal - $returnTotal, 2));

                    // Log for product "Dhojj" with ID 13539

                    Log::info('Available quantity for Dhojj', [
                        'product_id' => $saleProduct->product_id,
                        'product_name' => $saleProduct->product_name,
                        'sale_total' => $saleTotal,
                        'return_total' => $returnTotal,
                        'available_quantity' => $availableQuantity,
                    ]);


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
            Log::info('Products before filtering', ['products' => $products]);
            $products = array_filter($products, function ($product) {
                Log::info('Filtering product', [
                    'product_id' => $product['product_id'],
                    'available_quantity' => $product['available_quantity'],
                ]);
                return $product['available_quantity'] > 0;
            });

            if (empty($products)) {
                Log::warning('No available products after processing', ['company_id' => $companyId]);
                return response()->json([]);
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
                'product_ids' => array_column(array_values($products), 'product_id'),
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

            Log::info('Return field values fetched', [
                'sale_product_ids' => $saleProductIds,
                'return_field_values' => $returnFieldValues->toArray(),
            ]);

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

                    $primaryMeasureUnit = ProductList::where('product_id', $productId)
                        ->where('is_primary', 1)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->pluck('measure_unit_id')->first();
                    if (!$primaryMeasureUnit) {
                        $primaryMeasureUnit = ProductList::where('id', $productId)
                            ->where('company_id', $companyId)
                            ->whereNull('deleted_at')
                            ->orderBy('created_at', 'asc')
                            ->pluck('measure_unit_id')
                            ->first();
                    }
                    $primaryMeasureUnitQuantity = MeasureUnit::where('id', $primaryMeasureUnit)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->pluck('quantity')
                        ->first();
                    $productMeasureUniId = Product::where('id', $productId)->pluck('measure_unit_id')->toArray();
                    $productListMeasureUnitId = ProductList::where('product_id', $productId)->pluck('measure_unit_id')->toArray();

                    $allMeasureUnitsId = collect(array_merge($productMeasureUniId, $productListMeasureUnitId))
                        ->unique()
                        ->values()
                        ->toArray();


                    $usedMeasureUnits = MeasureUnit::whereIn('id', $allMeasureUnitsId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->get(['id', 'name', 'quantity']);
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

                    // Fetch product metadata
                    $product = Product::where('id', $productId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->first();



                    $originalProductPrice = Product::where('id', $productId)->value('purchase_rate');

                    $saleProductsPrice = SaleProduct::where('product_id', $productId)->orderBy('created_at', 'desc')->pluck('price');
                    $latestPrice = $saleProductsPrice->first();

                    // Get the minimum price
                    $minProductPrice = $saleProductsPrice->min();

                    // Get the average price
                    $avgProductPrice = $saleProductsPrice->avg();

                    // Initialize product entry
                    if (!isset($products[$productId])) {
                        $products[$productId] = [
                            'product_id' => $productId,
                            'product_name' => $saleProduct->product_name,
                            'product_code' => $saleProduct->product_code,

                            'original_price' => $originalProductPrice ?? null,
                            'latest_price' => $latestPrice ?? null,
                            'min_price' => $minProductPrice ?? null,
                            'avg_price' => $avgProductPrice ?? null,
                            'amount' => $saleProduct->amount ?? null,
                            'is_vatable' => (bool) $saleProduct->is_vatable,
                            'used_measure_units' => $usedMeasureUnits,
                            'measure_unit_id' => $primaryMeasureUnit,

                            'measure_unit_quantity' => $primaryMeasureUnitQuantity,
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
                    $regularQuantity = $saleProduct->quantity ?? 0;
                    $freeQuantity = $saleProduct->free_quantity ?? 0;
                    $saleRegular = $this->calculatePieces($regularQuantity, $measureUnitQuantity);
                    $saleFree = $this->calculatePieces($freeQuantity, $measureUnitQuantity);
                    $saleTotal = $saleRegular + $saleFree;

                    // Calculate returned quantity
                    $returnProducts = $salesReturnProducts->where('sale_product_id', $saleProduct->id);
                    $returned = 0;
                    $lastReturnMeasureUnitId = null;
                    $lastReturnMeasureUnitQuantity = 1;

                    foreach ($returnProducts as $returnProduct) {
                        $returnMeasureUnitId = $returnProduct->measure_unit_id ?? null;
                        $returnMeasureUnitQuantity = isset($measureUnits[$returnMeasureUnitId]) ? $measureUnits[$returnMeasureUnitId]->quantity : 1;
                        $regularQuantity = $returnProduct->quantity ?? 0;
                        $freeQuantity = $returnProduct->free_quantity ?? 0;

                        $returnRegularQuantity = $this->calculatePieces($regularQuantity, $returnMeasureUnitQuantity);
                        $freeReturnQuantity = $this->calculatePieces($freeQuantity, $returnMeasureUnitQuantity);
                        $returnQuantity = $returnRegularQuantity + $freeReturnQuantity;
                        $returned += $returnQuantity; // Accumulate returned quantity
                        $lastReturnMeasureUnitId = $returnMeasureUnitId;
                        $lastReturnMeasureUnitQuantity = $returnMeasureUnitQuantity;

                        // Check for measure unit mismatch
                        if ($returnMeasureUnitId !== $saleProduct->measure_unit_id) {
                            Log::warning('Measure unit mismatch for return product', [
                                'sale_product_id' => $saleProduct->id,
                                'return_product_id' => $returnProduct->id,
                                'sale_measure_unit_id' => $saleProduct->measure_unit_id,
                                'return_measure_unit_id' => $returnMeasureUnitId,
                            ]);
                        }

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
                    Log::info('Return field values for sale product', [
                        'sale_product_id' => $saleProduct->id,
                        'return_field_values' => $saleProductReturnFieldValues->toArray(),
                    ]);

                    if ($saleProduct->fieldValues->isNotEmpty()) {
                        $quantityIndices = $saleProduct->fieldValues->pluck('quantity_index')->unique();
                        foreach ($quantityIndices as $quantityIndex) {
                            $saleFieldValues = $saleProduct->fieldValues->where('quantity_index', $quantityIndex)
                                ->pluck('value', 'product_field_id')
                                ->toArray();

                            $isReturned = false; // Default to not returned
                            if ($saleProductReturnFieldValues->isNotEmpty()) {
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
                    $returnTotal = $returned;
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


            Log::info('Final products array', ['products' => $products]);


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

        } catch (QueryException $e) {

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
                'sales_return_products' => 'array|min:1',
                'sales_return_products.*.sale_product_id' => 'nullable|integer|exists:sale_products,id',
                'sales_return_products.*.purchase_product_id' => 'nullable|integer|exists:purchase_products,id',
                'sales_return_products.*.product_id' => 'nullable|exists:products,id',
                'sales_return_products.*.product_name' => 'nullable|string|max:255',
                'sales_return_products.*.barcode' => 'nullable|string|max:255',
                'sales_return_products.*.quantity' => 'required|numeric|min:0',
                'sales_return_products.*.free_quantity' => 'nullable|numeric|min:0',
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
                'sales_return_products.*.field_values.*.*.quantity_type' => 'required_if:sales_return_products.*.field_values,exists|string|in:regular,free',
                'sales_return_additional' => 'nullable|array',
                'sales_return_additional.place' => 'nullable|string|max:255',
                'sales_return_additional.transport' => 'nullable|string|max:255',
                'sales_return_additional.vehicle_number' => 'nullable|string|max:255',
                'sales_return_additional.vehicle_name' => 'nullable|string|max:255',
                'sales_return_additional.driver_name' => 'nullable|string|max:255',
                'sales_return_additional.return_code' => 'required_if:sales_return_additional,exists|string|max:255',
                'sales_return_additional.driver_contact_number' => 'nullable|string|max:255',
                'sales_return_additional.return_date' => 'nullable|date',
                'sales_return_additional.return_time' => 'nullable|date_format:H:i:s',
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

            // Fetch measure units
            $measureUnits = MeasureUnit::whereIn('id', collect($validated['sales_return_products'] ?? [])
                ->pluck('measure_unit_id')
                ->unique()
                ->toArray())
                ->get()
                ->keyBy('id')
                ->map(function ($unit) {
                    return (object) ['quantity' => $unit->quantity ?? 1];
                })->toArray();


            // Fetch sale products based on product criteria
            $productIds = array_filter(array_column($validated['sales_return_products'] ?? [], 'product_id'));
            $productNames = array_filter(array_column($validated['sales_return_products'] ?? [], 'product_name'));
            $barcodes = array_filter(array_map(fn($item) => $item['barcode'] ?? null, $validated['sales_return_products'] ?? []));

            if (empty($productIds) && empty($productNames) && empty($barcodes) && !$validated['return_entire_all'] && empty($validated['sale_product_ids'])) {
                return response()->json(['error' => 'At least one of product_id, product_name, or barcode must be provided in sales_return_products unless returning entire sale'], 422);
            }

            $saleProductQuery = SaleProduct::with(['fieldValues', 'sale'])
                ->whereHas('sale', function ($query) use ($validated) {
                    $query->where('company_id', $validated['company_id'])->whereNull('deleted_at');
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

                ->when(!empty($validated['sale_id']), function ($query) use ($validated) {
                    $query->where('sale_id', $validated['sale_id']);
                })
                ->orderBy('created_at');

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

            // Fetch available field values for validation
            $saleProductIds = $allSaleProducts->pluck('id')->toArray();
            $availableFieldValues = DB::table('sales_product_field_values')
                ->whereIn('sale_product_id', $saleProductIds)
                ->where('company_id', $validated['company_id'])
                ->whereNull('deleted_at')
                ->select(['sale_product_id', 'product_field_id', 'quantity_index', 'value'])
                ->get()
                ->groupBy('sale_product_id')
                ->map(function ($group) {
                    return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                        return $fvGroup->map(function ($fv) {
                            return [
                                'product_field_id' => $fv->product_field_id,
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index,
                            ];
                        })->toArray();
                    })->toArray();
                })->toArray();

            // Select a sale for context
            $sale = Sale::where('company_id', $validated['company_id'])
                ->whereIn('id', $allSaleProducts->pluck('sale_id')->unique())
                ->orderBy('created_at', 'desc')
                ->first();
            if (!$sale) {
                return response()->json(['error' => 'No valid sale found'], 404);
            }
            $validated['sale_id'] = $sale->id;

            // Generate unique invoice number
            $date = $validated['invoice_date'] ? Carbon::parse($validated['invoice_date']) : now();
            $fiscalYearStart = Carbon::create($date->year, 7, 1);
            $fiscalYear = $date->lessThan($fiscalYearStart)
                ? ($date->year - 1) . '-' . substr($date->year, 2, 2)
                : $date->year . '-' . substr($date->year + 1, 2, 2);
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
            $useSaleProductIds = !empty($validated['sale_product_ids']);
            $salesReturnProducts = [];

            if ($returnEntireAll) {
                $saleProducts = $allSaleProducts->filter(function ($saleProduct) use ($validated) {
                    return $saleProduct->sale_id == $validated['sale_id'];
                });
                if ($saleProducts->isEmpty()) {
                    Log::warning('No sale products found for sale', ['sale_id' => $validated['sale_id']]);
                    return response()->json(['error' => 'No products found in this sale'], 404);
                }


                $salesReturnProducts = $saleProducts->map(function ($saleProduct) use ($validated, $measureUnits) {
                    $availablePieces = $this->calculateAvailablePieces($saleProduct, $validated['company_id'], $measureUnits);
                    if ($availablePieces < 0) {
                        return null;
                    }
                    $saleMeasureUnitQuantity = $measureUnits[$saleProduct->measure_unit_id]->quantity ?? 1;
                    [$quantity, $freeQuantity] = $this->convertToTargetMeasureUnit($availablePieces, 0, $saleMeasureUnitQuantity);
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
                        'field_values' => $this->getFieldValuesGroupedByQuantityIndex($saleProduct->fieldValues),
                    ];
                })->filter()->values()->toArray();

                if (empty($salesReturnProducts)) {
                    return response()->json(['error' => 'All products in this sale have already been returned'], 422);
                }
            } elseif ($useSaleProductIds) {
                $saleProducts = SaleProduct::whereIn('id', $validated['sale_product_ids'])
                    ->with(['fieldValues', 'sale'])
                    ->whereHas('sale', function ($query) use ($validated) {
                        $query->where('company_id', $validated['company_id'])->whereNull('deleted_at');
                    })
                    ->orderBy('created_at')
                    ->get();

                if ($saleProducts->isEmpty()) {
                    Log::warning('No sale products found for provided IDs', ['sale_product_ids' => $validated['sale_product_ids']]);
                    return response()->json(['error' => 'No valid sale products found for provided IDs'], 404);
                }


                $salesReturnProducts = $saleProducts->map(function ($saleProduct) use ($validated, $measureUnits) {
                    $availablePieces = $this->calculateAvailablePieces($saleProduct, $validated['company_id'], $measureUnits);
                    if ($availablePieces < 0) {
                        return null;
                    }
                    $saleMeasureUnitQuantity = $measureUnits[$saleProduct->measure_unit_id]->quantity ?? 1;
                    [$quantity, $freeQuantity] = $this->convertToTargetMeasureUnit($availablePieces, 0, $saleMeasureUnitQuantity);
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
                        'field_values' => $this->getFieldValuesGroupedByQuantityIndex($saleProduct->fieldValues),
                    ];
                })->filter()->values()->toArray();

                if (empty($salesReturnProducts)) {
                    return response()->json(['error' => 'All specified products have already been returned'], 422);
                }
            } else {
                foreach ($validated['sales_return_products'] as $index => $product) {
                    if (empty($product['product_id']) && empty($product['product_name']) && empty($product['barcode'])) {
                        return response()->json(['error' => "At least one of product_id, product_name, or barcode must be provided for sales_return_products at index {$index}"], 422);
                    }

                    $measureUnitId = $product['measure_unit_id'];
                    $returnMeasureUnitData = $measureUnits[$measureUnitId] ?? MeasureUnit::find($measureUnitId);
                    $returnMeasureUnitQuantity = $returnMeasureUnitData->quantity ?? 0;

                    if (!$returnMeasureUnitQuantity) {
                        return response()->json(['error' => "Invalid measure unit ID {$measureUnitId} at index {$index}"], 422);
                    }

                    // Calculate expected pieces
                    $expectedRegular = (float) ($product['quantity'] ?? 0);
                    $expectedFree = (float) ($product['free_quantity'] ?? 0);
                    $expectedRegularPieces = $this->calculatePieces($expectedRegular, $returnMeasureUnitQuantity);
                    $expectedFreePieces = $this->calculatePieces($expectedFree, $returnMeasureUnitQuantity);

                    $expectedTotalPieces = $expectedRegularPieces + $expectedFreePieces;

                    Log::debug('Piece calculation', [
                        'index' => $index,
                        'product_id' => $product['product_id'] ?? null,
                        'product_name' => $product['product_name'] ?? null,
                        'barcode' => $product['barcode'] ?? null,
                        'quantity' => $expectedRegular,
                        'free_quantity' => $expectedFree,
                        'measure_unit_id' => $measureUnitId,
                        'measure_unit_quantity' => $returnMeasureUnitQuantity,
                        'regular_pieces' => $expectedRegularPieces,
                        'free_pieces' => $expectedFreePieces,
                        'total_pieces' => $expectedTotalPieces,
                    ]);

                    // Validate field_values against total pieces
                    $fieldValuesFlat = !empty($product['field_values']) ? $this->flattenFieldValues($product['field_values'], $index) : [];
                    // Count the total number of field_values sets (each set represents one piece)
                    $totalFieldValueSets = count($product['field_values']);

                    if (!empty($fieldValuesFlat) && $totalFieldValueSets != $expectedTotalPieces) {
                        Log::error('Field values total quantity mismatch', [
                            'index' => $index,
                            'expected_total_pieces' => $expectedTotalPieces,
                            'found_total_pieces' => $totalFieldValueSets,
                            'field_values' => $fieldValuesFlat,
                        ]);
                        return response()->json([
                            'error' => "Field values total quantity mismatch at index {$index}. Expected total: {$expectedTotalPieces} pieces; Found: {$totalFieldValueSets} pieces",
                        ], 422);
                    }

                    // Validate field values against available sale product field values
                    if (!empty($fieldValuesFlat)) {
                        foreach ($fieldValuesFlat as $fv) {
                            $saleProductId = $fv['sale_product_id'];
                            $quantityIndex = $fv['quantity_index'];
                            $availableFv = $availableFieldValues[$saleProductId][$quantityIndex] ?? [];

                            if (empty($availableFv)) {
                                return response()->json([
                                    'error' => "Invalid field value for sale_product_id {$saleProductId} at quantity_index {$quantityIndex} at index {$index}. Not available for return.",
                                ], 422);
                            }

                            $fvMatch = collect($availableFv)->firstWhere(function ($available) use ($fv) {
                                return $available['product_field_id'] == $fv['product_field_id'] &&
                                    $available['value'] == $fv['value'];
                            });

                            if (!$fvMatch) {
                                return response()->json([
                                    'error' => "Field value mismatch for sale_product_id {$saleProductId} at quantity_index {$quantityIndex} at index {$index}.",
                                ], 422);
                            }
                        }
                    }

                    $filteredSaleProducts = $allSaleProducts->filter(function ($saleProduct) use ($product) {
                        return ($product['product_id'] && $saleProduct->product_id == $product['product_id']) ||
                            ($product['product_name'] && str_contains(strtolower($saleProduct->name), strtolower($product['product_name']))) ||
                            ($product['barcode'] && $saleProduct->product_code == $product['barcode']);
                    })->sortBy('created_at');

                    if ($filteredSaleProducts->isEmpty()) {
                        return response()->json(['error' => "No sale products found for product criteria at index {$index}"], 404);
                    }

                    $hasFieldValues = !empty($product['field_values']);
                    $saleProductIds = $hasFieldValues ? array_unique(array_column($fieldValuesFlat, 'sale_product_id')) : [];

                    if (!$hasFieldValues && isset($product['sale_product_id'])) {
                        $saleProductIds = [$product['sale_product_id']];
                    }

                    if (empty($saleProductIds) && $hasFieldValues) {
                        return response()->json(['error' => "No valid sale product IDs found in field values at index {$index}"], 422);
                    }

                    if (empty($saleProductIds)) {
                        // Apply strict FIFO: Track allocated pieces across all products in this payload
                        static $allocatedPiecesBySaleProduct = [];

                        $fifoSaleProducts = $filteredSaleProducts->filter(function ($saleProduct) use ($validated, $measureUnits, &$allocatedPiecesBySaleProduct) {
                            $availablePieces = $this->calculateAvailablePieces($saleProduct, $validated['company_id'], $measureUnits);
                            // Subtract previously allocated pieces in this request
                            $previouslyAllocated = $allocatedPiecesBySaleProduct[$saleProduct->id] ?? 0;
                            $remainingAvailable = $availablePieces - $previouslyAllocated;
                            Log::debug('Calculated available pieces after allocation', [
                                'sale_product_id' => $saleProduct->id,
                                'total_sold' => $saleProduct->quantity + ($saleProduct->free_quantity ?? 0),
                                'returned' => $saleProduct->saleProductReturns->sum(fn($return) => $return->quantity + ($return->free_quantity ?? 0)),
                                'available' => $availablePieces,
                                'previously_allocated' => $previouslyAllocated,
                                'remaining_available' => $remainingAvailable
                            ]);
                            return $remainingAvailable > 0;
                        })->sortBy('created_at')->values();

                        if ($fifoSaleProducts->isEmpty()) {
                            Log::error('No available sale product found for FIFO', [
                                'product_id' => $product['product_id'] ?? null,
                                'product_name' => $product['product_name'] ?? null,
                                'barcode' => $product['barcode'] ?? null,
                                'index' => $index,
                            ]);
                            return response()->json(['error' => "No available sale product found for criteria at index {$index}"], 422);
                        }

                        $remainingRegularPieces = $expectedRegularPieces;
                        $remainingFreePieces = $expectedFreePieces;
                        $remainingTotalPieces = $expectedRegularPieces + $expectedFreePieces;
                        $allocations = [];

                        foreach ($fifoSaleProducts as $saleProduct) {
                            // Skip if no pieces remain to allocate
                            if ($remainingTotalPieces <= 0) {
                                break;
                            }

                            $measureUnitId = $saleProduct->measure_unit_id;
                            $measureUnitData = $measureUnits[$measureUnitId] ?? MeasureUnit::find($measureUnitId);
                            $saleMeasureUnitQuantity = $measureUnitData->quantity ?? 1;

                            $availablePiecesFifo = $this->calculateAvailablePieces($saleProduct, $validated['company_id'], $measureUnits);
                            // Subtract previously allocated pieces
                            $previouslyAllocated = $allocatedPiecesBySaleProduct[$saleProduct->id] ?? 0;
                            $availablePiecesFifo -= $previouslyAllocated;

                            // Continue allocating from this sale_product_id until it's exhausted
                            while ($availablePiecesFifo > 0 && $remainingTotalPieces > 0) {
                                $allocateTotalPieces = min($remainingTotalPieces, $availablePiecesFifo);
                                $allocateRegularPieces = min($remainingRegularPieces, $allocateTotalPieces);
                                $allocateFreePieces = min($remainingFreePieces, $allocateTotalPieces - $allocateRegularPieces);

                                if ($allocateTotalPieces > 0) {
                                    $saleProductIds[] = $saleProduct->id;
                                    $allocations[$saleProduct->id] = ($allocations[$saleProduct->id] ?? [
                                        'regular_pieces' => 0,
                                        'free_pieces' => 0
                                    ]);
                                    $allocations[$saleProduct->id]['regular_pieces'] += $allocateRegularPieces;
                                    $allocations[$saleProduct->id]['free_pieces'] += $allocateFreePieces;

                                    // Update allocated pieces for this sale_product_id
                                    $allocatedPiecesBySaleProduct[$saleProduct->id] = ($allocatedPiecesBySaleProduct[$saleProduct->id] ?? 0) + $allocateTotalPieces;

                                    $remainingRegularPieces -= $allocateRegularPieces;
                                    $remainingFreePieces -= $allocateFreePieces;
                                    $remainingTotalPieces -= $allocateTotalPieces;
                                    $availablePiecesFifo -= $allocateTotalPieces;

                                    Log::debug('FIFO allocation', [
                                        'index' => $index,
                                        'sale_product_id' => $saleProduct->id,
                                        'sale_id' => $saleProduct->sale_id,
                                        'regular_pieces' => $allocateRegularPieces,
                                        'free_pieces' => $allocateFreePieces,
                                        'total_pieces' => $allocateTotalPieces,
                                        'available_pieces' => $availablePiecesFifo,
                                        'previously_allocated' => $previouslyAllocated,
                                        'remaining_regular_pieces' => $remainingRegularPieces,
                                        'remaining_free_pieces' => $remainingFreePieces,
                                        'sale_measure_unit_quantity' => $saleMeasureUnitQuantity,
                                    ]);

                                    // If this sale_product_id is exhausted, move to the next one
                                    if ($availablePiecesFifo <= 0) {
                                        break;
                                    }
                                } else {
                                    break; // No pieces allocated, exit inner loop
                                }
                            }
                        }

                        if ($remainingTotalPieces > 0) {
                            Log::error('Insufficient stock for product', [
                                'index' => $index,
                                'product_id' => $product['product_id'] ?? null,
                                'product_name' => $product['product_name'] ?? null,
                                'barcode' => $product['barcode'] ?? null,
                                'requested_total_pieces' => $expectedTotalPieces,
                                'remaining_regular_pieces' => $remainingRegularPieces,
                                'remaining_free_pieces' => $remainingFreePieces,
                            ]);
                            return response()->json([
                                'error' => "Insufficient stock for product at index {$index}. Requested: {$expectedTotalPieces} pieces (regular: {$expectedRegularPieces}, free: {$expectedFreePieces}), Allocated: " . ($expectedRegularPieces - $remainingRegularPieces) . " regular, " . ($expectedFreePieces - $remainingFreePieces) . " free",
                            ], 422);
                        }
                    }

                    // Group field values by sale_product_id and quantity_index
                    $groupedFieldValues = collect($fieldValuesFlat)
                        ->groupBy('sale_product_id')
                        ->map(function ($group) {
                            return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                                return collect($fvGroup)->map(function ($fv) {
                                    return [
                                        'sale_product_id' => $fv['sale_product_id'],
                                        'product_field_id' => $fv['product_field_id'],
                                        'value' => $fv['value'],
                                        'quantity_index' => $fv['quantity_index'],
                                        'quantity_type' => $fv['quantity_type'],
                                    ];
                                })->unique(function ($fv) {
                                    return "{$fv['product_field_id']}:{$fv['value']}:{$fv['quantity_type']}";
                                })->values()->toArray();
                            })->toArray();
                        })->toArray();

                    foreach ($saleProductIds as $saleProductId) {
                        $saleProduct = $filteredSaleProducts->firstWhere('id', $saleProductId);
                        if (!$saleProduct) {
                            return response()->json(['error' => "Invalid sale product ID {$saleProductId} at index {$index}"], 422);
                        }


                        if ($hasFieldValues) {
                            $MeasureUnitId = $saleProduct->measure_unit_id ?? null;
                            $measureUnitDataName = MeasureUnit::where('id', $MeasureUnitId)->first();
                            $MeasureUnitQuantityUsed = $measureUnitDataName->quantity ?? 1;

                            $availablePieces = $this->calculateAvailablePiecesForfifo($saleProduct, $validated['company_id'], $MeasureUnitQuantityUsed);

                        } else {
                            $saleMeasureUnit = $saleProduct->measure_unit_id ?? 1;
                            $measureUnitId = MeasureUnit::where('id', $saleMeasureUnit)->first();
                            $saleMeasureUnitQuantity = $measureUnitId->quantity ?? 0;

                            $availablePieces = $this->calculateAvailablePiecesForFifo($saleProduct, $validated['company_id'], $saleMeasureUnitQuantity);


                        }


                        if ($hasFieldValues) {
                            $fvByIndex = $groupedFieldValues[$saleProductId] ?? [];
                            $totalFieldValueSetsForProduct = count($fvByIndex);

                            if ($totalFieldValueSetsForProduct == 0) {
                                return response()->json(['error' => "No valid field value sets for sale product ID {$saleProductId} at index {$index}"], 422);
                            }


                            if ($totalFieldValueSetsForProduct > $availablePieces) {

                                return response()->json([
                                    'error' => "Insufficient stock for sale product ID {$saleProductId} at index {$index}. Requested: {$totalFieldValueSetsForProduct}, Available: {$availablePieces}",
                                ], 422);
                            }

                            $regularFieldValueSets = collect($fvByIndex)->filter(function ($fvSet) {
                                return collect($fvSet)->first()['quantity_type'] === 'regular';
                            })->count();
                            $freeFieldValueSets = collect($fvByIndex)->filter(function ($fvSet) {
                                return collect($fvSet)->first()['quantity_type'] === 'free';
                            })->count();

                            [$quantity, $freeQuantity] = $this->convertToTargetMeasureUnit(
                                $regularFieldValueSets,
                                $freeFieldValueSets,
                                $returnMeasureUnitQuantity
                            );
                            $fieldValues = $fvByIndex;

                            Log::debug('Field values allocation', [
                                'index' => $index,
                                'sale_product_id' => $saleProductId,
                                'sale_id' => $saleProduct->sale_id,
                                'regular_pieces' => $regularFieldValueSets,
                                'free_pieces' => $freeFieldValueSets,
                                'total_pieces' => $totalFieldValueSetsForProduct,
                                'quantity' => $quantity,
                                'free_quantity' => $freeQuantity,
                                'measure_unit_id' => $product['measure_unit_id'],
                                'measure_unit_quantity' => $returnMeasureUnitQuantity,
                                'field_values' => $fvByIndex,
                            ]);
                        } else {
                            $allocatedRegularPieces = $allocations[$saleProductId]['regular_pieces'] ?? 0;
                            $allocatedFreePieces = $allocations[$saleProductId]['free_pieces'] ?? 0;
                            $allocatedTotalPieces = $allocatedRegularPieces + $allocatedFreePieces;


                            if ($allocatedTotalPieces > $availablePieces) {
                                return response()->json([
                                    'error' => "Insufficient stock for sale product ID {$saleProductId} at index {$index}. Requested: {$allocatedTotalPieces}, Available: {$availablePieces}",
                                ], 422);
                            }

                            [$quantity, $freeQuantity] = $this->convertToTargetMeasureUnit(
                                $allocatedRegularPieces,
                                $allocatedFreePieces,
                                $returnMeasureUnitQuantity
                            );
                            $fieldValues = [];

                            Log::debug('FIFO quantity allocation', [
                                'index' => $index,
                                'sale_product_id' => $saleProductId,
                                'sale_id' => $saleProduct->sale_id,
                                'regular_pieces' => $allocatedRegularPieces,
                                'free_pieces' => $allocatedFreePieces,
                                'total_pieces' => $allocatedTotalPieces,
                                'quantity' => $quantity,
                                'free_quantity' => $freeQuantity,
                                'measure_unit_id' => $product['measure_unit_id'],
                                'measure_unit_quantity' => $returnMeasureUnitQuantity,
                            ]);
                        }

                        $salesReturnProducts[] = [
                            'sale_product_id' => $saleProductId,
                            'purchase_product_id' => $product['purchase_product_id'] ?? $saleProduct->purchase_product_id,
                            'product_id' => $saleProduct->product_id,
                            'quantity' => $quantity,
                            'free_quantity' => $freeQuantity,
                            'price' => $product['price'] ?? $saleProduct->price,
                            'amount' => ($product['price'] ?? $saleProduct->price) * $quantity - ($product['discount_amount'] ?? 0),
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'is_vatable' => $product['is_vatable'] ?? $saleProduct->is_vatable,
                            'measure_unit_id' => $product['measure_unit_id'],
                            'batch_no' => $product['batch_no'] ?? $saleProduct->batch_no,
                            'mfd' => $product['mfd'] ?? $saleProduct->mfd,
                            'expiry_date' => $product['expiry_date'] ?? $saleProduct->expiry_date,
                            'product_name' => $product['product_name'] ?? $saleProduct->name,
                            'field_values' => $fieldValues,
                        ];
                    }
                }
            }

            $validated['sales_return_products'] = $salesReturnProducts;
            if (empty($salesReturnProducts)) {
                return response()->json(['error' => 'No valid products available for return'], 422);
            }

            // Set sale-related fields
            $validated['batch_no'] = $validated['batch_no'] ?? ($sale->batch_no ? $sale->batch_no . '-RETURN' : null);
            $validated['customer_id'] = $validated['customer_id'] ?? $sale->customer_id;
            $validated['salesman_id'] = $validated['salesman_id'] ?? $sale->salesman_id;
            $validated['store_id'] = $validated['store_id'] ?? $sale->store_id;
            $validated['location_id'] = $validated['location_id'] ?? $sale->location_id;

            // Prepare sales return additionals
            $salesReturnAdditionalsData = $validated['sales_return_additional'] ?? null;
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
            $salesReturn = DB::transaction(function () use ($validated, $salesReturnAdditionalsData, $measureUnits) {
                SaleProduct::whereIn('id', array_column($validated['sales_return_products'], 'sale_product_id'))
                    ->lockForUpdate()
                    ->get();

                $salesReturnData = array_intersect_key($validated, array_flip((new SalesReturn)->getFillable()));
                $salesReturn = SalesReturn::create($salesReturnData);

                foreach ($validated['sales_return_products'] as $index => $product) {
                    $saleProduct = SaleProduct::find($product['sale_product_id']);
                    if (!$saleProduct) {
                        throw new \Exception("Sale product ID {$product['sale_product_id']} not found at index {$index}");
                    }


                    $meaureUnitId = $saleProduct->measure_unit_id;
                    $measureUnitData = MeasureUnit::where('id', $meaureUnitId)->first();
                    $measureUnits = $measureUnitData->quantity ?? 1;

                    $availablePieces = $this->calculateAvailablePiecesForfifo($saleProduct, $validated['company_id'], $measureUnits);


                    $returnMeasureUnitQuantity = $measureUnits[$product['measure_unit_id']]->quantity ?? 1;
                    $requestedPieces = $this->calculatePieces(
                        $product['quantity'] + ($product['free_quantity'] ?? 0),
                        $returnMeasureUnitQuantity
                    );

                    if ($requestedPieces > $availablePieces) {
                        throw new \Exception("Insufficient quantity for sale product ID {$product['sale_product_id']} at index {$index}. Available: {$availablePieces}, Requested: {$requestedPieces}");
                    }

                    $salesReturnProduct = $salesReturn->salesReturnProducts()->create([
                        'company_id' => $validated['company_id'],
                        'sales_return_id' => $salesReturn->id,
                        'sale_id' => $saleProduct->sale_id,
                        'sale_product_id' => $product['sale_product_id'],
                        'purchase_product_id' => $product['purchase_product_id'] ?? $saleProduct->purchase_product_id,
                        'product_id' => $saleProduct->product_id,

                        'product_name' => $product['product_name'] ?? $saleProduct->name,
                        'quantity' => $product['quantity'] ?? 0,
                        'free_quantity' => $product['free_quantity'] ?? 0,
                        'price' => $product['price'] ?? $saleProduct->price,
                        'amount' => $product['amount'] ?? (($product['price'] ?? $saleProduct->price) * $product['quantity']) - ($product['discount_amount'] ?? 0),
                        'discount_percent' => $product['discount_percent'] ?? 0,
                        'discount_amount' => $product['discount_amount'] ?? 0,
                        'is_vatable' => $product['is_vatable'] ?? $saleProduct->is_vatable,
                        'measure_unit_id' => $product['measure_unit_id'],
                        'batch_no' => $product['batch_no'] ?? $saleProduct->batch_no,
                        'mfd' => $product['mfd'] ?? $saleProduct->mfd,
                        'expiry_date' => $product['expiry_date'] ?? $saleProduct->expiry_date,
                    ]);

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
                        DB::table('sale_return_product_field_values')->insert($fieldValues);
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




    private function calculatePieces(float $quantity, float $measureUnitQuantity): float
    {
        if ($measureUnitQuantity <= 0) {
            Log::warning('Invalid measure unit quantity', ['measureUnitQuantity' => $measureUnitQuantity]);
            return 0;
        }


        $integerPart = floor($quantity);
        $decimalPart = $quantity - $integerPart;
        $decimalStr = (string) $decimalPart;
        $decimalPieces = $decimalStr > 0 ? (int) str_replace('.', '', (string) $decimalStr) : 0;

        return ($integerPart * $measureUnitQuantity) + $decimalPieces;
    }

    private function convertToTargetMeasureUnit(float $regularPieces, float $freePieces, float $targetMeasureUnitQuantity): array
    {
        if ($targetMeasureUnitQuantity <= 0) {
            Log::warning('Invalid target measure unit quantity', ['targetMeasureUnitQuantity' => $targetMeasureUnitQuantity]);
            return [0, 0];
        }

        $regularPiecesInt = floor($regularPieces / $targetMeasureUnitQuantity);
        $regularRemainingPieces = $regularPieces - ($regularPiecesInt * $targetMeasureUnitQuantity);
        $regularDecimal = $regularRemainingPieces > 0 ? (float) ('0.' . (int) $regularRemainingPieces) : 0;
        $regularQuantity = $regularPiecesInt + $regularDecimal;

        $freePiecesInt = floor($freePieces / $targetMeasureUnitQuantity);
        $freeRemainingPieces = $freePieces - ($freePiecesInt * $targetMeasureUnitQuantity);
        $freeDecimal = $freeRemainingPieces > 0 ? (float) ('0.' . (int) $freeRemainingPieces) : 0;
        $freeQuantity = $freePiecesInt + $freeDecimal;

        Log::debug('Converted to target measure unit', [
            'regular_pieces' => $regularPieces,
            'free_pieces' => $freePieces,
            'target_measure_unit_quantity' => $targetMeasureUnitQuantity,
            'regular_quantity' => $regularQuantity,
            'free_quantity' => $freeQuantity
        ]);

        return [$regularQuantity, $freeQuantity];
    }

    private function flattenFieldValues($fieldValues, $index): array
    {
        $flat = [];
        foreach ($fieldValues as $fvSet) {
            foreach ($fvSet as $fv) {
                $flat[] = [
                    'sale_product_id' => $fv['sale_product_id'] ?? throw new \Exception("Missing sale_product_id in field values at index {$index}."),
                    'product_field_id' => $fv['product_field_id'] ?? throw new \Exception("Missing product_field_id in field values at index {$index}."),
                    'value' => $fv['value'] ?? throw new \Exception("Missing value in field values at index {$index}."),
                    'quantity_index' => $fv['quantity_index'] ?? throw new \Exception("Missing quantity_index in field values at index {$index}."),
                    'quantity_type' => $fv['quantity_type'] ?? 'regular',
                ];
            }
        }
        Log::debug('Flattening field values', [
            'index' => $index,
            'field_values' => $fieldValues,
            'flat_field_values' => $flat
        ]);
        return $flat;
    }


    private function calculateAvailablePieces($saleProduct, int $companyId, $measureUnitsCalc): int
    {


        $saleMeasureUnitQuantity = isset($measureUnitsCalc[$saleProduct->measure_unit_id]) ? $measureUnitsCalc[$saleProduct->measure_unit_id]->quantity : 1;

        Log::debug('Measure unit quantity', [
            'sale_product_id' => $saleProduct->id,
            'measure_unit_id' => $saleProduct->measure_unit_id,
            'saleMeasureUnitQuantity' => $saleMeasureUnitQuantity
        ]);

        if ($saleMeasureUnitQuantity <= 0) {
            Log::warning('Invalid measure unit quantity for sale product', [
                'sale_product_id' => $saleProduct->id,
                'measureUnitQuantity' => $saleMeasureUnitQuantity
            ]);
            return 0;
        }


        $regularPieces = $this->calculatePieces($saleProduct->quantity ?? 0, $saleMeasureUnitQuantity);
        $freePieces = $this->calculatePieces($saleProduct->free_quantity ?? 0, $saleMeasureUnitQuantity);
        $totalSoldPieces = $regularPieces + $freePieces;

        $returnedPieces = $saleProduct->saleProductReturns()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->get()
            ->reduce(function ($carry, $return) use ($measureUnitsCalc) {
                $returnMeasureUnitQuantity = isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1;
                return $carry + $this->calculatePieces(
                    ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                    $returnMeasureUnitQuantity
                );
            }, 0);

        $availablePieces = $totalSoldPieces - $returnedPieces;

        if ($availablePieces < 0) {
            Log::warning('Negative available pieces detected', [
                'sale_product_id' => $saleProduct->id,
                'total_sold' => $totalSoldPieces,
                'returned' => $returnedPieces,
                'available' => $availablePieces
            ]);
        }

        Log::debug('Calculated available pieces', [
            'sale_product_id' => $saleProduct->id,
            'total_sold' => $totalSoldPieces,
            'returned' => $returnedPieces,
            'available' => $availablePieces
        ]);

        return max(0, (int) $availablePieces);
    }

    private function calculateAvailablePiecesForFifo($saleProduct, int $companyId, $measureUnitsCalc): int
    {

        $saleMeasureUnitQuantity = $measureUnitsCalc ?? 1;


        Log::debug('Measure unit quantity', [
            'sale_product_id' => $saleProduct->id,
            'measure_unit_id' => $saleProduct->measure_unit_id,
            'saleMeasureUnitQuantity' => $saleMeasureUnitQuantity
        ]);

        if ($saleMeasureUnitQuantity <= 0) {
            Log::warning('Invalid measure unit quantity for sale product', [
                'sale_product_id' => $saleProduct->id,
                'measureUnitQuantity' => $saleMeasureUnitQuantity
            ]);
            return 0;
        }


        $regularPieces = $this->calculatePieces($saleProduct->quantity ?? 0, $saleMeasureUnitQuantity);
        $freePieces = $this->calculatePieces($saleProduct->free_quantity ?? 0, $saleMeasureUnitQuantity);
        $totalSoldPieces = $regularPieces + $freePieces;

        $returnedPieces = $saleProduct->saleProductReturns()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->get()
            ->reduce(function ($carry, $return) use ($measureUnitsCalc) {
                $returnMeasureUnitQuantity = isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1;
                return $carry + $this->calculatePieces(
                    ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                    $returnMeasureUnitQuantity
                );
            }, 0);

        $availablePieces = $totalSoldPieces - $returnedPieces;

        if ($availablePieces < 0) {
            Log::warning('Negative available pieces detected', [
                'sale_product_id' => $saleProduct->id,
                'total_sold' => $totalSoldPieces,
                'returned' => $returnedPieces,
                'available' => $availablePieces
            ]);
        }

        Log::debug('Calculated available pieces', [
            'sale_product_id' => $saleProduct->id,
            'total_sold' => $totalSoldPieces,
            'returned' => $returnedPieces,
            'available' => $availablePieces
        ]);

        return max(0, (int) $availablePieces);
    }



    public function getItemByBillNumber($billNumber): JsonResponse
    {
        try {
            $purchase = SalesReturn::where('invoice_number', $billNumber)->firstOrFail();
            return $this->show($purchase->id);
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


    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'customer_id' => 'nullable|exists:customers,id',
                'customer_name' => 'required|string|max:255',
                'customer_address' => 'nullable|string|max:255',
                'customer_contact' => 'nullable|string|max:255',
                'credit_days' => 'nullable|string|max:255',
                'salesman_id' => 'nullable|exists:salesmen,id',
                'invoice_number' => 'nullable|string|max:255|unique:sales_returns,invoice_number',
                'document_number' => 'nullable|string|max:255',
                'ref_bill_no' => 'nullable|string|max:255',
                'return_bill_no' => 'nullable|string|max:255',
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
                'non_taxable_amount' => 'nullable|numeric',
                'taxable_amount' => 'nullable|numeric',
                'total_amount' => 'nullable|numeric|min:0',
                'round_of_amount' => 'nullable|numeric',
                'roundoff_type' => 'nullable|string|max:255',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank_name' => 'nullable|string',
                'payment.bank' => 'nullable|numeric|min:0',
                'payment_type' => 'nullable|string|in:cash,credit,bank',
                'sale_id' => [
                    'required_without:sale_invoice_number',
                    'integer',
                    'exists:sales,id',
                    Rule::exists('sales')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)->whereNull('deleted_at');
                    }),
                ],
                'sale_invoice_number' => [
                    'required_without:sale_id',
                    'string',
                    'max:255',
                    Rule::exists('sales', 'invoice_number')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)->whereNull('deleted_at');
                    }),
                ],
                'sales_return_products' => 'required|array',
                'sales_return_products.*.sale_product_id' => 'nullable|integer|exists:sale_products,id',
                'sales_return_products.*.product_id' => 'required|integer|exists:products,id',
                'sales_return_products.*.product_name' => 'nullable|string|max:255',
                'sales_return_products.*.product_code' => 'nullable|string|max:255',
                'sales_return_products.*.batch_no' => 'nullable|string|max:255',
                'sales_return_products.*.mfd' => 'nullable|string|max:255',
                'sales_return_products.*.expiry_date' => 'nullable|string|max:255',
                'sales_return_products.*.quantity' => 'required|numeric|min:0',
                'sales_return_products.*.free_quantity' => 'nullable|numeric|min:0',
                'sales_return_products.*.price' => 'required|numeric|min:0',
                'sales_return_products.*.amount' => 'required|numeric|min:0',
                'sales_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sales_return_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sales_return_products.*.is_vatable' => 'nullable|boolean',
                'sales_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'sales_return_products.*.field_values' => 'nullable|array',
                'sales_return_products.*.field_values.*' => 'array|min:1',
                'sales_return_products.*.field_values.*.*.sale_product_id' => [
                    'required_if:sales_return_products.*.field_values,array',
                    'integer',
                    'exists:sale_products,id'
                ],
                'sales_return_products.*.field_values.*.*.product_field_id' => [
                    'required_if:sales_return_products.*.field_values,array',
                    'integer',
                    'exists:product_fields,id'
                ],
                'sales_return_products.*.field_values.*.*.value' => [
                    'required_if:sales_return_products.*.field_values,array',
                    'string',
                    'max:255'
                ],
                'sales_return_products.*.field_values.*.*.quantity_index' => [
                    'required_if:sales_return_products.*.field_values,array',
                    'integer',
                    'min:0'
                ],
                'sales_return_products.*.field_values.*.*.quantity_type' => 'nullable|string|in:regular,free',
                'sales_return_products.*.field_values.*.*.name' => 'nullable|string|max:255',
                'sales_return_additional' => 'nullable|array',
                'sales_return_additional.place' => 'nullable|string|max:255',
                'sales_return_additional.transport' => 'nullable|string|max:255',
                'sales_return_additional.vehicle_number' => 'nullable|string|max:255',
                'sales_return_additional.vehicle_name' => 'nullable|string|max:255',
                'sales_return_additional.driver_name' => 'nullable|string|max:255',
                'sales_return_additional.return_code' => 'required_if:sales_return_additional,exists|string|max:255',
                'sales_return_additional.driver_contact_number' => 'nullable|string|max:255',
                'sales_return_additional.return_date' => 'nullable|string|max:255',
                'sales_return_additional.return_time' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                Log::error('Validation failed', ['errors' => $validator->errors()]);
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            Log::debug('Sales return request validated', ['request' => $validated]);

            $sale = Sale::when(isset($validated['sale_id']), function ($query) use ($validated) {
                return $query->where('id', $validated['sale_id']);
            })
                ->when(isset($validated['sale_invoice_number']), function ($query) use ($validated) {
                    return $query->where('invoice_number', $validated['sale_invoice_number']);
                })
                ->where('company_id', $validated['company_id'])
                ->whereNull('deleted_at')
                ->first();

            if (!$sale) {
                Log::error('Sale not found', [
                    'sale_id' => $validated['sale_id'] ?? null,
                    'sale_invoice_number' => $validated['sale_invoice_number'] ?? null,
                    'company_id' => $validated['company_id'],
                ]);
                return response()->json(['error' => 'Sale not found'], 404);
            }

            $validated['sale_id'] = $sale->id;

            $measureUnits = MeasureUnit::whereIn('id', collect($validated['sales_return_products'])
                ->pluck('measure_unit_id')
                ->merge($sale->saleProducts->pluck('measure_unit_id'))
                ->unique()
                ->toArray())
                ->get()
                ->keyBy('id')
                ->map(function ($unit) {
                    return (object) ['quantity' => $unit->quantity ?? 1];
                })->toArray();

            $saleProducts = $sale->saleProducts()
                ->where('company_id', $validated['company_id'])
                ->whereNull('deleted_at')
                ->with(['measureUnit', 'saleProductReturns' => fn($q) => $q->where('company_id', $validated['company_id'])->whereNull('deleted_at')])
                ->get();

            if ($saleProducts->isEmpty()) {
                Log::warning('No sale products found for sale', [
                    'sale_id' => $validated['sale_id'],
                    'company_id' => $validated['company_id'],
                ]);
                return response()->json(['error' => 'No products found in this sale'], 404);
            }

            $salesReturnProducts = [];
            foreach ($validated['sales_return_products'] as $index => $product) {
                $productId = $product['product_id'];
                $measureUnitId = $product['measure_unit_id'];
                $returnMeasureUnit = isset($measureUnits[$measureUnitId]) ? $measureUnits[$measureUnitId] : null;

                if (!$returnMeasureUnit) {
                    Log::error('Invalid measure unit ID', [
                        'measure_unit_id' => $measureUnitId,
                        'index' => $index,
                    ]);
                    return response()->json([
                        'error' => "Invalid measure unit ID {$measureUnitId} at index {$index}",
                    ], 422);
                }

                $returnMeasureUnitQuantity = $returnMeasureUnit->quantity ?? 1;
                $regularQuantity = (float) ($product['quantity'] ?? 0);
                $freeQuantity = (float) ($product['free_quantity'] ?? 0);
                $regularPieces = $this->calculatePieces($regularQuantity, $returnMeasureUnitQuantity);
                $freePieces = $this->calculatePieces($freeQuantity, $returnMeasureUnitQuantity);

                $totalRequestedPieces = $regularPieces + $freePieces;

                Log::debug('Return product quantities', [
                    'index' => $index,
                    'product_id' => $productId,
                    'regular_quantity' => $regularQuantity,
                    'free_quantity' => $freeQuantity,
                    'regular_pieces' => $regularPieces,
                    'free_pieces' => $freePieces,
                    'measure_unit_id' => $measureUnitId,
                    'measure_unit_quantity' => $returnMeasureUnitQuantity
                ]);

                $fieldValuesFlat = $this->flattenFieldValues($product['field_values'], $index);
                $groupedFieldValues = collect($fieldValuesFlat)
                    ->groupBy('sale_product_id')
                    ->map(function ($group) {
                        return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                            return collect($fvGroup)->map(function ($fv) {
                                return [
                                    'sale_product_id' => $fv['sale_product_id'],
                                    'product_field_id' => $fv['product_field_id'],
                                    'value' => $fv['value'],
                                    'quantity_index' => $fv['quantity_index'],
                                    'quantity_type' => $fv['quantity_type'] ?? 'regular',
                                    'name' => $fv['name'] ?? null,
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

                $hasFieldValues = !empty($fieldValuesFlat);
                $saleProductIds = $hasFieldValues ? array_keys($groupedFieldValues) : [];

                if (!$hasFieldValues) {
                    if (isset($product['sale_product_id'])) {
                        $saleProductIds = [$product['sale_product_id']];
                    } else {
                        // Apply FIFO: Select SaleProduct by product_id from the sale, ordered by created_at
                        $saleProductQuery = $saleProducts->where('product_id', $productId);
                        $fifoSaleProducts = $saleProductQuery->sortBy('created_at')->filter(function ($saleProduct) use ($validated, $measureUnits) {
                            $availablePieces = $this->calculateAvailablePieces($saleProduct, $validated['company_id'], $measureUnits);
                            Log::debug('Calculated available pieces', [
                                'sale_product_id' => $saleProduct->id,
                                'total_sold' => $saleProduct->quantity + ($saleProduct->free_quantity ?? 0),
                                'returned' => $saleProduct->saleProductReturns->sum(fn($return) => $return->quantity + ($return->free_quantity ?? 0)),
                                'available' => $availablePieces
                            ]);
                            return $availablePieces > 0;
                        })->values();

                        if ($fifoSaleProducts->isEmpty()) {
                            Log::error('No available sale product found for FIFO', [
                                'product_id' => $productId,
                                'sale_id' => $validated['sale_id'],
                                'index' => $index,
                            ]);
                            return response()->json([
                                'error' => "No available sale product found for product ID {$productId} in sale ID {$validated['sale_id']} at index {$index}",
                            ], 422);
                        }

                        // Distribute requested pieces across SaleProducts
                        $remainingRegularPieces = $regularPieces;
                        $remainingFreePieces = $freePieces;
                        $saleProductIds = [];
                        $allocations = [];

                        foreach ($fifoSaleProducts as $saleProduct) {
                            if ($remainingRegularPieces <= 0 && $remainingFreePieces <= 0) {
                                break;
                            }

                            $saleMeasureUnitQuantity = $measureUnits[$saleProduct->measure_unit_id]->quantity ?? 1;
                            Log::debug('Measure unit quantity', [
                                'sale_product_id' => $saleProduct->id,
                                'measure_unit_id' => $saleProduct->measure_unit_id,
                                'saleMeasureUnitQuantity' => $saleMeasureUnitQuantity
                            ]);

                            $availablePieces = $this->calculateAvailablePieces($saleProduct, $validated['company_id'], $measureUnits);
                            $totalRemainingPieces = $remainingRegularPieces + $remainingFreePieces;
                            $allocatePieces = min($totalRemainingPieces, $availablePieces);
                            $allocateRegularPieces = min($remainingRegularPieces, $allocatePieces);
                            $allocateFreePieces = min($remainingFreePieces, $allocatePieces - $allocateRegularPieces);

                            if ($allocateRegularPieces > 0 || $allocateFreePieces > 0) {
                                $saleProductIds[] = $saleProduct->id;
                                $allocations[$saleProduct->id] = [
                                    'regular_pieces' => $allocateRegularPieces,
                                    'free_pieces' => $allocateFreePieces,
                                ];

                                $remainingRegularPieces -= $allocateRegularPieces;
                                $remainingFreePieces -= $allocateFreePieces;

                                Log::debug('Allocated pieces to SaleProduct', [
                                    'index' => $index,
                                    'sale_product_id' => $saleProduct->id,
                                    'allocated_regular_pieces' => $allocateRegularPieces,
                                    'allocated_free_pieces' => $allocateFreePieces,
                                    'remaining_regular_pieces' => $remainingRegularPieces,
                                    'remaining_free_pieces' => $remainingFreePieces,
                                ]);
                            }
                        }

                        if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                            Log::error('Insufficient stock across all SaleProducts', [
                                'product_id' => $productId,
                                'sale_id' => $validated['sale_id'],
                                'index' => $index,
                                'total_requested_pieces' => $totalRequestedPieces,
                                'remaining_regular_pieces' => $remainingRegularPieces,
                                'remaining_free_pieces' => $remainingFreePieces,
                            ]);
                            return response()->json([
                                'error' => "Insufficient stock for product ID {$productId} in sale ID {$validated['sale_id']} at index {$index}. Requested: {$totalRequestedPieces} pieces (Regular: {$regularPieces}, Free: {$freePieces}), Allocated: " . ($totalRequestedPieces - ($remainingRegularPieces + $remainingFreePieces)) . " pieces.",
                            ], 422);
                        }
                    }
                }

                foreach ($saleProductIds as $saleProductId) {
                    $saleProduct = $saleProducts->firstWhere('id', $saleProductId);
                    if (!$saleProduct || $saleProduct->product_id != $productId) {
                        Log::error('Invalid or mismatched sale product ID', [
                            'sale_product_id' => $saleProductId,
                            'product_id' => $productId,
                            'index' => $index,
                        ]);
                        return response()->json([
                            'error' => "Invalid sale product ID {$saleProductId} for product ID {$productId} at index {$index}",
                        ], 422);
                    }

                    $saleMeasureUnitQuantity = $measureUnits[$saleProduct->measure_unit_id]->quantity ?? 1;
                    Log::debug('Measure unit quantity', [
                        'sale_product_id' => $saleProduct->id,
                        'measure_unit_id' => $saleProduct->measure_unit_id,
                        'saleMeasureUnitQuantity' => $saleMeasureUnitQuantity
                    ]);

                    $availablePieces = $this->calculateAvailablePieces($saleProduct, $validated['company_id'], $measureUnits);

                    if ($hasFieldValues) {
                        $fvByIndex = $groupedFieldValues[$saleProductId] ?? [];
                        $regularFieldValueSets = collect($fvByIndex)
                            ->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'regular')
                            ->count();
                        $freeFieldValueSets = collect($fvByIndex)
                            ->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'free')
                            ->count();
                        $totalRequestedPiecesForProduct = $regularFieldValueSets + $freeFieldValueSets;

                        if ($totalRequestedPiecesForProduct == 0) {
                            Log::error('No valid field value sets for sale product', [
                                'index' => $index,
                                'sale_product_id' => $saleProductId,
                                'field_values' => $fvByIndex
                            ]);
                            return response()->json([
                                'error' => "No valid field value sets for sale product ID {$saleProductId} at index {$index}",
                            ], 422);
                        }

                        if ($totalRequestedPiecesForProduct > $availablePieces) {
                            Log::error('Insufficient stock for sale product', [
                                'index' => $index,
                                'sale_product_id' => $saleProductId,
                                'total_requested_pieces' => $totalRequestedPiecesForProduct,
                                'available_pieces' => $availablePieces
                            ]);
                            return response()->json([
                                'error' => "Insufficient stock for sale product ID {$saleProductId} at index {$index}. Requested: {$totalRequestedPiecesForProduct}, Available: {$availablePieces}",
                            ], 422);
                        }

                        [$quantity, $freeQuantity] = $this->convertToTargetMeasureUnit($regularFieldValueSets, $freeFieldValueSets, $saleMeasureUnitQuantity);
                    } else {
                        $allocatedRegularPieces = $allocations[$saleProductId]['regular_pieces'] ?? $regularPieces;
                        $allocatedFreePieces = $allocations[$saleProductId]['free_pieces'] ?? $freePieces;
                        $totalRequestedPiecesForProduct = $allocatedRegularPieces + $allocatedFreePieces;

                        if ($totalRequestedPiecesForProduct > $availablePieces) {
                            Log::error('Insufficient stock for sale product', [
                                'index' => $index,
                                'sale_product_id' => $saleProductId,
                                'total_requested_pieces' => $totalRequestedPiecesForProduct,
                                'available_pieces' => $availablePieces
                            ]);
                            return response()->json([
                                'error' => "Insufficient stock for sale product ID {$saleProductId} at index {$index}. Requested: {$totalRequestedPiecesForProduct}, Available: {$availablePieces}",
                            ], 422);
                        }

                        [$quantity, $freeQuantity] = $this->convertToTargetMeasureUnit($allocatedRegularPieces, $allocatedFreePieces, $saleMeasureUnitQuantity);
                        Log::debug('Converted to target measure unit', [
                            'regular_pieces' => $allocatedRegularPieces,
                            'free_pieces' => $allocatedFreePieces,
                            'target_measure_unit_quantity' => $saleMeasureUnitQuantity,
                            'regular_quantity' => $quantity,
                            'free_quantity' => $freeQuantity
                        ]);
                    }

                    $salesReturnProducts[] = [
                        'sale_product_id' => $saleProductId,
                        'purchase_product_id' => $saleProduct->purchase_product_id,
                        'product_id' => $saleProduct->product_id,
                        'product_name' => $product['product_name'] ?? null,
                        'product_code' => $product['product_code'] ?? null,
                        'quantity' => $quantity,
                        'free_quantity' => $freeQuantity,

                        'amount' => ($product['price'] * $quantity) - ($product['discount_amount'] ?? 0),
                        'price' => $product['price'],
                        'discount_percent' => $product['discount_percent'] ?? 0,
                        'discount_amount' => $product['discount_amount'] ?? 0,
                        'is_vatable' => $product['is_vatable'] ?? $saleProduct->is_vatable,
                        'measure_unit_id' => $saleProduct->measure_unit_id,
                        'batch_no' => $product['batch_no'] ?? $saleProduct->batch_no,
                        'mfd' => $product['mfd'] ?? $saleProduct->mfd,
                        'expiry_date' => $product['expiry_date'] ?? $saleProduct->expiry_date,
                        'name' => $product['product_name'] ?? $saleProduct->name,
                        'field_values' => $hasFieldValues ? ($groupedFieldValues[$saleProductId] ?? []) : [],
                    ];

                    Log::debug('Allocation created', [
                        'index' => $index,
                        'sale_product_id' => $saleProductId,
                        'quantity' => $quantity,
                        'free_quantity' => $freeQuantity,
                        'regular_pieces' => $hasFieldValues ? $regularFieldValueSets : $allocatedRegularPieces,
                        'free_pieces' => $hasFieldValues ? $freeFieldValueSets : $allocatedFreePieces,
                        'sale_measure_unit_quantity' => $saleMeasureUnitQuantity
                    ]);
                }

                if ($hasFieldValues) {
                    $totalFieldValuePieces = collect($saleProductIds)->sum(function ($saleProductId) use ($groupedFieldValues) {
                        return collect($groupedFieldValues[$saleProductId] ?? [])->count();
                    });
                    $totalPayloadPieces = $regularPieces + $freePieces;
                    if ($totalFieldValuePieces != $totalPayloadPieces) {
                        Log::warning('Field value pieces do not match payload pieces', [
                            'index' => $index,
                            'product_id' => $productId,
                            'field_value_pieces' => $totalFieldValuePieces,
                            'payload_pieces' => $totalPayloadPieces
                        ]);
                        // Allow mismatch as per previous example
                    }
                }
            }

            $validated['sales_return_products'] = $salesReturnProducts;

            if (empty($validated['sales_return_products'])) {
                Log::error('No valid products available for return', [
                    'sale_id' => $validated['sale_id'],
                    'sales_return_products' => $salesReturnProducts,
                ]);
                return response()->json(['error' => 'No valid products available for return'], 422);
            }

            $salesReturn = DB::transaction(function () use ($validated) {
                $salesReturn = SalesReturn::create([
                    'company_id' => $validated['company_id'],
                    'customer_id' => $validated['customer_id'] ?? null,
                    'customer_address' => $validated['customer_address'] ?? null,
                    'customer_name' => $validated['customer_name'] ?? null,
                    'salesman_id' => $validated['salesman_id'] ?? null,
                    'invoice_number' => $validated['invoice_number'] ?? null,
                    'document_number' => $validated['document_number'] ?? null,
                    'batch_no' => $validated['batch_no'] ?? null,
                    'balance' => $validated['balance'] ?? null,
                    'invoice_date' => $validated['invoice_date'] ?? null,
                    'invoice_date_bs' => $validated['invoice_date_bs'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'reason' => $validated['reason'],
                    'store_id' => $validated['store_id'] ?? null,
                    'location_id' => $validated['location_id'] ?? null,
                    'excise_duty' => $validated['excise_duty'] ?? null,
                    'health_insurance' => $validated['health_insurance'] ?? null,
                    'freight_amount' => $validated['freight_amount'] ?? null,
                    'discount' => $validated['discount'] ?? null,
                    'taxable_amount' => $validated['taxable_amount'] ?? null,
                    'non_taxable_amount' => $validated['non_taxable_amount'] ?? null,
                    'discount_after_vat' => $validated['discount_after_vat'] ?? null,
                    'total_amount' => $validated['total_amount'] ?? null,
                    'round_of_amount' => $validated['round_of_amount'] ?? null,
                    'roundoff_type' => $validated['roundoff_type'] ?? null,
                    'payment_type' => $validated['payment_type'] ?? null,
                    'sale_id' => $validated['sale_id'],
                    'cash' => $validated['payment']['cash'] ?? 0,
                    'credit' => $validated['payment']['credit'] ?? 0,
                    'bank' => $validated['payment']['bank'] ?? 0,
                ]);

                Log::debug('Sales return created', ['sales_return_id' => $salesReturn->id]);

                if (isset($validated['sales_return_additional']) && !empty($validated['sales_return_additional'])) {
                    SaleReturnAdditional::create([
                        'company_id' => $validated['company_id'],
                        'sales_return_id' => $salesReturn->id,
                        'place' => $validated['sales_return_additional']['place'] ?? null,
                        'transport' => $validated['sales_return_additional']['transport'] ?? null,
                        'vehicle_number' => $validated['sales_return_additional']['vehicle_number'] ?? null,
                        'vehicle_name' => $validated['sales_return_additional']['vehicle_name'] ?? null,
                        'driver_name' => $validated['sales_return_additional']['driver_name'] ?? null,
                        'return_code' => $validated['sales_return_additional']['return_code'] ?? null,
                        'driver_contact_number' => $validated['sales_return_additional']['driver_contact_number'] ?? null,
                        'return_date' => $validated['sales_return_additional']['return_date'] ?? null,
                        'return_time' => $validated['sales_return_additional']['return_time'] ?? null,
                    ]);

                    Log::debug('Sales return additional created', ['sales_return_id' => $salesReturn->id]);
                }

                foreach ($validated['sales_return_products'] as $index => $product) {
                    $saleProduct = SaleProduct::where('id', $product['sale_product_id'])
                        ->where('company_id', $validated['company_id'])
                        ->whereNull('deleted_at')
                        ->first();

                    if (!$saleProduct) {
                        Log::error('Sale product not found', [
                            'sale_product_id' => $product['sale_product_id'],
                            'index' => $index
                        ]);
                        throw new \Exception("Sale product ID {$product['sale_product_id']} not found at index {$index}");
                    }

                    $salesReturnProduct = $salesReturn->salesReturnProducts()->create([
                        'company_id' => $validated['company_id'],
                        'sale_id' => $validated['sale_id'],
                        'sale_product_id' => $product['sale_product_id'],
                        'purchase_product_id' => $saleProduct->purchase_product_id,
                        'product_id' => $product['product_id'],
                        'product_code' => $product['product_code'],
                        'product_name' => $product['product_name'],
                        'quantity' => $product['quantity'],
                        'free_quantity' => $product['free_quantity'],
                        'price' => $product['price'],
                        'amount' => $product['amount'],
                        'discount_percent' => $product['discount_percent'],
                        'discount_amount' => $product['discount_amount'],
                        'is_vatable' => $product['is_vatable'],
                        'measure_unit_id' => $product['measure_unit_id'],
                        'batch_no' => $product['batch_no'],
                        'mfd' => $product['mfd'],
                        'expiry_date' => $product['expiry_date'],
                        'name' => $product['name'],
                    ]);

                    Log::debug('Sales return product created', [
                        'index' => $index,
                        'sales_return_product_id' => $salesReturnProduct->id,
                        'sale_product_id' => $product['sale_product_id'],
                        'quantity' => $product['quantity'],
                        'free_quantity' => $product['free_quantity'],
                        'measure_unit_id' => $product['measure_unit_id']
                    ]);

                    if (!empty($product['field_values'])) {
                        $fieldValues = [];
                        foreach ($product['field_values'] as $fvSet) {
                            foreach ($fvSet as $fv) {
                                $fieldValues[] = [
                                    'company_id' => $validated['company_id'],
                                    'sale_return_product_id' => $salesReturnProduct->id,
                                    'sale_product_id' => $fv['sale_product_id'],
                                    'product_id' => $product['product_id'],
                                    'product_field_id' => $fv['product_field_id'],
                                    'value' => $fv['value'],
                                    'quantity_index' => $fv['quantity_index'],
                                    'quantity_type' => $fv['quantity_type'] ?? 'regular',
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                            }
                        }

                        if (!empty($fieldValues)) {
                            SaleReturnProductFieldValue::insert($fieldValues);
                            Log::debug('Field values inserted for sales return product', [
                                'index' => $index,
                                'sales_return_product_id' => $salesReturnProduct->id,
                                'field_values' => $fieldValues,
                            ]);
                        }
                    }
                }

                return $salesReturn;
            });

            Log::info('Sales return transaction completed', ['sales_return_id' => $salesReturn->id]);

            return response()->json([
                'message' => 'Sales return created successfully',
                'data' => $salesReturn->load([
                    'salesReturnProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index', 'asc')->orderBy('product_field_id', 'asc');
                    },
                    'salesReturnAdditional'
                ])
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error creating sales return', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => $e->getMessage()], 500);
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
                'payment.bank_name' => 'nullable|string',
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

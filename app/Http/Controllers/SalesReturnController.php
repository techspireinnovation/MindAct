<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseProductFieldValue;
use App\Models\SalesReturn;
use App\Models\Sale;
use App\Models\MeasureUnit;
use App\Models\SalesReturnProduct;
use App\Models\SaleReturnAdditional;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SaleProduct;
use App\Models\Purchase;
use App\Models\Product;
use DB;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesReturnController extends Controller
{
 
    public function index(Request $request): JsonResponse
    {
        $query = SalesReturn::query();

        if ($request->has('keywords')) {
            $query->where('invoice_number', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
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
                $sequence = (int)$matches[1];
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

            // Fetch primary measure unit
            $primaryUnit = MeasureUnit::where('company_id', $companyId)
                ->where('is_primary', 1)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->select(['id', 'name', 'quantity'])
                ->first();

            if (!$primaryUnit) {
                Log::warning('No primary measure unit found for company', ['company_id' => $companyId]);
                return response()->json(['error' => 'No primary measure unit defined for this company'], 422);
            }

            // Fetch sale with products
            $sale = Sale::where('company_id', $companyId)
                ->where('invoice_number', $invoiceNumber)
                // ->where('status', 'sold')
                ->with([
                    'saleProducts' => function ($query) use ($companyId) {
                        $query->select([
                            'sale_products.id',
                            'sale_products.sale_id',
                            'sale_products.product_id',
                            'sale_products.measure_unit_id',
                            'sale_products.quantity',
                            'sale_products.free_quantity',
                            'sale_products.purchase_product_id',
                            'sale_products.price',
                            'sale_products.is_vatable',
                            'sale_products.expiry_date',
                            'products.name as product_name',
                            'products.product_unique_id as product_code',
                        ])
                            ->join('products', 'sale_products.product_id', '=', 'products.id')
                            ->whereRaw('(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)) - COALESCE((
                                SELECT SUM(srp.quantity + COALESCE(srp.free_quantity, 0))
                                FROM sales_return_products srp
                                WHERE srp.sale_product_id = sale_products.id
                                AND srp.company_id = ?
                                AND srp.deleted_at IS NULL
                            ), 0) > 0', [$companyId]);
                    },
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
                ]);
                return response()->json(['error' => 'No available products for this sale'], 404);
            }

            // Fetch measure units
            $measureUnitIds = $sale->saleProducts->pluck('measure_unit_id')->unique()->toArray();
            $measureUnits = MeasureUnit::whereIn('id', $measureUnitIds)
                ->where('company_id', $companyId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->select(['id', 'name', 'quantity'])
                ->get()
                ->keyBy('id');

            // Aggregate product data
            $products = [];
            $productIds = $sale->saleProducts->pluck('product_id')->unique()->toArray();
            $purchaseProductIds = $sale->saleProducts->pluck('purchase_product_id')->unique()->toArray();

            foreach ($sale->saleProducts as $saleProduct) {
                $productId = $saleProduct->product_id;
                $measureUnit = $measureUnits[$saleProduct->measure_unit_id] ?? ['quantity' => 1];

                if ($measureUnit === ['quantity' => 1]) {
                    Log::warning('Measure unit not found for sale product', [
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
                        'is_vatable' => $saleProduct->is_vatable,
                        'measure_unit_id' => $primaryUnit->id,
                        'measure_unit_name' => $primaryUnit->name,
                        'measure_unit_quantity' => $primaryUnit->quantity,
                        'purchased_quantity' => 0,
                        'return_quantity' => 0,
                        'sale_quantity' => 0,
                        'sales_return_quantity' => 0,
                        'available_quantity' => 0,
                        'expiry_dates' => [],
                        'field_values' => [],
                        'purchase_products' => [],
                    ];
                }

                // Calculate quantities for sale product
                $measureUnitQuantity = is_object($measureUnit) ? $measureUnit->quantity : $measureUnit['quantity'];
                $saleTotal = ($saleProduct->quantity + ($saleProduct->free_quantity ?? 0)) * $measureUnitQuantity;

                $salesReturnTotal = SalesReturnProduct::where('sale_product_id', $saleProduct->id)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->sum(DB::raw('(quantity + COALESCE(free_quantity, 0)) * COALESCE((SELECT quantity FROM measure_units WHERE id = sales_return_products.measure_unit_id), 1)'));

                $products[$productId]['sale_quantity'] += $saleTotal;
                $products[$productId]['sales_return_quantity'] += $salesReturnTotal;
                $products[$productId]['available_quantity'] += ($saleTotal - $salesReturnTotal);

                if ($saleProduct->expiry_date && !in_array($saleProduct->expiry_date, $products[$productId]['expiry_dates'])) {
                    $products[$productId]['expiry_dates'][] = $saleProduct->expiry_date;
                }
            }

            // Fetch purchase quantities and field values
            $purchaseProducts = PurchaseProduct::select([
                'purchase_products.id as purchase_product_id',
                'purchase_products.purchase_id',
                'purchase_products.product_id',
                'purchase_products.quantity',
                'purchase_products.free_quantity',
                'purchase_products.price as price',
                'purchase_products.is_vatable',
                'purchase_products.measure_unit_id as measure_unit_id',
                'purchase_products.expiry_date',
                'purchases.purchase_bill_number',
                'purchases.invoice_date as invoice_date',
                'products.name as product_name',
                'products.product_unique_id as product_code',
                'measure_units.name as measure_unit_name',
                'measure_units.quantity as measure_unit_quantity',
            ])
                ->join('purchases', 'purchase_products.purchase_id', '=', 'purchases.id')
                ->join('products', 'purchase_products.product_id', '=', 'products.id')
                ->join('measure_units', 'purchase_products.measure_unit_id', '=', 'measure_units.id')
                ->whereIn('purchase_products.id', $purchaseProductIds)
                ->where('purchase_products.company_id', $companyId)
                ->whereNull('purchase_products.deleted_at')
                ->whereNull('purchases.deleted_at')
                ->whereNull('measure_units.deleted_at')
                ->get();

            $fieldValues = PurchaseProductFieldValue::select([
                'purchase_product_field_values.purchase_product_id',
                'purchase_product_field_values.product_field_id',
                'purchase_product_field_values.quantity_index',
                'purchase_product_field_values.value',
                'product_fields.name',
                'purchases.id as purchase_id',
                'purchases.purchase_bill_number',
            ])
                ->join('purchase_products', 'purchase_product_field_values.purchase_product_id', '=', 'purchase_products.id')
                ->join('purchases', 'purchase_products.purchase_id', '=', 'purchases.id')
                ->join('product_fields', 'purchase_product_field_values.product_field_id', '=', 'product_fields.id')
                ->whereIn('purchase_product_field_values.purchase_product_id', $purchaseProductIds)
                ->where('purchase_product_field_values.company_id', $companyId)
                ->whereNull('purchase_product_field_values.deleted_at')
                ->whereNull('purchase_products.deleted_at')
                ->whereNull('purchases.deleted_at')
                ->get();

            foreach ($productIds as $productId) {
                if (!isset($products[$productId])) {
                    continue;
                }

                // Calculate purchased and return quantities
                $purchasedTotal = PurchaseProduct::where('product_id', $productId)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->sum(DB::raw('(quantity + COALESCE(free_quantity, 0)) * COALESCE((SELECT quantity FROM measure_units WHERE id = purchase_products.measure_unit_id), 1)'));

                $purchaseReturnTotal = PurchaseProductReturn::where('product_id', $productId)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->sum(DB::raw('(quantity + COALESCE(free_quantity, 0)) * COALESCE((SELECT quantity FROM measure_units WHERE id = purchase_product_returns.measure_unit_id), 1)'));

                $products[$productId]['purchased_quantity'] = $purchasedTotal;
                $products[$productId]['return_quantity'] = $purchaseReturnTotal;
                $products[$productId]['available_quantity'] = $purchasedTotal - $purchaseReturnTotal - $products[$productId]['sale_quantity'] + $products[$productId]['sales_return_quantity'];

                // Add field values
                $productFieldValues = $fieldValues->whereIn('purchase_product_id', $purchaseProductIds)->toArray();
                $products[$productId]['field_values'] = array_map(function ($fv) {
                    return [
                        'purchase_id' => $fv['purchase_id'],
                        'purchase_bill_number' => $fv['purchase_bill_number'],
                        'purchase_product_id' => $fv['purchase_product_id'],
                        'product_field_id' => $fv['product_field_id'],
                        'name' => $fv['name'],
                        'value' => $fv['value'],
                        'quantity_index' => $fv['quantity_index'],
                    ];
                }, $productFieldValues);

                // Add purchase products
                $productPurchaseProducts = $purchaseProducts->where('product_id', $productId);
                foreach ($productPurchaseProducts as $pp) {
                    $ppMeasureUnit = $measureUnits[$pp->measure_unit_id] ?? ['quantity' => 1];
                    $ppMeasureUnitQuantity = is_object($ppMeasureUnit) ? $ppMeasureUnit->quantity : $ppMeasureUnit['quantity'];
                    $ppTotal = ($pp->quantity + ($pp->free_quantity ?? 0)) * $ppMeasureUnitQuantity;

                    $ppReturnTotal = PurchaseProductReturn::where('purchase_product_id', $pp->purchase_product_id)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->sum(DB::raw('(quantity + COALESCE(free_quantity, 0)) * COALESCE((SELECT quantity FROM measure_units WHERE id = purchase_product_returns.measure_unit_id), 1)'));

                    $ppSaleTotal = SaleProduct::where('purchase_product_id', $pp->purchase_product_id)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->sum(DB::raw('(quantity + COALESCE(free_quantity, 0)) * COALESCE((SELECT quantity FROM measure_units WHERE id = sale_products.measure_unit_id), 1)'));

                    $ppSalesReturnTotal = SalesReturnProduct::where('purchase_product_id', $pp->purchase_product_id)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->sum(DB::raw('(quantity + COALESCE(free_quantity, 0)) * COALESCE((SELECT quantity FROM measure_units WHERE id = sales_return_products.measure_unit_id), 1)'));

                    $products[$productId]['purchase_products'][] = [
                        'purchase_product_id' => $pp->purchase_product_id,
                        'purchase_id' => $pp->purchase_id,
                        'purchase_bill_number' => $pp->purchase_bill_number,
                        'invoice_date' => $pp->invoice_date,
                        'product_id' => $pp->product_id,
                        'product_name' => $pp->product_name,
                        'product_code' => $pp->product_code,
                        'quantity' => $pp->quantity,
                        'free_quantity' => $pp->free_quantity ?? 0,
                        'price' => $pp->price,
                        'is_vatable' => $pp->is_vatable,
                        'measure_unit_id' => $pp->measure_unit_id,
                        'measure_unit_name' => $pp->measure_unit_name,
                        'measure_unit_quantity' => $ppMeasureUnitQuantity,
                        'available_quantity' => $ppTotal - $ppReturnTotal - $ppSaleTotal + $ppSalesReturnTotal,
                        'return_quantity' => $ppReturnTotal,
                        'sale_quantity' => $ppSaleTotal,
                        'sales_return_quantity' => $ppSalesReturnTotal,
                        'expiry_date' => $pp->expiry_date,
                    ];
                }
            }

            // Filter out products with no available quantity
            $products = array_filter($products, function ($product) {
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

            return response()->json([
                'message' => 'Product details retrieved successfully',
                'data' => array_values($products),
            ]);
        } catch (QueryException $e) {
            Log::error('Database error in getSaleByInvoiceNumber', [
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);
            dd($e->getMessage());
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in getSaleByInvoiceNumber', [
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

            // Get invoice numbers where at least one product has remaining quantity
            $invoiceNumbers = Sale::where('company_id', $companyId)
                ->whereNotNull('invoice_number')
                ->where('invoice_number', '!=', '')
                ->whereHas('saleProducts', function ($query) {
                    $query->whereRaw('(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)) - COALESCE((
                        SELECT SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0))
                        FROM sales_return_products
                        WHERE sales_return_products.sale_product_id = sale_products.id
                        AND sales_return_products.deleted_at IS NULL
                    ), 0) > 0');
                })
                ->distinct()
                ->pluck('invoice_number')
                ->toArray();

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
    /**
     * Generate a unique batch number based on fiscal year.
     */

    /**
     * Store a new sales return.
     */
    public function store(Request $request): JsonResponse
{
    try {
        // Define validation rules
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'customer_id' => 'nullable|exists:customers,id',
            'salesman_id' => 'nullable|exists:salesmen,id',
            'sale_id' => 'nullable|exists:sales,id',
            'sale_invoice_number' => 'required|string|max:255',
            'invoice_number' => 'nullable|string|max:255|unique:sales_returns,invoice_number',
            'document_number' => 'nullable|string|max:255',
            'batch_no' => 'nullable|string|max:255|unique:sales_returns,batch_no',
            'balance' => 'nullable|numeric|min:0',
            'invoice_date' => 'nullable|date',
            'invoice_date_bs' => 'nullable|date',
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
            'sales_return_products.*.quantity' => 'required|numeric|min:0.0001',
            'sales_return_products.*.free_quantity' => 'nullable|numeric|min:0',
            'sales_return_products.*.price' => 'required|numeric|min:0',
            'sales_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
            'sales_return_products.*.discount_amount' => 'nullable|numeric|min:0',
            'sales_return_products.*.is_vatable' => 'nullable|boolean',
            'sales_return_products.*.measure_unit_id' => 'required|exists:measure_units,id',
            'sales_return_products.*.batch_no' => 'required|string|max:255',
            'sales_return_products.*.mfd' => 'nullable|string|max:255',
            'sales_return_products.*.expiry_date' => 'nullable|date',
            'sales_return_products.*.field_values' => 'nullable|array',
            'sales_return_products.*.field_values.*' => 'array|min:1',
            'sales_return_products.*.field_values.*.product_field_id' => 'nullable|integer|exists:product_fields,id',
            'sales_return_products.*.field_values.*.value' => 'nullable|string|max:255',
            'return_additionals_sale' => 'nullable|array',
            'return_additionals_sale.place' => 'nullable|string|max:255',
            'return_additionals_sale.transport' => 'nullable|string|max:255',
            'return_additionals_sale.vehicle_number' => 'nullable|string|max:255',
            'return_additionals_sale.vehicle_name' => 'nullable|string|max:255',
            'return_additionals_sale.driver_name' => 'nullable|string|max:255',
            'return_additionals_sale.return_code' => 'required_with:return_additionals_sale|string|max:255',
            'return_additionals_sale.driver_contact_number' => 'nullable|string|max:255',
            'return_additionals_sale.return_date' => 'nullable|date',
            'return_additionals_sale.return_time' => 'nullable|date_format:H:i:s',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();
        $validated['return_entire_all'] = $validated['return_entire_all'] ?? $validated['return_entire_sale'] ?? false;
        Log::debug('Initial validated sales_return_products', ['sales_return_products' => $validated['sales_return_products'] ?? []]);

        // Fetch sale
        $sale = Sale::with(['saleProducts.fieldValues', 'saleAdditionals'])
            ->where('invoice_number', $validated['sale_invoice_number'])
            ->where('company_id', $validated['company_id'])
            ->first();
        if (!$sale) {
            return response()->json(['error' => 'Sale not found for the provided sale_invoice_number'], 422);
        }
        $validated['sale_id'] = $sale->id;

        // Validate sale_id
        if (isset($request['sale_id']) && $validated['sale_id'] !== $request['sale_id']) {
            return response()->json(['error' => 'Provided sale_id does not match sale_invoice_number'], 422);
        }

        // Helper to get available quantity in the sale unit
        $getAvailableQuantity = function ($saleProduct, $companyId) {
            $totalSold = $saleProduct->quantity + ($saleProduct->free_quantity ?? 0);
            $returned = SalesReturnProduct::where('sale_product_id', $saleProduct->id)
                ->where('sales_return_products.company_id', $companyId)
                ->whereNull('sales_return_products.deleted_at')
                ->sum(DB::raw('quantity + COALESCE(free_quantity, 0)'));
            $available = max(0, $totalSold - $returned);

            Log::debug('Available quantity calculation', [
                'sale_product_id' => $saleProduct->id,
                'measure_unit_id' => $saleProduct->measure_unit_id,
                'total_sold' => $totalSold,
                'returned' => $returned,
                'available' => $available,
            ]);

            return $available;
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
                ->orderBy('created_at')
                ->get();
            if ($saleProducts->isEmpty()) {
                return response()->json(['error' => 'No products found in the specified sale'], 422);
            }

            $validated['sales_return_products'] = $saleProducts->map(function ($product) use ($validated, $getAvailableQuantity) {
                $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                    return $group->map(function ($field) {
                        return [
                            'product_field_id' => $field->product_field_id,
                            'value' => $field->value,
                            'quantity_index' => $field->quantity_index,
                        ];
                    })->toArray();
                })->values()->toArray();

                $availableQuantity = $getAvailableQuantity($product, $validated['company_id']);
                if ($availableQuantity <= 0) {
                    return null;
                }

                // Use entire available quantity
                $quantity = floor($availableQuantity);
                $freeQuantity = $availableQuantity - $quantity;

                Log::info('Return entire sale calculation', [
                    'sale_product_id' => $product->id,
                    'product_id' => $product->product_id,
                    'measure_unit_id' => $product->measure_unit_id,
                    'available_quantity' => $availableQuantity,
                    'quantity' => $quantity,
                    'free_quantity' => $freeQuantity,
                ]);

                return [
                    'sale_product_id' => $product->id,
                    'purchase_product_id' => $product->purchase_product_id,
                    'product_id' => $product->product_id,
                    'quantity' => $quantity,
                    'free_quantity' => $freeQuantity,
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
            })->filter()->values()->toArray();

            if (empty($validated['sales_return_products'])) {
                return response()->json(['error' => 'No remaining products available to return'], 404);
            }
        } elseif ($useSaleProductIds) {
            $saleProducts = SaleProduct::whereIn('id', $validated['sale_product_ids'])
                ->with('fieldValues')
                ->where('sale_id', $validated['sale_id'])
                ->orderBy('created_at')
                ->get();

            if ($saleProducts->isEmpty()) {
                return response()->json(['error' => 'No valid sale products found for provided IDs'], 422);
            }

            $validated['sales_return_products'] = $saleProducts->map(function ($product) use ($validated, $getAvailableQuantity) {
                $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                    return $group->map(function ($field) {
                        return [
                            'product_field_id' => $field->product_field_id,
                            'value' => $field->value,
                            'quantity_index' => $field->quantity_index,
                        ];
                    })->toArray();
                })->values()->toArray();

                $availableQuantity = $getAvailableQuantity($product, $validated['company_id']);
                if ($availableQuantity <= 0) {
                    return null;
                }

                // Use entire available quantity
                $quantity = floor($availableQuantity);
                $freeQuantity = $availableQuantity - $quantity;

                Log::info('Return specific products calculation', [
                    'sale_product_id' => $product->id,
                    'product_id' => $product->product_id,
                    'measure_unit_id' => $product->measure_unit_id,
                    'available_quantity' => $availableQuantity,
                    'quantity' => $quantity,
                    'free_quantity' => $freeQuantity,
                ]);

                return [
                    'sale_product_id' => $product->id,
                    'purchase_product_id' => $product->purchase_product_id,
                    'product_id' => $product->product_id,
                    'quantity' => $quantity,
                    'free_quantity' => $freeQuantity,
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
            })->filter()->values()->toArray();

            if (empty($validated['sales_return_products'])) {
                return response()->json(['error' => 'No remaining products available to return for the specified product IDs'], 404);
            }
        } else {
            $saleProducts = $sale->saleProducts()->with('fieldValues')
                ->orderBy('created_at')
                ->get();
            if ($saleProducts->isEmpty()) {
                return response()->json(['error' => 'No products found in the specified sale'], 422);
            }

            $salesReturnProducts = [];
            foreach ($validated['sales_return_products'] as $index => $product) {
                $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);
                $productId = $product['product_id'];
                $measureUnitId = $product['measure_unit_id'];

                Log::debug('Processing manual quantity for product', [
                    'index' => $index,
                    'product_id' => $productId,
                    'quantity' => $product['quantity'],
                    'free_quantity' => $product['free_quantity'] ?? 0,
                    'measure_unit_id' => $measureUnitId,
                ]);

                $totalAvailable = 0;
                $availableSaleProducts = [];
                foreach ($saleProducts as $saleProduct) {
                    if ($saleProduct->product_id === $productId && $saleProduct->measure_unit_id === $measureUnitId) {
                        $available = $getAvailableQuantity($saleProduct, $validated['company_id']);
                        $totalAvailable += $available;
                        $availableSaleProducts[] = ['sale_product' => $saleProduct, 'available' => $available];
                    }
                }

                Log::debug('Total available quantity', [
                    'product_id' => $productId,
                    'total_available' => $totalAvailable,
                ]);

                if ($requestedQuantity > $totalAvailable) {
                    return response()->json([
                        'error' => "Insufficient available quantity for product ID {$productId} at index {$index}. Requested: {$requestedQuantity}, Available: {$totalAvailable}",
                    ], 422);
                }

                $remainingRequested = $requestedQuantity;
                foreach ($availableSaleProducts as $avail) {
                    $saleProduct = $avail['sale_product'];
                    $available = $avail['available'];
                    if ($remainingRequested <= 0 || $available <= 0) {
                        continue;
                    }

                    $returnQuantity = min($available, $remainingRequested);
                    $quantity = floor($returnQuantity);
                    $freeQuantity = $returnQuantity - $quantity;

                    if ($quantity < 0.0001) {
                        continue;
                    }

                    $fieldValues = $saleProduct->fieldValues->groupBy('quantity_index')->map(function ($group) {
                        return $group->map(function ($field) {
                            return [
                                'product_field_id' => $field->product_field_id,
                                'value' => $field->value,
                                'quantity_index' => $field->quantity_index,
                            ];
                        })->toArray();
                    })->values()->toArray();

                    $salesReturnProducts[] = [
                        'sale_product_id' => $saleProduct->id,
                        'purchase_product_id' => $saleProduct->purchase_product_id,
                        'product_id' => $saleProduct->product_id,
                        'quantity' => $quantity,
                        'free_quantity' => $freeQuantity,
                        'price' => $product['price'],
                        'discount_percent' => $product['discount_percent'] ?? 0,
                        'discount_amount' => $product['discount_amount'] ?? 0,
                        'is_vatable' => $product['is_vatable'] ?? $saleProduct->is_vatable,
                        'measure_unit_id' => $saleProduct->measure_unit_id,
                        'batch_no' => $product['batch_no'],
                        'mfd' => $product['mfd'] ?? $saleProduct->mfd,
                        'expiry_date' => $product['expiry_date'] ?? $saleProduct->expiry_date,
                        'field_values' => isset($product['field_values']) ? $product['field_values'] : $fieldValues,
                    ];

                    $remainingRequested -= $returnQuantity;

                    Log::info('Manual quantity allocation', [
                        'sale_product_id' => $saleProduct->id,
                        'product_id' => $saleProduct->product_id,
                        'measure_unit_id' => $saleProduct->measure_unit_id,
                        'available' => $available,
                        'return_quantity' => $returnQuantity,
                        'remaining_requested' => $remainingRequested,
                    ]);
                }

                if ($remainingRequested > 0) {
                    return response()->json([
                        'error' => "Insufficient available quantity for product ID {$productId} at index {$index}. Requested: {$requestedQuantity}, Allocated: " . ($requestedQuantity - $remainingRequested),
                    ], 422);
                }
            }

            $validated['sales_return_products'] = $salesReturnProducts;

            if (empty($validated['sales_return_products'])) {
                return response()->json(['error' => 'No valid products selected for return'], 422);
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
                ->first();
            if (!$saleProduct) {
                return response()->json([
                    'error' => "Sale product ID {$saleProductId} at index {$index} does not belong to sale ID {$validated['sale_id']}",
                ], 422);
            }

            // Validate measure unit
            if ($saleProduct->measure_unit_id !== $measureUnitId) {
                return response()->json([
                    'error' => "Measure unit ID {$measureUnitId} does not match sale product ID {$saleProductId} (expected {$saleProduct->measure_unit_id}) at index {$index}",
                ], 422);
            }

            // Validate purchase_product_id
            if ($purchaseProductId && $saleProduct->purchase_product_id !== $purchaseProductId) {
                return response()->json([
                    'error' => "Purchase product ID {$purchaseProductId} at index {$index} does not match sale product ID {$saleProductId} (expected {$saleProduct->purchase_product_id})",
                ], 422);
            }

            $purchaseProduct = PurchaseProduct::where('id', $purchaseProductId)
                ->where('company_id', $validated['company_id'])
                ->where('product_id', $productId)
                ->first();
            if (!$purchaseProduct) {
                return response()->json([
                    'error' => "Purchase product ID {$purchaseProductId} at index {$index} is invalid for product ID {$productId}",
                ], 422);
            }

            // Check available quantity
            $availableQuantity = $getAvailableQuantity($saleProduct, $validated['company_id']);
            if ($requestedQuantity > $availableQuantity) {
                return response()->json([
                    'error' => "Cannot return more than sold for sale product ID {$saleProductId} at index {$index}. Available: {$availableQuantity}, Requested: {$requestedQuantity}",
                ], 422);
            }

            // Validate field_values
            $hasFieldValues = PurchaseProductFieldValue::where('purchase_product_id', $purchaseProductId)->exists();
            if ($hasFieldValues && !($returnEntireAll || $useSaleProductIds) && isset($product['field_values']) &&
                count($product['field_values']) !== (int)$product['quantity']) {
                return response()->json([
                    'error' => "Field values count (" . count($product['field_values']) . ") must match quantity ({$product['quantity']}) for product ID {$productId} at index {$index}",
                ], 422);
            }
            Log::debug('Field values validation', [
                'sale_product_id' => $saleProductId,
                'has_field_values' => $hasFieldValues,
                'field_values_provided' => isset($product['field_values']),
                'field_values_count' => isset($product['field_values']) ? count($product['field_values']) : 0,
                'quantity' => $product['quantity'],
            ]);

            if (isset($product['field_values'])) {
                foreach ($product['field_values'] as $setIndex => $fieldValueSet) {
                    $fieldIds = array_column($fieldValueSet, 'product_field_id');
                    if (count($fieldIds) !== count(array_unique($fieldIds))) {
                        return response()->json([
                            'error' => "Duplicate product_field_id found in field_values set {$setIndex} for product ID {$productId} at index {$index}",
                        ], 422);
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
                'return_code' => 'RETURN' . now()->format('YmdHis'),
                'driver_contact_number' => $saleAdditional->driver_contact_number ?? null,
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

        $salesReturn = DB::transaction(function () use ($salesReturnData, $validated, $salesReturnAdditionalsData, $getAvailableQuantity) {
            SaleProduct::where('sale_id', $validated['sale_id'])
                ->lockForUpdate()
                ->get();

            foreach ($validated['sales_return_products'] as $index => $product) {
                $saleProduct = SaleProduct::find($product['sale_product_id']);
                if (!$saleProduct) {
                    throw new \Exception("Sale product ID {$product['sale_product_id']} not found at index {$index}");
                }
                $availableQuantity = $getAvailableQuantity($saleProduct, $validated['company_id']);
                $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);

                if ($requestedQuantity > $availableQuantity) {
                    throw new \Exception("Concurrent update detected. Insufficient available quantity for sale product ID {$product['sale_product_id']} at index {$index}. Available: {$availableQuantity}, Requested: {$requestedQuantity}");
                }
            }

            $salesReturn = SalesReturn::create($salesReturnData);

            foreach ($validated['sales_return_products'] as $index => $product) {
                $product['company_id'] = $validated['company_id'];
                $product['sales_return_id'] = $salesReturn->id;
                $productModel = Product::find($product['product_id']);
                $product['product_code'] = $productModel->code ?? $productModel->product_code ?? null;
                $product['product_name'] = $productModel->name ?? 'Unknown';

                $availableFieldValues = PurchaseProductFieldValue::withoutGlobalScopes()
                    ->select([
                        'purchase_product_field_values.quantity_index',
                        'purchase_product_field_values.product_field_id',
                        'purchase_product_field_values.value',
                    ])
                    ->where('purchase_product_field_values.purchase_product_id', $product['purchase_product_id'])
                    ->where('purchase_product_field_values.company_id', $validated['company_id'])
                    ->whereNull('purchase_product_field_values.deleted_at')
                    ->get();

                Log::debug('Available field values for sales return', [
                    'purchase_product_id' => $product['purchase_product_id'],
                    'sale_product_id' => $product['sale_product_id'],
                    'field_values' => $availableFieldValues->toArray(),
                ]);

                if (isset($product['product_code']) && strlen($product['product_code']) > 255) {
                    $product['product_code'] = substr($product['product_code'], 0, 255);
                    Log::warning('Truncated product_code for sales return product', [
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
                        $matched = false;
                        foreach ($availableFieldValues->groupBy('quantity_index') as $quantityIndex => $availableGroup) {
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
                            if ($matchesAllFields && !in_array($quantityIndex, $selectedQuantityIndices)) {
                                foreach ($fieldValueSet as $fieldValue) {
                                    $fieldValues[] = [
                                        'company_id' => $validated['company_id'],
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'product_id' => $salesReturnProduct->product_id,
                                        'sale_return_product_id' => $salesReturnProduct->id,
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
                            Log::warning('Field values mismatch for sales return', [
                                'purchase_product_id' => $product['purchase_product_id'],
                                'sale_product_id' => $product['sale_product_id'],
                                'field_value_set' => $fieldValueSet,
                                'available_field_values' => $availableFieldValues->toArray(),
                            ]);
                            throw new \Exception("Provided field values do not match available stock for sale product ID {$product['sale_product_id']} at index {$index}. Expected values: " . json_encode($availableFieldValues->toArray()));
                        }
                    }

                    if (count($selectedQuantityIndices) > (int)$product['quantity']) {
                        throw new \Exception("Number of selected field value sets (" . count($selectedQuantityIndices) . ") exceeds requested quantity ({$product['quantity']}) for sale product ID {$product['sale_product_id']} at index {$index}");
                    }

                    if (!empty($fieldValues)) {
                        SalesReturnProductFieldValue::insert($fieldValues);
                        Log::debug('SalesReturnProductFieldValue created', [
                            'sale_return_product_id' => $salesReturnProduct->id,
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
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
    } catch (\Exception $e) {
        Log::error('Unexpected error during sales return creation', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        return response()->json(['error' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
    }
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
                'invoice_date_bs' => 'nullable|date',
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
                'sales_return_products.*.expiry_date' => 'nullable|date',
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
                if ($hasFieldValues && !($returnEntireBatch || $returnEntireSale || $useSaleProductIds) &&
                    (!isset($product['field_values']) || count($product['field_values']) !== $product['quantity'])) {
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

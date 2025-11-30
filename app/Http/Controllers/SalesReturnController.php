<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\MeasureUnit;
use App\Models\Product;
use App\Models\ProductList;
use App\Models\PurchaseProduct;
use App\Models\PurchaseStockProduct;
use App\Services\AvailableQuantityService;

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
                'company_id' => 'required',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'error' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            $invoiceNumber = $request->invoice_number;
            $companyId = $request->company_id;
            $branchId = $request->branch_id;

            // Fetch measure units
            $measureUnits = MeasureUnit::where('company_id', $companyId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->select(['id', 'name', 'quantity'])
                ->get()
                ->keyBy('id');

            // Fetch sale with products and field values
            $sale = Sale::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('invoice_number', $invoiceNumber)
                ->whereNull('deleted_at')
                ->with([
                    'saleProducts' => function ($query) use ($companyId, $branchId) {
                        $query->select([
                            'sale_products.id',
                            'sale_products.sale_id',
                            'sale_products.product_id',
                            'sale_products.measure_unit_id',
                            'sale_products.quantity',
                            'sale_products.amount',
                            'sale_products.free_quantity',
                            'sale_products.purchase_stock_product_id',
                            'sale_products.purchase_product_id',
                            'sale_products.price',
                            'sale_products.is_vatable',
                            'sale_products.expiry_date',
                            'products.name as product_name',
                            'products.product_unique_id as product_code',
                        ])
                            ->join('products', 'sale_products.product_id', '=', 'products.id')
                            ->where('sale_products.company_id', $companyId)
                            ->where('sale_products.branch_id', $branchId)
                            ->whereNull('sale_products.deleted_at')
                            ->whereNull('products.deleted_at');
                    },
                    'saleProducts.fieldValues' => function ($query) use ($companyId, $branchId) {
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
                            ->where('sales_product_field_values.branch_id', $branchId)
                            ->whereNull('sales_product_field_values.deleted_at')
                            ->whereNull('product_fields.deleted_at');
                    },
                ])
                ->select([
                    'id',
                    'company_id',
                    'branch_id',
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
               
                return response()->json(['error' => 'Sale not found'], 404);
            }

            // Fetch sales return products
            $salesReturnProducts = SalesReturnProduct::whereIn('sale_product_id', $sale->saleProducts->pluck('id'))
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->with([
                    'fieldValues' => function ($query) use ($companyId, $branchId) {
                        $query->select([
                            'sale_return_product_field_values.sale_return_product_id',
                            'sale_return_product_field_values.quantity_index',
                            'sale_return_product_field_values.product_field_id',
                            'sale_return_product_field_values.value',
                            'product_fields.name',
                        ])
                            ->join('product_fields', 'sale_return_product_field_values.product_field_id', '=', 'product_fields.id')
                            ->where('sale_return_product_field_values.company_id', $companyId)
                            ->where('sale_return_product_field_values.branch_id', $branchId)
                            ->whereNull('sale_return_product_field_values.deleted_at')
                            ->whereNull('product_fields.deleted_at');
                    },
                ])
                ->get();


            $returnedFieldValues = [];
            foreach ($salesReturnProducts as $returnProduct) {
                $saleProductId = $returnProduct->sale_product_id;
                foreach ($returnProduct->fieldValues as $fv) {
                    $key = $saleProductId . '-' . $fv->quantity_index . '-' . $fv->product_field_id;
                    $returnedFieldValues[$key] = true;
                    
                }
            }

            // Aggregate product data
            $products = [];
            $productIds = $sale->saleProducts->pluck('product_id')->unique()->toArray();

            foreach ($sale->saleProducts as $saleProduct) {
                $productId = $saleProduct->product_id;
                $product = Product::with('measureUnit:id,quantity')
                    ->select('id', 'measure_unit_id', 'retail_sales_price')
                    ->find($productId);



                $unitQty = $product->measureUnit->quantity ?? 1;
                $originalPricePerPiece = $product->retail_sales_price / $unitQty;

                // 2. Get all sale prices for this product (company + branch), ordered by created_at
                $salePrices = SaleProduct::where('product_id', $productId)
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->orderBy('created_at')
                    ->pluck('price');

                // 3. Calculate prices per piece
                $latestPrice = $salePrices->last() ?? 0; // latest by created_at
                $latestPricePerPiece = $latestPrice / $unitQty;
                $avgPricePerPiece = $salePrices->avg() / $unitQty;
                $minPricePerPiece = $salePrices->min() / $unitQty;


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

                $quantityInPieces = $this->calculatePieces($regularQuantity, $measureUnitQuantity);

                $freeQuantity = $saleProduct->free_quantity ?? 0;

                $freeQuantityInPieces = $this->calculatePieces($freeQuantity, $measureUnitQuantity);
                $saleTotal = $this->sumQuantityAndFree($quantityInPieces, $freeQuantityInPieces);

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

                        $returnQuantityInPieces = $this->calculatePieces($returnQuantity, $returnMeasureUnitQuantity);

                        $returnFreeQuantity = $returnProduct->free_quantity ?? 0;

                        $returnFreeQuantityInPieces = $this->calculatePieces($returnFreeQuantity, $returnMeasureUnitQuantity);

                        $returnTotal += $this->sumQuantityAndFree($returnQuantityInPieces, $returnFreeQuantityInPieces);
                    }
                   
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
                        'original_price' => $originalPricePerPiece ?? 0,
                        'min_price' => $minPricePerPiece ?? 0,
                        'avg_price' => $avgPricePerPiece ?? 0,
                        'latest_price' => $latestPricePerPiece ?? 0,
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
                    $sortedFieldValues = $saleProduct->fieldValues->sortBy(function ($fv) {
                        return [$fv->purchase_stock_product_id, $fv->quantity_index];
                    });

                    foreach ($sortedFieldValues as $fv) {
                        $key = $saleProduct->id . '-' . $fv->quantity_index . '-' . $fv->product_field_id;

                        if (!isset($returnedFieldValues[$key])) {
                            $products[$productId]['field_values'][] = [
                                'sale_product_id' => $saleProduct->id,
                                'purchase_stock_product_id' => $saleProduct->purchase_stock_product_id,
                                'purchase_product_id' => $saleProduct->purchase_product_id,
                                'stock_product_id' => $saleProduct->stock_product_id,
                                'stock_adjustment_id' => $saleProduct->stock_adjustment_id,
                                'stock_reconciliation_id' => $saleProduct->stock_reconciliation_id,
                                'stock_transfer_id' => $saleProduct->stock_transfer_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->name,
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index,
                                'quantity_type' => $fv->quantity_type,
                            ];
                        } else {
                           
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
                        'purchase_stock_product_id' => $saleProduct->purchase_stock_product_id,
                        'purchase_product_id' => $saleProduct->purchase_product_id,
                        'stock_product_id' => $saleProduct->stock_product_id,
                        'stock_transfer_id' => $saleProduct->stock_transfer_id,
                        'stock_reconciliation_id' => $saleProduct->stock_reconciliation_id,
                        'stock_adjustment_id' => $saleProduct->stock_adjustment_id,

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

                $purchasedTotal = PurchaseStockProduct::where('product_id', $productId)
                    ->where('purchase_stock_products.company_id', $companyId)
                    ->where('purchase_stock_products.branch_id', $branchId)
                    ->whereNull('purchase_stock_products.deleted_at')
                    ->join('measure_units', 'purchase_stock_products.measure_unit_id', '=', 'measure_units.id')
                    ->where('measure_units.company_id', $companyId)
                    ->whereNull('measure_units.deleted_at')
                    ->sum(DB::raw('(purchase_stock_products.quantity + COALESCE(purchase_stock_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)'));

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
                'branch_id' => $sale->branch_id,
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

            
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {

          
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    public function listAvailableInvoiceNumbers(Request $request): JsonResponse
    {
        try {
            // Validate company_id
            $validator = Validator::make($request->all(), [
                'company_id' => 'required'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');
            $purchaseType = $request->purchase_type;

            // Fetch all active measure units for the company
            $measureUnits = MeasureUnit::where('company_id', $companyId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->select(['id', 'name', 'quantity'])
                ->get()
                ->keyBy('id');

            // Get invoice numbers where at least one product has remaining quantity
            $invoiceNumbers = Sale::where('sales.company_id', $companyId)
                ->where('sales.branch_id', $branchId)
                ->whereNotNull('sales.invoice_number')
                ->where('sales.invoice_number', '!=', '')
                ->where('sales.purchase_type', $purchaseType)
                ->whereHas('saleProducts', function ($query) use ($companyId, $branchId, $measureUnits, $purchaseType) {
                    $query->leftJoin('measure_units as sale_mu', function ($join) use ($companyId, $branchId) {
                        $join->on('sale_products.measure_unit_id', '=', 'sale_mu.id')
                            ->where('sale_mu.company_id', $companyId)

                            ->where('sale_mu.is_active', 1)
                            ->whereNull('sale_mu.deleted_at');
                    })
                        ->where('sale_products.company_id', $companyId)
                        ->where('sale_products.branch_id', $branchId)
                        ->where('sale_products.purchase_type', $purchaseType)
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

           ;

            if (empty($invoiceNumbers)) {
                return response()->json(["data" => []], 200);
            }

            return response()->json([
                'message' => 'Available invoice numbers with remaining quantities retrieved successfully !',
                'data' => $invoiceNumbers
            ], 200);
        } catch (QueryException $e) {

           
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'An unexpected error occurred !'], 500);
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


            if (!$sale) {
             
                return response()->json(['error' => 'Sale not found'], 404);
            }


            if (empty($sale->saleProducts)) {
               
                return response()->json(['error' => 'No available products for this sale'], 404);
            }


            $saleData = $sale->toArray();
            foreach ($saleData['sale_products'] as &$product) {

                $totalReturned = SalesReturnProduct::where('sale_product_id', $product['id'])
                    ->whereNull('deleted_at')
                    ->sum(DB::raw('quantity + COALESCE(free_quantity, 0)'));
                $product['remaining_quantity'] = ($product['quantity'] + ($product['free_quantity'] ?? 0)) - $totalReturned;


                $product['purchase_product_id'] = $product['purchase_product_id'] ?? null;


                $unavailableQuantityIndices = [];
                if (!empty($product['sale_product_returns'])) {
                    $returnIds = array_column($product['sale_product_returns'], 'id');
                    $unavailableQuantityIndices = SaleReturnProductFieldValue::whereIn('sale_return_product_id', $returnIds)
                        ->whereNull('deleted_at')
                        ->pluck('quantity_index')
                        ->toArray();
                    $unavailableQuantityIndices = array_unique($unavailableQuantityIndices);
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
                $product['field_values'] = array_values($groupedFieldValues);


                unset($product['sale_product_returns']);
            }


            $saleData['sale_products'] = array_filter($saleData['sale_products'], function ($product) {
                return !empty($product['field_values']) && $product['remaining_quantity'] > 0;
            });


            if (empty($saleData['sale_products'])) {
               
                return response()->json(['error' => 'No available products for this sale'], 404);
            }

            return response()->json(['data' => $saleData]);
        } catch (QueryException $e) {
           

            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    public function listAvailableRefNumbers(Request $request): JsonResponse
    {
        try {
            // Validate company_id
            $validator = Validator::make($request->all(), [
                'company_id' => 'required'
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
          
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required',
                'customer_id' => 'nullable|exists:customers,id',
                'customer_name' => 'required|string|max:255',
                'purchase_type' => 'nulllable|string|max:255',
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
                'sub_total_before_discount' => 'nullable|numeric',
                'vat_amount' => 'nullable|numeric',
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
                'sales_return_products.*.field_values.*.*.purchase_stock_product_id' => [
                    'required_if:sales_return_products.*.field_values,array',
                    'integer',
                    'exists:purchase_stock_products,id'
                ],
                'sales_return_products.*.field_values.*.*.purchase_product_id' => [
                    'nullable',
                ],
                'sales_return_products.*.field_values.*.*.stock_product_id' => [
                    'nullable',
                ],
                'sales_return_products.*.field_values.*.*.stock_reconciliation_id' => [
                    'nullable',
                ],
                'sales_return_products.*.field_values.*.*.stock_adjustment_id' => [
                    'nullable',
                ],
                'sales_return_products.*.field_values.*.*.stock_transfer_id' => [
                    'nullable',
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
                
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $validated['company_id'] = $request->company_id;
            $validated['branch_id'] = $request->branch_id;

           

            $sale = Sale::when(isset($validated['sale_id']), function ($query) use ($validated) {
                return $query->where('id', $validated['sale_id']);
            })
                ->when(isset($validated['sale_invoice_number']), function ($query) use ($validated) {
                    return $query->where('invoice_number', $validated['sale_invoice_number']);
                })
                ->where('company_id', $validated['company_id'])
                ->where('branch_id', $validated['branch_id'])
                ->whereNull('deleted_at')
                ->first();

            if (!$sale) {
                
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
                ->where('branch_id', $validated['branch_id'])
                ->whereNull('deleted_at')
                ->with(['measureUnit', 'saleProductReturns' => fn($q) => $q->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id'])->whereNull('deleted_at')])
                ->get();

            if ($saleProducts->isEmpty()) {
                
                return response()->json(['error' => 'No products found in this sale'], 404);
            }

            // Initialize in-memory available pieces map
            $availablePiecesPerSaleProduct = [];
            foreach ($saleProducts as $saleProduct) {
                $availablePiecesPerSaleProduct[$saleProduct->id] = $this->calculateAvailablePieces($saleProduct, $validated['company_id'], $validated['branch_id'], $measureUnits);
            }

            // Track sale-product IDs that are completely exhausted across return lines
            $exhaustedSaleProductIds = [];

            $salesReturnProducts = [];
            foreach ($validated['sales_return_products'] as $index => $product) {
                $productId = $product['product_id'];
                $measureUnitId = $product['measure_unit_id'];
                $returnMeasureUnit = isset($measureUnits[$measureUnitId]) ? $measureUnits[$measureUnitId] : null;

                if (!$returnMeasureUnit) {
                    
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

               

                $fieldValuesFlat = $this->flattenFieldValues($product['field_values'], $index);
                $groupedFieldValues = collect($fieldValuesFlat)
                    ->groupBy('sale_product_id')
                    ->map(function ($group) {
                        return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                            return collect($fvGroup)->map(function ($fv) {
                                return [
                                    'sale_product_id' => $fv['sale_product_id'],
                                    'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                    'purchase_product_id' => $fv['purchase_product_id'],
                                    'stock_product_id' => $fv['stock_product_id'],
                                    'stock_reconciliation_id' => $fv['stock_reconciliation_id'],
                                    'stock_transfer_id' => $fv['stock_transfer_id'],
                                    'stock_adjustment_id' => $fv['stock_adjustment_id'],
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

              

                $hasFieldValues = !empty($fieldValuesFlat);
                $saleProductIds = $hasFieldValues ? array_keys($groupedFieldValues) : [];

                // Flag to identify FIFO case for skipping redundant checks later
                $isFIFO = !$hasFieldValues && !isset($product['sale_product_id']);

                if ($isFIFO) {
                    // Apply FIFO: Select SaleProduct by product_id only, ordered by created_at
                    $saleProductQuery = $saleProducts->where('product_id', $productId);
                    $fifoSaleProducts = $saleProductQuery->sortBy('created_at')->filter(function ($saleProduct) use ($validated, $measureUnits, $availablePiecesPerSaleProduct, $exhaustedSaleProductIds) {
                        // Skip sale-products already exhausted in previous return lines
                        if (in_array($saleProduct->id, $exhaustedSaleProductIds, true)) {
                            return false;
                        }
                        $availablePieces = $availablePiecesPerSaleProduct[$saleProduct->id];
                       
                        return $availablePieces > 0;
                    })->values();

                    if ($fifoSaleProducts->isEmpty()) {
                       
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
                       

                        // Use updated in-memory available
                        $availablePieces = $availablePiecesPerSaleProduct[$saleProduct->id];
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

                            // Subtract allocated pieces from in-memory available immediately
                            $availablePiecesPerSaleProduct[$saleProduct->id] -= $allocatePieces;

                            // Mark exhausted if nothing left
                            if ($availablePiecesPerSaleProduct[$saleProduct->id] <= 0) {
                                $exhaustedSaleProductIds[] = $saleProduct->id;
                            }

                        
                        }
                    }

                    if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                       
                        return response()->json([
                            'error' => "Insufficient stock for product ID {$productId} in sale ID {$validated['sale_id']} at index {$index}. Requested: {$totalRequestedPieces} pieces (Regular: {$regularPieces}, Free: {$freePieces}), Allocated: " . ($totalRequestedPieces - ($remainingRegularPieces + $remainingFreePieces)) . " pieces.",
                        ], 422);
                    }
                } else if (!$hasFieldValues) {
                    if (isset($product['sale_product_id'])) {
                        $saleProductIds = [$product['sale_product_id']];
                    }
                }

                foreach ($saleProductIds as $saleProductId) {
                    $saleProduct = $saleProducts->firstWhere('id', $saleProductId);
                    if (!$saleProduct || $saleProduct->product_id != $productId) {
                        
                        return response()->json([
                            'error' => "Invalid sale product ID {$saleProductId} for product ID {$productId} at index {$index}",
                        ], 422);
                    }

                    $saleMeasureUnitQuantity = $measureUnits[$saleProduct->measure_unit_id]->quantity ?? 1;
                   

                    // Use updated in-memory available
                    $availablePieces = $availablePiecesPerSaleProduct[$saleProduct->id];

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
                           
                            return response()->json([
                                'error' => "No valid field value sets for sale product ID {$saleProductId} at index {$index}",
                            ], 422);
                        }

                        // Check against updated available (skip not needed for FIFO, but this is hasFieldValues so check)
                        if ($totalRequestedPiecesForProduct > $availablePieces) {
                           
                            return response()->json([
                                'error' => "Insufficient stock for sale product ID {$saleProductId} at index {$index}. Requested: {$totalRequestedPiecesForProduct}, Available: {$availablePieces}",
                            ], 422);
                        }

                        [$quantity, $freeQuantity] = $this->convertToTargetMeasureUnit($regularFieldValueSets, $freeFieldValueSets, $returnMeasureUnitQuantity);
                    } else {
                        $allocatedRegularPieces = $allocations[$saleProductId]['regular_pieces'] ?? $regularPieces;
                        $allocatedFreePieces = $allocations[$saleProductId]['free_pieces'] ?? $freePieces;
                        $totalRequestedPiecesForProduct = $allocatedRegularPieces + $allocatedFreePieces;

                        // Conditional check - skip for FIFO since allocation already handled it
                        if (!$isFIFO) {
                            if ($totalRequestedPiecesForProduct > $availablePieces) {
                                
                                return response()->json([
                                    'error' => "Insufficient stock for sale product ID {$saleProductId} at index {$index}. Requested: {$totalRequestedPiecesForProduct}, Available: {$availablePieces}",
                                ], 422);
                            }
                        }

                        [$quantity, $freeQuantity] = $this->convertToTargetMeasureUnit($allocatedRegularPieces, $allocatedFreePieces, $returnMeasureUnitQuantity);
                        
                    }

                    $salesReturnProducts[] = [
                        'sale_product_id' => $saleProductId,
                        'purchase_stock_product_id' => $saleProduct->purchase_stock_product_id,
                        'product_id' => $saleProduct->product_id,
                        'product_name' => $product['product_name'] ?? null,
                        'product_code' => $product['product_code'] ?? null,
                        'quantity' => $quantity,
                        'free_quantity' => $freeQuantity,
                        'amount' => $product['amount'],
                        'price' => $product['price'],
                        'discount_percent' => $product['discount_percent'] ?? 0,
                        'discount_amount' => $product['discount_amount'] ?? 0,
                        'is_vatable' => $product['is_vatable'] ?? $saleProduct->is_vatable,
                        'measure_unit_id' => $product['measure_unit_id'] ?? 0,
                        'batch_no' => $product['batch_no'] ?? $saleProduct->batch_no,
                        'mfd' => $product['mfd'] ?? $saleProduct->mfd,
                        'expiry_date' => $product['expiry_date'] ?? $saleProduct->expiry_date,
                        'name' => $product['product_name'] ?? $saleProduct->name,
                        'field_values' => $hasFieldValues ? ($groupedFieldValues[$saleProductId] ?? []) : [],
                    ];

                    // Subtract from in-memory available after allocation (for non-FIFO cases; FIFO already subtracted earlier)
                    if (!$isFIFO) {
                        $availablePiecesPerSaleProduct[$saleProductId] -= $totalRequestedPiecesForProduct;
                        // Mark exhausted if nothing left
                        if ($availablePiecesPerSaleProduct[$saleProductId] <= 0) {
                            $exhaustedSaleProductIds[] = $saleProductId;
                        }
                    }

                    
                }

                if ($hasFieldValues) {
                    $totalFieldValuePieces = collect($saleProductIds)->sum(function ($saleProductId) use ($groupedFieldValues) {
                        return collect($groupedFieldValues[$saleProductId] ?? [])->count();
                    });
                    $totalPayloadPieces = $regularPieces + $freePieces;
                    if ($totalFieldValuePieces != $totalPayloadPieces) {
                       
                        // Allow mismatch as per previous example
                    }
                }
            }

            $validated['sales_return_products'] = $salesReturnProducts;

            if (empty($validated['sales_return_products'])) {
              
                return response()->json(['error' => 'No valid products available for return'], 422);
            }

            $salesReturn = DB::transaction(function () use ($validated) {
                $salesReturn = SalesReturn::create([
                    'company_id' => $validated['company_id'],
                    'branch_id' => $validated['branch_id'],
                    'customer_id' => $validated['customer_id'] ?? null,
                    'purchase_type' => $validated['purchase_type'] ?? null,
                    'customer_address' => $validated['customer_address'] ?? null,
                    'customer_name' => $validated['customer_name'] ?? null,
                    'salesman_id' => $validated['salesman_id'] ?? null,
                    'invoice_number' => $validated['invoice_number'] ?? null,
                    'sales_bill_number' => $validated['sale_invoice_number'] ?? null,
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
                    'sub_total_before_discount' => $validated['sub_total_before_discount'] ?? null,
                    'vat_amount' => $validated['vat_amount'] ?? null,
                    'taxable_amount' => $validated['taxable_amount'] ?? null,
                    'non_taxable_amount' => $validated['non_taxable_amount'] ?? null,
                    'discount_after_vat' => $validated['discount_after_vat'] ?? null,
                    'total_amount' => $validated['total_amount'] ?? null,
                    'round_of_amount' => $validated['round_of_amount'] ?? null,
                    'roundoff_type' => $validated['roundoff_type'] ?? null,
                    'payment_type' => $validated['payment_type'] ?? null,
                    'sale_id' => $validated['sale_id'],
                    'payment' => [
                        'cash' => $validated['payment']['cash'] ?? null,
                        'credit' => $validated['payment']['credit'] ?? null,
                        'bank' => $validated['payment']['bank'] ?? null,
                    ],
                ]);

                

                if (isset($validated['sales_return_additional']) && !empty($validated['sales_return_additional'])) {
                    SaleReturnAdditional::create([
                        'company_id' => $validated['company_id'],
                        'branch_id' => $validated['branch_id'],
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

                  
                }

                foreach ($validated['sales_return_products'] as $index => $product) {
                    $saleProduct = SaleProduct::where('id', $product['sale_product_id'])
                        ->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['branch_id'])
                        ->whereNull('deleted_at')
                        ->first();

                    if (!$saleProduct) {
                       
                        throw new \Exception("Sale product ID {$product['sale_product_id']} not found at index {$index}");
                    }

                    $salesReturnProduct = $salesReturn->salesReturnProducts()->create([
                        'company_id' => $validated['company_id'],
                        'branch_id' => $validated['branch_id'],
                        'sale_id' => $validated['sale_id'],
                        'sale_product_id' => $product['sale_product_id'],
                        'purchase_type' => $validated['purchase_type'] ?? null,
                        'purchase_stock_product_id' => $saleProduct->purchase_stock_product_id,
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

                   

                    if (!empty($product['field_values'])) {
                        $fieldValues = [];
                        foreach ($product['field_values'] as $fvSet) {
                            foreach ($fvSet as $fv) {
                                $fieldValues[] = [
                                    'company_id' => $validated['company_id'],
                                    'branch_id' => $validated['branch_id'],
                                    'sale_return_product_id' => $salesReturnProduct->id,
                                    'sale_product_id' => $fv['sale_product_id'],
                                    'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
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
                           
                        }
                    }
                }

                return $salesReturn;
            });

            

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
           
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }





    public function getSaleProductNames(Request $request): JsonResponse
    {
        try {
            // Validate inputs
            $validator = Validator::make($request->all(), [
                'company_id' => 'required',
                'purchase_type' => 'required|string', // Adjust 'in' values based on your valid purchase types
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');
            $purchaseType = $request->input('purchase_type');

           

            // Authentication check (middleware should handle authorization)
            if (!auth()->check()) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            // Fetch measure units
            $measureUnits = MeasureUnit::where('company_id', $companyId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->select(['id', 'name', 'quantity'])
                ->get()
                ->keyBy('id');

           

            // Fetch sales with products
            $sales = Sale::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->with([
                    'saleProducts' => function ($query) use ($companyId, $branchId, $purchaseType) {
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
                            ->join('purchase_stock_products', 'sale_products.purchase_stock_product_id', '=', 'purchase_stock_products.id')

                            ->where('sale_products.company_id', $companyId)
                            ->where('sale_products.branch_id', $branchId)
                            ->where('sale_products.purchase_type', $purchaseType)
                            ->whereNull('sale_products.deleted_at')
                            ->whereNull('products.deleted_at')
                            ->whereNull('purchase_stock_products.deleted_at');
                        // ->whereNull('purchases.deleted_at')
                        // ->where('purchase_stock_products.purchase_type', $purchaseType);
                    },
                ])
                ->select(['id', 'company_id'])
                ->get();

            if ($sales->isEmpty()) {
               
                return response()->json(['message' => 'No sale products with available quantities found', 'data' => []], 404);
            }

            // Fetch sales return products
            $saleProductIds = $sales->pluck('saleProducts.*.id')->flatten()->unique()->toArray();
            $salesReturnProducts = SalesReturnProduct::whereIn('sale_product_id', $saleProductIds)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->select(
                    [
                        'id',
                        'sale_product_id',
                        'quantity',
                        'free_quantity',
                        'measure_unit_id',

                    ]
                )
                ->get();

            

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
                    $saleTotal = $this->sumQuantityAndFree($saleRegular, $saleFree);

                    // Calculate returned quantity
                    $returnProducts = $salesReturnProducts->where('sale_product_id', $saleProduct->id);
                    $returned = 0;
                    foreach ($returnProducts as $returnProduct) {
                        $returnMeasureUnitId = $returnProduct->measure_unit_id ?? null;
                        $returnMeasureUnitQuantity = isset($measureUnits[$returnMeasureUnitId]) ? $measureUnits[$returnMeasureUnitId]->quantity : 1;
                        $regularQuantity = $returnProduct->quantity ?? 0;
                        $freeQuantity = $returnProduct->free_quantity ?? 0;

                        $returnRegularQuantity = $this->calculatePieces($regularQuantity, $returnMeasureUnitQuantity);
                        $freeReturnQuantity = $this->calculatePieces($freeQuantity, $returnMeasureUnitQuantity);
                        $returnQuantity = $this->sumQuantityAndFree($returnRegularQuantity, $freeReturnQuantity);

                        $returned += $returnQuantity;

                     
                    }

                    if ($returned >= $saleTotal) {
                       
                        continue;
                    }

                    // Calculate available quantity
                    $returnTotal = round($returned, 2);
                    $saleTotal = round($saleTotal, 2);
                    $availableQuantity = max(0, round($saleTotal - $returnTotal, 2));

                  

                    $products[$productId]['sale_quantity'] += $saleTotal;
                    $products[$productId]['sales_return_quantity'] += $returnTotal;
                    $products[$productId]['available_quantity'] += $availableQuantity;
                }
            }

            // Filter products with available quantity
          
            $products = array_filter($products, function ($product) {
                
                return $product['available_quantity'] > 0;
            });

            if (empty($products)) {
                
                return response()->json(['message' => 'No sale products with available quantities found', 'data' => []], 200);
            }

            // Prepare response
            $response = array_map(function ($product) {
                return [
                    'key' => $product['product_id'],
                    'value' => $product['product_name'],
                ];
            }, array_values($products));

            

            return response()->json([
                'message' => 'Data Received Successfully !!',
                'data' => $response,
            ], 200);

        } catch (QueryException $e) {
           
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
           
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
                'company_id' => 'required|integer',
                'sale_id' => 'nullable|integer|exists:sales,id',
            ]);

            if ($validator->fails()) {

                return response()->json(['errors' => $validator->errors()], 422);
            }

            $productId = $request->input('product_id');
            $productCode = $request->input('product_code');
            $productName = trim(strtolower($request->input('product_name')));
            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');
            $saleId = $request->input('sale_id');

           

            if (!$productId && !$productCode && !$productName && !$saleId) {
                return response()->json(['error' => 'At least one of product_id, product_name, or sale_id is required'], 422);
            }

            // Authentication check
            if (!auth()->check()) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $user = auth()->user();
            $userCompanyId = optional($user->company)->company_id;

            if ($userCompanyId != $companyId) {

                return response()->json(['error' => 'Unauthorized access to company resources'], 200);
            }

            // Fetch measure units
            $measureUnits = MeasureUnit::where('company_id', $companyId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->select(['id', 'name', 'quantity'])
                ->get()
                ->keyBy('id');

          

            // Fetch sales with products and field values
            $salesQuery = Sale::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at');

            if ($saleId) {
                $salesQuery->where('id', $saleId);
            }

            $sales = $salesQuery->with([
                'saleProducts' => function ($query) use ($companyId, $branchId, $productId, $productName, $productCode) {
                    $query->select([
                        'sale_products.id',
                        'sale_products.sale_id',
                        'sale_products.product_id',
                        'sale_products.measure_unit_id',
                        'sale_products.quantity',
                        'sale_products.amount',
                        'sale_products.free_quantity',
                        'sale_products.purchase_stock_product_id',
                        'sale_products.price',
                        'sale_products.is_vatable',
                        'sale_products.expiry_date',
                        'products.name as product_name',
                        'products.product_unique_id as product_code',
                    ])
                        ->join('products', 'sale_products.product_id', '=', 'products.id')
                        ->where('sale_products.company_id', $companyId)
                        ->where('sale_products.branch_id', $branchId)
                        ->whereNull('sale_products.deleted_at')
                        ->whereNull('products.deleted_at');

                    if ($productId) {
                        $query->where('sale_products.product_id', $productId);
                    }

                    if ($productName) {
                        $query->where('products.name', $productName);
                    }

                    if ($productCode) {
                        $query->where('products.product_unique_id', $productCode);
                    }
                },
                'saleProducts.fieldValues' => function ($query) use ($companyId, $branchId) {
                    $query->select([
                        'sales_product_field_values.sale_product_id',
                        'sales_product_field_values.product_field_id',
                        'sales_product_field_values.quantity_index',
                        'sales_product_field_values.value',
                        'product_fields.name',
                    ])
                        ->join('product_fields', 'sales_product_field_values.product_field_id', '=', 'product_fields.id')
                        ->where('sales_product_field_values.company_id', $companyId)
                        ->where('sales_product_field_values.branch_id', $branchId)
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
                
                return response()->json(['message' => 'No products available for sales return', 'data' => []], 404);
            }

            // Fetch sales return products
            $saleProductIds = $sales->pluck('saleProducts.*.id')->flatten()->unique()->toArray();
            $salesReturnProducts = SalesReturnProduct::whereIn('sale_product_id', $saleProductIds)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
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

          

            // Fetch return field values for comparison
            $returnFieldValues = DB::table('sale_return_product_field_values')
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
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
                        
                    }

                    // Fetch product metadata
                    $product = Product::with('measureUnit:id,quantity')
                        ->where('id', $productId)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->first();

                    $unitQty = $product->measureUnit->quantity ?? 1;
                    $originalPricePerPiece = ($product->retail_sales_price ?? 0) / $unitQty;

                    // Fetch all sale prices once
                    $salePrices = SaleProduct::where('product_id', $productId)
                        ->where('company_id', $companyId)
                        ->where('branch_id', $branchId)
                        ->whereNull('deleted_at')
                        ->orderBy('created_at')
                        ->pluck('price');

                    $latestPrice = $salePrices->last() ?? 0;
                    $latestPricePerPiece = $latestPrice / $unitQty;
                    $minPricePerPiece = $salePrices->min() / $unitQty;
                    $avgPricePerPiece = $salePrices->avg() / $unitQty;

                    // Initialize product entry
                    if (!isset($products[$productId])) {
                        $products[$productId] = [
                            'product_id' => $productId,
                            'product_name' => $saleProduct->product_name,
                            'product_code' => $saleProduct->product_code,
                            'original_price' => $originalPricePerPiece ?? 0,
                            'latest_price' => $latestPricePerPiece ?? 0,
                            'min_price' => $minPricePerPiece ?? 0,
                            'avg_price' => $avgPricePerPiece ?? 0,
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
                    $saleTotal = $this->sumQuantityAndFree($saleRegular, $saleFree);

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
                        $returnQuantity = $this->sumQuantityAndFree($returnRegularQuantity, $freeReturnQuantity);
                        $returned += $returnQuantity;
                        $lastReturnMeasureUnitId = $returnMeasureUnitId;
                        $lastReturnMeasureUnitQuantity = $returnMeasureUnitQuantity;

                        if ($returnMeasureUnitId !== $saleProduct->measure_unit_id) {
                            Log::warning('Measure unit mismatch for return product', [
                                'sale_product_id' => $saleProduct->id,
                                'return_product_id' => $returnProduct->id,
                                'sale_measure_unit_id' => $saleProduct->measure_unit_id,
                                'return_measure_unit_id' => $returnMeasureUnitId,
                            ]);
                        }

                       
                    }

                    if ($returned >= $saleTotal) {
                        
                    }

                   
                    // Determine returned quantity indices for field values
                    $returnedIndices = [];
                    $saleProductReturnFieldValues = $returnFieldValues[$saleProduct->id] ?? collect([]);
                    

                    if ($saleProduct->fieldValues->isNotEmpty()) {
                        $quantityIndices = $saleProduct->fieldValues->pluck('quantity_index')->unique();
                        foreach ($quantityIndices as $quantityIndex) {
                            $saleFieldValues = $saleProduct->fieldValues->where('quantity_index', $quantityIndex)
                                ->pluck('value', 'product_field_id')
                                ->toArray();

                            $isReturned = false;
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
                    $availableQuantity = $saleTotal - $returnTotal;

                   

                    $products[$productId]['sale_quantity'] += $saleTotal;
                    $products[$productId]['sales_return_quantity'] += $returnTotal;
                    $products[$productId]['return_quantity'] += $returnTotal;
                    $products[$productId]['available_quantity'] += $availableQuantity;

                    if ($saleProduct->expiry_date && !in_array($saleProduct->expiry_date, $products[$productId]['expiry_dates'])) {
                        $products[$productId]['expiry_dates'][] = $saleProduct->expiry_date;
                    }

                    
                    if ($saleProduct->fieldValues->isNotEmpty()) {
                      
                        $tempFieldValues = [];

                        foreach ($saleProduct->fieldValues as $fv) {
                            if (!in_array($fv->quantity_index, $returnedIndices)) {
                                $purchaseStockProductId = $saleProduct->purchase_stock_product_id ?? 'null';
                                $quantityIndex = $fv->quantity_index;

                                // Initialize the grouped structure if not already set
                                if (!isset($tempFieldValues[$purchaseStockProductId])) {
                                    $tempFieldValues[$purchaseStockProductId] = [];
                                }
                                if (!isset($tempFieldValues[$purchaseStockProductId][$quantityIndex])) {
                                    $tempFieldValues[$purchaseStockProductId][$quantityIndex] = [];
                                }

                              
                                $tempFieldValues[$purchaseStockProductId][$quantityIndex][] = [
                                    'sale_product_id' => $saleProduct->id,
                                    'purchase_stock_product_id' => $saleProduct->purchase_stock_product_id,
                                    'purchase_product_id' => $saleProduct->purchase_product_id ?? null,
                                    'stock_product_id' => $saleProduct->stock_product_id ?? null,
                                    'stock_transfer_id' => $saleProduct->stock_transfer_id ?? null,
                                    'stock_reconciliation_id' => $saleProduct->stock_reconciliation_id ?? null,
                                    'stock_adjustment_id' => $saleProduct->stock_adjustment_id ?? null,
                                    'product_field_id' => $fv->product_field_id,
                                    'name' => $fv->name,
                                    'value' => $fv->value,
                                    'quantity_index' => $fv->quantity_index,
                                ];
                            }
                        }

                        // Sort by purchase_stock_product_id and quantity_index, then flatten into the original flat array format
                        ksort($tempFieldValues); // Sort by purchase_stock_product_id
                        foreach ($tempFieldValues as $purchaseStockProductId => $quantityIndices) {
                            ksort($quantityIndices); // Sort by quantity_index
                            foreach ($quantityIndices as $quantityIndex => $fieldValues) {
                                // Sort field values by product_field_id to ensure consistent order within the same quantity_index
                                usort($fieldValues, function ($a, $b) {
                                    return $a['product_field_id'] <=> $b['product_field_id'];
                                });
                                foreach ($fieldValues as $fieldValue) {
                                    $products[$productId]['field_values'][] = $fieldValue;
                                    Log::info('Added eligible field value', [
                                        'sale_product_id' => $fieldValue['sale_product_id'],
                                        'purchase_stock_product_id' => $purchaseStockProductId,
                                        'quantity_index' => $quantityIndex,
                                        'product_field_id' => $fieldValue['product_field_id'],
                                        'value' => $fieldValue['value'],
                                    ]);
                                }
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
                        'purchase_stock_product_id' => $saleProduct->purchase_stock_product_id,
                        'purchase_product_id' => $saleProduct->purchase_product_id,
                        'stock_product_id' => $saleProduct->stock_product_id,
                        'stock_adjustment_id' => $saleProduct->stock_adjustment_id,
                        'stock_reconciliation_id' => $saleProduct->stock_reconciliation_id,
                        'stock_transfer_id' => $saleProduct->stock_transfer_id,
                    ];
                }
            }

            // Calculate purchased quantity
            foreach ($products as $productId => &$product) {
                $purchasedTotal = PurchaseStockProduct::where('product_id', $productId)
                    ->where('purchase_stock_products.company_id', $companyId)
                    ->where('purchase_stock_products.branch_id', $branchId)
                    ->whereNull('purchase_stock_products.deleted_at')
                    ->join('measure_units', 'purchase_stock_products.measure_unit_id', '=', 'measure_units.id')
                    ->where('measure_units.company_id', $companyId)
                    ->whereNull('measure_units.deleted_at')
                    ->sum(DB::raw('(purchase_stock_products.quantity + COALESCE(purchase_stock_products.free_quantity, 0)) * measure_units.quantity'));

                $product['purchased_quantity'] = (int) ($purchasedTotal ?? 0);
              

             
            }


            $products = array_filter($products, function ($product) {
              
                return $product['available_quantity'] > 0;
            });

            if (empty($products)) {
                
                return response()->json(['message' => 'No products available for sales return', 'data' => []], 404);
            }

            

            $response = [
                'message' => 'Product details retrieved successfully',
                'data' => [
                    [
                        'products' => array_values($products),
                    ],
                ],
            ];

          

            return response()->json($response);

        } catch (QueryException $e) {
          
            return response()->json(['error' => 'Database query error'], 500);
        } catch (\Exception $e) {
        
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function storeItemWise(Request $request): JsonResponse
    {
        try {
            // Define validation rule
            $validator = Validator::make($request->all(), [
                'company_id' => 'required',
                'customer_id' => 'nullable|exists:customers,id',
                'purchase_type' => 'nullable|string|max:255',
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
                'sales_return_products.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',
                'sales_return_products.*.purchase_product_id' => 'nullable',
                'sales_return_products.*.stock_product_id' => 'nullable',
                'sales_return_products.*.stock_adjustment_id' => 'nullable',
                'sales_return_products.*.stock_reconciliation_id' => 'nullable',
                'sales_return_products.*.stock_transfer_id' => 'nullable',
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
                'sales_return_products.*.field_values.*.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',
                'sales_return_products.*.field_values.*.*.purchase_product_id' => 'nullable',
                'sales_return_products.*.field_values.*.*.stock_product_id' => 'nullable',
                'sales_return_products.*.field_values.*.*.stock_adjustmentt_id' => 'nullable',
                'sales_return_products.*.field_values.*.*.stock_reconciliation_id' => 'nullable',
                'sales_return_products.*.field_values.*.*.stock_transfer_id' => 'nullable',

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
            $validated['branch_id'] = $request->branch_id;
            $validated['return_entire_all'] = $validated['return_entire_all'] ?? $validated['return_entire_sale'] ?? false;
            

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
                    $query->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['branch_id'])->whereNull('deleted_at');
                })
                ->when(!empty($productIds), function ($query) use ($productIds) {
                    $query->whereIn('product_id', $productIds);
                })
                ->when(!empty($productNames), function ($query) use ($productNames) {
                    $query->where(function ($q) use ($productNames) {
                        foreach ($productNames as $name) {
                            $q->orWhere('name', $name);
                        }
                    });
                })

                ->when(!empty($validated['sale_id']), function ($query) use ($validated) {
                    $query->where('sale_id', $validated['sale_id']);
                })
                ->orderBy('created_at');

            $allSaleProducts = $saleProductQuery->get();
            if ($allSaleProducts->isEmpty()) {
                
                return response()->json(['error' => 'No sale products found for the provided criteria'], 404);
            }

            // Fetch available field values for validation
            $saleProductIds = $allSaleProducts->pluck('id')->toArray();
            $availableFieldValues = DB::table('sales_product_field_values')
                ->whereIn('sale_product_id', $saleProductIds)
                ->where('company_id', $validated['company_id'])
                ->where('branch_id', $validated['branch_id'])
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
                ->where('branch_id', $validated['branch_id'])
                ->whereIn('id', $allSaleProducts->pluck('sale_id')->unique())
                ->orderBy('created_at', 'desc')
                ->first();
            if (!$sale) {
                return response()->json(['error' => 'No valid sale found'], 404);
            }
            $validated['sale_id'] = $sale->id;

            // Generate unique invoice number

            // Handle return modes
            $returnEntireAll = $validated['return_entire_all'];
            $useSaleProductIds = !empty($validated['sale_product_ids']);
            $salesReturnProducts = [];

            if ($returnEntireAll) {
                $saleProducts = $allSaleProducts->filter(function ($saleProduct) use ($validated) {
                    return $saleProduct->sale_id == $validated['sale_id'];
                });
                if ($saleProducts->isEmpty()) {
                   
                    return response()->json(['error' => 'No products found in this sale'], 404);
                }


                $salesReturnProducts = $saleProducts->map(function ($saleProduct) use ($validated, $measureUnits) {
                    $measureUnitID = $saleProduct->measure_unit_id ?? 1;
                    $measureUnit = MeasureUnit::where('id', $measureUnitID)->first();
                    $measureUnitsCalc = $measureUnit->quantity ?? 1;
                    $availablePieces = $this->calculateAvailablePiecesStore($saleProduct, $validated['company_id'], $validated['branch_id'], $measureUnitsCalc);
                    if ($availablePieces < 0) {
                        return null;
                    }
                    $saleMeasureUnitQuantity = $measureUnits[$saleProduct->measure_unit_id]->quantity ?? 1;
                    [$quantity, $freeQuantity] = $this->convertToTargetMeasureUnit($availablePieces, 0, $saleMeasureUnitQuantity);
                    return [
                        'sale_product_id' => $saleProduct->id,
                        'purchase_stock_product_id' => $saleProduct->purchase_stock_product_id,
                        'purchase_product_id' => $saleProduct->purchase_product_id ?? null,
                        'stock_product_id' => $saleProduct->stock_product_id ?? null,
                        'stock_adjustment_id' => $saleProduct->stock_adjustment_id ?? null,
                        'stock_reconciliation_id' => $saleProduct->stock_reconciliation_id ?? null,
                        'stock_transfer_id' => $saleProduct->stock_transfer_id ?? null,

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
                        $query->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id'])->whereNull('deleted_at');
                    })
                    ->orderBy('created_at')
                    ->get();

                if ($saleProducts->isEmpty()) {
                  
                    return response()->json(['error' => 'No valid sale products found for provided IDs'], 404);
                }


                $salesReturnProducts = $saleProducts->map(function ($saleProduct) use ($validated, $measureUnits) {
                    $measureUnitID = $saleProduct->measure_unit_id ?? 1;
                    $measureUnit = MeasureUnit::where('id', $measureUnitID)->first();
                    $measureUnitsCalc = $measureUnit->quantity ?? 1;
                    $availablePieces = $this->calculateAvailablePiecesStore($saleProduct, $validated['company_id'], $validated['branch_id'], $measureUnitsCalc);
                    if ($availablePieces < 0) {
                        return null;
                    }
                    $saleMeasureUnitQuantity = $measureUnits[$saleProduct->measure_unit_id]->quantity ?? 1;
                    [$quantity, $freeQuantity] = $this->convertToTargetMeasureUnit($availablePieces, 0, $saleMeasureUnitQuantity);
                    return [
                        'sale_product_id' => $saleProduct->id,
                        'purchase_stock_product_id' => $saleProduct->purchase_stock_product_id,
                        'purchase_product_id' => $saleProduct->purchase_product_id ?? null,
                        'stock_product_id' => $saleProduct->stock_product_id ?? null,
                        'stock_transfer_id' => $saleProduct->stock_transfer_id ?? null,
                        'stock_reconciliation_id' => $saleProduct->stock_reconciliation_id ?? null,
                        'stock_adjustment_id' => $saleProduct->stock_adjustment_id ?? null,
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

                   

                    // Validate field_values against total pieces
                    $fieldValuesFlat = !empty($product['field_values']) ? $this->flattenFieldValues($product['field_values'], $index) : [];
                    // Count the total number of field_values sets (each set represents one piece)
                    $totalFieldValueSets = count($product['field_values']);

                    if (!empty($fieldValuesFlat) && $totalFieldValueSets != $expectedTotalPieces) {
                    
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
                            $measureUnitID = $saleProduct->measure_unit_id ?? 1;
                            $measureUnit = MeasureUnit::where('id', $measureUnitID)->first();
                            $measureUnitsCalc = $measureUnit->quantity ?? 1;
                            $availablePieces = $this->calculateAvailablePiecesStore($saleProduct, $validated['company_id'], $validated['branch_id'], $measureUnitsCalc);
                            // Subtract previously allocated pieces in this request
                            $previouslyAllocated = $allocatedPiecesBySaleProduct[$saleProduct->id] ?? 0;
                            $remainingAvailable = $availablePieces - $previouslyAllocated;
                            
                            return $remainingAvailable > 0;
                        })->sortBy('created_at')->values();

                        if ($fifoSaleProducts->isEmpty()) {
                          
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

                            $measureUnitID = $saleProduct->measure_unit_id ?? 1;
                            $measureUnit = MeasureUnit::where('id', $measureUnitID)->first();
                            $measureUnitsCalc = $measureUnit->quantity ?? 1;

                            $availablePiecesFifo = $this->calculateAvailablePiecesStore($saleProduct, $validated['company_id'], $validated['branch_id'], $measureUnitsCalc);
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
                                        'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                        'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                        'stock_product_id' => $fv['stock_product_id'] ?? null,
                                        'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                                        'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                        'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
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

                            $availablePieces = $this->calculateAvailablePiecesForfifo($saleProduct, $validated['company_id'], $validated['branch_id'], $MeasureUnitQuantityUsed);

                        } else {
                            $saleMeasureUnit = $saleProduct->measure_unit_id ?? 1;
                            $measureUnitId = MeasureUnit::where('id', $saleMeasureUnit)->first();
                            $saleMeasureUnitQuantity = $measureUnitId->quantity ?? 0;

                            $availablePieces = $this->calculateAvailablePiecesForFifo($saleProduct, $validated['company_id'], $validated['branch_id'], $saleMeasureUnitQuantity);


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

                         
                        }

                        $salesReturnProducts[] = [
                            'sale_product_id' => $saleProductId,
                            'purchase_stock_product_id' => $product['purchase_stock_product_id'] ?? $saleProduct->purchase_stock_product_id,
                            'purchase_product_id' => $product['purchase_product_id'] ?? null,
                            'stock_product_id' => $product['stock_product_id'] ?? null,
                            'stock_transfer_id' => $product['stock_transfer_id'] ?? null,
                            'stock_reconciliation_id' => $product['stock_reconciliation_id'] ?? null,
                            'stock_adjustment_id' => $product['stock_adjustment_id'] ?? null,
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
                    'company_id' => $validated['company_id'],
                    'branch_id' => $validated['branch_id'],
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

                    $availablePieces = $this->calculateAvailablePiecesForfifo($saleProduct, $validated['company_id'], $validated['branch_id'], $measureUnits);


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
                        'purchase_type' => $validated['purchase_type'] ?? null,
                        'branch_id' => $validated['branch_id'],
                        'sales_return_id' => $salesReturn->id,
                        'sale_id' => $saleProduct->sale_id,
                        'sale_product_id' => $product['sale_product_id'],
                        'purchase_stock_product_id' => $product['purchase_stock_product_id'] ?? $saleProduct->purchase_stock_product_id,
                        'purchase_product_id' => $product['purchase_product_id'] ?? null,
                        'stock_product_id' => $product['stock_product_id'] ?? null,
                        'stock_transfer_id' => $product['stock_transfer_id'] ?? null,
                        'stock_reconciliation_id' => $product['stock_reconciliation_id'] ?? null,
                        'stock_adjustment_id' => $product['stock_adjustment_id'] ?? null,
                        'product_id' => $saleProduct->product_id,

                        'product_name' => $product['product_name'] ?? $saleProduct->name,
                        'quantity' => $product['quantity'] ?? 0,
                        'free_quantity' => $product['free_quantity'] ?? 0,
                        'price' => $product['price'] ?? $saleProduct->price,
                        'amount' => $product['amount'] ?? null,
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
                                    'branch_id' => $validated['branch_id'],
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'product_id' => $salesReturnProduct->product_id,
                                    'sale_return_product_id' => $salesReturnProduct->id,
                                    'sale_product_id' => $fieldValue['sale_product_id'],
                                    'purchase_stock_product_id' => $fieldValue['purchase_stock_product_id'],
                                    'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                                    'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                                    'stock_transfer_id' => $fieldValue['stock_transfer_id'] ?? null,
                                    'stock_reconciliation_id' => $fieldValue['stock_reconciliation_id'] ?? null,
                                    'stock_adjustment_id' => $fieldValue['stock_adjustment_id'] ?? null,
                                    'quantity_index' => $quantityIndex,
                                    'value' => $fieldValue['value'],
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                            }
                        }
                        DB::table('sale_return_product_field_values')->insert($fieldValues);
                       
                    }
                }

                SaleReturnAdditional::create([
                    'company_id' => $validated['company_id'],
                    'branch_id' => $validated['branch_id'],
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
           
            return response()->json(['error' => 'Resource not found: ' . $e->getMessage()], 404);
        } catch (QueryException $e) {
          
            return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }


    public function updateItemWise(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required',
                'customer_id' => 'nullable|exists:customers,id',
                'purchase_type' => 'nullable|string|max:255',
                'salesman_id' => 'nullable|exists:salesmen,id',
                'sale_id' => 'nullable|exists:sales,id',
                'invoice_number' => 'nullable|string|max:255|unique:sales_returns,invoice_number,' . $id,
                'document_number' => 'nullable|string|max:255',
                'batch_no' => 'nullable|string|max:255|unique:sales_returns,batch_no,' . $id,
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
                'sales_return_products.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',
                'sales_return_products.*.purchase_product_id' => 'nullable',
                'sales_return_products.*.stock_product_id' => 'nullable',
                'sales_return_products.*.stock_adjustsment_id' => 'nullable',
                'sales_return_products.*.stock_reconciliation_id' => 'nullable',
                'sales_return_products.*.stock_transfer_id' => 'nullable',

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
                'sales_return_products.*.field_values.*.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',

                'sales_return_products.*.field_values.*.*.purchase_product_id' => 'nullable',
                'sales_return_products.*.field_values.*.*.stock_product_id' => 'nullable',
                'sales_return_products.*.field_values.*.*.stock_transfer_id' => 'nullable',
                'sales_return_products.*.field_values.*.*.stock_reconciliation_id' => 'nullable',
                'sales_return_products.*.field_values.*.*.stock_adjustment_id' => 'nullable',
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
            $validated['branch_id'] = $request->branch_id;

            $salesReturn = DB::transaction(function () use ($validated, $id) {

                $measureUnits = MeasureUnit::whereIn('id', collect($validated['sales_return_products'] ?? [])
                    ->pluck('measure_unit_id')
                    ->unique()
                    ->toArray())
                    ->get()
                    ->keyBy('id')
                    ->map(function ($unit) {
                        return (object) ['quantity' => $unit->quantity ?? 1];
                    })->toArray();

                // Fetch the sales return
                $salesReturn = SalesReturn::where('id', $id)
                    ->where('company_id', $validated['company_id'])
                    ->where('branch_id', $validated['branch_id'])
                    ->whereNull('deleted_at')
                    ->first();

                if (!$salesReturn) {
                    throw new \Exception("Sales return ID {$id} not found");
                }

                // Delete existing sales return products and their field values
                $productsToDelete = SalesReturnProduct::where('sales_return_id', $salesReturn->id)
                    ->where('company_id', $validated['company_id'])
                    ->where('branch_id', $validated['branch_id'])
                    ->get();

                if ($productsToDelete->isNotEmpty()) {
                    $productsToDelete->each(function ($product) {
                        SaleReturnProductFieldValue::where('sale_return_product_id', $product->id)
                            ->where('company_id', $product->company_id)
                            ->where('branch_id', $product->branch_id)
                            ->delete();
                    });

                    SalesReturnProduct::where('sales_return_id', $salesReturn->id)
                        ->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['branch_id'])
                        ->delete();
                }

                

                SaleReturnAdditional::where('sales_return_id', $salesReturn->id)
                    ->where('company_id', $validated['company_id'])
                    ->where('branch_id', $validated['branch_id'])
                    ->delete();

               

                // Fetch all sale products for the company
                $allSaleProducts = SaleProduct::where('company_id', $validated['company_id'])
                    ->where('branch_id', $validated['branch_id'])
                    ->whereNull('deleted_at')
                    ->with(['measureUnit', 'saleProductReturns' => fn($q) => $q->where('company_id', $validated['company_id'])->whereNull('deleted_at')])
                    ->get();

                if ($allSaleProducts->isEmpty()) {
                    throw new \Exception("No products found for the company");
                }

                $saleProductIds = $allSaleProducts->pluck('id')->toArray();

                $returnedFieldValues = DB::table('sale_return_product_field_values')
                    ->whereIn('sale_product_id', $saleProductIds)
                    ->where('company_id', $validated['company_id'])
                    ->where('branch_id', $validated['branch_id'])
                    ->whereNull('deleted_at')
                    ->select('sale_product_id', 'quantity_index')
                    ->get()
                    ->groupBy('sale_product_id')
                    ->map(function ($group) {
                        return $group->pluck('quantity_index')->unique()->toArray();
                    })->toArray();

                $availableFieldValues = DB::table('sales_product_field_values')
                    ->whereIn('sale_product_id', $saleProductIds)
                    ->where('company_id', $validated['company_id'])
                    ->where('branch_id', $validated['branch_id'])
                    ->whereNull('deleted_at')
                    ->select(['sale_product_id', 'product_field_id', 'quantity_index', 'value'])
                    ->get()
                    ->groupBy('sale_product_id')
                    ->map(function ($group) use ($returnedFieldValues) {
                        $saleProductId = $group->first()->sale_product_id;
                        $returnedIndices = $returnedFieldValues[$saleProductId] ?? [];
                        return $group->groupBy('quantity_index')
                            ->filter(function ($fvGroup, $quantityIndex) use ($returnedIndices) {
                                return !in_array((int) $quantityIndex, $returnedIndices);
                            })
                            ->map(function ($fvGroup) {
                                return $fvGroup->map(function ($fv) {
                                    return [
                                        'product_field_id' => $fv->product_field_id,
                                        'value' => $fv->value,
                                    ];
                                })->toArray();
                            })->toArray();
                    })->toArray();

                $salesReturnProducts = [];

                foreach ($validated['sales_return_products'] as $index => $product) {
                    if (empty($product['product_id']) && empty($product['product_name']) && empty($product['barcode'])) {
                        throw new \Exception("At least one of product_id, product_name, or barcode must be provided for sales_return_products at index {$index}");
                    }

                    $measureUnitId = $product['measure_unit_id'];
                    $returnMeasureUnitData = $measureUnits[$measureUnitId] ?? MeasureUnit::find($measureUnitId);
                    $returnMeasureUnitQuantity = $returnMeasureUnitData->quantity ?? 1;

                    if (!$returnMeasureUnitQuantity) {
                        throw new \Exception("Invalid measure unit ID {$measureUnitId} at index {$index}");
                    }

                    // Calculate expected pieces
                    $expectedRegular = (float) ($product['quantity'] ?? 0);
                    $expectedFree = (float) ($product['free_quantity'] ?? 0);
                    $expectedRegularPieces = $this->calculatePieces($expectedRegular, $returnMeasureUnitQuantity);
                    $expectedFreePieces = $this->calculatePieces($expectedFree, $returnMeasureUnitQuantity);

                    $expectedTotalPieces = $expectedRegularPieces + $expectedFreePieces;

                 

                    // Validate field values against total pieces
                    $fieldValuesFlat = !empty($product['field_values']) ? $this->flattenFieldValues($product['field_values'], $index) : [];
                    $totalFieldValueSets = count($product['field_values']);

                    if (!empty($fieldValuesFlat) && $totalFieldValueSets != $expectedTotalPieces) {
                        throw new \Exception("Field values total quantity mismatch at index {$index}. Expected total: {$expectedTotalPieces} pieces; Found: {$totalFieldValueSets} pieces");
                    }

                    // Validate field values against available sale product field values
                    if (!empty($fieldValuesFlat)) {
                        foreach ($fieldValuesFlat as $fv) {
                            $saleProductId = $fv['sale_product_id'];
                            $quantityIndex = $fv['quantity_index'];
                            $availableFv = $availableFieldValues[$saleProductId][$quantityIndex] ?? [];

                            if (empty($availableFv)) {
                                throw new \Exception("Invalid field value for sale_product_id {$saleProductId} at quantity_index {$quantityIndex} at index {$index}. Not available for return.");
                            }

                            $fvMatch = collect($availableFv)->firstWhere(function ($available) use ($fv) {
                                return $available['product_field_id'] == $fv['product_field_id'] &&
                                    $available['value'] == $fv['value'];
                            });

                            if (!$fvMatch) {
                                throw new \Exception("Field value mismatch for sale_product_id {$saleProductId} at quantity_index {$quantityIndex} at index {$index}.");
                            }
                        }
                    }

                    $filteredSaleProducts = $allSaleProducts->filter(function ($saleProduct) use ($product) {
                        return ($product['product_id'] && $saleProduct->product_id == $product['product_id']) ||
                            ($product['product_name'] && str_contains(strtolower($saleProduct->name), strtolower($product['product_name']))) ||
                            ($product['barcode'] && $saleProduct->product_code == $product['barcode']);
                    })->sortBy('created_at');

                    if ($filteredSaleProducts->isEmpty()) {
                        throw new \Exception("No sale products found for product criteria at index {$index}");
                    }

                    $hasFieldValues = !empty($product['field_values']);
                    $saleProductIds = $hasFieldValues ? array_unique(array_column($fieldValuesFlat, 'sale_product_id')) : [];

                    if (!$hasFieldValues && isset($product['sale_product_id'])) {
                        $saleProductIds = [$product['sale_product_id']];
                    }

                    if (empty($saleProductIds) && $hasFieldValues) {
                        throw new \Exception("No valid sale product IDs found in field values at index {$index}");
                    }

                    if (empty($saleProductIds)) {
                        // Apply strict FIFO: Track allocated pieces across all products in this payload
                        static $allocatedPiecesBySaleProduct = [];

                        $fifoSaleProducts = $filteredSaleProducts->filter(function ($saleProduct) use ($validated, $measureUnits, &$allocatedPiecesBySaleProduct) {
                            $measureUnitID = $saleProduct->measure_unit_id ?? 1;
                            $measureUnit = MeasureUnit::where('id', $measureUnitID)->first();
                            $measureUnitsCalc = $measureUnit->quantity ?? 1;

                            $availablePieces = $this->calculateAvailablePiecesForfifo($saleProduct, $validated['company_id'], $validated['branch_id'], $measureUnitsCalc);
                            // Subtract previously allocated pieces in this request
                            $previouslyAllocated = $allocatedPiecesBySaleProduct[$saleProduct->id] ?? 0;
                            $remainingAvailable = $availablePieces - $previouslyAllocated;
                         
                            return $remainingAvailable > 0;
                        })->sortBy('created_at')->values();

                        if ($fifoSaleProducts->isEmpty()) {
                            throw new \Exception("No available sale product found for criteria at index {$index}");
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

                            $availablePiecesFifo = $this->calculateAvailablePiecesForFifo($saleProduct, $validated['company_id'], $validated['branch_id'], $saleMeasureUnitQuantity);
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
                            throw new \Exception("Insufficient stock for product at index {$index}. Requested: {$expectedTotalPieces} pieces (regular: {$expectedRegularPieces}, free: {$expectedFreePieces}), Allocated: " . ($expectedRegularPieces - $remainingRegularPieces) . " regular, " . ($expectedFreePieces - $remainingFreePieces) . " free");
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
                                        'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                        'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                        'stock_product_id' => $fv['stock_product_id'] ?? null,
                                        'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                        'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                                        'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,


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
                            throw new \Exception("Invalid sale product ID {$saleProductId} at index {$index}");
                        }

                        if ($hasFieldValues) {
                            $MeasureUnitId = $saleProduct->measure_unit_id ?? null;
                            $measureUnitDataName = MeasureUnit::where('id', $MeasureUnitId)->first();
                            $MeasureUnitQuantityUsed = $measureUnitDataName->quantity ?? 1;

                            $availablePieces = $this->calculateAvailablePiecesForfifo($saleProduct, $validated['company_id'], $validated['branch_id'], $MeasureUnitQuantityUsed);
                        } else {
                            $saleMeasureUnit = $saleProduct->measure_unit_id ?? 1;
                            $measureUnitId = MeasureUnit::where('id', $saleMeasureUnit)->first();
                            $saleMeasureUnitQuantity = $measureUnitId->quantity ?? 0;

                            $availablePieces = $this->calculateAvailablePiecesForFifo($saleProduct, $validated['company_id'], $validated['branch_id'], $saleMeasureUnitQuantity);
                        }

                        if ($hasFieldValues) {
                            $fvByIndex = $groupedFieldValues[$saleProductId] ?? [];
                            $totalFieldValueSetsForProduct = count($fvByIndex);

                            if ($totalFieldValueSetsForProduct == 0) {
                                throw new \Exception("No valid field value sets for sale product ID {$saleProductId} at index {$index}");
                            }

                            if ($totalFieldValueSetsForProduct > $availablePieces) {
                                throw new \Exception("Insufficient stock for sale product ID {$saleProductId} at index {$index}. Requested: {$totalFieldValueSetsForProduct}, Available: {$availablePieces}");
                            }

                            $regularFieldValueSets = collect($fvByIndex)
                                ->filter(function ($fvSet) {
                                    return collect($fvSet)->first()['quantity_type'] === 'regular';
                                })->count();
                            $freeFieldValueSets = collect($fvByIndex)
                                ->filter(function ($fvSet) {
                                    return collect($fvSet)->first()['quantity_type'] === 'free';
                                })->count();

                            [$quantity, $freeQuantity] = $this->convertToTargetMeasureUnit(
                                $regularFieldValueSets,
                                $freeFieldValueSets,
                                $returnMeasureUnitQuantity
                            );
                            $fieldValues = $fvByIndex;

                         
                        } else {
                            $allocatedRegularPieces = $allocations[$saleProductId]['regular_pieces'] ?? 0;
                            $allocatedFreePieces = $allocations[$saleProductId]['free_pieces'] ?? 0;
                            $allocatedTotalPieces = $allocatedRegularPieces + $allocatedFreePieces;

                            if ($allocatedTotalPieces > $availablePieces) {
                                throw new \Exception("Insufficient stock for sale product ID {$saleProductId} at index {$index}. Requested: {$allocatedTotalPieces}, Available: {$availablePieces}");
                            }

                            [$quantity, $freeQuantity] = $this->convertToTargetMeasureUnit(
                                $allocatedRegularPieces,
                                $allocatedFreePieces,
                                $returnMeasureUnitQuantity
                            );
                            $fieldValues = [];

                      
                        }

                        $salesReturnProducts[] = [
                            'sale_product_id' => $saleProductId,
                            'purchase_stock_product_id' => $product['purchase_stock_product_id'] ?? $saleProduct->purchase_stock_product_id,
                            'purchase_product_id' => $product['purchase_product_id'] ?? null,
                            'stock_product_id' => $product['stock_product_id'] ?? null,
                            'stock_transfer_id' => $product['stock_transfer_id'] ?? null,
                            'stock_adjustment_id' => $product['stock_adjustment_id'] ?? null,
                            'stock_reconciliation_id' => $product['stock_reconciliation_id'] ?? null,
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

                $validated['sales_return_products'] = $salesReturnProducts;
                if (empty($salesReturnProducts)) {
                    throw new \Exception("No valid products available for return");
                }

                // Set sale-related fields
                $validated['batch_no'] = $validated['batch_no'] ?? null;
                $validated['customer_id'] = $validated['customer_id'] ?? null;
                $validated['salesman_id'] = $validated['salesman_id'] ?? null;
                $validated['store_id'] = $validated['store_id'] ?? null;
                $validated['location_id'] = $validated['location_id'] ?? null;

                // Prepare sales return additionals
                $salesReturnAdditionalsData = $validated['sales_return_additional'] ?? null;
                if (!$salesReturnAdditionalsData) {
                    $salesReturnAdditionalsData = [
                        'return_code' => 'RET-' . now()->format('YmdHis'),
                        'return_date' => now()->toDateString(),
                        'return_time' => now()->toTimeString(),
                    ];
                } else {
                    $salesReturnAdditionalsData = array_merge([
                        'return_code' => 'RET-' . now()->format('YmdHis'),
                        'return_date' => now()->toDateString(),
                        'return_time' => now()->toTimeString(),
                    ], array_filter($salesReturnAdditionalsData, fn($value) => !is_null($value)));
                }

                // Update sales return
                $salesReturnData = array_intersect_key($validated, array_flip((new SalesReturn)->getFillable()));
                $salesReturn->update($salesReturnData);

                // Create new sales return products
                foreach ($validated['sales_return_products'] as $product) {
                    $saleProduct = SaleProduct::find($product['sale_product_id']);
                    if (!$saleProduct) {
                        throw new \Exception("Sale product ID {$product['sale_product_id']} not found");
                    }

                    $salesReturnProduct = $salesReturn->salesReturnProducts()->create([
                        'company_id' => $validated['company_id'],
                        'purchase_type' => $validated['purchase_type'] ?? null,
                        'branch_id' => $validated['branch_id'],
                        'sales_return_id' => $salesReturn->id,
                        'sale_id' => $saleProduct->sale_id,
                        'sale_product_id' => $product['sale_product_id'],
                        'purchase_stock_product_id' => $product['purchase_stock_product_id'] ?? $saleProduct->purchase_stock_product_id,
                        'stock_product_id' => $product['stock_product_id'] ?? null,
                        'stock_transfer_id' => $product['stock_transfer_id'] ?? null,
                        'stock_reconciliation_id' => $product['stock_reconciliation_id'] ?? null,
                        'purchase_product_id' => $product['purchase_product_id'] ?? null,
                        'stock_adjustment_id' => $product['stock_adjustment_id'] ?? null,
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
                                    'branch_id' => $validated['branch_id'],
                                    'product_field_id' => $fieldValue['product_field_id'],
                                    'product_id' => $salesReturnProduct->product_id,
                                    'sale_return_product_id' => $salesReturnProduct->id,
                                    'sale_product_id' => $fieldValue['sale_product_id'],
                                    'purchase_stock_product_id' => $fieldValue['purchase_stock_product_id'],
                                    'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                                    'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                                    'stock_reconciliation_id' => $fieldValue['stock_reconciliation_id'] ?? null,
                                    'stock_adjustment_id' => $fieldValue['stock_adjustment_id'] ?? null,
                                    'stock_transfer_id' => $fieldValue['stock_transfer_id'] ?? null,
                                    'quantity_index' => $quantityIndex,
                                    'value' => $fieldValue['value'],
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ];
                            }
                        }
                        DB::table('sale_return_product_field_values')->insert($fieldValues);
                        
                    }
                }

                // Create sales return additional
                SaleReturnAdditional::create([
                    'company_id' => $validated['company_id'],
                    'branch_id' => $validated['branch_id'],
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
                'message' => 'Sales return updated successfully',
                'data' => $salesReturn->load([
                    'salesReturnProducts.fieldValues' => fn($query) => $query->orderBy('quantity_index')->orderBy('product_field_id'),
                    'salesReturnAdditional',
                ]),
            ], 200);
        } catch (ModelNotFoundException $e) {
           
            return response()->json(['error' => 'Resource not found: ' . $e->getMessage()], 404);
        } catch (QueryException $e) {
            
            return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function sumQuantityAndFree($quantity, $freeQuantity): string
    {
        $quantity = (string) ($quantity ?? '0');
        $freeQuantity = (string) ($freeQuantity ?? '0');

        // Determine max number of decimals
        $decimals = max(
            strlen(explode('.', $quantity)[1] ?? ''),
            strlen(explode('.', $freeQuantity)[1] ?? '')
        );

        return bcadd($quantity, $freeQuantity, $decimals); // string with preserved decimals
    }


    public function calculatePieces(string $quantity, float $measureUnitQuantity): float
    {
        if ($measureUnitQuantity <= 0) {
            
            return 0;
        }

        // Split integer and decimal parts WITHOUT float
        [$integerPart, $decimalPart] = array_pad(explode('.', $quantity), 2, '0');

        $integer = (int) $integerPart;
        $decimalPieces = (int) $decimalPart;

        return ($integer * $measureUnitQuantity) + $decimalPieces;
    }

    private function convertToTargetMeasureUnit(float $regularPieces, float $freePieces, float $targetMeasureUnitQuantity): array
    {
        if ($targetMeasureUnitQuantity <= 0) {
           
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

       

        return [$regularQuantity, $freeQuantity];
    }

    private function flattenFieldValues($fieldValues, $index): array
    {
        $flat = [];
        foreach ($fieldValues as $fvSet) {
            foreach ($fvSet as $fv) {
                $flat[] = [
                    'sale_product_id' => $fv['sale_product_id'] ?? throw new \Exception("Missing sale_product_id in field values at index {$index}."),
                    'purchase_stock_product_id' => $fv['purchase_stock_product_id'] ?? throw new \Exception("Missing purhcase_stock_product_id in field values at index {$index}."),
                    'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                    'stock_product_id' => $fv['stock_product_id'] ?? null,
                    'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                    'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                    'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                    'product_field_id' => $fv['product_field_id'] ?? throw new \Exception("Missing product_field_id in field values at index {$index}."),
                    'value' => $fv['value'] ?? throw new \Exception("Missing value in field values at index {$index}."),
                    'quantity_index' => $fv['quantity_index'] ?? throw new \Exception("Missing quantity_index in field values at index {$index}."),
                    'quantity_type' => $fv['quantity_type'] ?? 'regular',
                ];
            }
        }
        
        return $flat;
    }


    private function calculateAvailablePieces($saleProduct, int $companyId, int $branchId, $measureUnitsCalc): int
    {
        $measureUnitQuantity = $measureUnitsCalc[$saleProduct->measure_unit_id]->quantity ?? 1;

        $regularPieces = $this->calculatePieces($saleProduct->quantity ?? 0, $measureUnitQuantity);
        $freePieces = $this->calculatePieces($saleProduct->free_quantity ?? 0, $measureUnitQuantity);
        $totalSoldPieces = $regularPieces + $freePieces;

        $returnedPieces = $saleProduct->saleProductReturns()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
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
            
        }

      

        return max(0, (int) $availablePieces);
    }

    private function calculateAvailablePiecesStore($saleProduct, int $companyId, int $branchId, $measureUnitsCalc): int
    {


        $regularPieces = $this->calculatePieces($saleProduct->quantity ?? 0, $measureUnitsCalc);
        $freePieces = $this->calculatePieces($saleProduct->free_quantity ?? 0, $measureUnitsCalc);
        $totalSoldPieces = $regularPieces + $freePieces;

        $returnedPieces = $saleProduct->saleProductReturns()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
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
           
        }

        
        return max(0, (int) $availablePieces);
    }


    private function calculateAvailablePiecesForFifo($saleProduct, int $companyId, int $branchId, $measureUnitsCalc): int
    {

        $saleMeasureUnitQuantity = $measureUnitsCalc ?? 1;


     

        if ($saleMeasureUnitQuantity <= 0) {
            
            return 0;
        }


        $regularPieces = $this->calculatePieces($saleProduct->quantity ?? 0, $saleMeasureUnitQuantity);
        $freePieces = $this->calculatePieces($saleProduct->free_quantity ?? 0, $saleMeasureUnitQuantity);
        $totalSoldPieces = $regularPieces + $freePieces;

        $returnedPieces = $saleProduct->saleProductReturns()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
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
           
        }

       

        return max(0, (int) $availablePieces);
    }



    public function getItemByBillNumber($billNumber): JsonResponse
    {
        try {
            $purchase = SalesReturn::where('id', $billNumber)->firstOrFail();
            return $this->show($purchase->id);
        } catch (ModelNotFoundException $e) {
           
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
           
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }








    private function getFieldValuesGroupedByQuantityIndex($fieldValues)
    {
        return $fieldValues->groupBy('quantity_index')->map(function ($group) {
            return $group->map(function ($field) {
                return [
                    'product_field_id' => $field->product_field_id,
                    'purchase_stock_product_id' => $field->sale_product_id ?? null,
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
            

            $validator = Validator::make($request->all(), [
                'company_id' => 'nullable',
                'customer_id' => 'nullable|exists:customers,id',
                'customer_name' => 'required|string|max:255',
                'purchase_type' => 'nullable|string|max:255',
                'customer_address' => 'nullable|string|max:255',
                'customer_contact' => 'nullable|string|max:255',
                'credit_days' => 'nullable|string|max:255',
                'salesman_id' => 'nullable|exists:salesmen,id',
                'invoice_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('sales_returns', 'invoice_number')->ignore($id),
                ],
                'document_number' => 'nullable|string|max:255',
                'ref_bill_no' => 'nullable|string|max:255',
                'return_bill_no' => 'nullable|string|max:255',

                'batch_no' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('sales_returns', 'batch_no')->ignore($id),
                ],
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
                'sub_total_before_discount' => 'nullable|numeric',
                'vat_amount' => 'nullable|numeric',
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
                    Rule::exists('sales', 'id')->where(fn($q) => $q->where('company_id', $request->company_id)->whereNull('deleted_at')),
                ],
                'sale_invoice_number' => [
                    'required_without:sale_id',
                    'string',
                    'max:255',
                    Rule::exists('sales', 'invoice_number')->where(fn($q) => $q->where('company_id', $request->company_id)->whereNull('deleted_at')),
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
                'sales_return_products.*.amount' => [
                    'nullable',
                    'numeric',
                    'min:0',
                ],
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
                'sales_return_products.*.field_values.*.*.purchase_stock_product_id' => [
                    'required_if:sales_return_products.*.field_values,array',
                    'integer',
                    'exists:purchase_stock_products,id'
                ],
                'sales_return_products.*.field_values.*.*.purchase_product_id' => ['nullable'],
                'sales_return_products.*.field_values.*.*.stock_product_id' => ['nullable'],
                'sales_return_products.*.field_values.*.*.stock_reconciliation_id' => ['nullable'],
                'sales_return_products.*.field_values.*.*.stock_adjustment_id' => ['nullable'],
                'sales_return_products.*.field_values.*.*.stock_transfer_id' => ['nullable'],
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
               
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $validated['branch_id'] = $request->branch_id;
          

           

            $result = DB::transaction(function () use ($validated, $id) {
                $salesReturn = SalesReturn::where('id', $id)
                    ->where('company_id', $validated['company_id'])
                    ->where('branch_id', $validated['branch_id'])
                    ->whereNull('deleted_at')
                    ->first();

                if (!$salesReturn) {
                  
                    return response()->json(['error' => 'Sales return not found'], 404);
                }
               

                $saleQuery = Sale::when(isset($validated['sale_id']), fn($q) => $q->where('id', $validated['sale_id']))
                    ->when(isset($validated['sale_invoice_number']), fn($q) => $q->where('invoice_number', $validated['sale_invoice_number']))
                    ->where('company_id', $validated['company_id'])
                    ->where('branch_id', $validated['branch_id'])
                    ->whereNull('deleted_at');

                $sale = $saleQuery->first();

                if (!$sale) {
                    
                    return response()->json(['error' => 'Sale not found'], 404);
                }
                

                $validated['sale_id'] = $sale->id;

                // Delete existing sales return products and their field values
                $productsToDelete = SalesReturnProduct::where('sales_return_id', $salesReturn->id)
                    ->where('company_id', $validated['company_id'])
                    ->where('branch_id', $validated['branch_id'])
                    ->get();

                if ($productsToDelete->isNotEmpty()) {
                    $productsToDelete->each(function ($product) {
                        SaleReturnProductFieldValue::where('sale_return_product_id', $product->id)
                            ->where('company_id', $product->company_id)
                            ->where('branch_id', $product->branch_id)
                            ->delete();
                    });

                    SalesReturnProduct::where('sales_return_id', $salesReturn->id)
                        ->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['branch_id'])
                        ->delete();
                }

               

                $measureUnits = MeasureUnit::whereIn('id', collect($validated['sales_return_products'])
                    ->pluck('measure_unit_id')
                    ->merge($sale->saleProducts->pluck('measure_unit_id'))
                    ->unique())
                    ->get()
                    ->keyBy('id')
                    ->map(fn($u) => (object) ['quantity' => $u->quantity ?? 1])
                    ->toArray();

               

                $saleProducts = $sale->saleProducts()
                    ->where('company_id', $validated['company_id'])
                    ->where('branch_id', $validated['branch_id'])
                    ->whereNull('deleted_at')
                    ->with([
                        'measureUnit',
                        'saleProductReturns' => fn($q) => $q
                            ->where('company_id', $validated['company_id'])
                            ->where('branch_id', $validated['branch_id'])
                            ->whereNull('deleted_at')
                    ])
                    ->get()
                    ->keyBy('id');

                if ($saleProducts->isEmpty()) {
                   
                    return response()->json(['error' => 'No products found in this sale'], 404);
                }
              

                $salesReturnProducts = [];
                $exhaustedSaleProductIds = [];

                foreach ($validated['sales_return_products'] as $index => $product) {
                    $productId = $product['product_id'];
                    $measureUnitId = $product['measure_unit_id'];
                    $returnUnitQty = $measureUnits[$measureUnitId]->quantity ?? 1;

                    $regularPieces = $this->calculatePieces($product['quantity'] ?? 0, $returnUnitQty);
                    $freePieces = $this->calculatePieces($product['free_quantity'] ?? 0, $returnUnitQty);
                    $totalRequiredPieces = $regularPieces + $freePieces;

                   

                    // Flatten field_values, similar to store method
                    $fieldValuesFlat = $this->flattenFieldValues($product['field_values'], $index);
                    $hasFieldValues = !empty($fieldValuesFlat);

                    // Group field_values by sale_product_id and quantity_index
                    $groupedFieldValues = collect($fieldValuesFlat)
                        ->groupBy('sale_product_id')
                        ->map(function ($group) {
                            return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                                return collect($fvGroup)->map(function ($fv) {
                                    return [
                                        'sale_product_id' => $fv['sale_product_id'],
                                        'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                        'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                        'stock_product_id' => $fv['stock_product_id'] ?? null,
                                        'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                        'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                                        'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                                        'product_field_id' => $fv['product_field_id'],
                                        'value' => $fv['value'],
                                        'quantity_index' => $fv['quantity_index'],
                                        'quantity_type' => $fv['quantity_type'] ?? 'regular',
                                    ];
                                })->unique(function ($fv) {
                                    return "{$fv['product_field_id']}:{$fv['value']}:{$fv['quantity_type']}";
                                })->values()->toArray();
                            })->toArray();
                        })->toArray();

                  

                    $isFIFO = !$hasFieldValues && !isset($product['sale_product_id']);
                    $saleProductIds = $hasFieldValues ? array_keys($groupedFieldValues) : [];

                    if ($isFIFO) {
                        // FIFO case: Select SaleProduct by product_id, ordered by created_at
                        $candidates = $saleProducts->where('product_id', $productId)
                            ->sortBy('created_at')
                            ->filter(function ($saleProduct) use ($validated, $measureUnits, $id, $exhaustedSaleProductIds) {
                                if (in_array($saleProduct->id, $exhaustedSaleProductIds, true)) {
                                    return false;
                                }
                                try {
                                    $availablePieces = $this->calculateAvailablePiecesforUpdate($saleProduct, $validated['company_id'], $validated['branch_id'], $measureUnits, $id, $exhaustedSaleProductIds);
                                   
                                    return $availablePieces > 0;
                                } catch (\Exception $e) {
                                   
                                    throw $e;
                                }
                            })->values();

                        if ($candidates->isEmpty()) {
                          
                            return response()->json([
                                'error' => "No available sale product found for product ID {$productId} at index {$index}"
                            ], 422);
                        }

                        $remainingReg = $regularPieces;
                        $remainingFree = $freePieces;
                        $consumedPieces = [];

                        foreach ($candidates as $saleProduct) {
                            if ($remainingReg <= 0 && $remainingFree <= 0) {
                                break;
                            }

                            $saleUnitQty = $measureUnits[$saleProduct->measure_unit_id]->quantity ?? 1;
                            try {
                                $availPieces = $this->calculateAvailablePiecesforUpdate(
                                    $saleProduct,
                                    $validated['company_id'],
                                    $validated['branch_id'],
                                    $measureUnits,
                                    $id,
                                    $consumedPieces
                                );
                              
                            } catch (\Exception $e) {
                              
                                return response()->json([
                                    'error' => "Error calculating available pieces for sale product ID {$saleProduct->id}: {$e->getMessage()}"
                                ], 500);
                            }

                            $allocReg = min($remainingReg, $availPieces);
                            $allocFree = min($remainingFree, $availPieces - $allocReg);

                            if ($allocReg > 0 || $allocFree > 0) {
                                [$qty, $freeQty] = $this->convertToTargetMeasureUnit($allocReg, $allocFree, $returnUnitQty);

                                if ($qty > 0 || $freeQty > 0) {
                                    $salesReturnProducts[] = [
                                        'sale_product_id' => $saleProduct->id,
                                        'purchase_stock_product_id' => $saleProduct->purchase_stock_product_id,
                                        'purchase_product_id' => $saleProduct->purchase_product_id ?? null,
                                        'stock_product_id' => $saleProduct->stock_product_id ?? null,
                                        'stock_transfer_id' => $saleProduct->stock_transfer_id ?? null,
                                        'stock_reconciliation_id' => $saleProduct->stock_reconciliation_id ?? null,
                                        'stock_adjustment_id' => $saleProduct->stock_adjustment_id ?? null,
                                        'product_id' => $saleProduct->product_id,
                                        'product_name' => $product['product_name'] ?? $saleProduct->name,
                                        'product_code' => $product['product_code'] ?? $saleProduct->product_code,
                                        'quantity' => $qty,
                                        'free_quantity' => $freeQty,
                                        'price' => $product['price'],
                                        'amount' => $product['amount'] ?? 0,
                                        'discount_percent' => $product['discount_percent'] ?? 0,
                                        'discount_amount' => $product['discount_amount'] ?? 0,
                                        'is_vatable' => $product['is_vatable'] ?? $saleProduct->is_vatable,
                                        'measure_unit_id' => $measureUnitId,
                                        'batch_no' => $product['batch_no'] ?? $saleProduct->batch_no,
                                        'mfd' => $product['mfd'] ?? $saleProduct->mfd,
                                        'expiry_date' => $product['expiry_date'] ?? $saleProduct->expiry_date,
                                        'field_values' => [],
                                    ];

                                    $consumedPieces[$saleProduct->id] = ($consumedPieces[$saleProduct->id] ?? 0) + $allocReg + $allocFree;
                                    $remainingReg -= $allocReg;
                                    $remainingFree -= $allocFree;

                                  
                                }
                            }
                        }

                        if (abs($remainingReg + $remainingFree) > 0.0001) {
                           
                            return response()->json([
                                'error' => "Insufficient stock for product {$productId} at index {$index}. Remaining: Regular {$remainingReg}, Free {$remainingFree}"
                            ], 422);
                        }
                    } else {
                        // Handle field_values or specific sale_product_id case
                        if ($hasFieldValues) {
                            // Validate sale_product_ids in field_values
                            foreach ($saleProductIds as $saleProductId) {
                                if (!$saleProducts->contains('id', $saleProductId)) {
                                   
                                    return response()->json([
                                        'error' => "Invalid sale_product_id {$saleProductId} for product {$productId} at index {$index}"
                                    ], 422);
                                }

                                // Validate consistent quantity_type within sets
                                $sets = $groupedFieldValues[$saleProductId] ?? [];
                                foreach ($sets as $set) {
                                    $quantityType = $set[0]['quantity_type'] ?? 'regular';
                                    foreach ($set as $fv) {
                                        if (($fv['quantity_type'] ?? 'regular') !== $quantityType) {
                                            
                                            return response()->json([
                                                'error' => "Mixed quantity_type in field_values set for product {$productId} at index {$index}"
                                            ], 422);
                                        }
                                    }
                                }

                                $saleProduct = $saleProducts->firstWhere('id', $saleProductId);
                                if (!$saleProduct) {
                                    
                                    return response()->json([
                                        'error' => "Sale product ID {$saleProductId} not found for product {$productId} at index {$index}"
                                    ], 422);
                                }

                                $saleUnitQty = $measureUnits[$saleProduct->measure_unit_id]->quantity ?? 1;

                                // Validate inputs before calling calculateAvailablePiecesforUpdate
                                if (!isset($measureUnits[$saleProduct->measure_unit_id])) {
                                   
                                    return response()->json([
                                        'error' => "Measure unit not found for sale product ID {$saleProductId} at index {$index}"
                                    ], 422);
                                }

                                try {
                                    $availablePieces = $this->calculateAvailablePiecesforUpdate($saleProduct, $validated['company_id'], $validated['branch_id'], $measureUnits, $id, $exhaustedSaleProductIds);
                                    
                                } catch (\Exception $e) {
                                   
                                    return response()->json([
                                        'error' => "Error calculating available pieces for sale product ID {$saleProductId}: {$e->getMessage()}"
                                    ], 500);
                                }

                                $fvByIndex = $groupedFieldValues[$saleProductId] ?? [];
                                $regularFieldValueSets = collect($fvByIndex)
                                    ->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'regular')
                                    ->count();
                                $freeFieldValueSets = collect($fvByIndex)
                                    ->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'free')
                                    ->count();

                                $totalRequestedPiecesForProduct = $regularFieldValueSets + $freeFieldValueSets;

                                if ($totalRequestedPiecesForProduct == 0) {
                                  
                                    return response()->json([
                                        'error' => "No valid field value sets for sale product ID {$saleProductId} at index {$index}"
                                    ], 422);
                                }

                                if ($totalRequestedPiecesForProduct > $availablePieces) {
                                   
                                    return response()->json([
                                        'error' => "Insufficient stock for sale product ID {$saleProductId} at index {$index}. Requested: {$totalRequestedPiecesForProduct}, Available: {$availablePieces}"
                                    ], 422);
                                }

                                [$qty, $freeQty] = $this->convertToTargetMeasureUnit($regularFieldValueSets, $freeFieldValueSets, $returnUnitQty);

                                $salesReturnProducts[] = [
                                    'sale_product_id' => $saleProduct->id,
                                    'purchase_stock_product_id' => $saleProduct->purchase_stock_product_id,
                                    'purchase_product_id' => $saleProduct->purchase_product_id ?? null,
                                    'stock_product_id' => $saleProduct->stock_product_id ?? null,
                                    'stock_transfer_id' => $saleProduct->stock_transfer_id ?? null,
                                    'stock_reconciliation_id' => $saleProduct->stock_reconciliation_id ?? null,
                                    'stock_adjustment_id' => $saleProduct->stock_adjustment_id ?? null,
                                    'product_id' => $saleProduct->product_id,
                                    'product_name' => $product['product_name'] ?? $saleProduct->name,
                                    'product_code' => $product['product_code'] ?? $saleProduct->product_code,
                                    'quantity' => $qty,
                                    'free_quantity' => $freeQty,
                                    'price' => $product['price'],
                                    'amount' => $product['amount'] ?? 0,
                                    'discount_percent' => $product['discount_percent'] ?? 0,
                                    'discount_amount' => $product['discount_amount'] ?? 0,
                                    'is_vatable' => $product['is_vatable'] ?? $saleProduct->is_vatable,
                                    'measure_unit_id' => $measureUnitId,
                                    'batch_no' => $product['batch_no'] ?? $saleProduct->batch_no,
                                    'mfd' => $product['mfd'] ?? $saleProduct->mfd,
                                    'expiry_date' => $product['expiry_date'] ?? $saleProduct->expiry_date,
                                    'field_values' => $fvByIndex,
                                ];

                                $exhaustedSaleProductIds[] = $saleProduct->id;

                              
                            }

                            // Validate field_values match payload quantities
                            $totalFieldValuePieces = collect($saleProductIds)->sum(function ($saleProductId) use ($groupedFieldValues) {
                                return collect($groupedFieldValues[$saleProductId] ?? [])->count();
                            });

                            if ($totalFieldValuePieces != $totalRequiredPieces) {
                              
                            }
                        } else {
                            // Non-field_values case with specific sale_product_id
                            $saleProductId = $product['sale_product_id'] ?? null;
                            if ($saleProductId) {
                                $saleProduct = $saleProducts->firstWhere('id', $saleProductId);
                                if (!$saleProduct || $saleProduct->product_id != $productId) {
                                   
                                    return response()->json([
                                        'error' => "Invalid sale product ID {$saleProductId} for product {$productId} at index {$index}"
                                    ], 422);
                                }

                                $saleUnitQty = $measureUnits[$saleProduct->measure_unit_id]->quantity ?? 1;

                                if (!isset($measureUnits[$saleProduct->measure_unit_id])) {
                                
                                    return response()->json([
                                        'error' => "Measure unit not found for sale product ID {$saleProductId} at index {$index}"
                                    ], 422);
                                }

                                try {
                                    $availablePieces = $this->calculateAvailablePiecesforUpdate($saleProduct, $validated['company_id'], $validated['branch_id'], $measureUnits, $id, $exhaustedSaleProductIds);
                                    
                                } catch (\Exception $e) {
                                   
                                    return response()->json([
                                        'error' => "Error calculating available pieces for sale product ID {$saleProductId}: {$e->getMessage()}"
                                    ], 500);
                                }

                                if ($totalRequiredPieces > $availablePieces) {
                                   
                                    return response()->json([
                                        'error' => "Insufficient stock for sale product ID {$saleProductId} at index {$index}. Requested: {$totalRequiredPieces}, Available: {$availablePieces}"
                                    ], 422);
                                }

                                [$qty, $freeQty] = $this->convertToTargetMeasureUnit($regularPieces, $freePieces, $returnUnitQty);

                                $salesReturnProducts[] = [
                                    'sale_product_id' => $saleProduct->id,
                                    'purchase_stock_product_id' => $saleProduct->purchase_stock_product_id,
                                    'purchase_product_id' => $saleProduct->purchase_product_id ?? null,
                                    'stock_product_id' => $saleProduct->stock_product_id ?? null,
                                    'stock_transfer_id' => $saleProduct->stock_transfer_id ?? null,
                                    'stock_reconciliation_id' => $saleProduct->stock_reconciliation_id ?? null,
                                    'stock_adjustment_id' => $saleProduct->stock_adjustment_id ?? null,
                                    'product_id' => $saleProduct->product_id,
                                    'product_name' => $product['product_name'] ?? $saleProduct->name,
                                    'product_code' => $product['product_code'] ?? $saleProduct->product_code,
                                    'quantity' => $qty,
                                    'free_quantity' => $freeQty,
                                    'price' => $product['price'],
                                    'amount' => $product['amount'] ?? 0,
                                    'discount_percent' => $product['discount_percent'] ?? 0,
                                    'discount_amount' => $product['discount_amount'] ?? 0,
                                    'is_vatable' => $product['is_vatable'] ?? $saleProduct->is_vatable,
                                    'measure_unit_id' => $measureUnitId,
                                    'batch_no' => $product['batch_no'] ?? $saleProduct->batch_no,
                                    'mfd' => $product['mfd'] ?? $saleProduct->mfd,
                                    'expiry_date' => $product['expiry_date'] ?? $saleProduct->expiry_date,
                                    'field_values' => [],
                                ];

                                $exhaustedSaleProductIds[] = $saleProduct->id;

                              
                            } else {
                                
                                return response()->json([
                                    'error' => "No sale_product_id or field_values provided for product {$productId} at index {$index}"
                                ], 422);
                            }
                        }
                    }
                }

                if (empty($salesReturnProducts)) {
                    
                    return response()->json(['error' => 'No valid products available for return'], 422);
                }

               

                return [
                    'salesReturn' => $salesReturn,
                    'sale' => $sale,
                    'salesReturnProducts' => $salesReturnProducts
                ];
            });

            if ($result instanceof JsonResponse) {
                return $result;
            }

            $salesReturn = $result['salesReturn'];
            $sale = $result['sale'];
            $salesReturnProducts = $result['salesReturnProducts'];

           

            $salesReturn = DB::transaction(function () use ($validated, $salesReturn, $salesReturnProducts) {
                if (empty($salesReturnProducts)) {
                    
                    return response()->json(['error' => 'No sales return products to persist'], 422);
                }

                $salesReturn->update([
                    'company_id' => $validated['company_id'],
                    'purchase_type' => $validated['purchase_type'] ?? null,
                    'branch_id' => $validated['branch_id'],
                    'customer_id' => $validated['customer_id'] ?? null,
                    'customer_name' => $validated['customer_name'],
                    'customer_address' => $validated['customer_address'] ?? null,
                    'customer_contact' => $validated['customer_contact'] ?? null,
                    'salesman_id' => $validated['salesman_id'] ?? null,
                    'invoice_number' => $validated['invoice_number'] ?? null,
                    'sales_bill_number,' => $validated['sale_invoice_number'] ?? null,
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
                    'discount_after_vat' => $validated['discount_after_vat'] ?? null,
                    'non_taxable_amount' => $validated['non_taxable_amount'] ?? null,
                    'taxable_amount' => $validated['taxable_amount'] ?? null,
                    'sub_total_before_discount' => $validated['sub_total_before_discount'] ?? null,
                    'vat_amount' => $validated['vat_amount'] ?? null,
                    'total_amount' => $validated['total_amount'] ?? null,
                    'round_of_amount' => $validated['round_of_amount'] ?? null,
                    'roundoff_type' => $validated['roundoff_type'] ?? null,
                    'payment_type' => $validated['payment_type'] ?? null,
                    'sale_id' => $validated['sale_id'],
                    'payment' => [
                        'cash' => $validated['payment']['cash'] ?? null,
                        'credit' => $validated['payment']['credit'] ?? null,
                        'bank' => $validated['payment']['bank'] ?? null,
                    ],
                ]);

               

                $existing = SaleReturnAdditional::where('sales_return_id', $salesReturn->id)
                    ->where('company_id', $validated['company_id'])
                    ->where('branch_id', $validated['branch_id'])
                    ->first();

                if (!empty($validated['sales_return_additional'])) {
                    $data = $validated['sales_return_additional'];
                    if ($existing) {
                        $existing->update($data);
                        
                    } else {
                        SaleReturnAdditional::create(array_merge($data, [
                            'company_id' => $validated['company_id'],
                            'branch_id' => $validated['branch_id'],
                            'sales_return_id' => $salesReturn->id,
                        ]));
                        
                    }
                } elseif ($existing) {
                    $existing->delete();
                    
                }

                foreach ($salesReturnProducts as $index => $row) {
                    $srProduct = $salesReturn->salesReturnProducts()->create([
                        'company_id' => $validated['company_id'],
                        'purchase_type' => $validated['purchase_type'] ?? null,
                        'branch_id' => $validated['branch_id'],
                        'sale_id' => $validated['sale_id'],
                        'sale_product_id' => $row['sale_product_id'],
                        'purchase_stock_product_id' => $row['purchase_stock_product_id'],
                        'purchase_product_id' => $row['purchase_product_id'] ?? null,
                        'stock_product_id' => $row['stock_product_id'] ?? null,
                        'stock_transfer_id' => $row['stock_transfer_id'] ?? null,
                        'stock_reconciliation_id' => $row['stock_reconciliation_id'] ?? null,
                        'stock_adjustment_id' => $row['stock_adjustment_id'] ?? null,
                        'product_id' => $row['product_id'],
                        'product_name' => $row['product_name'],
                        'product_code' => $row['product_code'],
                        'quantity' => $row['quantity'],
                        'free_quantity' => $row['free_quantity'],
                        'price' => $row['price'],
                        'amount' => $row['amount'],
                        'discount_percent' => $row['discount_percent'],
                        'discount_amount' => $row['discount_amount'],
                        'is_vatable' => $row['is_vatable'],
                        'measure_unit_id' => $row['measure_unit_id'],
                        'batch_no' => $row['batch_no'],
                        'mfd' => $row['mfd'],
                        'expiry_date' => $row['expiry_date'],
                    ]);

                    

                    if (!empty($row['field_values'])) {
                        SaleReturnProductFieldValue::where('sale_return_product_id', $srProduct->id)
                            ->where('company_id', $validated['company_id'])
                            ->where('branch_id', $validated['branch_id'])
                            ->delete();

                       

                        $fieldValues = [];
                        foreach ($row['field_values'] as $fvSet) {
                            foreach ($fvSet as $fv) {
                                $fieldValues[] = [
                                    'company_id' => $validated['company_id'],
                                    'branch_id' => $validated['branch_id'],
                                    'sale_return_product_id' => $srProduct->id,
                                    'sale_product_id' => $fv['sale_product_id'],
                                    'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                    'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                    'stock_product_id' => $fv['stock_product_id'] ?? null,
                                    'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                                    'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                    'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                                    'product_id' => $row['product_id'],
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
                            
                        }
                    }
                }

                return $salesReturn;
            });

            

            return response()->json([
                'message' => 'Sales return updated successfully',
                'data' => $salesReturn->load([
                    'salesReturnProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index', 'asc')->orderBy('product_field_id', 'asc');
                    },
                    'salesReturnAdditional',
                ]),
            ], 200);
        } catch (ModelNotFoundException $e) {
            
            return response()->json(['error' => 'Sale not found'], 404);
        } catch (QueryException $e) {
          
            return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }





    private function calculateAvailablePiecesforUpdate(
        $saleProduct,
        int $companyId,
        int $branchId,

        array $measureUnits,
        ?int $excludeReturnId = null,
        array &$consumedInRequest = []
    ): float {
        $unitQty = $measureUnits[$saleProduct->measure_unit_id]->quantity ?? 1;

        // 1) Pieces sold in this sale product
        $sold = $this->calculatePieces($saleProduct->quantity, $unitQty)
            + $this->calculatePieces($saleProduct->free_quantity ?? 0, $unitQty);

        // 2) Pieces returned for this sale product (excluding the current return)
        $returned = $saleProduct->saleProductReturns()
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->when($excludeReturnId, fn($q) => $q->where('sales_return_id', '!=', $excludeReturnId))
            ->with('measureUnit')
            ->get()
            ->reduce(
                fn($carry, $ret) =>
                $carry
                + $this->calculatePieces($ret->quantity ?? 0, $ret->measureUnit->quantity ?? 1)
                + $this->calculatePieces($ret->free_quantity ?? 0, $ret->measureUnit->quantity ?? 1),
                0
            );

        // 3) Get the pieces used in the current request
        $usedInThis = $consumedInRequest[$saleProduct->id] ?? 0;

        // Calculate the available pieces
        $available = max(0, ($sold - $returned) - $usedInThis);

       
        return $available;
    }



    public function show(Request $request, $id): JsonResponse
    {
        try {
            // Step 1: Load Sales Return with relations
            $salesReturn = SalesReturn::with('salesReturnProducts.fieldValues.productField')
                ->findOrFail($id);




            $productIds = $salesReturn->salesReturnProducts->pluck('product_id')->unique();

            // Step 2: Load measure units
            $productMeasureUnits = ProductList::whereIn('product_id', $productIds)
                ->where('company_id', $request->company_id)
                ->with(['measureUnit:id,name,quantity'])
                ->get()
                ->groupBy('product_id');

            $request->merge([
                'company_id' => $request->company_id ?? null,
                'branch_id' => $request->branch_id ?? null,
            ]);

            $salesBillNumber = $salesReturn->sales_bill_number;

            // Step 3: Get available quantities
            $availableMap = [];

            if ($salesBillNumber) {
                // Bill-wise
                $response = AvailableQuantityService::getSaleByInvoiceNumber($request, $salesBillNumber);
                $responseData = $response->getData(true);
                $products = $responseData['data'][0]['products'] ?? ($responseData['data'] ?? []);
                foreach ($products as $p) {
                    $availableMap[$p['product_id']] = $p['available_quantity'] ?? 0;
                }
            } else {
                // Product-wise
                foreach ($salesReturn->salesReturnProducts as $item) {
                    $productID = $item->product_id;
                    $response = AvailableQuantityService::getAvailableProductsForSalesReturn($request, $productID, $salesBillNumber);
                    $resp = $response->getData(true);
                    $products = $resp['data'][0]['products'] ?? ($resp['data'] ?? []);
                    foreach ($products as $p) {
                        $availableMap[$p['product_id']] = $p['available_quantity'] ?? 0;
                    }
                }
            }

            // Step 4: Merge products with same (sales_return_id, product_id, measureunit_id)
            $mergedProducts = $salesReturn->salesReturnProducts
                ->groupBy(function ($item) {
                    return $item->sales_return_id . '-' . $item->product_id . '-' . $item->measureunit_id;
                })
                ->map(function ($group) {
                    $first = $group->first();
                    $first->quantity = $group->sum('quantity');

                    // Merge all field values together
                    $allFieldValues = $group->flatMap(function ($item) {
                        return $item->fieldValues;
                    })->unique('id')->values();

                    // Sort field values by purchase_stock_product_id, then by quantity_index
                    $sortedFieldValues = $allFieldValues->sortBy([
                        ['purchase_stock_product_id', 'asc'],
                        ['quantity_index', 'asc']
                    ])->values();

                    $first->setRelation('fieldValues', $sortedFieldValues);

                    return $first;
                })
                ->values();


            foreach ($mergedProducts as $saleReturn) {
                $units = $productMeasureUnits->get($saleReturn->product_id, collect())
                    ->pluck('measureUnit');
                $saleReturn->setRelation('measure_units', $units);

                $productId = $saleReturn->product_id;
                $productCode = Product::where('id', $productId)->value('product_unique_id');


                $saleReturn->setRelation(
                    'field_values',
                    $saleReturn->fieldValues->map(function ($fv) {
                        $fv->name = $fv->productField->name ?? null;
                        return $fv;
                    })->values()
                );

                $saleReturn->product_code = $productCode;
                $saleReturn->remaining_quantity = $availableMap[$saleReturn->product_id] ?? 0;
            }


            $salesReturn->setRelation('salesReturnProducts', $mergedProducts);


            return response()->json($salesReturn);

        } catch (ModelNotFoundException $e) {
           
            return response()->json(['error' => 'Sales Return not found'], 404);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }



    public function destroy($id): JsonResponse
    {
        try {
            $salesReturn = SalesReturn::findOrFail($id);
            $salesReturn->delete();
            return response()->json(['message' => 'Sales Return deleted']);
        } catch (ModelNotFoundException $e) {
           
            return response()->json(['error' => 'Sales Return not found'], 404);
        } catch (QueryException $e) {
           
            return response()->json(['error' => 'Database error'], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'Unexpected error'], 500);
        }


    }

    public function getSalesReturnByProduct(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|integer|exists:products,id',
                'company_id' => 'nullable|integer',
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
           
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
           
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
           
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
           
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
           
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
           
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

    public function filterByBarcode(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer|exists:companies,id',
                'barcode' => 'nullable|string|max:255',
                'product_unique_id' => 'nullable|string|max:255',
                'sale_id' => 'nullable|integer|exists:sales,id'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            if (!$request->filled('barcode') && !$request->filled('product_unique_id')) {
                return response()->json(['error' => 'At least barcode or product_unique_id is required'], 422);
            }

            $companyId = $request->input('company_id');
            $barcode = $request->input('barcode');
            $productUniqueId = $request->input('product_unique_id');
            $saleId = $request->input('sale_id');

            // resolve product id + product model
            if ($barcode) {
                $productList = ProductList::where('company_id', $companyId)
                    ->where('barcode', $barcode)
                    ->whereNull('deleted_at')
                    ->first();
                if (!$productList) {
                    return response()->json(['error' => 'No product found for this barcode', 'searched_value' => $barcode], 404);
                }
                $productId = $productList->product_id;
                $productModel = Product::where('id', $productId)->where('company_id', $companyId)->first();
            } else {
                $productModel = Product::where('company_id', $companyId)
                    ->where('product_unique_id', $productUniqueId)
                    ->whereNull('deleted_at')
                    ->first();
                if (!$productModel) {
                    return response()->json(['error' => 'No product found for this product_unique_id', 'searched_value' => $productUniqueId], 404);
                }
                $productId = $productModel->id;
            }

            // fetch measure units for calculations
            $measureUnits = MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            // Build sales query (sales that have saleProducts of this product)
            $salesQuery = Sale::where('company_id', $companyId)
                ->whereNull('deleted_at');

            if ($saleId) {
                $salesQuery->where('id', $saleId);
            }

            $sales = $salesQuery->with([
                'saleProducts' => function ($q) use ($companyId, $productId) {
                    $q->select([
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
                        ->whereNull('products.deleted_at')
                        ->where('sale_products.product_id', $productId);
                },
                'saleProducts.fieldValues' => function ($q) use ($companyId) {
                    $q->select([
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
                }
            ])->select([
                        'id',
                        'company_id',
                        'customer_id',
                        'customer_name',
                        'invoice_number',
                        'invoice_date',
                        'total_amount',
                        'is_vatable'
                    ])->get();

            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sale found for this product', 'data' => []], 404);
            }

            // collect sale product ids for returns and return field values
            $saleProductIds = $sales->pluck('saleProducts')->flatten()->pluck('id')->unique()->toArray();

            $salesReturnProducts = SalesReturnProduct::whereIn('sale_product_id', $saleProductIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get();

            $returnFieldValues = DB::table('sale_return_product_field_values')
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->whereIn('sale_product_id', $saleProductIds)
                ->select(['sale_product_id', 'product_field_id', 'quantity_index', 'value'])
                ->get()
                ->groupBy('sale_product_id');

            // Aggregate products across sales (only the single productId in scope, but keep general)
            $products = [];

            foreach ($sales as $sale) {
                if ($sale->saleProducts->isEmpty()) {
                    continue;
                }

                foreach ($sale->saleProducts as $saleProduct) {
                    $pid = $saleProduct->product_id;

                    // primary measure unit from product_lists
                    $primaryMeasureUnit = ProductList::where('product_id', $pid)
                        ->where('is_primary', 1)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->pluck('measure_unit_id')
                        ->first();

                    if (!$primaryMeasureUnit) {
                        $primaryMeasureUnit = ProductList::where('product_id', $pid)
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
                        ->first() ?? 1;

                    // gather used measure units (product.measure_unit_id + productLists)
                    $productMeasureUnitIds = Product::where('id', $pid)->pluck('measure_unit_id')->toArray();
                    $productListMeasureUnitIds = ProductList::where('product_id', $pid)->pluck('measure_unit_id')->toArray();
                    $allMeasureUnitIds = collect(array_merge($productMeasureUnitIds, $productListMeasureUnitIds))
                        ->unique()
                        ->values()
                        ->toArray();

                    $usedMeasureUnits = MeasureUnit::whereIn('id', $allMeasureUnitIds)
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->get(['id', 'name', 'quantity']);

                    // sale product measure unit to compute pieces
                    $measureUnitId = $saleProduct->measure_unit_id ?? null;
                    $measureUnit = isset($measureUnits[$measureUnitId]) ? [
                        'id' => $measureUnits[$measureUnitId]->id,
                        'name' => $measureUnits[$measureUnitId]->name,
                        'quantity' => $measureUnits[$measureUnitId]->quantity ?? 1,
                    ] : ['id' => null, 'name' => 'null', 'quantity' => 1];

                    $measureUnitQuantity = $measureUnit['quantity'];

                    // price stats from sale_products
                    $originalProductPrice = Product::where('id', $pid)->value('purchase_rate');
                    $saleProductsPrice = SaleProduct::where('product_id', $pid)->orderBy('created_at', 'desc')->pluck('price');
                    $latestPrice = $saleProductsPrice->first() ?? null;
                    $minProductPrice = $saleProductsPrice->min() ?? null;
                    $avgProductPrice = $saleProductsPrice->avg() ?? null;

                    // init product entry
                    if (!isset($products[$pid])) {
                        $products[$pid] = [
                            'product_id' => $pid,
                            'product_name' => $saleProduct->product_name,
                            'product_code' => $saleProduct->product_code,
                            'barcode' => $barcode,
                            'original_price' => $originalProductPrice ?? 0,
                            'latest_price' => $latestPrice,
                            'min_price' => $minProductPrice,
                            'avg_price' => $avgProductPrice,
                            'amount' => 0,
                            'is_vatable' => (bool) $saleProduct->is_vatable,
                            'used_measure_units' => $usedMeasureUnits->map(fn($u) => [
                                'id' => $u->id,
                                'name' => $u->name,
                                'quantity' => $u->quantity,
                            ])->values()->toArray(),
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

                    // ensure min price updated
                    if ($saleProduct->price !== null && ($products[$pid]['min_price'] === null || $saleProduct->price < $products[$pid]['min_price'])) {
                        $products[$pid]['min_price'] = $saleProduct->price;
                    }

                    // sale quantity in primary pieces (account for free_quantity)
                    $regularQuantity = $saleProduct->quantity ?? 0;
                    $freeQuantity = $saleProduct->free_quantity ?? 0;
                    // calculatePieces is your helper used in getAvailableProductsForSalesReturn
                    $saleRegular = $this->calculatePieces($regularQuantity, $measureUnitQuantity);
                    $saleFree = $this->calculatePieces($freeQuantity, $measureUnitQuantity);
                    $saleTotal = $saleRegular + $saleFree;

                    // calculate returned quantity for this sale product (using SalesReturnProduct)
                    $returnProductsForSale = $salesReturnProducts->where('sale_product_id', $saleProduct->id);
                    $returned = 0;
                    foreach ($returnProductsForSale as $returnProduct) {
                        $retUnitQty = isset($measureUnits[$returnProduct->measure_unit_id]) ? $measureUnits[$returnProduct->measure_unit_id]->quantity : 1;
                        $retReg = $this->calculatePieces($returnProduct->quantity ?? 0, $retUnitQty);
                        $retFree = $this->calculatePieces($returnProduct->free_quantity ?? 0, $retUnitQty);
                        $returned += ($retReg + $retFree);
                    }

                    // determine returned indices for field values (to exclude already-returned field items)
                    $returnedIndices = [];
                    $saleProductReturnFieldValues = collect($returnFieldValues[$saleProduct->id] ?? []);
                    if ($saleProduct->fieldValues->isNotEmpty()) {
                        $quantityIndices = $saleProduct->fieldValues->pluck('quantity_index')->unique();
                        foreach ($quantityIndices as $qtyIndex) {
                            $saleFieldValues = $saleProduct->fieldValues->where('quantity_index', $qtyIndex)
                                ->pluck('value', 'product_field_id')
                                ->toArray();

                            $isReturned = false;
                            if ($saleProductReturnFieldValues->isNotEmpty()) {
                                $isReturned = true;
                                foreach ($saleFieldValues as $fieldId => $value) {
                                    $returnMatch = $saleProductReturnFieldValues->firstWhere(function ($rfv) use ($fieldId, $value, $qtyIndex) {
                                        return $rfv->product_field_id == $fieldId
                                            && $rfv->value == $value
                                            && $rfv->quantity_index == $qtyIndex;
                                    });

                                    if (!$returnMatch) {
                                        $isReturned = false;
                                        break;
                                    }
                                }
                            }

                            if ($isReturned) {
                                $returnedIndices[] = $qtyIndex;
                            }
                        }
                    } else {
                        if ($returned > 0) {
                            $returnedIndices[] = 0;
                        }
                    }

                    $returnTotal = $returned;
                    $availableQuantity = max(0, round($saleTotal - $returnTotal, 2));

                    // accumulate aggregated totals
                    $products[$pid]['sale_quantity'] += $saleTotal;
                    $products[$pid]['sales_return_quantity'] += $returnTotal;
                    $products[$pid]['return_quantity'] += $returnTotal;
                    $products[$pid]['available_quantity'] += $availableQuantity;
                    $products[$pid]['amount'] += $saleProduct->amount ?? 0;

                    if ($saleProduct->expiry_date && !in_array($saleProduct->expiry_date, $products[$pid]['expiry_dates'])) {
                        $products[$pid]['expiry_dates'][] = $saleProduct->expiry_date;
                    }

                    // add non-returned field values
                    if ($saleProduct->fieldValues->isNotEmpty()) {
                        foreach ($saleProduct->fieldValues as $fv) {
                            if (!in_array($fv->quantity_index, $returnedIndices)) {
                                $products[$pid]['field_values'][] = [
                                    'sale_product_id' => $saleProduct->id,
                                    'purchase_product_id' => $saleProduct->purchase_product_id ?? null,
                                    'product_field_id' => $fv->product_field_id,
                                    'name' => $fv->name,
                                    'value' => $fv->value,
                                    'quantity_index' => $fv->quantity_index,
                                ];
                            }
                        }
                    }

                    // add sale product entry
                    $products[$pid]['sale_products'][] = [
                        'sale_product_id' => $saleProduct->id,
                        'sale_id' => $saleProduct->sale_id,
                        'invoice_number' => $sale->invoice_number,
                        'invoice_date' => $sale->invoice_date,
                        'product_id' => $saleProduct->product_id,
                        'barcode' => $barcode,
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
                        'expiry_date' => $saleProduct->expiry_date ?? null,
                        'purchase_product_id' => $saleProduct->purchase_product_id ?? null,
                    ];
                }
            }

            // Compute purchased_quantity for each aggregated product using purchase_products (accurate, uses measure unit quantity multiplier)
            foreach ($products as $pid => &$prod) {
                $purchasedTotal = PurchaseProduct::where('product_id', $pid)
                    ->where('purchase_products.company_id', $companyId)
                    ->whereNull('purchase_products.deleted_at')
                    ->join('measure_units', 'purchase_products.measure_unit_id', '=', 'measure_units.id')
                    ->where('measure_units.company_id', $companyId)
                    ->whereNull('measure_units.deleted_at')
                    ->sum(DB::raw('(purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) * measure_units.quantity'));

                $prod['purchased_quantity'] = (int) ($purchasedTotal ?? 0);
            }
            unset($prod);

            // keep only products that have available_quantity > 0
            $products = array_filter($products, function ($p) {
                return ($p['available_quantity'] ?? 0) > 0;
            });

            if (empty($products)) {
                return response()->json(['message' => 'No products available for sales return', 'data' => []], 404);
            }

            // prepare response (match the requested nested format)
            $response = [
                'message' => 'Product details retrieved successfully',
                'data' => [
                    [
                        'products' => array_values($products),
                    ],
                ],
            ];

            return response()->json($response, 200);

        } catch (QueryException $e) {
            
            return response()->json(['error' => 'Database query error'], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }


}

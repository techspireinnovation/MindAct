<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Helpers\PurchaseReturnHelper;

use App\Models\MeasureUnit;
use App\Models\ProductList;
use App\Models\PurchaseStockReturn;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseStockProduct;
use App\Models\PurchaseStockProductFieldValue;
use App\Models\PurhcaseStockReturn;
use App\Models\PurchaseStockProductReturn;
use App\Models\PurchaseStockProductReturnFieldValue;
use App\Models\PurchaseProductFieldValue;
use App\Models\PurchaseProductReturn;
use App\Models\StockTransferFieldValue;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnHistory;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\SaleProduct;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SalesProductFieldValue;
use App\Models\SalesReturnProduct;
use App\Services\AvailableQuantityService;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PurchaseReturnController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = PurchaseStockReturn::query();

        if ($request->has('keywords')) {
            $query->where('purchase_bill_number', 'LIKE', '%' . $request->input('keywords') . '%')->orWhereHas('customer', function ($query) use ($request) {
                $query->where('party_name', 'LIKE', "%" . $request->input('keywords') . "%");
            });
        }

        return response()->json($query->paginate(50));
    }

    // public function getAllPurchaseProductDetailsByName(Request $request): JsonResponse
    // {
    //     try {


    //     } catch (ModelNotFoundException $e) {
    //         return response()->json(["error" => "Item not Found!!"], 404);
    //     } catch (QueryException $e) {
    //         return response()->json(["error" => "Database error occurred !!"], 500);
    //     } catch (\Exception $e) {
    //         return response()->json(["error" => "An unexpected error occurred !!"], 500);
    //     }

    // }

    public function getItemByBillNumber($billNumber): JsonResponse
    {
        try {
            $purchase = PurchaseReturn::where('id', '=', $billNumber)->firstOrFail();
            return $this->show($purchase->id);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected query error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected exception error occurred'], 500);
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
                return response()->jsonjson([
                    'data' => 'Successfull !!',
                    'message' => 'No purchases with available products found'
                ], 200);
            }


            return response()->json($billNumbers);
        } catch (QueryException $e) {
            \Log::error('Database error in getRefBillNumber: ' . $e->getMessage());
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getRefBillNumber: ' . $e->getMessage());
            return response()->json(['error' => 'An Unexpected error occurred'], 500);
        }
    }



    public function getPurchaseBillNumber(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer',
                'branch_id' => 'required|integer|exists:branches,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()->first()], 422);
            }

            $companyId = $request->company_id;
            $branchId = $request->branch_id;



            Log::debug('Input parameters for purchase bill numbers', [
                'company_id' => $companyId,
                'user_id' => auth()->id(),
            ]);

            if (!auth()->check()) {
                return response()->json(['message' => 'Unauthenticated'], 401);
            }

            $measureUnits = MeasureUnit::where('company_id', $companyId)
                ->where('is_active', 1)
                ->whereNull('deleted_at')
                ->select(['id', 'name', 'quantity'])
                ->get()
                ->keyBy('id');

            Log::info('Measure units fetched', [
                'company_id' => $companyId,
                'measure_units_count' => $measureUnits->count(),
            ]);

            $purchases = Purchase::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->with([
                    'purchaseStockProducts' => function ($query) use ($companyId, $branchId) {
                        $query->select([
                            'purchase_stock_products.id',
                            'purchase_stock_products.purchase_id',
                            'purchase_stock_products.product_id',
                            'purchase_stock_products.measure_unit_id',
                            'purchase_stock_products.quantity',
                            'purchase_stock_products.free_quantity',
                            'products.name as product_name',
                        ])
                            ->join('products', 'purchase_stock_products.product_id', '=', 'products.id')
                            ->where('purchase_stock_products.company_id', $companyId)
                            ->where('purchase_stock_products.branch_id', $branchId)
                            ->whereNull('purchase_stock_products.deleted_at')
                            ->whereNull('products.deleted_at');
                    },
                    'purchaseStockProducts.fieldValues' => function ($query) use ($companyId, $branchId) {
                        $query->select([
                            'purchase_stock_product_field_values.purchase_stock_product_id',
                            'purchase_stock_product_field_values.product_field_id',
                            'purchase_stock_product_field_values.quantity_index',
                            'purchase_stock_product_field_values.value',
                            'product_fields.name',
                        ])
                            ->join('product_fields', 'purchase_stock_product_field_values.product_field_id', '=', 'product_fields.id')
                            ->where('purchase_stock_product_field_values.company_id', $companyId)
                            ->where('purchase_stock_product_field_values.branch_id', $branchId)
                            ->whereNull('purchase_stock_product_field_values.deleted_at')
                            ->whereNull('product_fields.deleted_at');
                    },
                ])
                ->select(['id', 'company_id', 'purchase_bill_number'])
                ->get();
         

            if ($purchases->isEmpty()) {
                Log::warning('No purchases found', ['company_id' => $companyId]);
                return response()->json([], 200);
            }

            $purchaseProductIds = $purchases->pluck('purchaseStockProducts.*.id')->flatten()->unique()->toArray();
            $purchaseReturnProducts = PurchaseStockProductReturn::whereIn('purchase_stock_product_id', $purchaseProductIds)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->select([
                    'id',
                    'purchase_stock_product_id',
                    'purchase_product_id',
                    'stock_product_id',
                    'stock_reconciliation_id',
                    'stock_adjustment_id',
                    'stock_transfer_id',
                    'quantity',
                    'free_quantity',
                    'measure_unit_id',
                ])
                ->get();

            $saleProducts = SaleProduct::whereIn('purchase_stock_product_id', $purchaseProductIds)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->select([
                    'id',
                    'purchase_stock_product_id',
                    'quantity',
                    'free_quantity',
                    'measure_unit_id',
                ])
                ->get();

            $saleProductIds = $saleProducts->pluck('id')->unique()->toArray();
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
                ])
                ->get();

            $purchaseReturnFieldValues = DB::table('purchase_stock_product_return_field_values')
                ->join('purchase_stock_product_returns', 'purchase_stock_product_return_field_values.purchase_stock_product_return_id', '=', 'purchase_stock_product_returns.id')
                ->where('purchase_stock_product_return_field_values.company_id', $companyId)
                ->where('purchase_stock_product_return_field_values.branch_id', $branchId)
                ->whereNull('purchase_stock_product_return_field_values.deleted_at')
                ->whereNull('purchase_stock_product_return_field_values.deleted_at')
                ->whereIn('purchase_stock_product_return_field_values.purchase_stock_product_id', $purchaseProductIds)
                ->select([
                    'purchase_stock_product_return_field_values.purchase_stock_product_id',
                    'purchase_stock_product_return_field_values.product_field_id',
                    'purchase_stock_product_return_field_values.quantity_index',
                    'purchase_stock_product_return_field_values.value',
                ])
                ->get()
                ->groupBy('purchase_stock_product_id');

            $salesFieldValues = DB::table('sales_product_field_values')
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

            $salesReturnFieldValues = DB::table('sale_return_product_field_values')
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

            $billNumbers = [];
            foreach ($purchases as $purchase) {
                if ($purchase->purchaseStockProducts->isEmpty()) {
                    Log::warning('No available products for purchase', [
                        'purchase_id' => $purchase->id,
                        'purchase_bill_number' => $purchase->purchase_bill_number,
                        'company_id' => $companyId,
                    ]);
                    continue;
                }

                $hasAvailableProducts = false;
                foreach ($purchase->purchaseStockProducts as $purchaseProduct) {
                    $productId = $purchaseProduct->product_id;
                    $measureUnitId = $purchaseProduct->measure_unit_id ?? null;
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
                        Log::warning('Measure unit not found for purchase product, using default', [
                            'purchase_product_id' => $purchaseProduct->id,
                            'measure_unit_id' => $purchaseProduct->measure_unit_id,
                        ]);
                    }

                    $purchaseTotal = ($purchaseProduct->quantity + ($purchaseProduct->free_quantity ?? 0)) * $measureUnitQuantity;

                    $returnProducts = $purchaseReturnProducts->where('purchase_stock_product_id', $purchaseProduct->id);
                    $purchaseReturned = 0;
                    $lastReturnMeasureUnitId = null;
                    $lastReturnMeasureUnitQuantity = 1;
                    foreach ($returnProducts as $returnProduct) {
                        $returnMeasureUnitId = $returnProduct->measure_unit_id ?? null;
                        $returnMeasureUnitQuantity = isset($measureUnits[$returnMeasureUnitId]) ? $measureUnits[$returnMeasureUnitId]->quantity : 1;
                        $returnQuantity = ($returnProduct->quantity + ($returnProduct->free_quantity ?? 0)) * $returnMeasureUnitQuantity;
                        $purchaseReturned += $returnQuantity;
                        $lastReturnMeasureUnitId = $returnMeasureUnitId;
                        $lastReturnMeasureUnitQuantity = $returnMeasureUnitQuantity;

                        Log::info('Processing purchase return product', [
                            'purchase_product_id' => $purchaseProduct->id,
                            'return_product_id' => $returnProduct->id,
                            'return_quantity' => $returnQuantity,
                            'measure_unit_id' => $returnMeasureUnitId,
                            'measure_unit_quantity' => $returnMeasureUnitQuantity,
                        ]);
                    }

                    $saleProductsForPurchase = $saleProducts->where('purchase_stock_product_id', $purchaseProduct->id);
                    $netSales = 0;
                    foreach ($saleProductsForPurchase as $saleProduct) {
                        $saleMeasureUnitId = $saleProduct->measure_unit_id ?? null;
                        $saleMeasureUnitQuantity = isset($measureUnits[$saleMeasureUnitId]) ? $measureUnits[$saleMeasureUnitId]->quantity : 1;
                        $saleQuantity = ($saleProduct->quantity + ($saleProduct->free_quantity ?? 0)) * $saleMeasureUnitQuantity;

                        $salesReturns = $salesReturnProducts->where('sale_product_id', $saleProduct->id);
                        $salesReturned = 0;
                        foreach ($salesReturns as $salesReturn) {
                            $salesReturnMeasureUnitId = $salesReturn->measure_unit_id ?? null;
                            $salesReturnMeasureUnitQuantity = isset($measureUnits[$salesReturnMeasureUnitId]) ? $measureUnits[$salesReturnMeasureUnitId]->quantity : 1;
                            $salesReturnQuantity = ($salesReturn->quantity + ($salesReturn->free_quantity ?? 0)) * $salesReturnMeasureUnitQuantity;
                            $salesReturned += $salesReturnQuantity;
                        }

                        $netSales += ($saleQuantity - $salesReturned);
                    }

                    $availableQuantity = $purchaseTotal - $purchaseReturned - $netSales;
                    if ($purchaseProduct->fieldValues->isNotEmpty()) {
                        $purchaseFieldValues = $purchaseProduct->fieldValues;
                        $purchaseReturnFieldValuesForProduct = $purchaseReturnFieldValues[$purchaseProduct->id] ?? collect([]);
                        $saleFieldValuesForProduct = collect([]);
                        $salesReturnFieldValuesForProduct = collect([]);

                        foreach ($saleProductsForPurchase as $saleProduct) {
                            $saleFieldValuesForProduct = $saleFieldValuesForProduct->merge($salesFieldValues[$saleProduct->id] ?? collect([]));
                            $salesReturnFieldValuesForProduct = $salesReturnFieldValuesForProduct->merge($salesReturnFieldValues[$saleProduct->id] ?? collect([]));
                        }

                        $quantityIndices = $purchaseFieldValues->pluck('quantity_index')->unique();
                        $availableIndices = [];
                        foreach ($quantityIndices as $quantityIndex) {
                            $fieldValuesForIndex = $purchaseFieldValues->where('quantity_index', $quantityIndex)
                                ->pluck('value', 'product_field_id')
                                ->toArray();

                            $isReturnedOrSold = false;

                            $isReturned = true;
                            foreach ($fieldValuesForIndex as $fieldId => $value) {
                                $returnMatch = $purchaseReturnFieldValuesForProduct->firstWhere(function ($rfv) use ($fieldId, $value, $quantityIndex) {
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
                                $isReturnedOrSold = true;
                            }

                            // Check sales
                            $isSold = true;
                            foreach ($fieldValuesForIndex as $fieldId => $value) {
                                $saleMatch = $saleFieldValuesForProduct->firstWhere(function ($sfv) use ($fieldId, $value, $quantityIndex) {
                                    return $sfv->product_field_id == $fieldId &&
                                        $sfv->value == $value &&
                                        $sfv->quantity_index == $quantityIndex;
                                });
                                if (!$saleMatch) {
                                    $isSold = false;
                                    break;
                                }
                            }
                            if ($isSold) {
                                // Check if sold item was returned
                                $isSalesReturned = true;
                                foreach ($fieldValuesForIndex as $fieldId => $value) {
                                    $salesReturnMatch = $salesReturnFieldValuesForProduct->firstWhere(function ($srfv) use ($fieldId, $value, $quantityIndex) {
                                        return $srfv->product_field_id == $fieldId &&
                                            $srfv->value == $value &&
                                            $srfv->quantity_index == $quantityIndex;
                                    });
                                    if (!$salesReturnMatch) {
                                        $isSalesReturned = false;
                                        break;
                                    }
                                }
                                if (!$isSalesReturned) {
                                    $isReturnedOrSold = true;
                                }
                            }

                            if (!$isReturnedOrSold) {
                                $availableIndices[] = $quantityIndex;
                            }
                        }

                        // Adjust available quantity based on available field value indices
                        $availableFieldCount = count($availableIndices);
                        $expectedFieldCount = floor($availableQuantity / $measureUnitQuantity);
                        if ($availableFieldCount < $expectedFieldCount) {
                            $availableQuantity = $availableFieldCount * $measureUnitQuantity;
                        }
                    }

                    // Round quantities
                    $purchaseTotal = round($purchaseTotal, 2);
                    $purchaseReturned = round($purchaseReturned, 2);
                    $netSales = round($netSales, 2);
                    $availableQuantity = max(0, round($availableQuantity, 2));

                    Log::info('Quantity calculation for purchase product', [
                        'purchase_product_id' => $purchaseProduct->id,
                        'purchase_total' => $purchaseTotal,
                        'purchase_returned' => $purchaseReturned,
                        'net_sales' => $netSales,
                        'available_quantity' => $availableQuantity,
                        'measure_unit_quantity' => $measureUnitQuantity,
                    ]);

                    if ($availableQuantity > 0) {
                        $hasAvailableProducts = true;
                        break;
                    }
                }

                if ($hasAvailableProducts && !in_array($purchase->purchase_bill_number, $billNumbers)) {
                    $billNumbers[] = $purchase->purchase_bill_number;
                }
            }

            if (empty($billNumbers)) {
                Log::warning('No purchase bill numbers with available products after processing', ['company_id' => $companyId]);
                return response()->json([], 200);
            }

            Log::info('Final bill numbers prepared', [
                'bill_numbers' => $billNumbers,
                'count' => count($billNumbers),
            ]);

            return response()->json($billNumbers);
        } catch (QueryException $e) {
            dd($e->getMessage());
            Log::error('Database query error in getPurchaseBillNumber', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings(),
            ]);
            return response()->json(['error' => 'A database error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error in getPurchaseBillNumber', [
                'company_id' => $companyId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    public function getPurchaseByBillNumber(Request $request): JsonResponse
    {
        try {
            // Validate input parameters
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer',
                'branch_id' => 'required|integer|exists:branches,id',
                'purchase_bill_number' => 'nullable|string|max:255',
                'purchase_number' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                Log::warning('Validation failed', ['errors' => $validator->errors()]);
                return response()->json(['errors' => $validator->errors()], 422);
            }

            if (!$request->hasAny(['purchase_bill_number', 'purchase_number'])) {
                Log::warning('No valid search parameter provided', ['request' => $request->all()]);
                return response()->json(['error' => 'At least one of purchase_bill_number or purchase_number is required'], 422);
            }

            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');
            $purchaseBillNumber = $request->input('purchase_bill_number');
            $purchaseNumber = $request->input('purchase_number');

            Log::debug('Input parameters', [
                'company_id' => $companyId,
                'purchase_bill_number' => $purchaseBillNumber,
                'purchase_number' => $purchaseNumber,
            ]);

            // Enable query logging for debugging
            DB::enableQueryLog();

            // Query purchase with related data
            $purchaseQuery = Purchase::where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->when($purchaseBillNumber, fn($q) => $q->where('purchase_bill_number', $purchaseBillNumber))
                ->when($purchaseNumber, fn($q) => $q->where('purchase_number', $purchaseNumber))
                ->with([
                    'purchaseStockProducts' => function ($query) use ($companyId, $branchId) {
                        $query->whereNull('deleted_at')
                            ->where('company_id', $companyId)
                            ->where('branch_id', $branchId)
                            ->with([
                                'measureUnit' => fn($q) => $q->select('id', 'name', 'quantity')->whereNull('deleted_at'),
                                'fieldValues.productField' => fn($q) => $q->select('id', 'name')->whereNull('deleted_at'),
                                'purchaseStockProductReturns' => fn($subQuery) => $subQuery->whereNull('deleted_at')
                                    ->where('company_id', $companyId)
                                    ->where('branch_id', $branchId)
                                    ->with(['measureUnit' => fn($q) => $q->select('id', 'name', 'quantity')->whereNull('deleted_at')]),
                                'saleProducts' => fn($subQuery) => $subQuery->whereNull('deleted_at')
                                    ->where('company_id', $companyId)
                                    ->with([
                                        'measureUnit' => fn($q) => $q->select('id', 'name', 'quantity')->whereNull('deleted_at'),
                                        'saleProductReturns' => fn($subSubQuery) => $subSubQuery->whereNull('deleted_at')
                                            ->where('company_id', $companyId)
                                            ->with(['measureUnit' => fn($q) => $q->select('id', 'name', 'quantity')->whereNull('deleted_at')]),
                                    ]),
                            ]);
                    }
                ]);

            $purchase = $purchaseQuery->first();

            if (!$purchase) {
                Log::info('Purchase not found', [
                    'company_id' => $companyId,
                    'purchase_bill_number' => $purchaseBillNumber,
                    'purchase_number' => $purchaseNumber,
                    'query_log' => DB::getQueryLog(),
                ]);
                return response()->json(['error' => 'Purchase not found'], 404);
            }

            if (empty($purchase->purchaseStockProducts)) {
                Log::info('No purchase products found', [
                    'purchase_id' => $purchase->id,
                    'company_id' => $companyId,
                    'query_log' => DB::getQueryLog(),
                ]);
                return response()->json(['error' => 'No available products for this purchase'], 404);
            }

            // Prepare response data
            $purchaseData = $purchase->toArray();
            $payment = $purchase->payment ?? [];

            $purchaseData['payment'] = [
                'cash' => $payment['cash'] ?? null,
                'credit' => $payment['credit'] ?? null,
                'bank' => $payment['bank'] ?? null,
            ];

            $measureUnitsCalc = MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            $purchaseProducts = collect($purchaseData['purchase_stock_products'])->filter(function ($product) use ($companyId, $measureUnitsCalc) {
                Log::debug('Raw purchase product data', [
                    'purchase_stock_product_id' => $product['id'] ?? 'unknown',
                    'quantity' => $product['quantity'] ?? 0,
                    'free_quantity' => $product['free_quantity'] ?? 0,
                    'measure_unit_id' => $product['measure_unit_id'] ?? null,
                ]);


                // Ensure measureUnit is valid
                $measureUnitId = $product['measure_unit_id'] ?? null;
                $unitData = isset($measureUnitsCalc[$measureUnitId]) ? [
                    'id' => $measureUnitsCalc[$measureUnitId]->id,
                    'name' => $measureUnitsCalc[$measureUnitId]->name,
                    'quantity' => $measureUnitsCalc[$measureUnitId]->quantity ?? 1
                ] : [
                    'id' => null,
                    'name' => 'null',
                    'quantity' => 1
                ];

                Log::debug('Processing product measure unit', [
                    'purchase_product_id' => $product['id'] ?? 'unknown',
                    'measure_unit' => $unitData,
                ]);

                // Calculate total quantity in pieces
                $totalQuantity = ((float) ($product['quantity'] ?? 0)) + ((float) ($product['free_quantity'] ?? 0));
                $unitQuantity = $unitData['quantity'] ?? 1;
                $decimalStr = explode('.', (string) $totalQuantity);
                $quantityInt = floor($totalQuantity);
                $decimalDigits = isset($decimalStr[1]) ? (int) str_replace('.', '', $decimalStr[1]) : 0;
                $totalPurchaseQuantityInPieces = ($quantityInt * $unitQuantity) + $decimalDigits;

                Log::debug('Total purchase quantity calculation', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'quantity' => $product['quantity'] ?? 0,
                    'free_quantity' => $product['free_quantity'] ?? 0,
                    'total_quantity' => $totalQuantity,
                    'unit_quantity' => $unitQuantity,
                    'quantity_int' => $quantityInt,
                    'decimal_digits' => $decimalDigits,
                    'total_purchase_quantity_in_pieces' => $totalPurchaseQuantityInPieces,
                ]);

                // Calculate returned quantities
                $totalReturnedInPieces = collect($product['purchase_stock_product_returns'] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $measureUnitQuantity = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $quantity = (float) ($return['quantity'] ?? 0);
                    $freeQuantity = (float) ($return['free_quantity'] ?? 0);

                    // Calculate pieces for quantity
                    $integerPart = floor($quantity);
                    $decimalPart = $quantity - $integerPart;
                    $decimalPieces = $decimalPart > 0 ? (int) str_replace('.', '', (string) $decimalPart) : 0;
                    $quantityPieces = ($integerPart * $measureUnitQuantity) + $decimalPieces;

                    // Calculate pieces for free_quantity
                    $freeIntegerPart = floor($freeQuantity);
                    $freeDecimalPart = $freeQuantity - $freeIntegerPart;
                    $freeDecimalPieces = $freeDecimalPart > 0 ? (int) str_replace('.', '', (string) $freeDecimalPart) : 0;
                    $freeQuantityPieces = ($freeIntegerPart * $measureUnitQuantity) + $freeDecimalPieces;

                    $returnTotal = $quantityPieces + $freeQuantityPieces;

                    Log::debug('Return quantity calculation', [
                        'return_id' => $return['id'] ?? 'unknown',
                        'quantity' => $quantity,
                        'free_quantity' => $freeQuantity,
                        'sum_quantity' => $quantity + $freeQuantity,
                        'measure_unit_id' => $unitId,
                        'measure_unit_quantity' => $measureUnitQuantity,
                        'quantity_pieces' => $quantityPieces,
                        'free_quantity_pieces' => $freeQuantityPieces,
                        'total_returned_pieces' => $returnTotal,
                    ]);

                    return $returnTotal;
                });

                // Calculate sold quantities
                $totalSoldInPieces = collect($product['sale_products'] ?? [])->sum(function ($sale) use ($measureUnitsCalc) {
                    $unitId = $sale['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $saleTotalQty = ((float) ($sale['quantity'] ?? 0)) + ((float) ($sale['free_quantity'] ?? 0));
                    $saleDecimalStr = explode('.', (string) $saleTotalQty);
                    $saleQtyInt = floor($saleTotalQty);
                    $saleQtyDec = isset($saleDecimalStr[1]) ? (int) str_replace('.', '', $saleDecimalStr[1]) : 0;
                    $soldPieces = ($saleQtyInt * $unitQty) + $saleQtyDec;

                    Log::debug('Sale quantity calculation', [
                        'sale_id' => $sale['id'] ?? 'unknown',
                        'quantity' => $sale['quantity'] ?? 0,
                        'free_quantity' => $sale['free_quantity'] ?? 0,
                        'total_quantity' => $saleTotalQty,
                        'unit_quantity' => $unitQty,
                        'sale_qty_int' => $saleQtyInt,
                        'sale_qty_dec' => $saleQtyDec,
                        'sold_pieces' => $soldPieces,
                    ]);

                    return $soldPieces;
                });

                // Calculate sale returns
                $totalSaleReturnsInPieces = collect($product['sale_products'] ?? [])->flatMap(function ($sale) {
                    return $sale['sale_product_returns'] ?? [];
                })->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retTotalQty = ((float) ($return['quantity'] ?? 0)) + ((float) ($return['free_quantity'] ?? 0));
                    $retDecimalStr = explode('.', (string) $retTotalQty);
                    $retQtyInt = floor($retTotalQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (int) str_replace('.', '', $retDecimalStr[1]) : 0;
                    $saleReturnPieces = ($retQtyInt * $unitQty) + $retQtyDec;

                    Log::debug('Sale return quantity calculation', [
                        'sale_return_id' => $return['id'] ?? 'unknown',
                        'quantity' => $return['quantity'] ?? 0,
                        'free_quantity' => $return['free_quantity'] ?? 0,
                        'total_quantity' => $retTotalQty,
                        'unit_quantity' => $unitQty,
                        'ret_qty_int' => $retQtyInt,
                        'ret_qty_dec' => $retQtyDec,
                        'sale_return_pieces' => $saleReturnPieces,
                    ]);

                    return $saleReturnPieces;
                });

                $availableQuantityInPieces = $totalPurchaseQuantityInPieces - $totalReturnedInPieces - $totalSoldInPieces + $totalSaleReturnsInPieces;

                Log::debug('Available quantity calculation', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'total_purchase_quantity_in_pieces' => $totalPurchaseQuantityInPieces,
                    'total_returned_in_pieces' => $totalReturnedInPieces,
                    'total_sold_in_pieces' => $totalSoldInPieces,
                    'total_sale_returns_in_pieces' => $totalSaleReturnsInPieces,
                    'available_quantity_in_pieces' => $availableQuantityInPieces,
                ]);

                return $availableQuantityInPieces > 0;
            })->map(function ($product) use ($companyId, $branchId, $measureUnitsCalc) {
                Log::debug('Raw purchase product data in map', [
                    'purchase_product_id' => $product['id'] ?? 'unknown',
                    'quantity' => $product['quantity'] ?? 0,
                    'free_quantity' => $product['free_quantity'] ?? 0,
                    'measure_unit_id' => $product['measure_unit_id'] ?? null,
                ]);

                // Ensure measureUnit is valid
                $measureUnitId = $product['measure_unit_id'] ?? null;
                $unitData = isset($measureUnitsCalc[$measureUnitId]) ? [
                    'id' => $measureUnitsCalc[$measureUnitId]->id,
                    'name' => $measureUnitsCalc[$measureUnitId]->name,
                    'quantity' => $measureUnitsCalc[$measureUnitId]->quantity ?? 1
                ] : [
                    'id' => null,
                    'name' => 'null',
                    'quantity' => 1
                ];

                Log::debug('Processing product measure unit in map', [
                    'purchase_product_id' => $product['id'] ?? 'unknown',
                    'measure_unit' => $unitData,
                ]);

                // Calculate quantities
                $unitQuantity = $unitData['quantity'] ?? 1;
                $quantity = (float) ($product['quantity'] ?? 0);
                $decimalStrforRegularQuantity = explode('.', (string) $quantity);
                $regularQuantityInt = floor($quantity);
                $regularDecimalDigits = isset($decimalStrforRegularQuantity[1]) ? (int) str_replace('.', '', $decimalStrforRegularQuantity[1]) : 0;
                $totalRegularQuantity = ($regularQuantityInt * $unitQuantity) + $regularDecimalDigits;
                $freeQuantity = (float) ($product['free_quantity'] ?? 0);
                $decimalStrforFreeQuantity = explode('.', (string) $freeQuantity);
                $freeQuantityInt = floor($freeQuantity);
                $freeDecimalDigits = isset($decimalStrforFreeQuantity[1]) ? (int) str_replace('.', '', $decimalStrforFreeQuantity[1]) : 0;
                $totalFreeQuantity = ($freeQuantityInt * $unitQuantity) + $freeDecimalDigits;

                // For Total Remaining
                $totalQuantity = $quantity + $freeQuantity;
                $decimalStr = explode('.', (string) $totalQuantity);
                $quantityInt = floor($totalQuantity);
                $decimalDigits = isset($decimalStr[1]) ? (int) str_replace('.', '', $decimalStr[1]) : 0;
                $totalPurchaseQuantityInPieces = ($quantityInt * $unitQuantity) + $decimalDigits;
                $totalPurchaseQuantityInUOM = $totalQuantity;

                Log::debug('Purchase quantity in map', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'quantity' => $quantity,
                    'free_quantity' => $freeQuantity,
                    'total_quantity' => $totalQuantity,
                    'unit_quantity' => $unitQuantity,
                    'total_purchase_quantity_in_pieces' => $totalPurchaseQuantityInPieces,
                    'total_purchase_quantity_in_uom' => $totalPurchaseQuantityInUOM,
                ]);

                // Calculate returned quantities
                $totalReturnedInPieces = collect($product['purchase_stock_product_returns'] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $quantity = (float) ($return['quantity'] ?? 0);
                    $freeQuantity = (float) ($return['free_quantity'] ?? 0);

                    // Calculate pieces for quantity
                    $integerPart = floor($quantity);
                    $decimalPart = $quantity - $integerPart;
                    $decimalPieces = $decimalPart > 0 ? (int) str_replace('.', '', (string) $decimalPart) : 0;
                    $quantityPieces = ($integerPart * $unitQty) + $decimalPieces;

                    // Calculate pieces for free_quantity
                    $freeIntegerPart = floor($freeQuantity);
                    $freeDecimalPart = $freeQuantity - $freeIntegerPart;
                    $freeDecimalPieces = $freeDecimalPart > 0 ? (int) str_replace('.', '', (string) $freeDecimalPart) : 0;
                    $freeQuantityPieces = ($freeIntegerPart * $unitQty) + $freeDecimalPieces;

                    $totalReturned = $quantityPieces + $freeQuantityPieces;

                    Log::debug('Total returned in map', [
                        'return_id' => $return['id'] ?? 'unknown',
                        'quantity' => $quantity,
                        'free_quantity' => $freeQuantity,
                        'sum_quantity' => $quantity + $freeQuantity,
                        'unit_quantity' => $unitQty,
                        'quantity_integer_part' => $integerPart,
                        'quantity_decimal_part' => $decimalPart,
                        'quantity_decimal_pieces' => $decimalPieces,
                        'quantity_pieces' => $quantityPieces,
                        'free_integer_part' => $freeIntegerPart,
                        'free_decimal_part' => $freeDecimalPart,
                        'free_decimal_pieces' => $freeDecimalPieces,
                        'free_quantity_pieces' => $freeQuantityPieces,
                        'total_returned' => $totalReturned,
                    ]);

                    return $totalReturned;
                });

                $returnedRegularInPieces = collect($product['purchase_stock_product_returns'] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retQty = (float) ($return['quantity'] ?? 0);
                    $retDecimalStr = explode('.', (string) $retQty);
                    $retQtyInt = floor($retQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (int) str_replace('.', '', $retDecimalStr[1]) : 0;
                    $returnedPieces = ($retQtyInt * $unitQty) + $retQtyDec;

                    Log::debug('Returned regular quantity in map', [
                        'return_id' => $return['id'] ?? 'unknown',
                        'quantity' => $retQty,
                        'unit_quantity' => $unitQty,
                        'ret_qty_int' => $retQtyInt,
                        'ret_qty_dec' => $retQtyDec,
                        'returned_pieces' => $returnedPieces,
                    ]);

                    return $returnedPieces;
                });

                $returnedFreeInPieces = collect($product['purchase_stock_product_returns'] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retFreeQty = (float) ($return['free_quantity'] ?? 0);
                    $retDecimalStr = explode('.', (string) $retFreeQty);
                    $retQtyInt = floor($retFreeQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (int) str_replace('.', '', $retDecimalStr[1]) : 0;
                    $returnedFreePieces = ($retQtyInt * $unitQty) + $retQtyDec;

                    Log::debug('Returned free quantity in map', [
                        'return_id' => $return['id'] ?? 'unknown',
                        'free_quantity' => $retFreeQty,
                        'unit_quantity' => $unitQty,
                        'ret_qty_int' => $retQtyInt,
                        'ret_qty_dec' => $retQtyDec,
                        'returned_free_pieces' => $returnedFreePieces,
                    ]);

                    return $returnedFreePieces;
                });

                // Calculate sold quantities
                $totalSoldInPieces = collect($product['sale_products'] ?? [])->sum(function ($sale) use ($measureUnitsCalc) {
                    $unitId = $sale['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $saleTotalQty = ((float) ($sale['quantity'] ?? 0)) + ((float) ($sale['free_quantity'] ?? 0));
                    $saleDecimalStr = explode('.', (string) $saleTotalQty);
                    $saleQtyInt = floor($saleTotalQty);
                    $saleQtyDec = isset($saleDecimalStr[1]) ? (int) str_replace('.', '', $saleDecimalStr[1]) : 0;
                    $soldPieces = ($saleQtyInt * $unitQty) + $saleQtyDec;

                    Log::debug('Sale quantity calculation in map', [
                        'sale_id' => $sale['id'] ?? 'unknown',
                        'quantity' => $sale['quantity'] ?? 0,
                        'free_quantity' => $sale['free_quantity'] ?? 0,
                        'total_quantity' => $saleTotalQty,
                        'unit_quantity' => $unitQty,
                        'sale_qty_int' => $saleQtyInt,
                        'sale_qty_dec' => $saleQtyDec,
                        'sold_pieces' => $soldPieces,
                    ]);

                    return $soldPieces;
                });

                // Calculate sale returns
                $totalSaleReturnsInPieces = collect($product['sale_products'] ?? [])->flatMap(function ($sale) {
                    return $sale['sale_product_returns'] ?? [];
                })->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retTotalQty = ((float) ($return['quantity'] ?? 0)) + ((float) ($return['free_quantity'] ?? 0));
                    $retDecimalStr = explode('.', (string) $retTotalQty);
                    $retQtyInt = floor($retTotalQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (int) str_replace('.', '', $retDecimalStr[1]) : 0;
                    $saleReturnPieces = ($retQtyInt * $unitQty) + $retQtyDec;

                    Log::debug('Sale return quantity calculation in map', [
                        'sale_return_id' => $return['id'] ?? 'unknown',
                        'quantity' => $return['quantity'] ?? 0,
                        'free_quantity' => $return['free_quantity'] ?? 0,
                        'total_quantity' => $retTotalQty,
                        'unit_quantity' => $unitQty,
                        'ret_qty_int' => $retQtyInt,
                        'ret_qty_dec' => $retQtyDec,
                        'sale_return_pieces' => $saleReturnPieces,
                    ]);

                    return $saleReturnPieces;
                });

                // Adjust remaining quantities
                $remainingRegularQuantity = max($totalRegularQuantity - $returnedRegularInPieces, 0);
                $remainingFreeQuantity = max($totalFreeQuantity - $returnedFreeInPieces, 0);
                $remainingQuantityInPieces = max($totalPurchaseQuantityInPieces - $totalReturnedInPieces - $totalSoldInPieces + $totalSaleReturnsInPieces, 0);
                $remainingQuantityInUOM = $remainingQuantityInPieces / ($unitData['quantity'] ?? 1);

                Log::debug('Final remaining quantity calculation', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'total_purchase_quantity_in_pieces' => $totalPurchaseQuantityInPieces,
                    'total_returned_in_pieces' => $totalReturnedInPieces,
                    'total_sold_in_pieces' => $totalSoldInPieces,
                    'total_sale_returns_in_pieces' => $totalSaleReturnsInPieces,
                    'remaining_quantity_in_pieces' => $remainingQuantityInPieces,
                    'remaining_quantity_in_uom' => $remainingQuantityInUOM,
                ]);

                // Process field values
                $unavailableQuantityIndices = [];
                $groupedFieldValues = [];

                // Handle purchase returns
                if (!empty($product['purchase_stock_product_returns'])) {
                    $returnIds = array_column($product['purchase_stock_product_returns'], 'id');
                    $unavailableQuantityIndices = PurchaseStockProductReturnFieldValue::whereIn('purchase_stock_product_return_id', $returnIds)
                        ->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->where('branch_id', $branchId)
                        ->pluck('quantity_index')
                        ->toArray();
                }

                // Handle sold units
                $saleProductIds = array_column($product['sale_products'] ?? [], 'id');
                $soldQuantityIndices = SalesProductFieldValue::whereIn('sale_product_id', $saleProductIds)
                    ->whereNull('deleted_at')
                    ->where('company_id', $companyId)
                    ->pluck('quantity_index')
                    ->toArray();
                $unavailableQuantityIndices = array_merge($unavailableQuantityIndices, $soldQuantityIndices);

                // Handle stock transfers
                $stockTransferQuantityIndices = StockTransferFieldValue::where('purchase_stock_product_id', $product['id'])
                    ->whereNull('deleted_at')
                    ->where('company_id', $companyId)
                    ->where('branch_id', $branchId)
                    ->pluck('quantity_index')
                    ->toArray();
                $unavailableQuantityIndices = array_merge($unavailableQuantityIndices, $stockTransferQuantityIndices);
                // Handle sales returns
                $saleReturnFieldValues = [];
                $saleReturnedIndices = [];
                if ($totalSaleReturnsInPieces > 0) {
                    $saleReturnFieldValues = SaleReturnProductFieldValue::whereIn(
                        'sale_return_product_id',
                        SalesReturnProduct::whereIn('sale_product_id', $saleProductIds)
                            ->whereNull('deleted_at')
                            ->where('company_id', $companyId)
                            ->pluck('id')
                    )
                        ->whereNull('deleted_at')
                        ->where('company_id', $companyId)
                        ->with(['productField' => fn($q) => $q->select('id', 'name')])
                        ->get()
                        ->groupBy('quantity_index')
                        ->map(function ($group) {
                            return $group->map(function ($field) {
                                return [
                                    'product_field_id' => $field->product_field_id,
                                    'value' => $field->value,
                                    'quantity_index' => $field->quantity_index,
                                    'quantity_type' => $field->quantity_type,
                                    'name' => $field->productField->name ?? 'N/A',
                                ];
                            })->toArray();
                        })->toArray();

                    $saleReturnedIndices = array_keys($saleReturnFieldValues);
                    $unavailableQuantityIndices = array_diff(array_unique($unavailableQuantityIndices), $saleReturnedIndices);
                }

                // Group available field values
                if (!empty($product['field_values'])) {
                    foreach ($product['field_values'] as $fieldValue) {
                        $quantityIndex = $fieldValue['quantity_index'] ?? 0;
                        if (in_array($quantityIndex, $unavailableQuantityIndices)) {
                            continue;
                        }
                        $groupedFieldValues[$quantityIndex][] = [
                            'purchase_stock_product_id' => $fieldValue['purchase_stock_product_id'] ?? null,
                            'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
                            'stock_product_id' => $fieldValue['stock_product_id'] ?? null,
                            'stock_reconciliation_id' => $fieldValue['stock_reconciliation_id'] ?? null,
                            'stock_adjustment_id' => $fieldValue['stock_adjustment_id'] ?? null,
                            'stock_transfer_id' => $fieldValue['stock_transfer_id'] ?? null,
                            'product_field_id' => $fieldValue['product_field_id'] ?? null,
                            'name' => $fieldValue['product_field']['name'] ?? 'N/A',
                            'quantity_index' => $quantityIndex,
                            'quantity_type' => $fieldValue['quantity_type'] ?? null,
                            'value' => $fieldValue['value'] ?? null,
                        ];
                    }
                }

                // Override with sales return field values
                if (!empty($saleReturnedIndices)) {
                    foreach ($saleReturnedIndices as $quantityIndex) {
                        if (isset($saleReturnFieldValues[$quantityIndex])) {
                            $groupedFieldValues[$quantityIndex] = $saleReturnFieldValues[$quantityIndex];
                        }
                    }
                }

                // Limit to available quantity and filter out empty arrays
                $groupedFieldValues = array_slice($groupedFieldValues, 0, (int) $remainingQuantityInPieces, true);
                $groupedFieldValues = array_filter($groupedFieldValues, fn($value) => !empty($value));

                Log::debug('Field values processing', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'grouped_field_values' => $groupedFieldValues,
                    'unavailable_quantity_indices' => $unavailableQuantityIndices,
                    'sale_returned_indices' => $saleReturnedIndices,
                ]);

                $getOriginalPrice = Product::where('id', $product['product_id'])->pluck('purchase_rate')->first();

                $getProductForMeasureUnits = Product::with('productLists')
                    ->where('id', $product['product_id'])
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->first();

                $allUnitIds = collect([]);
                if ($getProductForMeasureUnits) {
                    // Step 1: Get measure_unit_id from Product
                    $unitIds = collect([$getProductForMeasureUnits->measure_unit_id]);

                    // Step 2: Add all measure_unit_ids from ProductList
                    $productListUnitIds = $getProductForMeasureUnits->productLists->pluck('measure_unit_id');

                    // Step 3: Merge and make unique
                    $allUnitIds = $unitIds->merge($productListUnitIds)->unique()->values();
                } else {
                    Log::warning('Product not found for measure units', [
                        'product_id' => $product['product_id'] ?? 'unknown',
                        'company_id' => $companyId,
                    ]);
                }

                $measureUnitsForProducts = MeasureUnit::whereIn('id', $allUnitIds)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->get(['id', 'name', 'quantity'])
                    ->map(function ($unit) {
                        return [
                            'id' => $unit->id,
                            'name' => $unit->name,
                            'measure_unit_quantity' => $unit->quantity ?? null,
                        ];
                    });

                Log::debug('Measure units for product', [
                    'product_id' => $product['product_id'] ?? 'unknown',
                    'unit_ids' => $allUnitIds->toArray(),
                    'measure_units' => $measureUnitsForProducts->toArray(),
                ]);

                // Prepare product data, filtering out invalid values
                $productData = array_filter([
                    'purchase_stock_product_id' => $product['id'] ?? null,
                    'purchase_product_id' => $product['purchase_product_id'] ?? null,
                    'stock_transfer_id' => $product['stock_transfer_id'] ?? null,
                    'stock_product_id' => $product['stock_product_id'] ?? null,
                    'stock_reconciliation_id' => $product['stock_reconciliation_id'] ?? null,
                    'stock_adjustment_id' => $product['stock_adjustment_id'] ?? null,
                    'purchase_id' => $product['purchase_id'] ?? null,
                    'product_id' => $product['product_id'] ?? null,
                    'product_name' => $product['product_name'] ?? null,
                    'product_code' => $product['product_code'] ?? null,
                    'quantity' => $quantity,
                    'measure_unit_id' => $unitData['id'] ?? 0,
                    'measure_unit_quantity' => $unitData['quantity'] ?? 1,
                    'measure_unit_name' => $unitData['name'] ?? 'null',
                    'amount' => $product['amount'] ?? 0,
                    'free_quantity' => $freeQuantity,
                    'purchased_quantity' => $totalPurchaseQuantityInPieces,
                    'returned_quantity' => $totalReturnedInPieces,
                    'sold_quantity' => $totalSoldInPieces,
                    'sale_returned_quantity' => $totalSaleReturnsInPieces,
                    'measure_units_for_products' => $measureUnitsForProducts->toArray(),
                    'original_price' => $getOriginalPrice ?? 0,
                    'remaining_quantity' => $remainingQuantityInPieces,
                    'regular_remaining_quantity' => $remainingRegularQuantity,
                    'free_remaining_quantity' => $remainingFreeQuantity,
                    'remaining_quantity_in_uom' => $remainingQuantityInUOM,
                    'price' => $product['price'] ?? 0,
                    'is_vatable' => (bool) ($product['is_vatable'] ?? false),
                    'expiry_date' => $product['expiry_date'] ?? null,
                    'field_values' => array_values($groupedFieldValues),
                ], function ($value) {
                    return !is_null($value) && (!is_array($value) || !empty($value));
                });

                Log::debug('Final product data', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'product_data' => $productData,
                ]);

                return $productData;
            })->values()->toArray();

            if (empty($purchaseProducts)) {
                Log::info('No products with available quantity found', [
                    'purchase_id' => $purchase->id,
                    'company_id' => $companyId,
                    'query_log' => DB::getQueryLog(),
                ]);
                return response()->json(['error' => 'No products with available quantity found'], 404);
            }

            $purchaseData['purchase_stock_products'] = $purchaseProducts;

            return response()->json([
                'message' => 'Purchase details retrieved successfully',
                'data' => $purchaseData,
            ], 200);
        } catch (ModelNotFoundException $e) {
            Log::error('Purchase not found', [
                'company_id' => $companyId,
                'purchase_bill_number' => $purchaseBillNumber,
                'purchase_number' => $purchaseNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog(),
            ]);
            return response()->json(['error' => 'Purchase not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database query error', [
                'company_id' => $companyId,
                'purchase_bill_number' => $purchaseBillNumber,
                'purchase_number' => $purchaseNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog(),
            ]);
            return response()->json(['error' => 'Database error: ' . (config('app.debug') ? $e->getMessage() : 'An error occurred')], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error occurred', [
                'company_id' => $companyId,
                'purchase_bill_number' => $purchaseBillNumber,
                'purchase_number' => $purchaseNumber,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog(),
            ]);
            return response()->json(['error' => 'An unexpected error occurred: ' . (config('app.debug') ? $e->getMessage() : 'An error occurred')], 500);
        } finally {
            DB::disableQueryLog();
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
                $totalSaleReturns = SalesReturnProduct::whereIn(
                    'sale_product_id',
                    SaleProduct::where('purchase_product_id', $product['id'])
                        ->whereNull('deleted_at')
                        ->pluck('id')
                )
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
                $soldQuantityIndices = SalesProductFieldValue::whereIn(
                    'sale_product_id',
                    SaleProduct::where('purchase_product_id', $product['id'])
                        ->whereNull('deleted_at')
                        ->pluck('id')
                )
                    ->whereNull('deleted_at')
                    ->pluck('quantity_index')
                    ->toArray();
                $unavailableQuantityIndices = array_merge($unavailableQuantityIndices, $soldQuantityIndices);

                // 3. Sales-returned units
                $saleReturnedIndices = [];
                $saleReturnFieldValues = [];
                if ($totalSaleReturns > 0) {
                    $saleReturnFieldValues = SaleReturnProductFieldValue::whereIn(
                        'sale_return_product_id',
                        SalesReturnProduct::whereIn(
                            'sale_product_id',
                            SaleProduct::where('purchase_product_id', $product['id'])
                                ->whereNull('deleted_at')
                                ->pluck('id')
                        )
                            ->whereNull('deleted_at')
                            ->pluck('id')
                    )
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
            $productDetails = Helper::getPurchaseProductDetails($name, $company);



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


    public function getPurchaseProductNames(Request $request): JsonResponse
    {
        try {
            if (!$request->has('company_id')) {
                return response()->json(['error' => 'Missing required parameter: company_id'], 422);
            }

            if (!$request->has('branch_id')) {
                return response()->json(['error' => 'Missing required parameter: branch_id'], 422);
            }

            if (!$request->has('purchase_type')) {
                return response()->json([], 200);
            }

            $purchaseType = $request->purchase_type;

            // Get unique product IDs with available quantities for return
            $productIds = PurchaseStockProduct::where('purchase_stock_products.company_id', $request->company_id)
                ->where('branch_id', $request->branch_id)

                ->whereNull('purchase_stock_products.deleted_at')
                // ->join('purchases', 'purchases.id', '=', 'purchase_products.purchase_id')
                // ->whereNull('purchases.deleted_at')
                ->where('purchase_stock_products.purchase_type', $request->purchase_type)
                ->whereRaw('
                    (
                        (purchase_stock_products.quantity + COALESCE(purchase_stock_products.free_quantity, 0)) - 
                        COALESCE((
                            SELECT SUM(purchase_stock_product_returns.quantity + COALESCE(purchase_stock_product_returns.free_quantity, 0))
                            FROM purchase_stock_product_returns
                            WHERE purchase_stock_product_returns.purchase_stock_product_id = purchase_stock_products.id
                            AND purchase_stock_product_returns.deleted_at IS NULL
                        ), 0) - 
                        COALESCE((
                            SELECT SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0))
                            FROM sale_products
                            WHERE sale_products.purchase_product_id = purchase_stock_products.id
                            AND sale_products.deleted_at IS NULL
                        ), 0) + 
                        COALESCE((
                            SELECT SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0))
                            FROM sales_return_products
                            WHERE sales_return_products.sale_product_id IN (
                                SELECT id FROM sale_products
                                WHERE sale_products.purchase_product_id = purchase_stock_products.id
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
                return response()->json(['error' => 'No products with available quantities found'], 200);
            }

            // Get product names using the helper function
            $productNames = PurchaseReturnHelper::getPurchaseProductforPurchaseReturn($productIds, $request->company_id, $request->branch_id, $purchaseType);

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

            if (!$request->has('branch_id')) {
                return response()->json(['error' => 'Missing required parameter: branch_id'], 422);
            }

            if (!$request->has('purchase_type')) {
                return response()->json(['error' => 'Missing required parameter: purchase_type'], 422);
            }

            $purchaseType = $request->purchase_type;

            // Fetch product codes with available quantities
            $productCodes = PurchaseProduct::where('purchase_products.company_id', $request->company_id)
                ->whereNull('purchase_products.deleted_at')
                ->join('purchases', 'purchases.id', '=', 'purchase_products.purchase_id')
                ->whereNull('purchases.deleted_at')
                ->where('purchases.purchase_type', $request->purchase_type)
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
            $productDetails = PurchaseReturnHelper::getPurchaseProductforPurchaseReturnByPrductId($productCodes, $request->company_id, $request->branch_id, $purchaseType);

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

            if (!$request->has('company_id')) {
                return response()->json(['error' => 'Missing required parameter: company_id'], 422);
            }
            if (!$request->has('branch_id')) {
                return response()->json(['error' => 'Missing required parameter: branch_id'], 422);
            }

            if (!$request->has('purchase_type')) {
                return response()->json(['error' => 'Missing required parameter: purchase_type'], 422);
            }

            $purchaseType = $request->purchase_type;


            $productIds = PurchaseStockProduct::where('purchase_stock_products.company_id', $request->company_id)
                ->where('purchase_stock_products.branch_id', $request->branch_id)
                ->where('purchase_stock_products.purchase_type', $request->purchase_type)
                ->whereNull('purchase_stock_products.deleted_at')


                ->whereRaw('
                    (
                        (purchase_stock_products.quantity + COALESCE(purchase_stock_products.free_quantity, 0)) -
                        COALESCE((
                            SELECT SUM(purchase_stock_product_returns.quantity + COALESCE(purchase_stock_product_returns.free_quantity, 0))
                            FROM purchase_stock_product_returns
                            WHERE purchase_stock_product_returns.purchase_stock_product_id = purchase_stock_products.id
                            AND purchase_stock_product_returns.deleted_at IS NULL
                        ), 0) -
                        COALESCE((
                            SELECT SUM(sale_products.quantity + COALESCE(sale_products.free_quantity, 0))
                            FROM sale_products
                            WHERE sale_products.purchase_product_id = purchase_stock_products.id
                            AND sale_products.deleted_at IS NULL
                        ), 0) +
                        COALESCE((
                            SELECT SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0))
                            FROM sales_return_products
                            WHERE sales_return_products.sale_product_id IN (
                                SELECT id FROM sale_products
                                WHERE sale_products.purchase_product_id = purchase_stock_products.id
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


            $productDetails = PurchaseReturnHelper::getPurchaseProductforPurchaseReturnByBarcode($productIds, $request->company_id, $request->branch_id, $purchaseType);


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
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer',
                'branch_id' => 'required|integer|exists:branches,id',
                'product_code' => 'nullable|string|max:255',
                'product_name' => 'nullable|string|max:255',
                'barcode' => 'nullable|string|max:255',
                'purchase_bill_number' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                Log::warning('Validation failed', ['errors' => $validator->errors()]);
                return response()->json(['errors' => $validator->errors()], 422);
            }

            if (!$request->hasAny(['product_code', 'product_name', 'barcode', 'purchase_bill_number'])) {
                Log::warning('No valid search parameters provided', ['request' => $request->all()]);
                return response()->json(['error' => 'At least one of product_code, product_name, barcode, or purchase_bill_number is required'], 422);
            }

            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');
            $productCode = $request->input('product_code');
            $productName = trim(strtolower($request->input('product_name')));
            $barcode = $request->input('barcode');
            $purchaseBillNumber = $request->input('purchase_bill_number');

            Log::debug('Input parameters', [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'product_code' => $productCode,
                'product_name' => $productName,
                'barcode' => $barcode,
                'purchase_bill_number' => $purchaseBillNumber
            ]);


            DB::enableQueryLog();


            $measureUnitsCalc = MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');


            $purchaseProductsQuery = DB::table('purchase_stock_products')
                ->select([
                    'purchase_stock_products.id as purchase_stock_product_id',
                    'purchase_stock_products.purchase_id',
                    'purchase_stock_products.product_id',
                    'purchase_stock_products.product_name',
                    'purchase_stock_products.product_code',
                    'purchase_stock_products.quantity',
                    'purchase_stock_products.free_quantity',
                    'purchase_stock_products.expiry_date',
                    'purchase_stock_products.price',
                    'purchase_stock_products.is_vatable',
                    'purchase_stock_products.measure_unit_id',
                    'measure_units.name as measure_unit_name',
                    'measure_units.quantity as measure_unit_quantity',
                    // 'purchases.purchase_bill_number',
                    // 'purchases.invoice_date',
                ])
                ->join('measure_units', 'purchase_stock_products.measure_unit_id', '=', 'measure_units.id')
                // ->leftJoin('purchases', function ($join) use ($companyId) {
                //     $join->on('purchase_products.purchase_id', '=', 'purchases.id')
                //         ->where('purchases.company_id', $companyId)
                //         ->whereNull('purchases.deleted_at');
                // })
                ->where('purchase_stock_products.company_id', $companyId)
                ->where('purchase_stock_products.branch_id', $branchId)
                ->whereNull('purchase_stock_products.deleted_at')
                ->where('measure_units.company_id', $companyId)
                ->whereNull('measure_units.deleted_at')
                ->when($productCode, fn($q) => $q->where('purchase_stock_products.product_code', $productCode))
                ->when($productName, fn($q) => $q->whereRaw('LOWER(purchase_stock_products.product_name)  = ?', [strtolower($productName)]))
                ->when($barcode, fn($q) => $q->whereIn('purchase_stock_products.id', function ($subQuery) use ($barcode, $companyId) {
                    $subQuery->select('purchase_stock_product_id')
                        ->from('purchase_stock_product_field_values')
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->where('value', $barcode)
                        ->where('product_field_id', env('BARCODE_FIELD_ID', 1));
                }))
                // ->when($purchaseBillNumber, fn($q) => $q->where('purchases.purchase_bill_number', $purchaseBillNumber))
                // ->orderBy('purchases.invoice_date', 'ASC')
                ->orderBy('purchase_stock_products.created_at', 'ASC');

            // Fetch purchase products
            $purchaseProducts = $purchaseProductsQuery->get();
            Log::debug('Purchase products query results', [
                'purchase_stock_products' => $purchaseProducts,
                'query' => $purchaseProductsQuery->toSql(),
                'bindings' => $purchaseProductsQuery->getBindings()
            ]);

            if ($purchaseProducts->isEmpty()) {
                Log::info('No purchase products found', [
                    'company_id' => $companyId,
                    'branch_id' => $companyId,
                    'filters' => $request->only(['product_code', 'product_name', 'barcode', 'purchase_bill_number']),
                    'query_log' => DB::getQueryLog()
                ]);
                return response()->json(['error' => 'No products found matching the criteria'], 404);
            }

            // Fetch related data for calculations
            $purchaseProductIds = $purchaseProducts->pluck('purchase_stock_product_id')->toArray();

            $productId = $purchaseProducts->pluck('product_id')->unique()->toArray();


            $purchaseProductReturns = DB::table('purchase_stock_product_returns')
                ->select([
                    'purchase_stock_product_returns.purchase_stock_product_id',
                    'purchase_stock_product_returns.quantity',
                    'purchase_stock_product_returns.free_quantity',
                    'purchase_stock_product_returns.measure_unit_id',
                ])
                ->whereIn('purchase_stock_product_returns.purchase_stock_product_id', $purchaseProductIds)
                ->where('purchase_stock_product_returns.company_id', $companyId)
                ->where('purchase_stock_product_returns.branch_id', $branchId)
                ->whereNull('purchase_stock_product_returns.deleted_at')
                ->get()
                ->groupBy('purchase_stock_product_id');

            $saleProducts = DB::table('sale_products')
                ->select([
                    'sale_products.purchase_stock_product_id',
                    'sale_products.quantity',
                    'sale_products.free_quantity',
                    'sale_products.measure_unit_id',
                ])
                ->whereIn('sale_products.purchase_stock_product_id', $purchaseProductIds)
                ->where('sale_products.company_id', $companyId)
                ->where('sale_products.branch_id', $branchId)
                ->whereNull('sale_products.deleted_at')
                ->get()
                ->groupBy('purchase_stock_product_id');

            $salesReturnProducts = DB::table('sales_return_products')
                ->select([
                    'sale_products.purchase_stock_product_id',
                    'sales_return_products.quantity',
                    'sales_return_products.free_quantity',
                    'sales_return_products.measure_unit_id',
                ])
                ->join('sale_products', 'sales_return_products.sale_product_id', '=', 'sale_products.id')
                ->whereIn('sale_products.purchase_stock_product_id', $purchaseProductIds)
                ->where('sales_return_products.company_id', $companyId)
                ->where('sales_return_products.branch_id', $branchId)
                ->whereNull('sales_return_products.deleted_at')
                ->get()
                ->groupBy('purchase_stock_product_id');

            // Fetch field values and quantity indexes
            $soldQuantityIndexes = DB::table('sales_product_field_values')
                ->select([
                    'sale_products.purchase_stock_product_id',
                    'sales_product_field_values.quantity_index'
                ])
                ->join('sale_products', 'sales_product_field_values.sale_product_id', '=', 'sale_products.id')
                ->whereIn('sale_products.purchase_stock_product_id', $purchaseProductIds)
                ->where('sale_products.company_id', $companyId)
                ->where('sale_products.branch_id', $branchId)
                ->whereNull('sale_products.deleted_at')
                ->distinct()
                ->get()
                ->groupBy('purchase_stock_product_id')
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            $returnedQuantityIndexes = DB::table('purchase_stock_product_return_field_values')
                ->select([
                    'purchase_stock_product_returns.purchase_stock_product_id',
                    'purchase_stock_product_return_field_values.quantity_index'
                ])
                ->join('purchase_stock_product_returns', 'purchase_stock_product_return_field_values.purchase_stock_product_return_id', '=', 'purchase_stock_product_returns.id')
                ->whereIn('purchase_stock_product_returns.purchase_stock_product_id', $purchaseProductIds)
                ->where('purchase_stock_product_returns.company_id', $companyId)
                ->where('purchase_stock_product_returns.branch_id', $branchId)
                ->whereNull('purchase_stock_product_returns.deleted_at')
                ->distinct()
                ->get()
                ->groupBy('purchase_stock_product_id')
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            $fieldValues = DB::table('purchase_stock_product_field_values')
                ->select([
                    'purchase_stock_product_field_values.purchase_stock_product_id',
                    'purchase_stock_product_field_values.product_field_id',
                    'product_fields.name as product_field_name',
                    'purchase_stock_product_field_values.value',
                    'purchase_stock_product_field_values.quantity_index'

                ])
                ->leftJoin('product_fields', fn($join) => $join->on('purchase_stock_product_field_values.product_field_id', '=', 'product_fields.id')
                    ->where('product_fields.company_id', $companyId)
                    ->whereNull('product_fields.deleted_at'))
                ->leftJoin('purchase_stock_products', 'purchase_stock_product_field_values.purchase_stock_product_id', '=', 'purchase_stock_products.id')
                ->join('product_field_values', function ($join) use ($companyId, $productId) {
                    $join->on('purchase_stock_product_field_values.product_field_id', '=', 'product_field_values.product_field_id')

                        ->where('product_field_values.company_id', $companyId)
                        ->whereIn('product_field_values.product_id', $productId)
                        ->whereRaw('product_field_values.product_id = purchase_stock_products.product_id')
                        ->whereNull('product_field_values.deleted_at');
                })

                ->whereIn('purchase_stock_product_field_values.purchase_stock_product_id', $purchaseProductIds)
                ->where('purchase_stock_product_field_values.company_id', $companyId)
                ->where('purchase_stock_product_field_values.branch_id', $branchId)
                ->whereNull('purchase_stock_product_field_values.deleted_at')
                ->orderBy('purchase_stock_product_field_values.quantity_index', 'ASC')
                ->get()
                ->groupBy('purchase_stock_product_id');

            $saleReturnFieldValues = DB::table('sale_return_product_field_values')
                ->select([
                    'sale_products.purchase_stock_product_id',
                    'sale_return_product_field_values.product_field_id',
                    'product_fields.name as product_field_name',
                    'sale_return_product_field_values.value',
                    'sale_return_product_field_values.quantity_index'
                ])
                ->join('sales_return_products', 'sale_return_product_field_values.sale_return_product_id', '=', 'sales_return_products.id')
                ->join('sale_products', 'sales_return_products.sale_product_id', '=', 'sale_products.id')
                ->leftJoin('product_fields', fn($join) => $join->on('sale_return_product_field_values.product_field_id', '=', 'product_fields.id')
                    ->where('product_fields.company_id', $companyId))
                ->whereIn('sale_products.purchase_stock_product_id', $purchaseProductIds)
                ->where('sale_return_product_field_values.company_id', $companyId)
                ->whereNull('sale_return_product_field_values.deleted_at')
                ->get()
                ->groupBy('purchase_stock_product_id');

            // Process purchase products
            $purchaseProducts = $purchaseProducts->map(function ($pp) use ($measureUnitsCalc, $purchaseProductReturns, $saleProducts, $salesReturnProducts) {
                $measureUnitId = $pp->measure_unit_id ?? null;
                $unitData = isset($measureUnitsCalc[$measureUnitId]) ? [
                    'id' => $measureUnitsCalc[$measureUnitId]->id,
                    'name' => $measureUnitsCalc[$measureUnitId]->name,
                    'quantity' => $measureUnitsCalc[$measureUnitId]->quantity ?? 1
                ] : [
                    'id' => null,
                    'name' => 'null',
                    'quantity' => 1
                ];

                // Calculate total purchase quantity in pieces
                $quantity = $pp->quantity ?? 0; // e.g., 2.2
                $freeQuantity = $pp->free_quantity ?? 0; // e.g., 2.3
                $totalQuantity = $quantity + $freeQuantity; // 4.5
                $decimalStr = explode('.', (string) $totalQuantity); // ['4', '5']
                $quantityInt = floor($totalQuantity); // 4
                $decimalDigits = isset($decimalStr[1]) ? (float) $decimalStr[1] : 0; // 5.0
                $totalPurchaseQuantityInPieces = ($quantityInt * $unitData['quantity']) + $decimalDigits; // (4 * 2) + 5 = 13

                // Calculate returned quantities
                $totalReturnedInPieces = collect($purchaseProductReturns[$pp->purchase_stock_product_id] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return->measure_unit_id ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retTotalQty = ($return->quantity ?? 0) + ($return->free_quantity ?? 0);
                    $retDecimalStr = explode('.', (string) $retTotalQty);
                    $retQtyInt = floor($retTotalQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (float) $retDecimalStr[1] : 0;
                    return ($retQtyInt * $unitQty) + $retQtyDec;
                });

                // Calculate sold quantities
                $totalSoldInPieces = collect($saleProducts[$pp->purchase_stock_product_id] ?? [])->sum(function ($sale) use ($measureUnitsCalc) {
                    $unitId = $sale->measure_unit_id ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $saleTotalQty = ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0);
                    $saleDecimalStr = explode('.', (string) $saleTotalQty);
                    $saleQtyInt = floor($saleTotalQty);
                    $saleQtyDec = isset($saleDecimalStr[1]) ? (float) $saleDecimalStr[1] : 0;
                    return ($saleQtyInt * $unitQty) + $saleQtyDec;
                });

                // Calculate sale returns
                $totalSaleReturnsInPieces = collect($salesReturnProducts[$pp->purchase_stock_product_id] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return->measure_unit_id ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retTotalQty = ($return->quantity ?? 0) + ($return->free_quantity ?? 0);
                    $retDecimalStr = explode('.', (string) $retTotalQty);
                    $retQtyInt = floor($retTotalQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (float) $retDecimalStr[1] : 0;
                    return ($retQtyInt * $unitQty) + $retQtyDec;
                });

                $remainingQuantityInPieces = max($totalPurchaseQuantityInPieces - $totalReturnedInPieces - $totalSoldInPieces + $totalSaleReturnsInPieces, 0);
                $remainingQuantityInUOM = $remainingQuantityInPieces / ($unitData['quantity'] ?? 1);

                // Log calculations
                Log::debug('Quantity Calculation', [
                    'purchase_stock_product_id' => $pp->purchase_stock_product_id,
                    'quantity' => $quantity,
                    'free_quantity' => $freeQuantity,
                    'total_quantity' => $totalQuantity,
                    'measure_unit_quantity' => $unitData['quantity'],
                    'total_purchase_quantity_in_pieces' => $totalPurchaseQuantityInPieces,
                    'total_returned_in_pieces' => $totalReturnedInPieces,
                    'total_sold_in_pieces' => $totalSoldInPieces,
                    'total_sale_returns_in_pieces' => $totalSaleReturnsInPieces,
                    'remaining_quantity_in_pieces' => $remainingQuantityInPieces,
                    'remaining_quantity_in_uom' => $remainingQuantityInUOM
                ]);

                return (object) array_merge((array) $pp, [
                    'total_purchase_quantity_in_pieces' => $totalPurchaseQuantityInPieces,
                    'total_returned_in_pieces' => $totalReturnedInPieces,
                    'total_sold_in_pieces' => $totalSoldInPieces,
                    'total_sale_returns_in_pieces' => $totalSaleReturnsInPieces,
                    'remaining_quantity_in_pieces' => $remainingQuantityInPieces,
                    'remaining_quantity_in_uom' => $remainingQuantityInUOM,
                ]);
            })->filter(fn($pp) => $pp->remaining_quantity_in_pieces > 0);

            // Group by product_id for aggregation
            $products = $purchaseProducts->groupBy('product_id')->map(function ($group) use ($companyId, $measureUnitsCalc, $fieldValues, $saleReturnFieldValues, $soldQuantityIndexes, $returnedQuantityIndexes) {
                $first = $group->first();

                // Aggregate quantities
                $purchasedQuantity = $group->sum('total_purchase_quantity_in_pieces');
                $returnQuantity = $group->sum('total_returned_in_pieces');
                $saleQuantity = $group->sum('total_sold_in_pieces');
                $salesReturnQuantity = $group->sum('total_sale_returns_in_pieces');
                $availableQuantity = max($purchasedQuantity - $returnQuantity - $saleQuantity + $salesReturnQuantity, 0);

                // Fetch product metadata
                $product = Product::where('id', $first->product_id)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->first();



                $originalProductPrice = Product::where('id', $first->product_id)->value('purchase_rate');

                $purchaseProductsPrice = PurchaseStockProduct::where('product_id', $first->product_id)->orderBy('created_at', 'desc')->pluck('price');
                $latestPrice = $purchaseProductsPrice->first();

                // Get the minimum price
                $minProductPrice = $purchaseProductsPrice->min();

                // Get the average price
                $avgProductPrice = $purchaseProductsPrice->avg();




                $getProductForMeasureUnits = Product::with('productLists')
                    ->where('id', $product->id)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->first();


                if ($getProductForMeasureUnits) {
                    // Step 1: Get measure_unit_id from Product
                    $unitIds = collect([$getProductForMeasureUnits->measure_unit_id]);

                    // Step 2: Add all measure_unit_ids from ProductList
                    $productListUnitIds = $getProductForMeasureUnits->productLists->pluck('measure_unit_id');

                    // Step 3: Merge and make unique
                    $allUnitIds = $unitIds->merge($productListUnitIds)->unique()->values();
                } else {
                    echo ('Product not found');
                }

                $measureUnitsForProducts = MeasureUnit::whereIn('id', $allUnitIds)
                    ->where('company_id', $companyId)
                    ->whereNull('deleted_at')
                    ->get(['id', 'name', 'quantity']) // Get as a collection
                    ->map(function ($unit) {
                        return [
                            'id' => $unit->id,
                            'name' => $unit->name,
                            'measure_unit_quantity' => $unit->quantity ?? null,
                        ];
                    });


                $productFieldValues = collect();
                $productPurchaseProducts = $group->map(function ($pp) use ($fieldValues, $saleReturnFieldValues, $soldQuantityIndexes, $returnedQuantityIndexes, &$productFieldValues) {
                    $availableUnits = (int) $pp->remaining_quantity_in_pieces;
                    if ($availableUnits > 0 && isset($fieldValues[$pp->purchase_stock_product_id])) {
                        $soldIndexes = $soldQuantityIndexes[$pp->purchase_stock_product_id] ?? [];
                        $returnedIndexes = $returnedQuantityIndexes[$pp->purchase_stock_product_id] ?? [];
                        $excludedIndexes = array_unique(array_merge($soldIndexes, $returnedIndexes));

                        $ppFieldValues = $fieldValues[$pp->purchase_stock_product_id]
                            ->filter(fn($fv) => !in_array($fv->quantity_index, $excludedIndexes))
                            ->groupBy('quantity_index')
                            ->take($availableUnits)
                            ->flatten(1)
                            ->map(fn($fv) => [
                                // 'purchase_id' => $fv->purchase_id,
                                // 'purchase_bill_number' => $fv->purchase_bill_number ?? '',
                                'purchase_stock_product_id' => $fv->purchase_stock_product_id,
                                'purchase_product_id' => $fv->purchase_product_id ?? null,

                                'stock_product_id' => $fv->stock_product_id ?? null,
                                'stock_adjustment_id' => $fv->stock_adjustment_id ?? null,
                                'stock_transfer_id' => $fv->stock_transfer_id ?? null,
                                'stock_reconciliation_id' => $fv->stock_reconciliation_id ?? null,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->product_field_name ?? 'N/A',
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ])->values();
                        $productFieldValues = $productFieldValues->merge($ppFieldValues);
                    }

                    if ($availableUnits > 0 && isset($saleReturnFieldValues[$pp->purchase_stock_product_id])) {
                        $ppSaleReturnFieldValues = $saleReturnFieldValues[$pp->purchase_stock_product_id]
                            ->groupBy('purchase_stock_product_id')
                            ->take($availableUnits)
                            ->flatten(1)
                            ->map(fn($fv) => [
                                'purchase_id' => null,
                                'purchase_bill_number' => '',
                                'purchase_stock_product_id' => $fv->purchase_stock_product_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->product_field_name ?? 'N/A',
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ])->values();
                        $productFieldValues = $productFieldValues->merge($ppSaleReturnFieldValues);
                    }

                    return [
                        'purchase_stock_product_id' => $pp->purchase_stock_product_id,
                        // 'purchase_id' => $pp->purchase_id,
                        // 'purchase_bill_number' => $pp->purchase_bill_number,
                        // 'invoice_date' => $pp->invoice_date,
                        'product_id' => $pp->product_id,
                        'product_name' => $pp->product_name,
                        'product_code' => $pp->product_code,
                        'quantity' => $pp->quantity,
                        'free_quantity' => $pp->free_quantity ?? 0,
                        'price' => $pp->price,
                        'is_vatable' => (bool) $pp->is_vatable,

                        'measure_unit_id' => $pp->measure_unit_id,
                        'measure_unit_name' => $pp->measure_unit_name,
                        'measure_unit_quantity' => $pp->measure_unit_quantity,
                        'remaining_quantity_in_pieces' => $pp->remaining_quantity_in_pieces,
                        'remaining_quantity_in_uom' => $pp->remaining_quantity_in_uom,
                        'return_quantity' => $pp->total_returned_in_pieces,
                        'sale_quantity' => $pp->total_sold_in_pieces,
                        'sales_return_quantity' => $pp->total_sale_returns_in_pieces,
                        'expiry_date' => $pp->expiry_date
                    ];
                })->values()->toArray();

                if (empty($productPurchaseProducts)) {
                    Log::info('No purchase products found', [
                        'product_id' => $first->product_id,
                        'company_id' => $companyId
                    ]);
                    return null;
                }

                return [
                    'product_id' => $first->product_id,
                    'product_name' => $product ? $product->name : $first->product_name,
                    'product_code' => $first->product_code,
                    'original_price' => $originalProductPrice,
                    'min_price' => $minProductPrice,
                    'avg_price' => $avgProductPrice,
                    'latest_price' => $latestPrice,
                    'measure_units_for_products' => $measureUnitsForProducts,
                    'is_vatable' => (bool) $group->max('is_vatable'),
                    'measure_unit_id' => $first->measure_unit_id,
                    'measure_unit_name' => $first->measure_unit_name,
                    'measure_unit_quantity' => $first->measure_unit_quantity,
                    'purchased_quantity' => $purchasedQuantity,
                    'return_quantity' => $returnQuantity,
                    'sale_quantity' => $saleQuantity,
                    'sales_return_quantity' => $salesReturnQuantity,
                    'available_quantity' => $availableQuantity,
                    'expiry_dates' => array_filter($group->pluck('expiry_date')->unique()->toArray()),
                    'field_values' => $productFieldValues->values()->toArray(),
                    'purchase_stock_products' => $productPurchaseProducts
                ];
            })->filter()->values()->toArray();

            if (empty($products)) {
                Log::info('No products with available quantity found', [
                    'company_id' => $companyId,
                    'filters' => $request->only(['product_code', 'product_name', 'barcode', 'purchase_bill_number']),
                    'query_log' => DB::getQueryLog()
                ]);
                return response()->json(['error' => 'No products with available quantity found'], 404);
            }

            return response()->json([
                'message' => 'Product details retrieved successfully',
                'data' => $products,
            ], 200);
        } catch (ModelNotFoundException $e) {
            Log::error('Purchase product not found', [
                'error' => $e->getMessage(),
                'company_id' => $companyId,
                'inputs' => $request->all(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog()
            ]);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database query error', [
                'error' => $e->getMessage(),
                'company_id' => $companyId,
                'inputs' => $request->all(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog()
            ]);
            return response()->json(['error' => 'Database error: ' . (config('app.debug') ? $e->getMessage() : 'An error occurred')], 500);
        } catch (\Exception $e) {
            Log::error('Unexpected error occurred', [
                'error' => $e->getMessage(),
                'company_id' => $companyId,
                'inputs' => $request->all(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'trace' => $e->getTraceAsString(),
                'query_log' => DB::getQueryLog()
            ]);
            return response()->json(['error' => 'An unexpected error occurred: ' . (config('app.debug') ? $e->getMessage() : 'An unexpected error occurred')], 500);
        } finally {
            DB::disableQueryLog();
        }
    }


    public function storePurchaseReturnByInput(Request $request): JsonResponse
    {
        try {
            // Define validation rules
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer',
                'customer_id' => 'nullable|integer|exists:customers,id',
                'customer_name' => 'nullable|string|max:255',
                'pan_number' => 'nullable|numeric|digits:10',
                'invoice_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('purchase_stock_returns')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                            ->where('branch_id', $request->branch_id)
                            ->whereNull('deleted_at');
                    }),
                ],
                'address' => 'nullable|string|max:255',
                'customer_contact' => 'nullable|string|max:255',
                'purchase_number' => 'nullable|string|max:255',
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reason' => 'nullable|string|in:damaged,defective,incorrect,expired,other',
                'store_id' => 'nullable|integer|exists:stores,id',
                'location_id' => 'nullable|integer|exists:locations,id',
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
                'roundoff_type' => 'nullable|string',
                'total_amount' => 'nullable|numeric|min:0',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'purchase_return_products' => [
                    'required',
                    'array',
                    'min:1',
                    function ($attribute, $value, $fail) {
                        foreach ($value as $index => $product) {
                            if (empty($product['product_name']) && empty($product['purchase_product_code'])) {
                                $fail("At least one of product_name or purchase_product_code is required for product at index {$index}.");
                            }
                        }
                    },
                ],
                'purchase_return_products.*.product_id' => 'required|integer|exists:products,id',
                'purchase_return_products.*.purchase_product_code' => 'nullable|string|max:255',
                'purchase_return_products.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',
                'purchase_return_products.*.purchase_product_id' => 'nullable',
                'purchase_return_products.*.stock_product_id' => 'nullable',
                'purchase_return_products.*.stock_adjustment_id' => 'nullable',
                'purchase_return_products.*.stock_reconciliation_id' => 'nullable',
                'purchase_return_products.*.stock_transfer_id' => 'nullable',
                'purchase_return_products.*.product_name' => 'nullable|string|max:255',
                'purchase_return_products.*.mfd' => 'nullable|string|max:255',
                'purchase_return_products.*.customer_id' => 'nullable|integer|exists:customers,id',
                'purchase_return_products.*.quantity' => 'required|numeric|min:0',
                'purchase_return_products.*.free_quantity' => 'nullable|numeric|min:0',
                'purchase_return_products.*.price' => 'required|numeric|min:0',
                'purchase_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'purchase_return_products.*.discount_amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.is_vatable' => 'required|boolean',
                'purchase_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'purchase_return_products.*.expiry_date' => 'nullable|string|max:255',
                'purchase_return_products.*.field_values' => 'present|array',
                'purchase_return_products.*.field_values.*' => 'array|min:1',
                'purchase_return_products.*.field_values.*.*.purchase_stock_product_id' => 'required_if:field_values,array|integer|exists:purchase_stock_products,id',
                'purchase_return_products.*.field_values.*.*.purchase_product_id' => 'nullable',
                'purchase_return_products.*.field_values.*.*.stock_product_id' => 'nullable',
                'purchase_return_products.*.field_values.*.*.stock_adjustment_id' => 'nullable',
                'purchase_return_products.*.field_values.*.*.stock_reconciliation_id' => 'nullable',
                'purchase_return_products.*.field_values.*.*.stock_transfer_id' => 'nullable',
                'purchase_return_products.*.field_values.*.*.product_field_id' => 'required_if:field_values,array|integer|exists:product_fields,id',
                'purchase_return_products.*.field_values.*.*.value' => 'required_if:field_values,array|string|max:255',
                'purchase_return_products.*.field_values.*.*.quantity_index' => 'required_if:field_values,array|integer|min:0',
                'purchase_return_products.*.field_values.*.*.quantity_type' => 'nullable|string|in:regular,free',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
            }

            $validated = $validator->validated();
            $validated['branch_id'] = $request->branch_id;
            Log::debug('Validated request data', ['data' => $validated]);

            // Process in transaction
            $purchaseReturn = DB::transaction(function () use ($validated) {
                $processedProducts = [];
                $purchases = collect();
                // Initialize global allocation tracking
                $totalAllocatedPieces = [];

                foreach ($validated['purchase_return_products'] as $index => $productData) {
                    $regularQuantity = $productData['quantity'] ?? 0;
                    $freeQuantity = $productData['free_quantity'] ?? 0;

                    // Target measure unit
                    $targetMeasureUnit = MeasureUnit::findOrFail($productData['measure_unit_id']);
                    $targetMeasureUnitQuantity = $targetMeasureUnit->quantity ?? 1;

                    // Calculate requested pieces
                    $regularPieces = $this->calculatePieces($regularQuantity, $targetMeasureUnitQuantity);
                    $freePieces = $this->calculatePieces($freeQuantity, $targetMeasureUnitQuantity);
                    $totalRequestedPieces = $regularPieces + $freePieces;

                    Log::debug('Requested quantities', [
                        'product_id' => $productData['product_id'],
                        'index' => $index,
                        'regular_quantity' => $regularQuantity,
                        'free_quantity' => $freeQuantity,
                        'target_measure_unit_id' => $productData['measure_unit_id'],
                        'target_measure_unit_quantity' => $targetMeasureUnitQuantity,
                        'regular_pieces' => $regularPieces,
                        'free_pieces' => $freePieces,
                        'total_requested_pieces' => $totalRequestedPieces
                    ]);

                    // Normalize field values with robust flattening
                    $fieldValuesFlat = $this->flattenFieldValues($productData['field_values'], $index);
                    Log::debug('Flattened field values', [
                        'product_id' => $productData['product_id'],
                        'index' => $index,
                        'field_values_flat' => $fieldValuesFlat
                    ]);

                    // Validate field values
                    collect($fieldValuesFlat)->each(function ($fv) use ($index) {
                        if (empty($fv['purchase_stock_product_id']) || !is_numeric($fv['purchase_stock_product_id'])) {
                            throw new \Exception("Invalid purchase_stock_product_id in field_values at index {$index}");
                        }
                        if (!isset($fv['quantity_index']) || !is_numeric($fv['quantity_index']) || $fv['quantity_index'] < 0) {
                            throw new \Exception("Invalid quantity_index in field_values at index {$index}");
                        }
                    });

                    // Group field values
                    $groupedFieldValues = collect($fieldValuesFlat)
                        ->groupBy('purchase_stock_product_id')
                        ->map(function ($group): array {
                            return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                                return collect($fvGroup)->map(function ($fv) {
                                    return [
                                        'product_field_id' => $fv['product_field_id'],
                                        'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                        'stock_product_id' => $fv['stock_product_id'],
                                        'stock_transfer_id' => $fv['stock_transfer_id'],
                                        'stock_adjustment_id' => $fv['stock_adjustment_id'],
                                        'stock_reconciliation_id' => $fv['stock_reconciliation_id'],
                                        'purchase_product_id' => $fv['purchase_product_id'],
                                        'value' => $fv['value'],
                                        'quantity_index' => $fv['quantity_index'],
                                        'quantity_type' => $fv['quantity_type'] ?? 'regular'
                                    ];
                                })->unique(function ($fv) {
                                    return "{$fv['product_field_id']}:{$fv['value']}:{$fv['quantity_type']}";
                                })->values()->toArray();
                            })->toArray();
                        })
                        ->toArray();

                    Log::debug('Grouped field values', [
                        'product_id' => $productData['product_id'],
                        'index' => $index,
                        'grouped_field_values' => $groupedFieldValues
                    ]);

                    // Count field value sets
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
                    $requiresFieldValues = !empty($purchaseProductIds = array_keys($groupedFieldValues)) && PurchaseStockProductFieldValue::whereIn('purchase_stock_product_id', $purchaseProductIds)->whereNull('deleted_at')->exists();

                    Log::debug('Field value requirements', [
                        'product_id' => $productData['product_id'],
                        'index' => $index,
                        'has_field_values' => $hasFieldValues,
                        'requires_field_values' => $requiresFieldValues,
                        'purchase_stock_product_ids' => $purchaseProductIds,
                        'regular_field_value_sets' => $regularFieldValueSets,
                        'free_field_value_sets' => $freeFieldValueSets,
                        'regular_pieces' => $regularPieces,
                        'free_pieces' => $freePieces
                    ]);

                    if (!$hasFieldValues && $requiresFieldValues) {
                        throw new \Exception("Field values required for product ID {$productData['product_id']} at index {$index}.");
                    }
                    if ($hasFieldValues && !$requiresFieldValues) {
                        throw new \Exception("Field values provided for product ID {$productData['product_id']} at index {$index}, but none required.");
                    }
                    if ($hasFieldValues && ($regularFieldValueSets != $regularPieces || $freeFieldValueSets != $freePieces)) {
                        throw new \Exception("Field value sets (Regular: {$regularFieldValueSets}, Free: {$freeFieldValueSets}) must match pieces (Regular: {$regularPieces}, Free: {$freePieces}) at index {$index}.");
                    }

                    $remainingRegularPieces = $regularPieces;
                    $remainingFreePieces = $freePieces;
                    $allocations = [];
                    $usedQuantityIndexes = [];

                    // Fetch PurchaseProducts
                    $query = PurchaseStockProduct::where('product_id', $productData['product_id'])
                        ->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['branch_id'])
                        ->whereNull('deleted_at')
                        ->with([
                            'purchaseStockProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id'])->with('measureUnit'),
                            'fieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id']),
                            'saleProducts' => fn($q) => $q
                                ->whereNull('deleted_at')
                                ->where('company_id', $validated['company_id'])
                                ->with([
                                    'saleProductReturns' => fn($q) => $q
                                        ->whereNull('deleted_at')
                                        ->where('company_id', $validated['company_id'])
                                        ->where('branch_id', $validated['branch_id']), // MISSING branch_id
                                    'measureUnit'
                                ])
                        ]);

                    if ($hasFieldValues) {
                        $query->whereIn('id', $purchaseProductIds);
                    } elseif (isset($productData['purchase_stock_product_id'])) {
                        $query->where('id', $productData['purchase_stock_product_id']);
                    } else {
                        $query->whereNotExists(fn($subQuery) => $subQuery->select(DB::raw(1))->from('purchase_stock_product_field_values')->whereColumn('purchase_stock_product_id', 'purchase_stock_products.id')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id'])->whereNull('deleted_at'));
                    }

                    $purchaseProducts = $query->orderBy('created_at')->distinct()->get();


                    Log::debug('Fetched PurchaseProducts', [
                        'product_id' => $productData['product_id'],
                        'index' => $index,
                        'purchase_stock_product_ids' => $purchaseProducts->pluck('id')->toArray(),
                        'count' => $purchaseProducts->count()
                    ]);

                    if ($purchaseProducts->isEmpty()) {

                        throw new \Exception("No valid purchase products found for product ID {$productData['product_id']} at index {$index}.");
                    }

                    // Allocate with field values
                    if ($hasFieldValues) {
                        foreach ($groupedFieldValues as $purchaseProductId => $fvByIndex) {
                            $purchaseProduct = $purchaseProducts->firstWhere('id', $purchaseProductId) ?? throw new \Exception("Purchase product ID {$purchaseProductId} not found at index {$index}.");
                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;

                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            // Calculate total available pieces
                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $purchaseMeasureUnitQuantity, $validated['company_id']);

                            // Adjust for previously allocated pieces
                            if (isset($totalAllocatedPieces[$purchaseProductId])) {
                                $totalAvailablePieces -= $totalAllocatedPieces[$purchaseProductId];
                            }

                            Log::debug('Stock calculation for PurchaseProduct', [
                                'product_id' => $productData['product_id'],
                                'index' => $index,
                                'purchase_stock_product_id' => $purchaseProductId,
                                'quantity' => $purchaseProduct->quantity,
                                'free_quantity' => $purchaseProduct->free_quantity,
                                'measure_unit_id' => $purchaseProduct->measure_unit_id,
                                'measure_unit_quantity' => $purchaseMeasureUnitQuantity,
                                'total_available_pieces' => $totalAvailablePieces
                            ]);

                            if ($totalAvailablePieces <= 0) {
                                continue;
                            }

                            // Validate field values
                            $existingFieldValues = $purchaseProduct->fieldValues->groupBy('quantity_index')->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());
                            $unavailableQuantityIndices = $this->getUnavailableQuantityIndices($purchaseProduct, $validated['company_id']);
                            $salesReturnedIndices = SaleReturnProductFieldValue::whereIn('sale_return_product_id', $purchaseProduct->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns->pluck('id')))->whereNull('deleted_at')->pluck('quantity_index')->toArray();
                            $unavailableQuantityIndices = array_diff($unavailableQuantityIndices, $salesReturnedIndices);

                            Log::debug('Field value validation', [
                                'product_id' => $productData['product_id'],
                                'index' => $index,
                                'purchase_stock_product_id' => $purchaseProductId,
                                'existing_field_values' => $existingFieldValues,
                                'unavailable_quantity_indices' => $unavailableQuantityIndices,
                                'sales_returned_indices' => $salesReturnedIndices
                            ]);

                            foreach ($fvByIndex as $quantityIndex => $fvSet) {
                                if (in_array($quantityIndex, $unavailableQuantityIndices) || !isset($existingFieldValues[$quantityIndex])) {
                                    throw new \Exception("Invalid quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} at index {$index}.");
                                }
                                if (in_array($quantityIndex, $usedQuantityIndexes[$purchaseProductId] ?? [])) {
                                    throw new \Exception("Duplicate quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} at index {$index}.");
                                }
                                if (collect($fvSet)->pluck('value', 'product_field_id')->toArray() != $existingFieldValues[$quantityIndex]) {
                                    throw new \Exception("Field values for quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} do not match at index {$index}.");
                                }
                                $usedQuantityIndexes[$purchaseProductId][] = $quantityIndex;
                            }

                            $regularFvByIndex = collect($fvByIndex)->filter(function ($fvSet) {
                                return collect($fvSet)->first()['quantity_type'] === 'regular';
                            })->toArray();

                            $freeFvByIndex = collect($fvByIndex)->filter(function ($fvSet) {
                                return collect($fvSet)->first()['quantity_type'] === 'free';
                            })->toArray();

                            $totalRequestedForThisProduct = count($regularFvByIndex) + count($freeFvByIndex);
                            $allocatePieces = min($totalRequestedForThisProduct, $totalAvailablePieces);

                            if ($allocatePieces > 0) {
                                $allocateRegularPieces = min(count($regularFvByIndex), $allocatePieces);
                                $allocateFreePieces = min(count($freeFvByIndex), $allocatePieces - $allocateRegularPieces);

                                $allocatedRegularFv = array_slice($regularFvByIndex, 0, $allocateRegularPieces, true);
                                $allocatedFreeFv = array_slice($freeFvByIndex, 0, $allocateFreePieces, true);

                                [$allocateRegularQuantity, $allocateFreeQuantity] = $this->convertToTargetMeasureUnit($allocateRegularPieces, $allocateFreePieces, $targetMeasureUnitQuantity);

                                $allocations[] = [
                                    'purchase_stock_product_id' => $purchaseProductId,
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                    'field_values' => array_merge(
                                        array_values($allocatedRegularFv),
                                        array_values($allocatedFreeFv)
                                    ),
                                    'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                    'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                                    'customer_id' => $productData['customer_id'] ?? $purchaseProduct->customer_id,
                                    'return_measure_unit_id' => $productData['measure_unit_id'],
                                ];

                                // Update global allocation tracking
                                $totalAllocatedPieces[$purchaseProductId] = ($totalAllocatedPieces[$purchaseProductId] ?? 0) + ($allocateRegularPieces + $allocateFreePieces);

                                $remainingRegularPieces -= $allocateRegularPieces;
                                $remainingFreePieces -= $allocateFreePieces;

                                Log::debug('Allocation with field values', [
                                    'product_id' => $productData['product_id'],
                                    'index' => $index,
                                    'purchase_stock_product_id' => $purchaseProductId,
                                    'allocated_regular_pieces' => $allocateRegularPieces,
                                    'allocated_free_pieces' => $allocateFreePieces,
                                    'total_allocated_pieces' => $allocatePieces,
                                    'remaining_regular_pieces' => $remainingRegularPieces,
                                    'remaining_free_pieces' => $remainingFreePieces,
                                    'allocation' => end($allocations)
                                ]);
                            }
                        }
                    }

                    // Allocate remaining pieces (FIFO or single purchase_product_id)
                    if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                        $purchaseProduct = isset($productData['purchase_stock_product_id']) ? $purchaseProducts->firstWhere('id', $productData['purchase_stock_product_id']) : null;

                        if ($purchaseProduct) {
                            if ($purchaseProduct->fieldValues->isNotEmpty()) {
                                throw new \Exception("Purchase product ID {$purchaseProduct->id} has field values; field_values must be provided at index {$index}.");
                            }
                            $purchaseProducts = collect([$purchaseProduct]);
                        }

                        foreach ($purchaseProducts as $purchaseProduct) {
                            if ($remainingRegularPieces <= 0 && $remainingFreePieces <= 0)
                                break;

                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $purchaseMeasureUnitQuantity, $validated['company_id'], $validated['branch_id']);

                            // Adjust for previously allocated pieces
                            if (isset($totalAllocatedPieces[$purchaseProduct->id])) {
                                $totalAvailablePieces -= $totalAllocatedPieces[$purchaseProduct->id];
                            }

                            Log::debug('Stock calculation for FIFO PurchaseProduct', [
                                'product_id' => $productData['product_id'],
                                'index' => $index,
                                'purchase_stock_product_id' => $purchaseProduct->id,
                                'quantity' => $purchaseProduct->quantity,
                                'free_quantity' => $purchaseProduct->free_quantity,
                                'measure_unit_id' => $purchaseProduct->measure_unit_id,
                                'measure_unit_quantity' => $purchaseMeasureUnitQuantity,
                                'total_available_pieces' => $totalAvailablePieces
                            ]);

                            if ($totalAvailablePieces <= 0)
                                continue;

                            $totalRemainingPieces = $remainingRegularPieces + $remainingFreePieces;
                            $allocatePieces = min($totalRemainingPieces, $totalAvailablePieces);

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
                                    'customer_id' => $productData['customer_id'] ?? $purchaseProduct->customer_id,
                                    'return_measure_unit_id' => $productData['measure_unit_id'],
                                ];

                                $remainingRegularPieces -= $allocateRegularPieces;
                                $remainingFreePieces -= $allocateFreePieces;

                                // Update global allocation tracking
                                $totalAllocatedPieces[$purchaseProduct->id] = ($totalAllocatedPieces[$purchaseProduct->id] ?? 0) + ($allocateRegularPieces + $allocateFreePieces);

                                Log::debug('FIFO allocation', [
                                    'product_id' => $productData['product_id'],
                                    'index' => $index,
                                    'purchase_stock_product_id' => $purchaseProduct->id,
                                    'allocated_regular_pieces' => $allocateRegularPieces,
                                    'allocated_free_pieces' => $allocateFreePieces,
                                    'total_allocated_pieces' => $allocateRegularPieces + $allocateFreePieces,
                                    'remaining_regular_pieces' => $remainingRegularPieces,
                                    'remaining_free_pieces' => $remainingFreePieces,
                                    'allocation' => end($allocations)
                                ]);
                            }
                        }
                    }

                    if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                        Log::error('Insufficient stock detected', [
                            'product_id' => $productData['product_id'],
                            'index' => $index,
                            'requested_regular_pieces' => $regularPieces,
                            'requested_free_pieces' => $freePieces,
                            'total_requested_pieces' => $totalRequestedPieces,
                            'remaining_regular_pieces' => $remainingRegularPieces,
                            'remaining_free_pieces' => $remainingFreePieces,
                            'total_allocated_pieces' => $totalRequestedPieces - ($remainingRegularPieces + $remainingFreePieces),
                            'allocations' => $allocations
                        ]);
                        throw new \Exception("Insufficient stock for product ID {$productData['product_id']} at index {$index}. Requested: {$totalRequestedPieces} pieces (Regular: {$regularPieces}, Free: {$freePieces}), Allocated: " . ($totalRequestedPieces - ($remainingRegularPieces + $remainingFreePieces)) . " pieces.");
                    }

                    // Build processed products
                    foreach ($allocations as $allocation) {
                        $purchaseProduct = PurchaseStockProduct::findOrFail($allocation['purchase_stock_product_id']);
                        $processedProducts[] = [
                            'purchase_stock_product_id' => $allocation['purchase_stock_product_id'],
                            'product_id' => $productData['product_id'],
                            'product_name' => $productData['product_name'] ?? $purchaseProduct->product->name ?? '',
                            'purchase_product_code' => $productData['purchase_product_code'] ?? $purchaseProduct->product_code ?? '',
                            'mfd' => $allocation['mfd'],
                            'customer_id' => $allocation['customer_id'],
                            'quantity' => $allocation['quantity'],
                            'free_quantity' => $allocation['free_quantity'],
                            'price' => $productData['price'] ?? $purchaseProduct->price ?? 0,
                            'discount_percent' => $productData['discount_percent'] ?? 0,
                            'discount_amount' => $productData['discount_amount'] ?? 0,
                            'amount' => ($productData['price'] ?? $purchaseProduct->price ?? 0) * $allocation['quantity'] - ($productData['discount_amount'] ?? 0),
                            'is_vatable' => $productData['is_vatable'],
                            'measure_unit_id' => $allocation['return_measure_unit_id'],
                            'expiry_date' => $allocation['expiry_date'],
                            'field_values' => $allocation['field_values'],
                            'purchase_id' => $purchaseProduct->purchase_id,
                            'purchase_purchase_bill_number' => $purchases[$purchaseProduct->purchase_id]->purchase_bill_number ?? '',
                        ];
                    }
                }


                $purchaseReturnData = array_filter($validated, fn($key) => !in_array($key, ['purchase_return_products']), ARRAY_FILTER_USE_KEY);
                $purchaseReturnData['purchase_id'] = null;
                $purchaseReturn = PurchaseStockReturn::create($purchaseReturnData);

                foreach ($processedProducts as $productData) {
                    $productDataFiltered = array_filter($productData, fn($key) => !in_array($key, ['field_values', 'purchase_id', 'purchase_purchase_bill_number']), ARRAY_FILTER_USE_KEY);
                    $purchaseReturnProduct = $purchaseReturn->purchaseStockProductReturns()->create(array_merge($productDataFiltered, ['company_id' => $purchaseReturn->company_id, 'branch_id' => $purchaseReturn->branch_id]));

                    if (!empty($productData['field_values'])) {
                        foreach ($productData['field_values'] as $arrayIndex => $fvSet) {
                            $quantityIndex = isset($fvSet[0]['quantity_index']) ? $fvSet[0]['quantity_index'] : $arrayIndex;
                            foreach ($fvSet as $fv) {
                                PurchaseStockProductReturnFieldValue::create([
                                    'purchase_stock_product_return_id' => $purchaseReturnProduct->id,
                                    'purchase_stock_product_id' => $fv['purchase_stock_product_id'] ?? null,
                                    'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                    'stock_product_id' => $fv['stock_product_id'] ?? null,
                                    'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                    'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                                    'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                                    'product_field_id' => $fv['product_field_id'],
                                    'value' => $fv['value'],
                                    'product_id' => $purchaseReturnProduct->product_id,
                                    'company_id' => $purchaseReturnProduct->company_id,
                                    'branch_id' => $purchaseReturnProduct->branch_id,
                                    'quantity_index' => $fv['quantity_index'],
                                    'quantity_type' => $fv['quantity_type'], // Remove ?? null to ensure value is saved
                                ]);
                            }
                        }
                    }
                }

                Log::debug('Purchase return created', ['purchase_stock_return_id' => $purchaseReturn->id, 'processed_products' => $processedProducts]);

                return $purchaseReturn->load([
                    'purchaseStockProductReturns' => fn($query) => $query->select('id', 'purchase_stock_return_id', 'purchase_stock_product_id', 'product_id', 'product_name', 'purchase_product_code', 'quantity', 'free_quantity', 'price', 'discount_percent', 'discount_amount', 'amount', 'is_vatable', 'measure_unit_id', 'expiry_date', 'mfd', 'customer_id'),
                    'purchaseStockProductReturns.fieldValues' => fn($query) => $query->select('id', 'purchase_stock_product_return_id', 'product_field_id', 'value', 'quantity_index', 'quantity_type', 'product_id', 'company_id', 'created_at', 'updated_at', 'deleted_at')->orderBy('quantity_index')->orderBy('product_field_id')
                ]);
            });

            return response()->json(['message' => 'Purchase Return Created Successfully', 'data' => $purchaseReturn], 201);
        } catch (ModelNotFoundException $e) {
            Log::error('Model not found: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Record not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {

            Log::error('Unexpected error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Error creating purchase return: ' . $e->getMessage()], 500);
        }
    }

    private function flattenFieldValues($fieldValues, $index): array
    {
        $flattened = [];

        // Handle various nesting levels recursively
        $flattenRecursive = function ($items, $depth = 0) use (&$flattenRecursive, &$flattened, $index) {
            if ($depth > 5) { // Prevent infinite recursion
                throw new \Exception("Excessive nesting in field_values at index {$index}");
            }

            foreach ($items as $item) {
                if (is_array($item)) {
                    if (isset($item['purchase_stock_product_id'], $item['product_field_id'], $item['value'], $item['quantity_index'])) {
                        // Valid field value object
                        $flattened[] = [
                            'purchase_stock_product_id' => $item['purchase_stock_product_id'],
                            'stock_product_id' => $item['stock_product_id'] ?? null,
                            'stock_adjustment_id' => $item['stock_adjustment_id'] ?? null,
                            'stock_reconciliation_id' => $item['stock_reconciliation_id'] ?? null,
                            'stock_transfer_id' => $item['stock_transfer_id'] ?? null,
                            'purchase_product_id' => $item['purchase_product_id'] ?? null,
                            'product_field_id' => $item['product_field_id'],
                            'value' => $item['value'],
                            'quantity_index' => $item['quantity_index'],
                            'quantity_type' => $item['quantity_type'] ?? 'regular',
                            'name' => $item['name'] ?? null
                        ];
                    } else {
                        // Nested array, recurse
                        $flattenRecursive($item, $depth + 1);
                    }
                }
            }
        };

        $flattenRecursive($fieldValues);
        return $flattened;
    }


    private function calculatePieces(float $quantity, float $measureUnitQuantity): float
    {
        $integerPart = floor($quantity);
        $decimalPart = $quantity - $integerPart;
        $decimalPieces = $decimalPart > 0 ? (int) str_replace('.', '', (string) $decimalPart) : 0;
        return ($integerPart * $measureUnitQuantity) + $decimalPieces;
    }

    private function calculateAvailablePieces($purchaseProduct, float $measureUnitQuantity, int $companyId, int $branchID = null): float
    {

        $regularPieces = $this->calculatePieces($purchaseProduct->quantity ?? 0, $measureUnitQuantity);


        $freePieces = $this->calculatePieces($purchaseProduct->free_quantity ?? 0, $measureUnitQuantity);

        $purchaseReturnedPieces = $purchaseProduct->purchaseProductReturns->reduce(fn($carry, $return) => $carry + $this->calculatePieces($return->quantity ?? 0, $return->measureUnit->quantity ?? 1) + $this->calculatePieces($return->free_quantity ?? 0, $return->measureUnit->quantity ?? 1), 0);


        $soldPieces = $purchaseProduct->saleProducts->reduce(fn($carry, $sale) => $carry + $this->calculatePieces($sale->quantity ?? 0, $sale->measureUnit->quantity ?? 1) + $this->calculatePieces($sale->free_quantity ?? 0, $sale->measureUnit->quantity ?? 1), 0);

        $salesReturnedPieces = SalesReturnProduct::where('product_id', $purchaseProduct->product_id)
            ->where('company_id', $companyId)
            ->where('branch_id', $branchID)
            ->whereNull('deleted_at')
            ->with('measureUnit')
            ->get()
            ->reduce(fn($carry, $return) => $carry + $this->calculatePieces($return->quantity ?? 0, $return->measureUnit->quantity ?? 1) + $this->calculatePieces($return->free_quantity ?? 0, $return->measureUnit->quantity ?? 1), 0);



        $availablePieces = $regularPieces + $freePieces - $purchaseReturnedPieces - $soldPieces + $salesReturnedPieces;



        return max(0, ($regularPieces + $freePieces) - $purchaseReturnedPieces - $soldPieces + $salesReturnedPieces);
    }


    private function calculateAvailablePiecesForUpdate($purchaseProduct, float $measureUnitQuantity, int $companyId, $purchaseBillNumber = null, $purchaseId = null): float
    {
        $regularPieces = $this->calculatePieces($purchaseProduct->quantity ?? 0, $measureUnitQuantity);
        $freePieces = $this->calculatePieces($purchaseProduct->free_quantity ?? 0, $measureUnitQuantity);

        $purchaseReturnedPieces = $purchaseProduct->purchaseProductReturns()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->with('measureUnit')
            ->get()
            ->reduce(fn($carry, $return) => $carry + $this->calculatePieces($return->quantity ?? 0, $return->measureUnit->quantity ?? 1) + $this->calculatePieces($return->free_quantity ?? 0, $return->measureUnit->quantity ?? 1), 0);

        $soldPieces = $purchaseProduct->saleProducts()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->with('measureUnit')
            ->get()
            ->reduce(fn($carry, $sale) => $carry + $this->calculatePieces($sale->quantity ?? 0, $sale->measureUnit->quantity ?? 1) + $this->calculatePieces($sale->free_quantity ?? 0, $sale->measureUnit->quantity ?? 1), 0);

        $salesReturnQuery = SalesReturnProduct::where('product_id', $purchaseProduct->product_id)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->with('measureUnit');

        if ($purchaseBillNumber && $purchaseId) {
            $salesReturnQuery->whereIn('id', function ($query) use ($purchaseId, $companyId) {
                $query->select('sale_return_products.id')
                    ->from('sale_return_products')
                    ->join('sale_products', 'sale_return_products.sale_product_id', '=', 'sale_products.id')
                    ->where('sale_products.purchase_id', $purchaseId)
                    ->where('sale_products.company_id', $companyId)
                    ->whereNull('sale_products.deleted_at')
                    ->whereNull('sale_return_products.deleted_at');
            });
        }

        $salesReturnedPieces = $salesReturnQuery->get()
            ->reduce(fn($carry, $return) => $carry + $this->calculatePieces($return->quantity ?? 0, $return->measureUnit->quantity ?? 1) + $this->calculatePieces($return->free_quantity ?? 0, $return->measureUnit->quantity ?? 1), 0);

        $availablePieces = max(0, ($regularPieces + $freePieces) - $purchaseReturnedPieces - $soldPieces + $salesReturnedPieces);

        Log::debug('Calculated available pieces', [
            'purchase_product_id' => $purchaseProduct->id,
            'product_id' => $purchaseProduct->product_id,
            'purchase_id' => $purchaseId,
            'purchase_bill_number' => $purchaseBillNumber,
            'regular_pieces' => $regularPieces,
            'free_pieces' => $freePieces,
            'purchase_returned_pieces' => $purchaseReturnedPieces,
            'sold_pieces' => $soldPieces,
            'sales_returned_pieces' => $salesReturnedPieces,
            'available_pieces' => $availablePieces,
            'measure_unit_quantity' => $measureUnitQuantity
        ]);

        return $availablePieces;
    }

    private function convertToTargetMeasureUnit(float $regularPieces, float $freePieces, float $targetMeasureUnitQuantity): array
    {
        // Ensure targetMeasureUnitQuantity is not zero to prevent division by zero
        if ($targetMeasureUnitQuantity <= 0) {
            throw new \Exception('Target measure unit quantity must be greater than zero.');
        }

        // Calculate regular quantity
        $regularIntegerUnits = floor($regularPieces / $targetMeasureUnitQuantity);
        $regularRemainingPieces = $regularPieces - ($regularIntegerUnits * $targetMeasureUnitQuantity);
        // Convert remaining pieces to decimal (e.g., 567 -> 0.567)
        $regularDecimal = $regularRemainingPieces > 0 ? (float) ('0.' . (int) $regularRemainingPieces) : 0;
        $regularQuantity = $regularIntegerUnits + $regularDecimal;

        // Calculate free quantity
        $freeIntegerUnits = floor($freePieces / $targetMeasureUnitQuantity);
        $freeRemainingPieces = $freePieces - ($freeIntegerUnits * $targetMeasureUnitQuantity);
        // Convert remaining pieces to decimal (e.g., 567 -> 0.567)
        $freeDecimal = $freeRemainingPieces > 0 ? (float) ('0.' . (int) $freeRemainingPieces) : 0;
        $freeQuantity = $freeIntegerUnits + $freeDecimal;

        return [$regularQuantity, $freeQuantity];
    }

    private function getUnavailableQuantityIndices($purchaseProduct, int $companyId): array
    {
        $returnIndices = $purchaseProduct->purchaseProductReturns->isNotEmpty() ? PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $purchaseProduct->purchaseProductReturns->pluck('id'))->whereNull('deleted_at')->pluck('quantity_index')->toArray() : [];
        $soldIndices = $purchaseProduct->saleProducts->isNotEmpty() ? SalesProductFieldValue::whereIn('sale_product_id', $purchaseProduct->saleProducts->pluck('id'))->whereNull('deleted_at')->pluck('quantity_index')->toArray() : [];
        return array_merge($returnIndices, $soldIndices);
    }



    public function store(Request $request): JsonResponse
    {
        try {
            // Define validation rules
            $validator = Validator::make($request->all(), rules: [
                'company_id' => 'required|integer',
                'branch_id' => 'required|integer|exists:branches,id',
                // 'purchase_id' => 'nullable|integer|exists:purchases,id',
                'customer_id' => 'nullable|integer|exists:customers,id',
                'customer_name' => 'nullable|string|max:255',
                'invoice_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('purchase_returns')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at');
                    }),
                ],
                'pan_number' => 'nullable|string|max:255',
                'address' => 'nullable|string|max:255',
                'customer_contact' => 'nullable|string|max:255',
                'purchase_number' => 'nullable|string|max:255',
                'purchase_bill_number' => 'nullable|string|max:255',
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reason' => 'nullable|string|in:damaged,defective,incorrect,expired,other',
                'store_id' => 'nullable|integer|exists:stores,id',
                'location_id' => 'nullable|integer|exists:locations,id',
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
                'payment.bank_name' => 'nullable|string',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'return_entire_batch' => 'nullable|boolean',
                'purchase_return_products' => [
                    'required',
                    'array',
                    'min:1',
                    function ($attribute, $value, $fail) {
                        foreach ($value as $index => $product) {
                            if (empty($product['product_name']) && empty($product['purchase_product_code'])) {
                                $fail("At least one of product_name or purchase_product_code is required for product at index {$index}.");
                            }
                        }
                    },
                ],
                'purchase_return_products.*.product_id' => 'required|integer|exists:products,id',
                'purchase_return_products.*.purchase_stock_product_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('purchase_stock_products', 'id')->where(function ($query) use ($request) {
                        $query->where('company_id', $request->input('company_id'))
                            ->where('branch_id', $request->input('branch_id'));
                        if ($request->input('purchase_id')) {
                            $query->where('purchase_id', $request->input('purchase_id'));
                        }
                    }),
                ],
                'purchase_return_products.*.purchase_product_id' => 'nullable|numeric',
                'purchase_return_products.*.stock_product_id' => 'nullable|numeric',
                'purchase_return_products.*.stock_reconciliation_id' => 'nullable|numeric',
                'purchase_return_products.*.stock_adjustment_id' => 'nullable|numeric',
                'purchase_return_products.*.stock_transfer_id' => 'nullable|numeric',
                'purchase_return_products.*.product_name' => 'nullable|string|max:255',
                'purchase_return_products.*.purchase_product_code' => 'nullable|string|max:255',
                'purchase_return_products.*.mfd' => 'nullable|string|max:255',
                'purchase_return_products.*.customer_id' => 'nullable|integer|exists:customers,id',
                'purchase_return_products.*.quantity' => 'required|numeric|min:0',
                'purchase_return_products.*.free_quantity' => 'nullable|numeric|min:0',
                'purchase_return_products.*.price' => 'required|numeric|min:0',
                'purchase_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'purchase_return_products.*.discount_amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.is_vatable' => 'required|boolean',
                'purchase_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'purchase_return_products.*.expiry_date' => 'nullable|string|max:255',
                'purchase_return_products.*.field_values' => 'present|array',
                'purchase_return_products..field_values.' => 'array|min:1',
                'purchase_return_products..field_values..*.purchase_stock_product_id' => 'required_if:field_values,array|integer|exists:purchase_stock_products,id',
                'purchase_return_products..field_values..*.purchase_product_id' => 'required_if:field_values,array',
                'purchase_return_products..field_values..*.stock_product_id' => 'required_if:field_values,array',
                'purchase_return_products..field_values..*.stock_adjustment_id' => 'required_if:field_values,array',
                'purchase_return_products..field_values..*.stock_reconciliation_id' => 'required_if:field_values,array',

                'purchase_return_products..field_values..*.stock_transfer_id' => 'required_if:field_values,array',
                'purchase_return_products..field_values..*.product_field_id' => 'required_if:field_values,array|integer|exists:product_fields,id',
                'purchase_return_products..field_values..*.value' => 'required_if:field_values,array|string|max:255',
                'purchase_return_products..field_values..*.quantity_index' => 'required_if:field_values,array|integer|min:0',
                'purchase_return_products..field_values..*.quantity_type' => 'required_if:field_values,array|string|max:255',
            ]);
            $validated['purchase_return_products'] = $validated['purchase_return_products'] ?? [];

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $companyId = $request->company_id;
            $branchId = $request->branch_id;

            $validated = $validator->validated();
            Log::debug('Validated request data', ['data' => $validated]);

            // Initialize collections
            $processedProducts = [];
            $purchases = collect();

            // Helper function to calculate quantity in pieces
            $calculateQuantityInPieces = function ($quantity, $freeQuantity, $unitQuantity) {
                $totalQuantity = (float) ($quantity ?? 0) + (float) ($freeQuantity ?? 0);
                $decimalStr = explode('.', (string) $totalQuantity);
                $quantityInt = floor($totalQuantity);
                $decimalDigits = isset($decimalStr[1]) ? (float) $decimalStr[1] : 0;
                $totalPieces = ($quantityInt * $unitQuantity) + $decimalDigits;
                Log::debug('Calculating pieces', [
                    'quantity' => $quantity,
                    'free_quantity' => $freeQuantity,
                    'total_quantity' => $totalQuantity,
                    'unit_quantity' => $unitQuantity,
                    'quantity_int' => $quantityInt,
                    'decimal_digits' => $decimalDigits,
                    'total_pieces' => $totalPieces,
                ]);
                return $totalPieces;
            };

            // Handle entire batch return
            // $validated['return_entire_batch'] = false;
            // if ($validated['return_entire_batch'] ?? false) {
            //     if (!$validated['purchase_id']) {
            //         return response()->json(['error' => 'purchase_id is required when return_entire_batch is true'], 422);
            //     }
            //     $purchase = Purchase::findOrFail($validated['purchase_id']);
            //     if ($validated['purchase_bill_number'] && $validated['purchase_bill_number'] !== $purchase->purchase_bill_number) {
            //         return response()->json(['error' => 'Purchase bill number does not match purchase record'], 422);
            //     }
            //     $validated['purchase_return_products'] = $purchase->purchaseStockProducts()
            //         ->with(['measureUnit', 'fieldValues'])
            //         ->orderBy('created_at')
            //         ->get()
            //         ->map(function ($product) use ($validated, $branchId, $calculateQuantityInPieces) {
            //             $measureUnitId = $product->measure_unit_id ?? ($validated['purchase_return_products'][0]['measure_unit_id'] ?? null);
            //             if (!$measureUnitId) {
            //                 throw new \Exception("No measure unit specified for purchase_stock_product_id {$product->id}");
            //             }
            //             $measureUnit = MeasureUnit::findOrFail($measureUnitId);
            //             $unitQuantity = $measureUnit->quantity ?? 1;

            //             // Calculate purchased pieces
            //             $purchasedQuantityInPieces = $calculateQuantityInPieces($product->quantity, $product->free_quantity, $unitQuantity);

            //             // Calculate returned pieces
            //             $totalReturnedInPieces = PurchaseStockProductReturn::where('purchase_stock_product_id', $product->id)
            //                 ->where('company_id', $validated['company_id'])
            //                 ->where('branch_id', $branchId)
            //                 ->whereNull('deleted_at')
            //                 ->sum(function ($return) use ($calculateQuantityInPieces) {
            //                 $mu = MeasureUnit::findOrFail($return->measure_unit_id);
            //                 return $calculateQuantityInPieces($return->quantity, $return->free_quantity, $mu->quantity ?? 1);
            //             });

            //             // Calculate sold pieces
            //             $soldQuantityInPieces = SaleProduct::where('purchase_product_id', $product->id)
            //                 ->where('company_id', $validated['company_id'])
            //                 ->whereNull('deleted_at')
            //                 ->sum(function ($sale) use ($calculateQuantityInPieces) {
            //                 $mu = MeasureUnit::findOrFail($sale->measure_unit_id);
            //                 return $calculateQuantityInPieces($sale->quantity, $sale->free_quantity, $mu->quantity ?? 1);
            //             });

            //             // Calculate sale-returned pieces
            //             $salesReturnedInPieces = SalesReturnProduct::where('product_id', $product->product_id)
            //                 ->where('company_id', $validated['company_id'])
            //                 ->whereNull('deleted_at')
            //                 ->sum(function ($return) use ($calculateQuantityInPieces) {
            //                 $mu = MeasureUnit::findOrFail($return->measure_unit_id);
            //                 return $calculateQuantityInPieces($return->quantity, $return->free_quantity, $mu->quantity ?? 1);
            //             });

            //             // Available pieces
            //             $availableQuantityInPieces = ($purchasedQuantityInPieces - $soldQuantityInPieces) + $salesReturnedInPieces - $totalReturnedInPieces;
            //             if ($availableQuantityInPieces <= 0) {
            //                 return null;
            //             }

            //             $fieldValues = collect($product->fieldValues ?? [])->groupBy('quantity_index')->map(function ($group) {
            //                 return $group->map(function ($field) {
            //                     return [
            //                         'purchase_stock_product_id' => $field->purchase_stock_product_id,
            //                         'purchase_product_id' => $field->purchase_product_id,
            //                         'stock_product_id' => $field->stock_product_id,
            //                         'stock_reconciliation_id' => $field->stock_reconciliation_id,
            //                         'stock_transfer_id' => $field->stock_transfer_id,
            //                         'stock_adjustment_id' => $field->stock_adjustment_id,
            //                         'product_field_id' => $field->product_field_id,
            //                         'value' => $field->value,
            //                         'quantity_index' => $field->quantity_index,
            //                         'quantity_type' => $field->quantity_type,
            //                     ];
            //                 })->toArray();
            //             })->values()->take(ceil($availableQuantityInPieces))->toArray();

            //             return [
            //                 'purchase_stock_product_id' => $product->id,
            //                 'purchase_product_id' => $product->purchase_product_id ?? null,
            //                 'stock_product_id' => $product->stock_product_id ?? null,
            //                 'stock_reconciliation_id' => $product->stock_reconciliation_id ?? null,
            //                 'stock_adjustment_id' => $product->stock_adjustment_id ?? null,
            //                 'stock_transfer_id' => $product->stock_transfer_id ?? null,
            //                 'product_id' => $product->product_id,
            //                 'product_name' => $product->product_name,
            //                 'purchase_product_code' => $product->product_code,
            //                 'mfd' => $product->mfd,
            //                 'customer_id' => $product->customer_id,
            //                 'quantity' => $product->quantity,
            //                 'free_quantity' => $product->free_quantity,
            //                 'price' => $product->price,
            //                 'discount_percent' => $product->discount_percent,
            //                 'discount_amount' => $product->discount_amount,
            //                 'amount' => ($product->quantity * ($product->price ?? 0)) - ($product->discount_amount ?? 0),
            //                 'is_vatable' => $product->is_vatable,
            //                 'measure_unit_id' => $measureUnit->id,
            //                 'expiry_date' => $product->expiry_date,
            //                 'field_values' => $fieldValues,
            //             ];
            //         })->filter()->toArray();
            // }

            // Process purchase return products
            // Group products by product_id for FIFO allocation
            $productsById = collect($validated['purchase_return_products'])->groupBy('product_id')->map(function ($products) {
                return $products->toArray();
            })->toArray();

            foreach ($productsById as $productId => $productGroup) {
                // Calculate total requested pieces
                $totalRequestedPieces = 0;
                $productAllocations = [];
                $batchQuantities = [];

                // Collect total requested pieces and store product data
                foreach ($productGroup as $index => $productData) {
                    $regularQuantity = (float) ($productData['quantity'] ?? 0);
                    $freeQuantity = (float) ($productData['free_quantity'] ?? 0);
                    $totalQuantityInUOM = $regularQuantity + $freeQuantity;

                    $measureUnit = MeasureUnit::findOrFail($productData['measure_unit_id']);
                    $unitQuantity = $measureUnit->quantity ?? 1;

                    $regularPieces = $calculateQuantityInPieces($regularQuantity, 0, $unitQuantity);
                    $freePieces = $calculateQuantityInPieces(0, $freeQuantity, $unitQuantity);
                    $totalRequestedPieces += $regularPieces + $freePieces;

                    $productAllocations[$index] = [
                        'regular_pieces' => $regularPieces,
                        'free_pieces' => $freePieces,
                        'product_data' => $productData,
                        'allocations' => [],
                    ];

                    Log::debug('Requested quantities for product', [
                        'product_id' => $productId,
                        'index' => $index,
                        'regular_quantity' => $regularQuantity,
                        'free_quantity' => $freeQuantity,
                        'regular_pieces' => $regularPieces,
                        'free_pieces' => $freePieces,
                        'total_requested_pieces' => $totalRequestedPieces,
                        'measure_unit_id' => $productData['measure_unit_id'],
                        'unit_quantity' => $unitQuantity,
                    ]);
                }

                // Build query for PurchaseProducts
                $purchaseProductsQuery = PurchaseStockProduct::where('product_id', $productId)
                    ->where('company_id', $validated['company_id'])
                    ->where('branch_id', $branchId)
                    ->whereNull('deleted_at')
                    ->with([
                        'purchase' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $branchId),
                        'purchaseStockProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $branchId)->with('measureUnit'),
                        'saleProducts' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->with([
                            'saleProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->with([
                                'fieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])
                            ])
                        ]),
                        'measureUnit',
                        'fieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $branchId),
                        'stockTransferFieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $branchId),
                    ]);

                if ($validated['purchase_bill_number']) {
                    $purchaseProductsQuery->whereHas('purchase', function ($query) use ($validated) {
                        $query->where('purchase_bill_number', $validated['purchase_bill_number']);
                    });
                }

                $purchaseProducts = $purchaseProductsQuery->orderBy('created_at')->get();

                if ($purchaseProducts->isEmpty()) {


                    return response()->json(['error' => "No valid purchase products found for product ID {$productId}"], 404);
                }

                // Calculate total available pieces and initialize batch quantities
                $totalAvailablePieces = 0;
                foreach ($purchaseProducts as $purchaseProduct) {
                    $purchaseMeasureUnit = $purchaseProduct->measureUnit;
                    if (!$purchaseMeasureUnit) {
                        return response()->json(['error' => "Measure unit not found for purchase_product_id {$purchaseProduct->id}"], 404);
                    }
                    $purchaseUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                    $purchasedQuantityInPieces = $calculateQuantityInPieces($purchaseProduct->quantity, $purchaseProduct->free_quantity, $purchaseUnitQuantity);
                    $totalReturnedInPieces = $purchaseProduct->purchaseProductReturns->sum(function ($return) use ($calculateQuantityInPieces) {
                        $mu = MeasureUnit::findOrFail($return->measure_unit_id);
                        return $calculateQuantityInPieces($return->quantity, $return->free_quantity, $mu->quantity ?? 1);
                    });
                    $soldQuantityInPieces = $purchaseProduct->saleProducts->sum(function ($sale) use ($calculateQuantityInPieces) {
                        $mu = MeasureUnit::findOrFail($sale->measure_unit_id);
                        return $calculateQuantityInPieces($sale->quantity, $sale->free_quantity, $mu->quantity ?? 1);
                    });
                    $salesReturnedInPieces = $purchaseProduct->saleProducts->flatMap(function ($sale) use ($calculateQuantityInPieces) {
                        return $sale->saleReturnProducts->map(function ($return) use ($calculateQuantityInPieces) {
                            $mu = MeasureUnit::findOrFail($return->measure_unit_id);
                            return $calculateQuantityInPieces($return->quantity, $return->free_quantity, $mu->quantity ?? 1);
                        });
                    })->sum();

                    $availableQuantityInPieces = ($purchasedQuantityInPieces - $soldQuantityInPieces) + $salesReturnedInPieces - $totalReturnedInPieces;
                    $batchQuantities[$purchaseProduct->id] = $availableQuantityInPieces;
                    $totalAvailablePieces += $availableQuantityInPieces;

                    Log::debug('Availability check for purchase_product_id ' . $purchaseProduct->id, [
                        'purchased_pieces' => $purchasedQuantityInPieces,
                        'sold_pieces' => $soldQuantityInPieces,
                        'sales_returned_pieces' => $salesReturnedInPieces,
                        'total_returned_pieces' => $totalReturnedInPieces,
                        'available_pieces' => $availableQuantityInPieces,
                        'total_available_pieces' => $totalAvailablePieces,
                        'requested_pieces' => $totalRequestedPieces,
                    ]);
                }

                // Check if total requested pieces exceed total available pieces
                if ($totalRequestedPieces > $totalAvailablePieces + 0.0001) {
                    return response()->json([
                        'error' => "Insufficient stock for product ID {$productId}. Requested: {$totalRequestedPieces} pieces, Available: {$totalAvailablePieces} pieces"
                    ], 422);
                }

                // Process each product in the group
                foreach ($productGroup as $index => $productData) {
                    $regularQuantity = (float) ($productData['quantity'] ?? 0);
                    $freeQuantity = (float) ($productData['free_quantity'] ?? 0);
                    $measureUnit = MeasureUnit::findOrFail($productData['measure_unit_id']);
                    $unitQuantity = $measureUnit->quantity ?? 1;
                    $remainingRegularPieces = $productAllocations[$index]['regular_pieces'];
                    $remainingFreePieces = $productAllocations[$index]['free_pieces'];

                    // Normalize field_values
                    $fieldValuesFlat = collect($productData['field_values'])->flatMap(function ($item) {
                        return is_array($item) && isset($item[0]['product_field_id']) ? $item : [$item];
                    })->toArray();
                    $hasFieldValues = !empty($fieldValuesFlat);
                    $usedQuantityIndexes = [];

                    // Handle field values
                    if ($hasFieldValues) {
                        // Validate field_values structure
                        foreach ($fieldValuesFlat as $fv) {
                            if (!isset($fv['purchase_stock_product_id']) || !is_numeric($fv['purchase_stock_product_id'])) {
                                return response()->json(['error' => "Invalid or missing purchase_product_id in field_values at index {$index}"], 422);
                            }
                            if (!isset($fv['quantity_index']) || !is_numeric($fv['quantity_index']) || $fv['quantity_index'] < 0) {
                                return response()->json(['error' => "Invalid quantity_index in field_values at index {$index}"], 422);
                            }
                            if (!isset($fv['quantity_type']) || !in_array($fv['quantity_type'], ['regular', 'free'])) {
                                return response()->json(['error' => "Invalid quantity_type in field_values at index {$index}. Must be 'regular' or 'free'"], 422);
                            }
                            if ($fv['quantity_type'] === 'free' && $freeQuantity == 0) {
                                return response()->json(['error' => "quantity_type 'free' is not allowed when free_quantity is 0 at index {$index}"], 422);
                            }
                            if ($fv['quantity_type'] === 'regular' && $regularQuantity == 0) {
                                return response()->json(['error' => "quantity_type 'regular' is not allowed when quantity is 0 at index {$index}"], 422);
                            }
                        }

                        // Group field values by purchase_product_id and quantity_index
                        $groupedFieldValues = collect($fieldValuesFlat)
                            ->groupBy('purchase_stock_product_id')
                            ->map(function ($group) {
                                return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                                    return $fvGroup->map(function ($fv) {
                                        return [
                                            'product_field_id' => $fv['product_field_id'],
                                            'value' => $fv['value'],
                                            'quantity_index' => $fv['quantity_index'],
                                            'quantity_type' => $fv['quantity_type'],
                                            'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                            'purchase_product_id' => $fv['purchase_product_id'],
                                            'stock_product_id' => $fv['stock_product_id'],
                                            'stock_reconciliation_id' => $fv['stock_reconciliation_id'],
                                            'stock_transfer_id' => $fv['stock_transfer_id'],
                                            'stock_adjustment_id' => $fv['stock_adjustment_id'],

                                        ];
                                    })->unique(function ($fv) {
                                        return "{$fv['product_field_id']}:{$fv['value']}:{$fv['quantity_type']}";
                                    })->values()->toArray();
                                })->toArray();
                            })->toArray();

                        // Count field value sets for regular and free quantities
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

                        Log::debug('Field value sets', [
                            'product_id' => $productId,
                            'index' => $index,
                            'regular_field_value_sets' => $regularFieldValueSets,
                            'free_field_value_sets' => $freeFieldValueSets,
                            'regular_pieces' => $remainingRegularPieces,
                            'free_pieces' => $remainingFreePieces,
                        ]);

                        if ($hasFieldValues && ($regularFieldValueSets != $remainingRegularPieces || $freeFieldValueSets != $remainingFreePieces)) {
                            return response()->json([
                                'error' => "Field value sets (Regular: {$regularFieldValueSets}, Free: {$freeFieldValueSets}) must match pieces (Regular: {$remainingRegularPieces}, Free: {$remainingFreePieces}) for product ID {$productId} at index {$index}"
                            ], 422);
                        }

                        $purchaseProductIds = array_keys($groupedFieldValues);
                        $requiresFieldValues = PurchaseStockProductFieldValue::whereIn('purchase_stock_product_id', $purchaseProductIds)
                            ->where('company_id', $validated['company_id'])
                            ->where('branch_id', $branchId)
                            ->whereNull('deleted_at')
                            ->exists();

                        if ($hasFieldValues && !$requiresFieldValues) {
                            return response()->json([
                                'error' => "Field values provided for product ID {$productId} at index {$index}, but no field values are required."
                            ], 422);
                        }

                        // Validate product_fields_bill if provided
                        if (isset($productData['product_fields_bill'])) {
                            foreach ($productData['product_fields_bill'] as $billSet) {
                                foreach ($billSet as $field) {
                                    if ($field['purchase_stock_product_id'] != $productData['purchase_stock_product_id']) {
                                        return response()->json([
                                            'error' => "Incorrect purchase_stock_product_id {$field['purchase_stock_product_id']} in product_fields_bill for product ID {$productId} at index {$index}"
                                        ], 422);
                                    }
                                }
                            }
                        }

                        // Allocate with field values
                        foreach ($groupedFieldValues as $purchaseProductId => $fvByIndex) {
                            if ($remainingRegularPieces <= 0 && $remainingFreePieces <= 0) {
                                break;
                            }

                            $purchaseProduct = $purchaseProducts->firstWhere('id', $purchaseProductId);
                            if (!$purchaseProduct) {
                                return response()->json(['error' => "Purchase product ID {$purchaseProductId} not found at index {$index}"], 404);
                            }

                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                            $purchaseMeasureUnit = $purchaseProduct->measureUnit;
                            if (!$purchaseMeasureUnit) {
                                return response()->json(['error' => "Measure unit not found for purchase_product_id {$purchaseProductId} at index {$index}"], 404);
                            }
                            $purchaseUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            $availableQuantityInPieces = $batchQuantities[$purchaseProductId];
                            if ($availableQuantityInPieces <= 0) {
                                continue;
                            }

                            // Validate field values
                            $existingFieldValues = $purchaseProduct->fieldValues
                                ->groupBy('quantity_index')
                                ->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());
                            $saleReturnFieldValues = $purchaseProduct->saleProducts->flatMap(function ($sale) {
                                return $sale->saleProductReturns->flatMap(function ($return) {
                                    return $return->fieldValues;
                                });
                            })->groupBy('quantity_index')
                                ->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());

                            $unavailableQuantityIndices = [];
                            if ($purchaseProduct->purchaseStockProductReturns->isNotEmpty()) {
                                $returnIds = $purchaseProduct->purchaseStockProductReturns->pluck('id');
                                $unavailableQuantityIndices = PurchaseStockProductReturnFieldValue::whereIn('purchase_stock_product_return_id', $returnIds)
                                    ->whereNull('deleted_at')
                                    ->pluck('quantity_index')
                                    ->toArray();
                            }
                            if ($purchaseProduct->saleProducts->isNotEmpty()) {
                                $saleIds = $purchaseProduct->saleProducts->pluck('id');
                                $soldIndices = SalesProductFieldValue::whereIn('sale_product_id', $saleIds)
                                    ->whereNull('deleted_at')
                                    ->pluck('quantity_index')
                                    ->toArray();
                                $unavailableQuantityIndices = array_merge($unavailableQuantityIndices, $soldIndices);
                            }
                            $salesReturnedIndices = SaleReturnProductFieldValue::whereIn(
                                'sale_return_product_id',
                                SalesReturnProduct::whereIn('sale_product_id', $purchaseProduct->saleProducts->pluck('id'))
                                    ->whereNull('deleted_at')
                                    ->pluck('id')
                            )
                                ->whereNull('deleted_at')
                                ->pluck('quantity_index')
                                ->toArray();
                            $unavailableQuantityIndices = array_diff($unavailableQuantityIndices, $salesReturnedIndices);

                            $stockTransferIndices = StockTransferFieldValue::where('purchase_stock_product_id', $purchaseProduct->id)
                                ->whereNull('deleted_at')
                                ->where('company_id', $validated['company_id'])
                                ->where('branch_id', $branchId)
                                ->pluck('quantity_index')
                                ->toArray();
                            $unavailableQuantityIndices = array_merge($unavailableQuantityIndices, $stockTransferIndices);


                            foreach ($fvByIndex as $quantityIndex => $fvSet) {
                                if (in_array($quantityIndex, $unavailableQuantityIndices) || (!isset($existingFieldValues[$quantityIndex]) && !isset($saleReturnFieldValues[$quantityIndex]))) {
                                    return response()->json(['error' => "Invalid or already returned/sold quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} at index {$index}"], 422);
                                }
                                if (in_array($quantityIndex, $usedQuantityIndexes[$purchaseProductId] ?? [])) {
                                    return response()->json(['error' => "Duplicate quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} at index {$index}"], 422);
                                }
                                $providedFieldValues = collect($fvSet)->pluck('value', 'product_field_id')->toArray();
                                $expectedFieldValues = $existingFieldValues[$quantityIndex] ?? $saleReturnFieldValues[$quantityIndex] ?? [];
                                if ($providedFieldValues != $expectedFieldValues) {
                                    return response()->json(['error' => "Field values for quantity_index {$quantityIndex} do not match for purchase_product_id {$purchaseProductId} at index {$index}"], 422);
                                }
                                if (isset($fv['stock_transfer_id'])) {
                                    $validStockTransfer = StockTransferFieldValue::where('stock_transfer_id', $fv['stock_transfer_id'])
                                        ->where('purchase_stock_product_id', $purchaseProduct->id)
                                        ->where('company_id', $validated['company_id'])
                                        ->where('branch_id', $branchId)
                                        ->whereNull('deleted_at')
                                        ->exists();
                                    if (!$validStockTransfer) {
                                        return response()->json(['error' => "Invalid stock_transfer_id {$fv['stock_transfer_id']} for purchase_stock_product_id {$purchaseProduct->id} at index {$index}"], 422);
                                    }
                                }
                                $usedQuantityIndexes[$purchaseProductId][] = $quantityIndex;
                            }

                            // Split field values by quantity_type
                            $regularFvByIndex = collect($fvByIndex)
                                ->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'regular')
                                ->toArray();
                            $freeFvByIndex = collect($fvByIndex)
                                ->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'free')
                                ->toArray();

                            $totalRequestedForThisProduct = count($regularFvByIndex) + count($freeFvByIndex);
                            $allocatePieces = min($totalRequestedForThisProduct, $availableQuantityInPieces, $remainingRegularPieces + $remainingFreePieces);

                            if ($allocatePieces > 0) {
                                $allocateRegularPieces = min(count($regularFvByIndex), $remainingRegularPieces, $allocatePieces);
                                $allocateFreePieces = min(count($freeFvByIndex), $remainingFreePieces, $allocatePieces - $allocateRegularPieces);

                                // Convert allocated pieces back to target measure unit
                                $regularQuantity = floor($allocateRegularPieces / $unitQuantity);
                                $regularRemainingPieces = $allocateRegularPieces - ($regularQuantity * $unitQuantity);
                                $regularDecimal = $regularRemainingPieces > 0 ? (float) ('0.' . (int) $regularRemainingPieces) : 0;
                                $allocateRegularQuantity = $regularQuantity + $regularDecimal;

                                $freeQuantity = floor($allocateFreePieces / $unitQuantity);
                                $freeRemainingPieces = $allocateFreePieces - ($freeQuantity * $unitQuantity);
                                $freeDecimal = $freeRemainingPieces > 0 ? (float) ('0.' . (int) $freeRemainingPieces) : 0;
                                $allocateFreeQuantity = $freeQuantity + $freeDecimal;

                                $productAllocations[$index]['allocations'][] = [
                                    'purchase_stock_product_id' => $purchaseProductId,
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                    'field_values' => array_merge(
                                        array_values(array_slice($regularFvByIndex, 0, $allocateRegularPieces)),
                                        array_values(array_slice($freeFvByIndex, 0, $allocateFreePieces))
                                    ),
                                    'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                    'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                                    'customer_id' => $productData['customer_id'] ?? $purchaseProduct->customer_id,
                                    'return_measure_unit_id' => $productData['measure_unit_id'],
                                ];

                                $batchQuantities[$purchaseProductId] -= ($allocateRegularPieces + $allocateFreePieces);
                                $remainingRegularPieces -= $allocateRegularPieces;
                                $remainingFreePieces -= $allocateFreePieces;

                                Log::debug('Allocation with field values', [
                                    'product_id' => $productId,
                                    'index' => $index,
                                    'purchase_stock_product_id' => $purchaseProductId,
                                    'allocated_regular_pieces' => $allocateRegularPieces,
                                    'allocated_free_pieces' => $allocateFreePieces,
                                    'allocated_regular_quantity' => $allocateRegularQuantity,
                                    'allocated_free_quantity' => $allocateFreeQuantity,
                                    'remaining_regular_pieces' => $remainingRegularPieces,
                                    'remaining_free_pieces' => $remainingFreePieces,
                                    'batch_remaining_pieces' => $batchQuantities[$purchaseProductId],
                                ]);
                            }
                        }
                    }

                    // Allocate remaining pieces (FIFO or single purchase_product_id)
                    if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                        $purchaseProduct = isset($productData['purchase_stock_product_id']) ? $purchaseProducts->firstWhere('id', $productData['purchase_stock_product_id']) : null;

                        if ($purchaseProduct) {
                            if ($purchaseProduct->fieldValues->isNotEmpty()) {
                                return response()->json(['error' => "Purchase product ID {$purchaseProduct->id} has field values; field_values must be provided at index {$index}"], 422);
                            }
                            $purchaseProducts = collect([$purchaseProduct]);
                        }

                        foreach ($purchaseProducts as $purchaseProduct) {
                            if ($remainingRegularPieces <= 0 && $remainingFreePieces <= 0) {
                                break;
                            }

                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                            $purchaseMeasureUnit = $purchaseProduct->measureUnit;
                            if (!$purchaseMeasureUnit) {
                                return response()->json(['error' => "Measure unit not found for purchase_product_id {$purchaseProduct->id} at index {$index}"], 404);
                            }
                            $purchaseUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            $availableQuantityInPieces = $batchQuantities[$purchaseProduct->id];
                            if ($availableQuantityInPieces <= 0) {
                                continue;
                            }

                            // Modified: Cap allocations at requested regular and free pieces separately
                            $allocateRegularPieces = min($remainingRegularPieces, $availableQuantityInPieces);
                            $allocateFreePieces = min($remainingFreePieces, max(0, $availableQuantityInPieces - $allocateRegularPieces));

                            if ($allocateRegularPieces > 0 || $allocateFreePieces > 0) {
                                $regularIntegerQuantity = floor($allocateRegularPieces / $unitQuantity);
                                $regularRemainingPieces = $allocateRegularPieces - ($regularIntegerQuantity * $unitQuantity);
                                $regularDecimal = $regularRemainingPieces > 0 ? (float) ('0.' . (int) $regularRemainingPieces) : 0;
                                $allocateRegularQuantity = $regularIntegerQuantity + $regularDecimal;

                                $freeIntegerQuantity = floor($allocateFreePieces / $unitQuantity);
                                $freeRemainingPieces = $allocateFreePieces - ($freeIntegerQuantity * $unitQuantity);
                                $freeDecimal = $freeRemainingPieces > 0 ? (float) ('0.' . (int) $freeRemainingPieces) : 0;
                                $allocateFreeQuantity = $freeIntegerQuantity + $freeDecimal;

                                $productAllocations[$index]['allocations'][] = [
                                    'purchase_stock_product_id' => $purchaseProduct->id,
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                    'field_values' => [],
                                    'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                    'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                                    'customer_id' => $productData['customer_id'] ?? $purchaseProduct->customer_id,
                                    'return_measure_unit_id' => $productData['measure_unit_id'],
                                ];

                                $batchQuantities[$purchaseProduct->id] -= ($allocateRegularPieces + $allocateFreePieces);
                                $remainingRegularPieces -= $allocateRegularPieces;
                                $remainingFreePieces -= $allocateFreePieces;

                                Log::debug('FIFO allocation', [
                                    'product_id' => $productId,
                                    'index' => $index,
                                    'purchase_stock_product_id' => $purchaseProduct->id,
                                    'allocated_regular_pieces' => $allocateRegularPieces,
                                    'allocated_free_pieces' => $allocateFreePieces,
                                    'allocated_regular_quantity' => $allocateRegularQuantity,
                                    'allocated_free_quantity' => $allocateFreeQuantity,
                                    'remaining_regular_pieces' => $remainingRegularPieces,
                                    'remaining_free_pieces' => $remainingFreePieces,
                                    'batch_remaining_pieces' => $batchQuantities[$purchaseProduct->id],
                                ]);
                            }
                        }
                    }

                    if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                        return response()->json([
                            'error' => "Insufficient stock for product ID {$productId} at index {$index}. Requested: " . ($productAllocations[$index]['regular_pieces'] + $productAllocations[$index]['free_pieces']) . " pieces (Regular: {$productAllocations[$index]['regular_pieces']}, Free: {$productAllocations[$index]['free_pieces']}), Allocated: " . (($productAllocations[$index]['regular_pieces'] + $productAllocations[$index]['free_pieces']) - ($remainingRegularPieces + $remainingFreePieces)) . " pieces"
                        ], 422);
                    }

                    // Add allocations to processedProducts
                    foreach ($productAllocations[$index]['allocations'] as $allocation) {
                        $purchaseProduct = PurchaseStockProduct::findOrFail($allocation['purchase_stock_product_id']);
                        $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                        $purchaseMeasureUnit = $purchaseProduct->measureUnit;
                        $purchaseUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                        // Log remaining pieces for debugging
                        $purchasedQuantityInPieces = $calculateQuantityInPieces($purchaseProduct->quantity, $purchaseProduct->free_quantity, $purchaseUnitQuantity);
                        $totalReturnedInPieces = $purchaseProduct->purchaseProductReturns->sum(function ($return) use ($calculateQuantityInPieces) {
                            $mu = MeasureUnit::findOrFail($return->measure_unit_id);
                            return $calculateQuantityInPieces($return->quantity, $return->free_quantity, $mu->quantity ?? 1);
                        });
                        $soldQuantityInPieces = $purchaseProduct->saleProducts->sum(function ($sale) use ($calculateQuantityInPieces) {
                            $mu = MeasureUnit::findOrFail($sale->measure_unit_id);
                            return $calculateQuantityInPieces($sale->quantity, $sale->free_quantity, $mu->quantity ?? 1);
                        });
                        $salesReturnedInPieces = $purchaseProduct->saleProducts->flatMap(function ($sale) use ($calculateQuantityInPieces) {
                            return $sale->saleReturnProducts->map(function ($return) use ($calculateQuantityInPieces) {
                                $mu = MeasureUnit::findOrFail($return->measure_unit_id);
                                return $calculateQuantityInPieces($return->quantity, $return->free_quantity, $mu->quantity ?? 1);
                            });
                        })->sum();
                        $allocatedQuantityInPieces = $calculateQuantityInPieces($allocation['quantity'], $allocation['free_quantity'], $unitQuantity);
                        $remainingQuantityInPiecesAfterAllocation = ($purchasedQuantityInPieces - $soldQuantityInPieces) + $salesReturnedInPieces - $totalReturnedInPieces - $allocatedQuantityInPieces;

                        $processedProducts[] = [
                            'purchase_stock_product_id' => $allocation['purchase_stock_product_id'],
                            'purchase_product_id' => $productData['purchase_product_id'] ?? null,
                            'stock_product_id' => $productData['stock_product_id'] ?? null,
                            'stock_reconiliation_id' => $productData['stock_reconiliation_id'] ?? null,
                            'stock_transfer_id' => $productData['stock_transfer_id'] ?? null,
                            'stock_adjustment_id' => $productData['stock_adjustment_id'] ?? null,
                            'product_id' => $productId,
                            'product_name' => $productData['product_name'] ?? ($purchaseProduct->product->name ?? ''),
                            'purchase_product_code' => $productData['purchase_product_code'] ?? ($purchaseProduct->product_code ?? ''),
                            'mfd' => $allocation['mfd'],
                            'customer_id' => $productData['customer_id'] ?? ($purchaseProduct->customer_id ?? null),
                            'quantity' => $allocation['quantity'],
                            'free_quantity' => $allocation['free_quantity'],
                            'price' => $productData['price'] ?? ($purchaseProduct->price ?? 0),
                            'discount_percent' => $productData['discount_percent'] ?? 0,
                            'discount_amount' => $productData['discount_amount'] ?? 0,
                            'amount' => ($productData['price'] ?? ($purchaseProduct->price ?? 0)) * $allocation['quantity'] - ($productData['discount_amount'] ?? 0),
                            'is_vatable' => $productData['is_vatable'],
                            'measure_unit_id' => $productData['measure_unit_id'],
                            'expiry_date' => $allocation['expiry_date'],
                            'field_values' => $allocation['field_values'],
                            'purchase_id' => $purchaseProduct->purchase_id,
                            'company_id' => $companyId,
                            'branch_id' => $branchId,
                            'purchase_bill_number' => $purchaseProduct->purchase->purchase_bill_number ?? '',
                            'allocated_quantity_in_pieces' => $allocatedQuantityInPieces,
                            'remaining_quantity_in_pieces' => $remainingQuantityInPiecesAfterAllocation,
                        ];

                        // Debugging line removed in final code
                    }
                }
            }

            // Process transaction
            $purchaseReturn = DB::transaction(function () use ($validated, $purchases, $processedProducts, $companyId, $branchId) {
                $purchaseReturnData = collect($validated)->except(['purchase_return_products', 'return_entire_batch'])->filter()->toArray();
                $purchaseReturnData['company_id'] = $validated['company_id']; // Ensure correct company_id

                $purchaseReturn = PurchaseStockReturn::create($purchaseReturnData);

                $balanceUpdates = [];

                foreach ($processedProducts as $productData) {
                    $purchaseProductId = $productData['purchase_stock_product_id'];

                    $purchaseProduct = PurchaseStockProduct::findOrFail($purchaseProductId);
                    $purchaseId = $purchaseProduct->purchase_id;
                    $purchase = $purchases[$purchaseId] ?? Purchase::findOrFail($purchaseId);

                    $productDataFiltered = collect($productData)->except(['field_values', 'purchase_id', 'purchase_bill_number', 'allocated_quantity_in_pieces', 'remaining_quantity_in_pieces'])->filter()->toArray();
                    $productDataFiltered['company_id'] = $validated['company_id'];
                    // dd($productDataFiltered);
                    $purchaseReturnProduct = $purchaseReturn->purchaseStockProductReturns()->create($productDataFiltered);

                    if (!empty($productData['field_values'])) {
                        Log::debug('Storing field_values for purchase return product', [
                            'purchase_stock_product_return_id' => $purchaseReturnProduct->id,
                            'field_values' => $productData['field_values'],
                        ]);

                        foreach ($productData['field_values'] as $arrayIndex => $fvSet) {
                            $quantityIndex = isset($fvSet[0]['quantity_index']) ? $fvSet[0]['quantity_index'] : $arrayIndex;

                            foreach ($fvSet as $fv) {
                                PurchaseStockProductReturnFieldValue::create([
                                    'purchase_stock_product_return_id' => $purchaseReturnProduct->id,
                                    'purchase_stock_product_id' => $fv['purchase_stock_product_id'] ?? null,
                                    'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                    'stock_product_id' => $fv['stock_product_id'] ?? null,
                                    'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                    'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                                    'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                                    'value' => $fv['value'],
                                    'product_id' => $purchaseReturnProduct->product_id,
                                    'product_field_id' => $fv['product_field_id'],
                                    'company_id' => $validated['company_id'],
                                    'branch_id' => $branchId,
                                    'quantity_index' => $quantityIndex,
                                    'quantity_type' => $fv['quantity_type'],
                                ]);
                            }
                        }
                    }

                    // Calculate return value (exclude free_quantity)
                    $returnValue = ($productData['quantity'] * ($productData['price'] ?? 0)) - ($productData['discount_amount'] ?? 0);
                    $balanceUpdates[$purchaseId] = ($balanceUpdates[$purchaseId] ?? 0) + $returnValue;
                }



                // PurchaseReturnHistory::create([
                //     'purchase_return_id' => $purchaseReturn->id,
                //     'action' => 'created',
                //     'data' => array_merge($purchaseReturnData, ['purchase_return_products' => $processedProducts]),
                // ]);

                return $purchaseReturn->load([
                    'purchaseStockProductReturns' => function ($query) {
                        $query->select('id', 'purchase_stock_return_id', 'purchase_stock_product_id', 'product_id', 'product_name', 'purchase_product_code', 'quantity', 'free_quantity', 'price', 'discount_percent', 'discount_amount', 'amount', 'is_vatable', 'measure_unit_id', 'expiry_date', 'mfd', 'customer_id');
                    },
                    'purchaseStockProductReturns.fieldValues' => fn($query) => $query->orderBy('quantity_index')->orderBy('product_field_id'),
                ]);
            });

            return response()->json([
                'message' => 'Purchase Return Created Successfully',
                'data' => $purchaseReturn,
            ], 201);
        } catch (ModelNotFoundException $e) {
            Log::error('Purchase or related record not found: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Purchase or related record not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database error creating purchase return: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Error creating purchase return: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }




    public function updatePurchaseReturnByInput(Request $request, $id): JsonResponse
    {
        try {
            // Define validation rules (same as store method)
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer',
                'customer_id' => 'nullable|integer|exists:customers,id',
                'customer_name' => 'nullable|string|max:255',
                'pan_number' => 'nullable|numeric|digits:10',
                'invoice_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('purchase_returns')->where(function ($query) use ($request, $id) {
                        return $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at')
                            ->where('id', '!=', $id);
                    }),
                ],
                'address' => 'nullable|string|max:255',
                'customer_contact' => 'nullable|string|max:255',
                'purchase_number' => 'nullable|string|max:255',
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reason' => 'nullable|string|in:damaged,defective,incorrect,expired,other',
                'store_id' => 'nullable|integer|exists:stores,id',
                'location_id' => 'nullable|integer|exists:locations,id',
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
                'roundoff_type' => 'nullable|string',
                'total_amount' => 'nullable|numeric|min:0',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'purchase_return_products' => [
                    'required',
                    'array',
                    'min:1',
                    function ($attribute, $value, $fail) {
                        foreach ($value as $index => $product) {
                            if (empty($product['product_name']) && empty($product['purchase_product_code'])) {
                                $fail("At least one of product_name or purchase_product_code is required for product at index {$index}.");
                            }
                        }
                    },
                ],
                'purchase_return_products.*.product_id' => 'required|integer|exists:products,id',
                'purchase_return_products.*.purchase_product_code' => 'nullable|string|max:255',
                'purchase_return_products.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',
                'purchase_return_products.*.purchase_product_id' => 'nullable',
                'purchase_return_products.*.stock_product_id' => 'nullable',
                'purchase_return_products.*.stock_reconciliation_id' => 'nullable',
                'purchase_return_products.*.stock_adjustment_id' => 'nullable',
                'purchase_return_products.*.stock_transfer_id' => 'nullable',
                'purchase_return_products.*.product_name' => 'nullable|string|max:255',
                'purchase_return_products.*.mfd' => 'nullable|string|max:255',
                'purchase_return_products.*.customer_id' => 'nullable|integer|exists:customers,id',
                'purchase_return_products.*.quantity' => 'required|numeric|min:0',
                'purchase_return_products.*.free_quantity' => 'nullable|numeric|min:0',
                'purchase_return_products.*.price' => 'required|numeric|min:0',
                'purchase_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'purchase_return_products.*.discount_amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.is_vatable' => 'required|boolean',
                'purchase_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'purchase_return_products.*.expiry_date' => 'nullable|string|max:255',
                'purchase_return_products.*.field_values' => 'present|array',
                'purchase_return_products.*.field_values.*' => 'array|min:1',
                'purchase_return_products.*.field_values.*.*.purchase_stock_product_id' => 'required_if:field_values,array|integer|exists:purchase_stock_products,id',
                'purchase_return_products.*.field_values.*.*.purchase_product_id' => 'nullable',
                'purchase_return_products.*.field_values.*.*.stock_product_id' => 'nullable',
                'purchase_return_products.*.field_values.*.*.stock_adjustment_id' => 'nullable',
                'purchase_return_products.*.field_values.*.*.stock_reconciliation_id' => 'nullable',
                'purchase_return_products.*.field_values.*.*.stock_transfer_id' => 'nullable',
                'purchase_return_products.*.field_values.*.*.product_field_id' => 'required_if:field_values,array|integer|exists:product_fields,id',
                'purchase_return_products.*.field_values.*.*.value' => 'required_if:field_values,array|string|max:255',
                'purchase_return_products.*.field_values.*.*.quantity_index' => 'required_if:field_values,array|integer|min:0',
                'purchase_return_products.*.field_values.*.*.quantity_type' => 'nullable|string|in:regular,free',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
            }

            $validated = $validator->validated();

            $validated['branch_id'] = $request->branch_id;
            Log::debug('Validated request data for update', ['data' => $validated]);

            // Process in transaction
            $purchaseReturn = DB::transaction(function () use ($validated, $id) {
                // Find the existing purchase return
                $purchaseReturn = PurchaseStockReturn::findOrFail($id);
                $oldData = $purchaseReturn->toArray();
                $oldProducts = $purchaseReturn->purchaseStockProductReturns()->with('fieldValues')->get()->toArray();

                // Delete existing products and their field values first
                $purchaseReturn->purchaseStockProductReturns()->each(function ($product) {
                    $product->fieldValues()->delete();
                    $product->delete();
                });

                // Update purchase return data
                $purchaseReturnData = array_filter($validated, fn($key) => !in_array($key, ['purchase_return_products']), ARRAY_FILTER_USE_KEY);
                $purchaseReturn->update($purchaseReturnData);

                // Since existing products are deleted, no need for myOldIndices or myReturnedPieces
                $myOldIndices = [];
                $plannedAllocatedPieces = [];
                $plannedUsedQuantityIndexes = [];

                $processedProducts = [];
                $purchases = collect();

                foreach ($validated['purchase_return_products'] as $index => $productData) {
                    $regularQuantity = $productData['quantity'] ?? 0;
                    $freeQuantity = $productData['free_quantity'] ?? 0;

                    // Target measure unit
                    $targetMeasureUnit = MeasureUnit::findOrFail($productData['measure_unit_id']);
                    $targetMeasureUnitQuantity = $targetMeasureUnit->quantity ?? 1;

                    // Calculate requested pieces
                    $regularPieces = $this->calculatePieces($regularQuantity, $targetMeasureUnitQuantity);
                    $freePieces = $this->calculatePieces($freeQuantity, $targetMeasureUnitQuantity);
                    $totalRequestedPieces = $regularPieces + $freePieces;

                    Log::debug('Requested quantities', [
                        'product_id' => $productData['product_id'],
                        'index' => $index,
                        'regular_quantity' => $regularQuantity,
                        'free_quantity' => $freeQuantity,
                        'target_measure_unit_id' => $productData['measure_unit_id'],
                        'target_measure_unit_quantity' => $targetMeasureUnitQuantity,
                        'regular_pieces' => $regularPieces,
                        'free_pieces' => $freePieces,
                        'total_requested_pieces' => $totalRequestedPieces
                    ]);

                    // Normalize field values
                    $fieldValuesFlat = $this->flattenFieldValues($productData['field_values'], $index);
                    Log::debug('Flattened field values', [
                        'product_id' => $productData['product_id'],
                        'index' => $index,
                        'field_values_flat' => $fieldValuesFlat
                    ]);

                    // Validate field values
                    collect($fieldValuesFlat)->each(function ($fv) use ($index) {
                        if (empty($fv['purchase_stock_product_id']) || !is_numeric($fv['purchase_stock_product_id'])) {
                            throw new \Exception("Invalid purchase_stock_product_id in field_values at index {$index}");
                        }
                        if (!isset($fv['quantity_index']) || !is_numeric($fv['quantity_index']) || $fv['quantity_index'] < 0) {
                            throw new \Exception("Invalid quantity_index in field_values at index {$index}");
                        }
                    });

                    // Group field values
                    $groupedFieldValues = collect($fieldValuesFlat)
                        ->groupBy('purchase_stock_product_id')
                        ->map(function ($group) {
                            return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                                return collect($fvGroup)->map(function ($fv) {
                                    return [
                                        'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                        'purchase_product_id' => $fv['purchase_product_id'],
                                        'stock_product_id' => $fv['stock_product_id'],
                                        'stock_adjustment_id' => $fv['stock_adjustment_id'],
                                        'stock_reconciliation_id' => $fv['stock_reconciliation_id'],
                                        'stock_transfer_id' => $fv['stock_transfer_id'],
                                        'product_field_id' => $fv['product_field_id'],
                                        'value' => $fv['value'],
                                        'quantity_index' => $fv['quantity_index'],
                                        'quantity_type' => $fv['quantity_type'] ?? 'regular'
                                    ];
                                })->unique(function ($fv) {
                                    return "{$fv['product_field_id']}:{$fv['value']}:{$fv['quantity_type']}";
                                })->values()->toArray();
                            })->toArray();
                        })
                        ->toArray();

                    Log::debug('Grouped field values', [
                        'product_id' => $productData['product_id'],
                        'index' => $index,
                        'grouped_field_values' => $groupedFieldValues
                    ]);

                    // Count field value sets
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
                    $requiresFieldValues = !empty($purchaseProductIds = array_keys($groupedFieldValues)) && PurchaseStockProductFieldValue::whereIn('purchase_stock_product_id', $purchaseProductIds)->whereNull('deleted_at')->exists();

                    Log::debug('Field value requirements', [
                        'product_id' => $productData['product_id'],
                        'index' => $index,
                        'has_field_values' => $hasFieldValues,
                        'requires_field_values' => $requiresFieldValues,
                        'purchase_product_ids' => $purchaseProductIds,
                        'regular_field_value_sets' => $regularFieldValueSets,
                        'free_field_value_sets' => $freeFieldValueSets,
                        'regular_pieces' => $regularPieces,
                        'free_pieces' => $freePieces
                    ]);

                    if (!$hasFieldValues && $requiresFieldValues) {
                        throw new \Exception("Field values required for product ID {$productData['product_id']} at index {$index}.");
                    }
                    if ($hasFieldValues && !$requiresFieldValues) {
                        throw new \Exception("Field values provided for product ID {$productData['product_id']} at index {$index}, but none required.");
                    }
                    if ($hasFieldValues && ($regularFieldValueSets != $regularPieces || $freeFieldValueSets != $freePieces)) {
                        throw new \Exception("Field value sets (Regular: {$regularFieldValueSets}, Free: {$freeFieldValueSets}) must match pieces (Regular: {$regularPieces}, Free: {$freePieces}) at index {$index}.");
                    }

                    $remainingRegularPieces = $regularPieces;
                    $remainingFreePieces = $freePieces;
                    $allocations = [];
                    $usedQuantityIndexes = [];

                    // Fetch PurchaseProducts
                    $query = PurchaseStockProduct::where('product_id', $productData['product_id'])
                        ->where('company_id', $validated['company_id'])
                        ->where('branch_id', $validated['branch_id'])
                        ->whereNull('deleted_at')
                        ->with([
                            'purchaseStockProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id'])->with('measureUnit'),
                            'fieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id']),
                            'saleProducts' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->with(['saleProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']), 'measureUnit'])
                        ]);

                    if ($hasFieldValues) {
                        $query->whereIn('id', $purchaseProductIds);
                    } elseif (isset($productData['purchase_stock_product_id'])) {
                        $query->where('id', $productData['purchase_stock_product_id']);
                    } else {
                        $query->whereNotExists(fn($subQuery) => $subQuery->select(DB::raw(1))->from('purchase_stock_product_field_values')->whereColumn('purchase_stock_product_id', 'purchase_stock_products.id')->where('company_id', $validated['company_id'])->where('branch_id', $validated['branch_id'])->whereNull('deleted_at'));
                    }

                    $purchaseProducts = $query->orderBy('created_at')->distinct()->get();
                    Log::debug('Fetched PurchaseProducts', [
                        'product_id' => $productData['product_id'],
                        'index' => $index,
                        'purchase_stock_product_ids' => $purchaseProducts->pluck('id')->toArray(),
                        'count' => $purchaseProducts->count()
                    ]);

                    if ($purchaseProducts->isEmpty()) {
                        throw new \Exception("No valid purchase products found for product ID {$productData['product_id']} at index {$index}.");
                    }

                    // Allocate with field values
                    if ($hasFieldValues) {
                        foreach ($groupedFieldValues as $purchaseProductId => $fvByIndex) {
                            $purchaseProduct = $purchaseProducts->firstWhere('id', $purchaseProductId) ?? throw new \Exception("Purchase product ID {$purchaseProductId} not found at index {$index}.");
                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;

                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $purchaseMeasureUnitQuantity, $validated['company_id']);

                            $plannedAllocated = $plannedAllocatedPieces[$purchaseProductId] ?? 0;
                            $totalAvailablePieces -= $plannedAllocated;

                            Log::debug('Stock calculation for PurchaseProduct', [
                                'product_id' => $productData['product_id'],
                                'index' => $index,
                                'purchase_stock_product_id' => $purchaseProductId,
                                'quantity' => $purchaseProduct->quantity,
                                'free_quantity' => $purchaseProduct->free_quantity,
                                'measure_unit_id' => $purchaseProduct->measure_unit_id,
                                'measure_unit_quantity' => $purchaseMeasureUnitQuantity,
                                'total_available_pieces' => $totalAvailablePieces
                            ]);

                            $existingFieldValues = $purchaseProduct->fieldValues->groupBy('quantity_index')->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());
                            $unavailableQuantityIndices = $this->getUnavailableQuantityIndices($purchaseProduct, $validated['company_id']);
                            $salesReturnedIndices = SaleReturnProductFieldValue::whereIn('sale_return_product_id', $purchaseProduct->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns->pluck('id')))->whereNull('deleted_at')->pluck('quantity_index')->toArray();
                            $unavailableQuantityIndices = array_diff($unavailableQuantityIndices, $salesReturnedIndices);
                            $unavailableQuantityIndices = array_diff($unavailableQuantityIndices, $myOldIndices);
                            $plannedUsed = $plannedUsedQuantityIndexes[$purchaseProductId] ?? [];
                            $unavailableQuantityIndices = array_unique(array_merge($unavailableQuantityIndices, $plannedUsed));
                            Log::debug('Field value validation', [
                                'product_id' => $productData['product_id'],
                                'index' => $index,
                                'purchase_stock_product_id' => $purchaseProductId,
                                'existing_field_values' => $existingFieldValues,
                                'unavailable_quantity_indices' => $unavailableQuantityIndices,
                                'sales_returned_indices' => $salesReturnedIndices
                            ]);

                            foreach ($fvByIndex as $quantityIndex => $fvSet) {
                                if (in_array($quantityIndex, $unavailableQuantityIndices) || !isset($existingFieldValues[$quantityIndex])) {
                                    throw new \Exception("Invalid quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseProductId} at index {$index}.");
                                }
                                if (in_array($quantityIndex, $usedQuantityIndexes[$purchaseProductId] ?? [])) {
                                    throw new \Exception("Duplicate quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseProductId} at index {$index}.");
                                }
                                if (collect($fvSet)->pluck('value', 'product_field_id')->toArray() != $existingFieldValues[$quantityIndex]) {
                                    throw new \Exception("Field values for quantity_index {$quantityIndex} for purchase_stock_product_id {$purchaseProductId} do not match at index {$index}.");
                                }
                                $usedQuantityIndexes[$purchaseProductId][] = $quantityIndex;
                            }

                            $regularFvByIndex = collect($fvByIndex)->filter(function ($fvSet) {
                                return collect($fvSet)->first()['quantity_type'] === 'regular';
                            })->toArray();

                            $freeFvByIndex = collect($fvByIndex)->filter(function ($fvSet) {
                                return collect($fvSet)->first()['quantity_type'] === 'free';
                            })->toArray();

                            $totalRequestedForThisProduct = count($regularFvByIndex) + count($freeFvByIndex);
                            $allocatePieces = min($totalRequestedForThisProduct, $totalAvailablePieces);

                            if ($allocatePieces > 0) {
                                $allocateRegularPieces = min(count($regularFvByIndex), $allocatePieces);
                                $allocateFreePieces = min(count($freeFvByIndex), $allocatePieces - $allocateRegularPieces);

                                $allocatedRegularFv = array_slice($regularFvByIndex, 0, $allocateRegularPieces, true);
                                $allocatedFreeFv = array_slice($freeFvByIndex, 0, $allocateFreePieces, true);

                                [$allocateRegularQuantity, $allocateFreeQuantity] = $this->convertToTargetMeasureUnit($allocateRegularPieces, $allocateFreePieces, $targetMeasureUnitQuantity);

                                $allocations[] = [
                                    'purchase_stock_product_id' => $purchaseProductId,
                                    'quantity' => $allocateRegularQuantity,
                                    'free_quantity' => $allocateFreeQuantity,
                                    'field_values' => array_merge(
                                        array_values($allocatedRegularFv),
                                        array_values($allocatedFreeFv)
                                    ),
                                    'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                    'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                                    'customer_id' => $productData['customer_id'] ?? $purchaseProduct->customer_id,
                                    'return_measure_unit_id' => $productData['measure_unit_id'],
                                ];

                                $plannedAllocatedPieces[$purchaseProductId] = ($plannedAllocatedPieces[$purchaseProductId] ?? 0) + ($allocateRegularPieces + $allocateFreePieces);

                                $allocatedIndices = array_merge(array_keys($allocatedRegularFv), array_keys($allocatedFreeFv));
                                foreach ($allocatedIndices as $qi) {
                                    $plannedUsedQuantityIndexes[$purchaseProductId][] = $qi;
                                }

                                $remainingRegularPieces -= $allocateRegularPieces;
                                $remainingFreePieces -= $allocateFreePieces;

                                Log::debug('Allocation with field values', [
                                    'product_id' => $productData['product_id'],
                                    'index' => $index,
                                    'purchase_stock_product_id' => $purchaseProductId,
                                    'allocated_regular_pieces' => $allocateRegularPieces,
                                    'allocated_free_pieces' => $allocateFreePieces,
                                    'total_allocated_pieces' => $allocatePieces,
                                    'remaining_regular_pieces' => $remainingRegularPieces,
                                    'remaining_free_pieces' => $remainingFreePieces,
                                    'allocation' => end($allocations)
                                ]);
                            }
                        }
                    }

                    // Allocate remaining pieces (FIFO or single purchase_product_id)
                    if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                        $purchaseProduct = isset($productData['purchase_stock_product_id']) ? $purchaseProducts->firstWhere('id', $productData['purchase_stock_product_id']) : null;

                        if ($purchaseProduct) {
                            if ($purchaseProduct->fieldValues->isNotEmpty()) {
                                throw new \Exception("Purchase product ID {$purchaseProduct->id} has field values; field_values must be provided at index {$index}.");
                            }
                            $purchaseProducts = collect([$purchaseProduct]);
                        }

                        foreach ($purchaseProducts as $purchaseProduct) {
                            if ($remainingRegularPieces <= 0 && $remainingFreePieces <= 0)
                                break;

                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                            $purchaseMeasureUnit = MeasureUnit::findOrFail($purchaseProduct->measure_unit_id);
                            $purchaseMeasureUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            $totalAvailablePieces = $this->calculateAvailablePieces($purchaseProduct, $purchaseMeasureUnitQuantity, $validated['company_id']);

                            $plannedAllocated = $plannedAllocatedPieces[$purchaseProduct->id] ?? 0;
                            $totalAvailablePieces -= $plannedAllocated;

                            Log::debug('Stock calculation for FIFO PurchaseProduct', [
                                'product_id' => $productData['product_id'],
                                'index' => $index,
                                'purchase_stock_product_id' => $purchaseProduct->id,
                                'quantity' => $purchaseProduct->quantity,
                                'free_quantity' => $purchaseProduct->free_quantity,
                                'measure_unit_id' => $purchaseProduct->measure_unit_id,
                                'measure_unit_quantity' => $purchaseMeasureUnitQuantity,
                                'total_available_pieces' => $totalAvailablePieces
                            ]);

                            if ($totalAvailablePieces <= 0)
                                continue;

                            $totalRemainingPieces = $remainingRegularPieces + $remainingFreePieces;
                            $allocatePieces = min($totalRemainingPieces, $totalAvailablePieces);

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
                                    'customer_id' => $productData['customer_id'] ?? $purchaseProduct->customer_id,
                                    'return_measure_unit_id' => $productData['measure_unit_id'],
                                ];

                                $remainingRegularPieces -= $allocateRegularPieces;
                                $remainingFreePieces -= $allocateFreePieces;

                                $plannedAllocatedPieces[$purchaseProduct->id] = ($plannedAllocatedPieces[$purchaseProduct->id] ?? 0) + ($allocateRegularPieces + $allocateFreePieces);

                                Log::debug('FIFO allocation', [
                                    'product_id' => $productData['product_id'],
                                    'index' => $index,
                                    'purchase_stock_product_id' => $purchaseProduct->id,
                                    'allocated_regular_pieces' => $allocateRegularPieces,
                                    'allocated_free_pieces' => $allocateFreePieces,
                                    'total_allocated_pieces' => $allocateRegularPieces + $allocateFreePieces,
                                    'remaining_regular_pieces' => $remainingRegularPieces,
                                    'remaining_free_pieces' => $remainingFreePieces,
                                    'allocation' => end($allocations)
                                ]);
                            }
                        }
                    }

                    if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                        Log::error('Insufficient stock detected', [
                            'product_id' => $productData['product_id'],
                            'index' => $index,
                            'requested_regular_pieces' => $regularPieces,
                            'requested_free_pieces' => $freePieces,
                            'total_requested_pieces' => $totalRequestedPieces,
                            'remaining_regular_pieces' => $remainingRegularPieces,
                            'remaining_free_pieces' => $remainingFreePieces,
                            'total_allocated_pieces' => $totalRequestedPieces - ($remainingRegularPieces + $remainingFreePieces),
                            'allocations' => $allocations
                        ]);
                        throw new \Exception("Insufficient stock for product ID {$productData['product_id']} at index {$index}. Requested: {$totalRequestedPieces} pieces (Regular: {$regularPieces}, Free: {$freePieces}), Allocated: " . ($totalRequestedPieces - ($remainingRegularPieces + $remainingFreePieces)) . " pieces.");
                    }

                    // Build processed products
                    foreach ($allocations as $allocation) {
                        $purchaseProduct = PurchaseStockProduct::findOrFail($allocation['purchase_stock_product_id']);
                        $processedProducts[] = [
                            'purchase_stock_product_id' => $allocation['purchase_stock_product_id'],
                            'product_id' => $productData['product_id'],
                            'product_name' => $productData['product_name'] ?? $purchaseProduct->product->name ?? '',
                            'purchase_product_code' => $productData['purchase_product_code'] ?? $purchaseProduct->product_code ?? '',
                            'mfd' => $allocation['mfd'],
                            'customer_id' => $allocation['customer_id'],
                            'quantity' => $allocation['quantity'],
                            'free_quantity' => $allocation['free_quantity'],
                            'price' => $productData['price'] ?? $purchaseProduct->price ?? 0,
                            'discount_percent' => $productData['discount_percent'] ?? 0,
                            'discount_amount' => $productData['discount_amount'] ?? 0,
                            'amount' => ($productData['price'] ?? $purchaseProduct->price ?? 0) * $allocation['quantity'] - ($productData['discount_amount'] ?? 0),
                            'is_vatable' => $productData['is_vatable'],
                            'measure_unit_id' => $allocation['return_measure_unit_id'],
                            'expiry_date' => $allocation['expiry_date'],
                            'field_values' => $allocation['field_values'],
                            'purchase_id' => $purchaseProduct->purchase_id,
                            'purchase_purchase_bill_number' => $purchases[$purchaseProduct->purchase_id]->purchase_bill_number ?? '',
                        ];
                    }
                }

                // Create purchase return
                // $purchaseReturnData = array_filter($validated, fn($key) => !in_array($key, ['purchase_return_products']), ARRAY_FILTER_USE_KEY);
                // $purchaseReturnData['purchase_id'] = null;
                // $purchaseReturn = PurchaseStockReturn::create($purchaseReturnData);

                foreach ($processedProducts as $productData) {
                    $productDataFiltered = array_filter($productData, fn($key) => !in_array($key, ['field_values', 'purchase_id', 'purchase_purchase_bill_number']), ARRAY_FILTER_USE_KEY);
                    $purchaseReturnProduct = $purchaseReturn->purchaseStockProductReturns()->create(array_merge($productDataFiltered, ['company_id' => $purchaseReturn->company_id, 'branch_id' => $purchaseReturn->branch_id]));

                    if (!empty($productData['field_values'])) {
                        foreach ($productData['field_values'] as $arrayIndex => $fvSet) {
                            $quantityIndex = isset($fvSet[0]['quantity_index']) ? $fvSet[0]['quantity_index'] : $arrayIndex;
                            foreach ($fvSet as $fv) {
                                PurchaseStockProductReturnFieldValue::create([
                                    'purchase_stock_product_return_id' => $purchaseReturnProduct->id,
                                    'purchase_stock_product_id' => $fv['purchase_stock_product_id'] ?? null,
                                    'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                                    'stock_product_id' => $fv['stock_product_id'] ?? null,
                                    'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                                    'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                                    'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                                    'product_field_id' => $fv['product_field_id'],
                                    'value' => $fv['value'],
                                    'product_id' => $purchaseReturnProduct->product_id,
                                    'company_id' => $purchaseReturnProduct->company_id,
                                    'branch_id' => $purchaseReturnProduct->branch_id,
                                    'quantity_index' => $fv['quantity_index'],
                                    'quantity_type' => $fv['quantity_type'], // Remove ?? null to ensure value is saved
                                ]);
                            }
                        }
                    }
                }

                Log::debug('Purchase return created', ['purchase_stock_return_id' => $purchaseReturn->id, 'processed_products' => $processedProducts]);

                return $purchaseReturn->load([
                    'purchaseStockProductReturns' => fn($query) => $query->select('id', 'purchase_stock_return_id', 'purchase_stock_product_id', 'product_id', 'product_name', 'purchase_product_code', 'quantity', 'free_quantity', 'price', 'discount_percent', 'discount_amount', 'amount', 'is_vatable', 'measure_unit_id', 'expiry_date', 'mfd', 'customer_id'),
                    'purchaseStockProductReturns.fieldValues' => fn($query) => $query->select('id', 'purchase_stock_product_return_id', 'product_field_id', 'value', 'quantity_index', 'quantity_type', 'product_id', 'company_id', 'created_at', 'updated_at', 'deleted_at')->orderBy('quantity_index')->orderBy('product_field_id')
                ]);
            });

            return response()->json(['message' => 'Purchase Return Created Successfully', 'data' => $purchaseReturn], 201);
        } catch (ModelNotFoundException $e) {
            Log::error('Model not found: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Record not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            dd($e->getMessage());
            Log::error('Unexpected error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Error creating purchase return: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer',
                'purchase_id' => 'nullable|integer|exists:purchases,id',
                'customer_id' => 'nullable|integer|exists:customers,id',
                'customer_name' => 'nullable|string|max:255',
                'invoice_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('purchase_stock_returns')->where(function ($query) use ($request, $id) {
                        return $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at')
                            ->where('id', '!=', $id);
                    }),
                ],
                'pan_number' => 'nullable|string|max:255',
                'address' => 'nullable|string|max:255',
                'customer_contact' => 'nullable|string|max:255',
                'purchase_number' => 'nullable|string|max:255',
                'purchase_bill_number' => 'nullable|string|max:255',
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reason' => 'nullable|string|in:damaged,defective,incorrect,expired,other',
                'store_id' => 'nullable|integer|exists:stores,id',
                'location_id' => 'nullable|integer|exists:locations,id',
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
                'payment.bank_name' => 'nullable|string',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'return_entire_batch' => 'nullable|boolean',
                'purchase_return_products' => [
                    'required',
                    'array',
                    'min:1',
                    function ($attribute, $value, $fail) {
                        foreach ($value as $index => $product) {
                            if (empty($product['product_name']) && empty($product['purchase_product_code'])) {
                                $fail("At least one of product_name or purchase_product_code is required for product at index {$index}.");
                            }
                            if (!isset($product['quantity']) || is_null($product['quantity'])) {
                                $fail("Quantity is required for product at index {$index}.");
                            }
                        }
                    },
                ],
                'purchase_return_products.*.product_id' => 'required|integer|exists:products,id',
                'purchase_return_products.*.purchase_stock_product_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('purchase_stock_products', 'id')->where(function ($query) use ($request) {
                        $query->where('company_id', $request->input('company_id'))
                            ->where('branch_id', $request->input('branch_id'));
                        if ($request->input('purchase_id')) {
                            $query->where('purchase_id', $request->input('purchase_id'));
                        }
                    }),
                ],
                'purchase_return_products.*.purchase_product_id' => 'nullable|numeric',
                'purchase_return_products.*.stock_product_id' => 'nullable|numeric',
                'purchase_return_products.*.stock_adjustment_id' => 'nullable|numeric',
                'purchase_return_products.*.stock_reconciliation_id' => 'nullable|numeric',
                'purchase_return_products.*.stock_transfer_id' => 'nullable|numeric',
                'purchase_return_products.*.product_name' => 'nullable|string|max:255',
                'purchase_return_products.*.purchase_product_code' => 'nullable|string|max:255',
                'purchase_return_products.*.mfd' => 'nullable|string|max:255',
                'purchase_return_products.*.customer_id' => 'nullable|integer|exists:customers,id',
                'purchase_return_products.*.quantity' => 'required|numeric|min:0',
                'purchase_return_products.*.free_quantity' => 'nullable|numeric|min:0',
                'purchase_return_products.*.price' => 'required|numeric|min:0',
                'purchase_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'purchase_return_products.*.discount_amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.is_vatable' => 'required|boolean',
                'purchase_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'purchase_return_products.*.expiry_date' => 'nullable|string|max:255',
                'purchase_return_products.*.field_values' => 'present|array',
                'purchase_return_products.*.field_values.*' => 'array|min:1',
                'purchase_return_products.*.field_values.*.*.purchase_stock_product_id' => 'required_if:field_values,array|integer|exists:purchase_stock_products,id',
                'purchase_return_products.*.field_values.*.*.purchase_product_id' => 'required_if:field_values,array|integer|exists:purchase_products,id',
                'purchase_return_products.*.field_values.*.*.product_field_id' => 'required_if:field_values,array|integer|exists:product_fields,id',
                'purchase_return_products.*.field_values.*.*.value' => 'required_if:field_values,array|string|max:255',
                'purchase_return_products.*.field_values.*.*.quantity_index' => 'required_if:field_values,array|integer|min:0',
                'purchase_return_products.*.field_values.*.*.quantity_type' => 'required_if:field_values,array|string|in:regular,free',
            ]);

            if ($validator->fails()) {
                Log::error('Validation failed', [
                    'purchase_return_id' => $id,
                    'errors' => $validator->errors()->toArray(),
                ]);
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }



            $validated = $validator->validated();
            $branchId = $request->branch_id;
            $validated['branch_id'] = $branchId;
            Log::debug('Validated request data', [
                'purchase_return_id' => $id,
                'data' => $validated,
            ]);

            $companyId = $request->input('company_id');
            $branchId = $request->input('branch_id');

            $purchaseReturn = PurchaseStockReturn::where('id', $id)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->with(['purchaseStockProductReturns' => fn($q) => $q->whereNull('deleted_at')->with('measureUnit')])
                ->firstOrFail();



            Log::debug('Loaded purchase return', [
                'purchase_stock_return_id' => $id,
                'company_id' => $validated['company_id'],
                'purchase_return_products_count' => $purchaseReturn->purchaseStockProductReturns->count(),
                'purchase_return_products' => $purchaseReturn->purchaseStockProductReturns->map(function ($product) {
                    return [
                        'id' => $product->id,
                        'purchase_stock_product_id' => $product->purchase_stock_product_id,
                        'product_id' => $product->product_id,
                        'quantity' => $product->quantity,
                        'free_quantity' => $product->free_quantity,
                        'measure_unit_id' => $product->measure_unit_id,
                    ];
                })->toArray(),
            ]);

            // Helper function to calculate quantity in pieces
            $calculateQuantityInPieces = function ($quantity, $freeQuantity, $unitQuantity) {
                $regularQuantity = floor($quantity ?? 0);
                $regularDecimal = $quantity - $regularQuantity;
                $regularDecimalStr = (string) $regularDecimal;
                $regularDecimalInPieces = $regularDecimalStr > 0 ? (int) str_replace('.', '', (string) $regularDecimalStr) : 0;
                $quantityInt = ($regularQuantity * $unitQuantity) + $regularDecimalInPieces;

                $nonRegularQuantity = floor($freeQuantity ?? 0);
                $freeDecimal = $freeQuantity - $nonRegularQuantity;
                $nonRegularDecimalStr = (string) $freeDecimal;
                $nonRegularDecimalInPieces = $nonRegularDecimalStr > 0 ? (int) str_replace('.', '', (string) $nonRegularDecimalStr) : 0;
                $freeInt = ($nonRegularQuantity * $unitQuantity) + $nonRegularDecimalInPieces;

                $totalPieces = $quantityInt + $freeInt;

                Log::debug('Calculating pieces', [
                    'quantity' => $quantity,
                    'quantity_integer' => $regularQuantity,
                    'quantity_decimal' => $regularDecimal,
                    'quantity_decimal_in_pieces' => $regularDecimalInPieces,
                    'free_quantity' => $freeQuantity,
                    'free_quantity_integer' => $nonRegularQuantity,
                    'free_quantity_decimal' => $freeDecimal,
                    'free_quantity_decimal_in_pieces' => $nonRegularDecimalInPieces,
                    'unit_quantity' => $unitQuantity,
                    'regular_pieces' => $quantityInt,
                    'free_pieces' => $freeInt,
                    'total_pieces' => $totalPieces,
                ]);
                return $totalPieces;
            };

            return DB::transaction(function () use ($validated, $id, $purchaseReturn, $companyId, $branchId, $calculateQuantityInPieces) {
                // Initialize collections
                $processedProducts = [];
                $purchases = collect();
                $batchQuantities = [];
                $cumulativeAllocatedByPurchaseProduct = [];

                // Calculate quantities to add back from existing PurchaseProductReturn records
                $currentReturnQuantitiesByPurchaseProduct = [];
                Log::debug('Starting calculation of current return quantities', [
                    'purchase_return_id' => $id,
                    'existing_products_count' => $purchaseReturn->purchaseStockProductReturns->count(),
                ]);

                foreach ($purchaseReturn->purchaseStockProductReturns as $existingProduct) {
                    $mu = $existingProduct->measureUnit;
                    if (!$mu) {
                        Log::error('Measure unit not found for purchase_product_return_id', [
                            'purchase_return_id' => $id,
                            'purchase_stock_product_return_id' => $existingProduct->id,
                        ]);
                        return response()->json(['error' => "Measure unit not found for purchase_product_return_id {$existingProduct->id}"], 404);
                    }

                    // Validate purchase_product_id
                    $purchaseProduct = PurchaseStockProduct::where('id', $existingProduct->purchase_stock_product_id)
                        ->where('company_id', $companyId, )
                        ->where('branch_id', $branchId, )
                        ->whereNull('deleted_at')
                        ->with(['measureUnit'])
                        ->first();

                    if (!$purchaseProduct) {
                        Log::error('Invalid purchase_product_id in PurchaseStockProductReturn', [
                            'purchase_stock_return_id' => $id,
                            'purchase_stock_product_id' => $existingProduct->purchase_stock_product_id,
                            'purchase_stock_product_return_id' => $existingProduct->id,
                        ]);
                        return response()->json(['error' => "Invalid purchase_product_id {$existingProduct->purchase_stock_product_id} in purchase return product"], 422);
                    }

                    Log::debug('Validating purchase product', [
                        'purchase_stock_return_id' => $id,
                        'purchase_stock_product_return_id' => $existingProduct->id,
                        'purchase_stock_product_id' => $existingProduct->purchase_stock_product_id,
                        'product_id' => $purchaseProduct->product_id,
                        'measure_unit_id' => $purchaseProduct->measure_unit_id,
                        'quantity' => $purchaseProduct->quantity,
                        'free_quantity' => $purchaseProduct->free_quantity,
                    ]);

                    // Calculate pieces using the return's measure unit
                    $returnUnitQuantity = $mu->quantity ?? 1;
                    $returnPieces = $calculateQuantityInPieces($existingProduct->quantity, $existingProduct->free_quantity, $returnUnitQuantity);

                    // Check if purchase_product_id matches the return's measure_unit_id
                    if ($existingProduct->measure_unit_id != $purchaseProduct->measure_unit_id) {
                        Log::warning('Measure unit mismatch detected, checking for correct PurchaseProduct', [
                            'purchase_stock_return_id' => $id,
                            'purchase_stock_product_return_id' => $existingProduct->id,
                            'purchase_stock_product_id' => $existingProduct->purchase_stock_product_id,
                            'return_measure_unit_id' => $existingProduct->measure_unit_id,
                            'purchase_measure_unit_id' => $purchaseProduct->measure_unit_id,
                            'return_pieces' => $returnPieces,
                        ]);
                        $correctPurchaseProduct = PurchaseStockProduct::where('product_id', $purchaseProduct->product_id)
                            ->where('company_id', $companyId)
                            ->where('branch_id', $branchId)
                            ->where('measure_unit_id', $existingProduct->measure_unit_id)
                            ->whereNull('deleted_at')
                            ->with(['measureUnit'])
                            ->first();
                        if ($correctPurchaseProduct) {
                            Log::info('Reassigning to matching PurchaseProduct', [
                                'purchase_stock_return_id' => $id,
                                'purchase_stock_product_return_id' => $existingProduct->id,
                                'old_purchase_stock_product_id' => $existingProduct->purchase_stock_product_id,
                                'new_purchase_product_id' => $correctPurchaseProduct->id,
                                'measure_unit_id' => $existingProduct->measure_unit_id,
                            ]);
                            $existingProduct->purchase_stock_product_id = $correctPurchaseProduct->id;
                            $existingProduct->save();
                            $purchaseProduct = $correctPurchaseProduct;
                        }
                    }

                    // Calculate available stock in pieces
                    $purchaseUnitQuantity = $purchaseProduct->measureUnit->quantity ?? 1;
                    $purchasedQuantityInPieces = $calculateQuantityInPieces($purchaseProduct->quantity, $purchaseProduct->free_quantity, $purchaseUnitQuantity);

                    $totalReturnedInPieces = $purchaseProduct->purchaseStockProductReturns
                        ->where('purchase_stock_return_id', '!=', $id)
                        ->sum(function ($return) use ($calculateQuantityInPieces) {
                            $mu = MeasureUnit::findOrFail($return->measure_unit_id);
                            $returnUnitQuantity = $mu->quantity ?? 1;
                            $pieces = $calculateQuantityInPieces($return->quantity, $return->free_quantity, $returnUnitQuantity);
                            Log::debug('Calculating total returned pieces for other returns', [
                                'purchase_stock_product_id' => $return->purchase_stock_product_id,
                                'purchase_stock_return_id' => $return->purchase_stock_return_id,
                                'quantity' => $return->quantity,
                                'free_quantity' => $return->free_quantity,
                                'measure_unit_id' => $return->measure_unit_id,
                                'pieces' => $pieces,
                            ]);
                            return $pieces;
                        });



                    $soldQuantityInPieces = $purchaseProduct->saleProducts->sum(function ($sale) use ($calculateQuantityInPieces) {
                        $mu = MeasureUnit::findOrFail($sale->measure_unit_id);
                        $saleUnitQuantity = $mu->quantity ?? 1;
                        $pieces = $calculateQuantityInPieces($sale->quantity, $sale->free_quantity, $saleUnitQuantity);
                        Log::debug('Calculating sold pieces', [
                            'sale_product_id' => $sale->id,
                            'quantity' => $sale->quantity,
                            'free_quantity' => $sale->free_quantity,
                            'measure_unit_id' => $sale->measure_unit_id,
                            'pieces' => $pieces,
                        ]);
                        return $pieces;
                    });

                    $salesReturnedInPieces = $purchaseProduct->saleProducts->flatMap(function ($sale) use ($calculateQuantityInPieces) {
                        return $sale->saleReturnProducts->map(function ($return) use ($calculateQuantityInPieces) {
                            $mu = MeasureUnit::findOrFail($return->measure_unit_id);
                            $returnUnitQuantity = $mu->quantity ?? 1;
                            $pieces = $calculateQuantityInPieces($return->quantity, $return->free_quantity, $returnUnitQuantity);
                            Log::debug('Calculating sales returned pieces', [
                                'sale_return_product_id' => $return->id,
                                'quantity' => $return->quantity,
                                'free_quantity' => $return->free_quantity,
                                'measure_unit_id' => $return->measure_unit_id,
                                'pieces' => $pieces,
                            ]);
                            return $pieces;
                        });
                    })->sum();

                    $availableQuantityInPieces = ($purchasedQuantityInPieces - $soldQuantityInPieces) + $salesReturnedInPieces - $totalReturnedInPieces;

                    Log::debug('Key for currentReturnQuantitiesByPurchaseProduct', ['key' => $existingProduct->purchase_stock_product_id, 'purchase_stock_return_id' => $id, 'existing_product_id' => $existingProduct->id]);
                    $currentAddedBack = $currentReturnQuantitiesByPurchaseProduct[$existingProduct->purchase_stock_product_id] ?? 0;

                    if ($returnPieces > $availableQuantityInPieces + $currentAddedBack) {
                        Log::error('Adding back pieces exceeds available stock', [
                            'purchase_stock_return_id' => $id,
                            'purchase_stock_product_return_id' => $existingProduct->id,
                            'purchase_stock_product_id' => $existingProduct->purchase_stock_product_id,
                            'pieces_to_add_back' => $returnPieces,
                            'available_pieces' => $availableQuantityInPieces,
                            'purchased_pieces' => $purchasedQuantityInPieces,
                            'current_added_back' => $currentAddedBack,
                            'return_measure_unit_id' => $existingProduct->measure_unit_id,
                            'return_unit_quantity' => $returnUnitQuantity,
                            'purchase_measure_unit_id' => $purchaseProduct->measure_unit_id,
                            'purchase_unit_quantity' => $purchaseUnitQuantity,
                        ]);
                        return response()->json([
                            'error' => "Cannot add back {$returnPieces} pieces for purchase_product_id {$existingProduct->purchase_product_id}. Available: {$availableQuantityInPieces} pieces"
                        ], 422);
                    }

                    // Add pieces to currentReturnQuantitiesByPurchaseProduct
                    $currentReturnQuantitiesByPurchaseProduct[$existingProduct->purchase_stock_product_id] =
                        ($currentReturnQuantitiesByPurchaseProduct[$existingProduct->purchase_stock_product_id] ?? 0) + $returnPieces;

                    // Detailed logging
                    Log::debug('Adding back return quantity', [
                        'purchase_stock_return_id' => $id,
                        'purchase_stock_product_return_id' => $existingProduct->id,
                        'purchase_stock_product_id' => $existingProduct->purchase_stock_product_id,
                        'product_id' => $existingProduct->product_id,
                        'quantity' => $existingProduct->quantity,
                        'free_quantity' => $existingProduct->free_quantity,
                        'return_measure_unit_id' => $existingProduct->measure_unit_id,
                        'return_unit_quantity' => $returnUnitQuantity,
                        'return_pieces' => $returnPieces,
                        'purchase_measure_unit_id' => $purchaseProduct->measure_unit_id,
                        'purchase_unit_quantity' => $purchaseUnitQuantity,
                        'purchased_pieces' => $purchasedQuantityInPieces,
                        'sold_pieces' => $soldQuantityInPieces,
                        'sales_returned_pieces' => $salesReturnedInPieces,
                        'total_returned_pieces' => $totalReturnedInPieces,
                        'available_pieces' => $availableQuantityInPieces,
                        'total_added_back_for_product' => $currentReturnQuantitiesByPurchaseProduct[$existingProduct->purchase_stock_product_id],
                    ]);
                }

                // Log final state
                Log::debug('Final return quantities by purchase product', [
                    'purchase_stock_return_id' => $id,
                    'current_return_quantities' => $currentReturnQuantitiesByPurchaseProduct,
                ]);

                // Delete existing PurchaseProductReturn records
                $deletedCount = PurchaseStockProductReturn::where('purchase_stock_return_id', $id)->count();
                PurchaseStockProductReturn::where('purchase_stock_return_id', $id)->delete();
                Log::info('Deleted existing PurchaseProductReturn records', [
                    'purchase_stock_return_id' => $id,
                    'deleted_count' => $deletedCount,
                ]);

                // Group products by product_id for FIFO allocation
                $productsById = collect($validated['purchase_return_products'])->groupBy('product_id')->map(function ($products) {
                    return $products->toArray();
                })->toArray();

                Log::debug('Grouped products by product_id', [
                    'purchase_stock_return_id' => $id,
                    'products_by_id' => array_keys($productsById),
                ]);

                foreach ($productsById as $productId => $productGroup) {
                    // Calculate total requested pieces
                    $totalRequestedPieces = 0;
                    $productAllocations = [];

                    foreach ($productGroup as $index => $productData) {
                        if (is_null($productData['quantity'])) {
                            Log::error('Null quantity detected', [
                                'purchase_return_id' => $id,
                                'product_id' => $productId,
                                'index' => $index,
                            ]);
                            return response()->json(['error' => "Quantity cannot be null for product at index {$index}"], 422);
                        }
                        $regularQuantity = (float) ($productData['quantity'] ?? 0);
                        $freeQuantity = (float) ($productData['free_quantity'] ?? 0);
                        $measureUnit = MeasureUnit::findOrFail($productData['measure_unit_id']);
                        $unitQuantity = $measureUnit->quantity ?? 1;

                        $regularPieces = $calculateQuantityInPieces($regularQuantity, 0, $unitQuantity);
                        $freePieces = $calculateQuantityInPieces(0, $freeQuantity, $unitQuantity);
                        $totalRequestedPieces += $regularPieces + $freePieces;

                        $productAllocations[$index] = [
                            'regular_pieces' => $regularPieces,
                            'free_pieces' => $freePieces,
                            'product_data' => $productData,
                            'allocations' => [],
                        ];

                        Log::debug('Requested quantities for product', [
                            'purchase_stock_return_id' => $id,
                            'product_id' => $productId,
                            'index' => $index,
                            'regular_quantity' => $regularQuantity,
                            'free_quantity' => $freeQuantity,
                            'regular_pieces' => $regularPieces,
                            'free_pieces' => $freePieces,
                            'total_requested_pieces' => $totalRequestedPieces,
                            'measure_unit_id' => $productData['measure_unit_id'],
                            'unit_quantity' => $unitQuantity,
                        ]);
                    }

                    // Build query for PurchaseProducts
                    $purchaseProductsQuery = PurchaseStockProduct::where('product_id', $productId)
                        ->where('company_id', $companyId)
                        ->where('branch_id', $branchId)
                        ->whereNull('deleted_at')
                        ->with([
                            'purchase' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $companyId)->where('branch_id', $branchId),
                            'purchaseStockProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $companyId)->where('branch_id', $branchId)->where('purchase_stock_return_id', '!=', $id)->with('measureUnit'),
                            'saleProducts' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->with([
                                'saleReturnProducts' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->with([
                                    'fieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])
                                ])
                            ]),
                            'measureUnit',
                            'fieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $companyId)->where('branch_id', $branchId),

                        ]);

                    if ($validated['purchase_bill_number']) {
                        $purchaseProductsQuery->whereHas('purchase', function ($query) use ($validated) {
                            $query->where('purchase_bill_number', $validated['purchase_bill_number']);
                        });
                    }

                    $purchaseProducts = $purchaseProductsQuery->orderBy('created_at')->get();

                    Log::debug('Loaded purchase products for allocation', [
                        'purchase_stock_return_id' => $id,
                        'product_id' => $productId,
                        'purchase_stock_products_count' => $purchaseProducts->count(),
                        'purchase_stock_product_ids' => $purchaseProducts->pluck('id')->toArray(),
                    ]);

                    if ($purchaseProducts->isEmpty()) {
                        Log::error('No valid purchase products found', [
                            'purchase_stock_return_id' => $id,
                            'product_id' => $productId,
                        ]);
                        return response()->json(['error' => "No valid purchase products found for product ID {$productId}"], 404);
                    }

                    // Calculate total available pieces and initialize batch quantities
                    $totalAvailablePieces = 0;
                    foreach ($purchaseProducts as $purchaseProduct) {
                        $purchaseMeasureUnit = $purchaseProduct->measureUnit;
                        if (!$purchaseMeasureUnit) {
                            Log::error('Measure unit not found for purchase product', [
                                'purchase_stock_return_id' => $id,
                                'purchase_stock_product_id' => $purchaseProduct->id,
                            ]);
                            return response()->json(['error' => "Measure unit not found for purchase_product_id {$purchaseProduct->id}"], 404);
                        }
                        $purchaseUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                        $purchasedQuantityInPieces = $calculateQuantityInPieces($purchaseProduct->quantity, $purchaseProduct->free_quantity, $purchaseUnitQuantity);


                        $totalReturnedInPieces = $purchaseProduct->purchaseStockProductReturns->sum(function ($return) use ($calculateQuantityInPieces) {
                            $mu = MeasureUnit::findOrFail($return->measure_unit_id);
                            $pieces = $calculateQuantityInPieces($return->quantity, $return->free_quantity, $mu->quantity ?? 1);
                            Log::debug('Calculating total returned pieces for other returns', [
                                'purchase_product_id' => $return->purchase_product_id,
                                'purchase_return_id' => $return->purchase_return_id,
                                'quantity' => $return->quantity,
                                'free_quantity' => $return->free_quantity,
                                'measure_unit_id' => $return->measure_unit_id,
                                'pieces' => $pieces,
                            ]);
                            return $pieces;
                        });



                        $soldQuantityInPieces = $purchaseProduct->saleProducts->sum(function ($sale) use ($calculateQuantityInPieces) {
                            $mu = MeasureUnit::findOrFail($sale->measure_unit_id);
                            $pieces = $calculateQuantityInPieces($sale->quantity, $sale->free_quantity, $mu->quantity ?? 1);
                            Log::debug('Calculating sold pieces', [
                                'sale_product_id' => $sale->id,
                                'quantity' => $sale->quantity,
                                'free_quantity' => $sale->free_quantity,
                                'measure_unit_id' => $sale->measure_unit_id,
                                'pieces' => $pieces,
                            ]);
                            return $pieces;
                        });

                        $salesReturnedInPieces = $purchaseProduct->saleProducts->flatMap(function ($sale) use ($calculateQuantityInPieces) {
                            return $sale->saleReturnProducts->map(function ($return) use ($calculateQuantityInPieces) {
                                $mu = MeasureUnit::findOrFail($return->measure_unit_id);
                                $pieces = $calculateQuantityInPieces($return->quantity, $return->free_quantity, $mu->quantity ?? 1);
                                Log::debug('Calculating sales returned pieces', [
                                    'sale_return_product_id' => $return->id,
                                    'quantity' => $return->quantity,
                                    'free_quantity' => $return->free_quantity,
                                    'measure_unit_id' => $return->measure_unit_id,
                                    'pieces' => $pieces,
                                ]);
                                return $pieces;
                            });
                        })->sum();

                        $availableQuantityInPieces = ($purchasedQuantityInPieces - $soldQuantityInPieces) + $salesReturnedInPieces - $totalReturnedInPieces;

                        Log::debug('Key for addedBackPieces', ['key' => $purchaseProduct->id, 'purchase_stock_return_id' => $id]);
                        $addedBackPieces = $currentReturnQuantitiesByPurchaseProduct[$purchaseProduct->id] ?? 0;
                        $availableQuantityInPieces += $addedBackPieces;


                        $batchQuantities[$purchaseProduct->id] = $availableQuantityInPieces;
                        $totalAvailablePieces += $availableQuantityInPieces;


                        Log::debug('Calculated available pieces', [
                            'purchase_stock_return_id' => $id,
                            'purchase_stock_product_id' => $purchaseProduct->id,
                            'product_id' => $productId,
                            'purchase_id' => $purchaseProduct->purchase_id,
                            'purchase_bill_number' => $validated['purchase_bill_number'] ?? null,
                            'purchased_pieces' => $purchasedQuantityInPieces,
                            'sold_pieces' => $soldQuantityInPieces,
                            'sales_returned_pieces' => $salesReturnedInPieces,
                            'total_returned_pieces' => $totalReturnedInPieces,
                            'available_pieces' => $availableQuantityInPieces,
                            'added_back_pieces' => $addedBackPieces,
                            'total_available_pieces' => $totalAvailablePieces,
                            'requested_pieces' => $totalRequestedPieces,
                        ]);
                    }

                    if ($totalRequestedPieces > $totalAvailablePieces) {
                        Log::error('Insufficient stock for product', [
                            'purchase_stock_return_id' => $id,
                            'product_id' => $productId,
                            'total_requested_pieces' => $totalRequestedPieces,
                            'total_available_pieces' => $totalAvailablePieces,
                        ]);
                        return response()->json([
                            'error' => "Insufficient stock for product ID {$productId}. Requested: {$totalRequestedPieces} pieces, Available: {$totalAvailablePieces} pieces"
                        ], 422);
                    }

                    // Process all products in FIFO order across the group
                    $remainingPurchaseProducts = $purchaseProducts;
                    foreach ($productGroup as $index => $productData) {
                        $regularQuantity = (float) ($productData['quantity'] ?? 0);
                        $freeQuantity = (float) ($productData['free_quantity'] ?? 0);
                        $measureUnit = MeasureUnit::findOrFail($productData['measure_unit_id']);
                        $unitQuantity = $measureUnit->quantity ?? 1;
                        $remainingRegularPieces = $productAllocations[$index]['regular_pieces'];
                        $remainingFreePieces = $productAllocations[$index]['free_pieces'];

                        Log::debug('Starting allocation for product', [
                            'purchase_stock_return_id' => $id,
                            'product_id' => $productId,
                            'index' => $index,
                            'regular_pieces' => $remainingRegularPieces,
                            'free_pieces' => $remainingFreePieces,
                        ]);

                        // Normalize field_values
                        $fieldValuesFlat = collect($productData['field_values'])->flatMap(function ($item) {
                            return is_array($item) && isset($item[0]['product_field_id']) ? $item : [$item];
                        })->toArray();
                        $hasFieldValues = !empty($fieldValuesFlat);
                        $usedQuantityIndexes = [];

                        if ($hasFieldValues) {
                            foreach ($fieldValuesFlat as $fv) {
                                Log::debug('Field value entry', ['fv' => $fv, 'product_id' => $productId, 'index' => $index]);
                                if (!isset($fv['purchase_stock_product_id']) || !is_numeric($fv['purchase_stock_product_id'])) {
                                    Log::error('Invalid purchase_product_id in field_values', [
                                        'purchase_stock_return_id' => $id,
                                        'product_id' => $productId,
                                        'index' => $index,
                                        'field_value' => $fv,
                                    ]);
                                    return response()->json(['error' => "Invalid or missing purchase_product_id in field_values at index {$index}"], 422);
                                }
                                if (!isset($fv['quantity_index']) || !is_numeric($fv['quantity_index']) || $fv['quantity_index'] < 0) {
                                    Log::error('Invalid quantity_index in field_values', [
                                        'purchase_return_id' => $id,
                                        'product_id' => $productId,
                                        'index' => $index,
                                        'field_value' => $fv,
                                    ]);
                                    return response()->json(['error' => "Invalid quantity_index in field_values at index {$index}"], 422);
                                }
                                if (!isset($fv['quantity_type']) || !in_array($fv['quantity_type'], ['regular', 'free'])) {
                                    Log::error('Invalid quantity_type in field_values', [
                                        'purchase_return_id' => $id,
                                        'product_id' => $productId,
                                        'index' => $index,
                                        'field_value' => $fv,
                                    ]);
                                    return response()->json(['error' => "Invalid quantity_type in field_values at index {$index}. Must be 'regular' or 'free'"], 422);
                                }
                                if ($fv['quantity_type'] === 'free' && $freeQuantity == 0) {
                                    Log::error('Invalid free quantity_type with zero free_quantity', [
                                        'purchase_return_id' => $id,
                                        'product_id' => $productId,
                                        'index' => $index,
                                    ]);
                                    return response()->json(['error' => "quantity_type 'free' is not allowed when free_quantity is 0 at index {$index}"], 422);
                                }
                                if ($fv['quantity_type'] === 'regular' && $regularQuantity == 0) {
                                    Log::error('Invalid regular quantity_type with zero quantity', [
                                        'purchase_return_id' => $id,
                                        'product_id' => $productId,
                                        'index' => $index,
                                    ]);
                                    return response()->json(['error' => "quantity_type 'regular' is not allowed when quantity is 0 at index {$index}"], 422);
                                }
                            }

                            $groupedFieldValues = collect($fieldValuesFlat)
                                ->groupBy('purchase_stock_product_id')
                                ->map(function ($group) {
                                    return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                                        return $fvGroup->map(function ($fv) {
                                            return [
                                                'product_field_id' => $fv['product_field_id'],
                                                'value' => $fv['value'],
                                                'quantity_index' => $fv['quantity_index'],
                                                'quantity_type' => $fv['quantity_type'],
                                                'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                            ];
                                        })->unique(function ($fv) {
                                            return "{$fv['product_field_id']}:{$fv['value']}:{$fv['quantity_type']}";
                                        })->values()->toArray();
                                    })->toArray();
                                })->toArray();

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

                            Log::debug('Field value sets', [
                                'purchase_stock_return_id' => $id,
                                'product_id' => $productId,
                                'index' => $index,
                                'regular_field_value_sets' => $regularFieldValueSets,
                                'free_field_value_sets' => $freeFieldValueSets,
                                'regular_pieces' => $remainingRegularPieces,
                                'free_pieces' => $remainingFreePieces,
                                'field_values' => $fieldValuesFlat,
                            ]);

                            if ($hasFieldValues && ($regularFieldValueSets != $remainingRegularPieces || $freeFieldValueSets != $remainingFreePieces)) {
                                Log::error('Field value sets mismatch', [
                                    'purchase_stock_return_id' => $id,
                                    'product_id' => $productId,
                                    'index' => $index,
                                    'regular_field_value_sets' => $regularFieldValueSets,
                                    'free_field_value_sets' => $freeFieldValueSets,
                                    'regular_pieces' => $remainingRegularPieces,
                                    'free_pieces' => $remainingFreePieces,
                                ]);
                                return response()->json([
                                    'error' => "Field value sets (Regular: {$regularFieldValueSets}, Free: {$freeFieldValueSets}) must match pieces (Regular: {$remainingRegularPieces}, Free: {$remainingFreePieces}) for product ID {$productId} at index {$index}"
                                ], 422);
                            }

                            $purchaseProductIds = array_keys($groupedFieldValues);
                            $requiresFieldValues = PurchaseStockProductFieldValue::whereIn('purchase_stock_product_id', $purchaseProductIds)
                                ->where('company_id', $companyId)
                                ->where('branch_id', $branchId)
                                ->whereNull('deleted_at')
                                ->exists();

                            Log::debug('Checking field values requirement', [
                                'purchase_stock_return_id' => $id,
                                'product_id' => $productId,
                                'index' => $index,
                                'purchase_stock_product_ids' => $purchaseProductIds,
                                'requires_field_values' => $requiresFieldValues,
                            ]);

                            if ($hasFieldValues && !$requiresFieldValues) {
                                Log::error('Field values provided but not required', [
                                    'purchase_stock_return_id' => $id,
                                    'product_id' => $productId,
                                    'index' => $index,
                                ]);
                                return response()->json([
                                    'error' => "Field values provided for product ID {$productId} at index {$index}, but no field values are required."
                                ], 422);
                            }

                            foreach ($groupedFieldValues as $purchaseProductId => $fvByIndex) {
                                if ($remainingRegularPieces <= 0 && $remainingFreePieces <= 0) {
                                    break;
                                }

                                $purchaseProduct = $purchaseProducts->firstWhere('id', $purchaseProductId);
                                if (!$purchaseProduct) {
                                    Log::error('Purchase product not found for field values', [
                                        'purchase_stock_return_id' => $id,
                                        'product_id' => $productId,
                                        'index' => $index,
                                        'purchase_stock_product_id' => $purchaseProductId,
                                    ]);
                                    return response()->json(['error' => "Purchase product ID {$purchaseProductId} not found at index {$index}"], 404);
                                }

                                $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                                $purchaseMeasureUnit = $purchaseProduct->measureUnit;
                                if (!$purchaseMeasureUnit) {
                                    Log::error('Measure unit not found for purchase product in field values', [
                                        'purchase_stock_return_id' => $id,
                                        'purchase_stock_product_id' => $purchaseProductId,
                                        'index' => $index,
                                    ]);
                                    return response()->json(['error' => "Measure unit not found for purchase_product_id {$purchaseProductId} at index {$index}"], 404);
                                }
                                $purchaseUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                                $availableQuantityInPieces = $batchQuantities[$purchaseProductId] - ($cumulativeAllocatedByPurchaseProduct[$purchaseProductId] ?? 0);
                                Log::debug('Checking available quantity for field values allocation', [
                                    'purchase_stock_return_id' => $id,
                                    'product_id' => $productId,
                                    'index' => $index,
                                    'purchase_stock_product_id' => $purchaseProductId,
                                    'available_quantity_in_pieces' => $availableQuantityInPieces,
                                ]);

                                if ($availableQuantityInPieces <= 0) {
                                    continue;
                                }

                                $existingFieldValues = $purchaseProduct->fieldValues
                                    ->groupBy('quantity_index')
                                    ->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());
                                $saleReturnFieldValues = $purchaseProduct->saleProducts->flatMap(function ($sale) {
                                    return $sale->saleReturnProducts->flatMap(function ($return) {
                                        return $return->fieldValues;
                                    });
                                })->groupBy('quantity_index')
                                    ->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());

                                $unavailableQuantityIndices = [];
                                if ($purchaseProduct->purchaseStockProductReturns->isNotEmpty()) {
                                    $returnIds = $purchaseProduct->purchaseStockProductReturns->pluck('id');
                                    $unavailableQuantityIndices = PurchaseStockProductReturnFieldValue::whereIn('purchase_stock_product_return_id', $returnIds)
                                        ->whereNull('deleted_at')
                                        ->pluck('quantity_index')
                                        ->toArray();
                                }
                                if ($purchaseProduct->saleProducts->isNotEmpty()) {
                                    $saleIds = $purchaseProduct->saleProducts->pluck('id');
                                    $soldIndices = SalesProductFieldValue::whereIn('sale_product_id', $saleIds)
                                        ->whereNull('deleted_at')
                                        ->pluck('quantity_index')
                                        ->toArray();
                                    $unavailableQuantityIndices = array_merge($unavailableQuantityIndices, $soldIndices);
                                }
                                $salesReturnedIndices = SaleReturnProductFieldValue::whereIn(
                                    'sale_return_product_id',
                                    SalesReturnProduct::whereIn('sale_product_id', $purchaseProduct->saleProducts->pluck('id'))
                                        ->whereNull('deleted_at')
                                        ->pluck('id')
                                )
                                    ->whereNull('deleted_at')
                                    ->pluck('quantity_index')
                                    ->toArray();
                                $unavailableQuantityIndices = array_diff($unavailableQuantityIndices, $salesReturnedIndices);

                                Log::debug('Field values validation', [
                                    'purchase_stock_return_id' => $id,
                                    'product_id' => $productId,
                                    'index' => $index,
                                    'purchase_stock_product_id' => $purchaseProductId,
                                    'existing_field_values' => $existingFieldValues,
                                    'sale_return_field_values' => $saleReturnFieldValues,
                                    'unavailable_quantity_indices' => $unavailableQuantityIndices,
                                    'sales_returned_indices' => $salesReturnedIndices,
                                ]);

                                foreach ($fvByIndex as $quantityIndex => $fvSet) {
                                    if (in_array($quantityIndex, $unavailableQuantityIndices) || (!isset($existingFieldValues[$quantityIndex]) && !isset($saleReturnFieldValues[$quantityIndex]))) {
                                        Log::error('Invalid or unavailable quantity_index', [
                                            'purchase_stock_return_id' => $id,
                                            'product_id' => $productId,
                                            'index' => $index,
                                            'purchase_stock_product_id' => $purchaseProductId,
                                            'quantity_index' => $quantityIndex,
                                        ]);
                                        return response()->json(['error' => "Invalid or already returned/sold quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} at index {$index}"], 422);
                                    }
                                    if (in_array($quantityIndex, $usedQuantityIndexes[$purchaseProductId] ?? [])) {
                                        Log::error('Duplicate quantity_index', [
                                            'purchase_stock_return_id' => $id,
                                            'product_id' => $productId,
                                            'index' => $index,
                                            'purchase_stock_product_id' => $purchaseProductId,
                                            'quantity_index' => $quantityIndex,
                                        ]);
                                        return response()->json(['error' => "Duplicate quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} at index {$index}"], 422);
                                    }
                                    Log::debug('Field values set for comparison', [
                                        'purchase_stock_return_id' => $id,
                                        'product_id' => $productId,
                                        'index' => $index,
                                        'purchase_stock_product_id' => $purchaseProductId,
                                        'quantity_index' => $quantityIndex,
                                        'fv_set' => $fvSet,
                                    ]);
                                    $providedFieldValues = collect($fvSet)->pluck('value', 'product_field_id')->toArray();
                                    $expectedFieldValues = $existingFieldValues[$quantityIndex] ?? $saleReturnFieldValues[$quantityIndex] ?? [];
                                    if ($providedFieldValues != $expectedFieldValues) {
                                        Log::error('Field values mismatch', [
                                            'purchase_stock_return_id' => $id,
                                            'product_id' => $productId,
                                            'index' => $index,
                                            'purchase_stock_product_id' => $purchaseProductId,
                                            'quantity_index' => $quantityIndex,
                                            'provided_field_values' => $providedFieldValues,
                                            'expected_field_values' => $expectedFieldValues,
                                        ]);
                                        return response()->json(['error' => "Field values for quantity_index {$quantityIndex} do not match for purchase_product_id {$purchaseProductId} at index {$index}"], 422);
                                    }
                                    $usedQuantityIndexes[$purchaseProductId][] = $quantityIndex;
                                }

                                $regularFvByIndex = collect($fvByIndex)
                                    ->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'regular')
                                    ->toArray();
                                $freeFvByIndex = collect($fvByIndex)
                                    ->filter(fn($fvSet) => collect($fvSet)->first()['quantity_type'] === 'free')
                                    ->toArray();

                                $totalRequestedForThisProduct = count($regularFvByIndex) + count($freeFvByIndex);
                                $allocatePieces = min($totalRequestedForThisProduct, $availableQuantityInPieces, $remainingRegularPieces + $remainingFreePieces);

                                if ($allocatePieces > 0) {
                                    $allocateRegularPieces = min(count($regularFvByIndex), $remainingRegularPieces, $allocatePieces);
                                    $allocateFreePieces = min(count($freeFvByIndex), $remainingFreePieces, $allocatePieces - $allocateRegularPieces);

                                    $regularIntegerUnits = floor($allocateRegularPieces / $unitQuantity);
                                    $regularRemainingPieces = $allocateRegularPieces - ($regularIntegerUnits * $unitQuantity);
                                    $regularDecimal = $regularRemainingPieces > 0 ? (float) ('0.' . (int) $regularRemainingPieces) : 0;
                                    $allocateRegularQuantity = $regularIntegerUnits + $regularDecimal;

                                    $freeIntegerUnits = floor($allocateFreePieces / $unitQuantity);
                                    $freeRemainingPieces = $allocateFreePieces - ($freeIntegerUnits * $unitQuantity);
                                    $freeDecimal = $freeRemainingPieces > 0 ? (float) ('0.' . (int) $freeRemainingPieces) : 0;
                                    $allocateFreeQuantity = $freeIntegerUnits + $freeDecimal;

                                    $productAllocations[$index]['allocations'][] = [
                                        'purchase_stock_product_id' => $purchaseProductId,
                                        'quantity' => $allocateRegularQuantity,
                                        'free_quantity' => $allocateFreeQuantity,
                                        'field_values' => array_merge(
                                            array_values(array_slice($regularFvByIndex, 0, $allocateRegularPieces)),
                                            array_values(array_slice($freeFvByIndex, 0, $allocateFreePieces))
                                        ),
                                        'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                        'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                                        'customer_id' => $productData['customer_id'] ?? $purchaseProduct->customer_id,
                                        'return_measure_unit_id' => $productData['measure_unit_id'],
                                    ];

                                    $cumulativeAllocatedByPurchaseProduct[$purchaseProductId] = ($cumulativeAllocatedByPurchaseProduct[$purchaseProductId] ?? 0) + ($allocateRegularPieces + $allocateFreePieces);
                                    $batchQuantities[$purchaseProductId] -= ($allocateRegularPieces + $allocateFreePieces);
                                    $remainingRegularPieces -= $allocateRegularPieces;
                                    $remainingFreePieces -= $allocateFreePieces;

                                    Log::debug('Allocation with field values', [
                                        'purchase_stock_return_id' => $id,
                                        'product_id' => $productId,
                                        'index' => $index,
                                        'purchase_stock_product_id' => $purchaseProductId,
                                        'allocated_regular_pieces' => $allocateRegularPieces,
                                        'allocated_free_pieces' => $allocateFreePieces,
                                        'allocated_regular_quantity' => $allocateRegularQuantity,
                                        'allocated_free_quantity' => $allocateFreeQuantity,
                                        'remaining_regular_pieces' => $remainingRegularPieces,
                                        'remaining_free_pieces' => $remainingFreePieces,
                                        'batch_remaining_pieces' => $batchQuantities[$purchaseProductId],
                                        'cumulative_allocated_pieces' => $cumulativeAllocatedByPurchaseProduct[$purchaseProductId] ?? 0,
                                    ]);
                                }
                            }
                        }

                        if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                            $purchaseProduct = isset($productData['purchase_stock_product_id']) ? $purchaseProducts->firstWhere('id', $productData['purchase_stock_product_id']) : null;

                            if ($purchaseProduct) {
                                if ($purchaseProduct->fieldValues->isNotEmpty()) {
                                    Log::error('Field values required but not provided', [
                                        'purchase_stock_return_id' => $id,
                                        'product_id' => $productId,
                                        'index' => $index,
                                        'purchase_stock_product_id' => $purchaseProduct->id,
                                    ]);
                                    return response()->json(['error' => "Purchase product ID {$purchaseProduct->id} has field values; field_values must be provided at index {$index}"], 422);
                                }
                                $purchaseProductsToProcess = collect([$purchaseProduct]);
                            } else {
                                $purchaseProductsToProcess = $remainingPurchaseProducts;
                            }

                            foreach ($purchaseProductsToProcess as $purchaseProduct) {
                                if ($remainingRegularPieces <= 0 && $remainingFreePieces <= 0) {
                                    break;
                                }

                                $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                                $purchaseMeasureUnit = $purchaseProduct->measureUnit;

                                if (!$purchaseMeasureUnit) {
                                    Log::error('Measure unit not found for purchase product in FIFO allocation', [
                                        'purchase_stock_return_id' => $id,
                                        'product_id' => $productId,
                                        'index' => $index,
                                        'purchase_stock_product_id' => $purchaseProduct->id,
                                    ]);
                                    return response()->json(['error' => "Measure unit not found for purchase_product_id {$purchaseProduct->id} at index {$index}"], 422);
                                }
                                $purchaseUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                                $availableQuantityInPieces = $batchQuantities[$purchaseProduct->id] - ($cumulativeAllocatedByPurchaseProduct[$purchaseProduct->id] ?? 0);
                                Log::debug('Checking available quantity for FIFO allocation', [
                                    'purchase_stock_return_id' => $id,
                                    'product_id' => $productId,
                                    'index' => $index,
                                    'purchase_stock_product_id' => $purchaseProduct->id,
                                    'available_quantity_in_pieces' => $availableQuantityInPieces,
                                ]);

                                if ($availableQuantityInPieces <= 0) {
                                    continue;
                                }

                                $allocateRegularPieces = min($remainingRegularPieces, $availableQuantityInPieces);
                                $allocateFreePieces = min($remainingFreePieces, max(0, $availableQuantityInPieces - $allocateRegularPieces));

                                if ($allocateRegularPieces > 0 || $allocateFreePieces > 0) {
                                    $regularIntegerUnits = floor($allocateRegularPieces / $unitQuantity);
                                    $regularRemainingPieces = $allocateRegularPieces - ($regularIntegerUnits * $unitQuantity);
                                    $regularDecimal = $regularRemainingPieces > 0 ? (float) ('0.' . (int) $regularRemainingPieces) : 0;
                                    $allocateRegularQuantity = $regularIntegerUnits + $regularDecimal;

                                    $freeIntegerUnits = floor($allocateFreePieces / $unitQuantity);
                                    $freeRemainingPieces = $allocateFreePieces - ($freeIntegerUnits * $unitQuantity);
                                    $freeDecimal = $freeRemainingPieces > 0 ? (float) ('0.' . (int) $freeRemainingPieces) : 0;
                                    $allocateFreeQuantity = $freeIntegerUnits + $freeDecimal;

                                    $productAllocations[$index]['allocations'][] = [
                                        'purchase_stock_product_id' => $purchaseProduct->id,
                                        'quantity' => $allocateRegularQuantity,
                                        'free_quantity' => $allocateFreeQuantity,
                                        'field_values' => [],
                                        'mfd' => $productData['mfd'] ?? $purchaseProduct->mfd,
                                        'expiry_date' => $productData['expiry_date'] ?? $purchaseProduct->expiry_date,
                                        'customer_id' => $productData['customer_id'] ?? $purchaseProduct->customer_id,
                                        'return_measure_unit_id' => $productData['measure_unit_id'],
                                    ];

                                    $cumulativeAllocatedByPurchaseProduct[$purchaseProduct->id] = ($cumulativeAllocatedByPurchaseProduct[$purchaseProduct->id] ?? 0) + ($allocateRegularPieces + $allocateFreePieces);
                                    $batchQuantities[$purchaseProduct->id] -= ($allocateRegularPieces + $allocateFreePieces);
                                    $remainingRegularPieces -= $allocateRegularPieces;
                                    $remainingFreePieces -= $allocateFreePieces;

                                    Log::debug('FIFO allocation', [
                                        'purchase_stock_return_id' => $id,
                                        'product_id' => $productId,
                                        'index' => $index,
                                        'purchase_stock_product_id' => $purchaseProduct->id,
                                        'allocated_regular_pieces' => $allocateRegularPieces,
                                        'allocated_free_pieces' => $allocateFreePieces,
                                        'allocated_regular_quantity' => $allocateRegularQuantity,
                                        'allocated_free_quantity' => $allocateFreeQuantity,
                                        'remaining_regular_pieces' => $remainingRegularPieces,
                                        'remaining_free_pieces' => $remainingFreePieces,
                                        'batch_remaining_pieces' => $batchQuantities[$purchaseProduct->id],
                                        'cumulative_allocated_pieces' => $cumulativeAllocatedByPurchaseProduct[$purchaseProduct->id] ?? 0,
                                    ]);
                                }
                            }

                            $remainingPurchaseProducts = $remainingPurchaseProducts->filter(function ($purchaseProduct) use ($batchQuantities, $cumulativeAllocatedByPurchaseProduct) {
                                return ($batchQuantities[$purchaseProduct->id] - ($cumulativeAllocatedByPurchaseProduct[$purchaseProduct->id] ?? 0)) > 0;
                            });
                            Log::debug('Updated remaining purchase products', [
                                'purchase_stock_return_id' => $id,
                                'product_id' => $productId,
                                'index' => $index,
                                'remaining_purchase_stock_product_ids' => $remainingPurchaseProducts->pluck('id')->toArray(),
                            ]);
                        }

                        if ($remainingRegularPieces > 0 || $remainingFreePieces > 0) {
                            Log::error('Insufficient stock after allocation', [
                                'purchase_stock_return_id' => $id,
                                'product_id' => $productId,
                                'index' => $index,
                                'remaining_regular_pieces' => $remainingRegularPieces,
                                'remaining_free_pieces' => $remainingFreePieces,
                                'requested_pieces' => $productAllocations[$index]['regular_pieces'] + $productAllocations[$index]['free_pieces'],
                                'allocated_pieces' => ($productAllocations[$index]['regular_pieces'] + $productAllocations[$index]['free_pieces']) - ($remainingRegularPieces + $remainingFreePieces),
                            ]);
                            return response()->json([
                                'error' => "Insufficient stock for product ID {$productId} at index {$index}. Requested: " . ($productAllocations[$index]['regular_pieces'] + $productAllocations[$index]['free_pieces']) . " pieces (Regular: {$productAllocations[$index]['regular_pieces']}, Free: {$productAllocations[$index]['free_pieces']}), Allocated: " . (($productAllocations[$index]['regular_pieces'] + $productAllocations[$index]['free_pieces']) - ($remainingRegularPieces + $remainingFreePieces)) . " pieces"
                            ], 422);
                        }

                        foreach ($productAllocations[$index]['allocations'] as $allocation) {
                            $purchaseProduct = PurchaseStockProduct::findOrFail($allocation['purchase_stock_product_id']);
                            $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;
                            $purchaseMeasureUnit = $purchaseProduct->measureUnit;
                            $purchaseUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                            $purchasedQuantityInPieces = $calculateQuantityInPieces($purchaseProduct->quantity, $purchaseProduct->free_quantity, $purchaseUnitQuantity);
                            $totalReturnedInPieces = $purchaseProduct->purchaseStockProductReturns->sum(function ($return) use ($calculateQuantityInPieces) {
                                $mu = MeasureUnit::findOrFail($return->measure_unit_id);
                                $pieces = $calculateQuantityInPieces($return->quantity, $return->free_quantity, $mu->quantity ?? 1);
                                Log::debug('Calculating total returned pieces for processed product', [
                                    'purchase_stock_product_id' => $return->purchase_stock_product_id,
                                    'purchase_stock_return_id' => $return->purchase_stock_return_id,
                                    'quantity' => $return->quantity,
                                    'free_quantity' => $return->free_quantity,
                                    'measure_unit_id' => $return->measure_unit_id,
                                    'pieces' => $pieces,
                                ]);
                                return $pieces;
                            });
                            $soldQuantityInPieces = $purchaseProduct->saleProducts->sum(function ($sale) use ($calculateQuantityInPieces) {
                                $mu = MeasureUnit::findOrFail($sale->measure_unit_id);
                                $pieces = $calculateQuantityInPieces($sale->quantity, $sale->free_quantity, $mu->quantity ?? 1);
                                Log::debug('Calculating sold pieces for processed product', [
                                    'sale_product_id' => $sale->id,
                                    'quantity' => $sale->quantity,
                                    'free_quantity' => $sale->free_quantity,
                                    'measure_unit_id' => $sale->measure_unit_id,
                                    'pieces' => $pieces,
                                ]);
                                return $pieces;
                            });
                            $salesReturnedInPieces = $purchaseProduct->saleProducts->flatMap(function ($sale) use ($calculateQuantityInPieces) {
                                return $sale->saleReturnProducts->map(function ($return) use ($calculateQuantityInPieces) {
                                    $mu = MeasureUnit::findOrFail($return->measure_unit_id);
                                    $pieces = $calculateQuantityInPieces($return->quantity, $return->free_quantity, $mu->quantity ?? 1);
                                    Log::debug('Calculating sales returned pieces for processed product', [
                                        'sale_return_product_id' => $return->id,
                                        'quantity' => $return->quantity,
                                        'free_quantity' => $return->free_quantity,
                                        'measure_unit_id' => $return->measure_unit_id,
                                        'pieces' => $pieces,
                                    ]);
                                    return $pieces;
                                });
                            })->sum();
                            $allocatedQuantityInPieces = $calculateQuantityInPieces($allocation['quantity'], $allocation['free_quantity'], $unitQuantity);
                            $remainingQuantityInPiecesAfterAllocation = ($purchasedQuantityInPieces - $soldQuantityInPieces) + $salesReturnedInPieces - $totalReturnedInPieces - ($cumulativeAllocatedByPurchaseProduct[$purchaseProduct->id] ?? 0);

                            $processedProducts[] = [
                                'purchase_stock_product_id' => $allocation['purchase_stock_product_id'],
                                'product_id' => $productId,
                                'branch_id' => $branchId,
                                'product_name' => $productData['product_name'] ?? ($purchaseProduct->product->name ?? ''),
                                'purchase_product_code' => $productData['purchase_product_code'] ?? ($purchaseProduct->product_code ?? ''),
                                'mfd' => $allocation['mfd'],
                                'customer_id' => $productData['customer_id'] ?? ($purchaseProduct->customer_id ?? null),
                                'quantity' => $allocation['quantity'],
                                'free_quantity' => $allocation['free_quantity'],
                                'price' => $productData['price'] ?? ($purchaseProduct->price ?? 0),
                                'discount_percent' => $productData['discount_percent'] ?? 0,
                                'discount_amount' => $productData['discount_amount'] ?? 0,
                                'amount' => ($productData['price'] ?? ($purchaseProduct->price ?? 0)) * $allocation['quantity'] - ($productData['discount_amount'] ?? 0),
                                'is_vatable' => $productData['is_vatable'],
                                'measure_unit_id' => $productData['measure_unit_id'],
                                'expiry_date' => $allocation['expiry_date'],
                                'field_values' => $allocation['field_values'],
                                'purchase_id' => $purchaseProduct->purchase_id,
                                'purchase_bill_number' => $purchaseProduct->purchase->purchase_bill_number ?? '',
                                'allocated_quantity_in_pieces' => $allocatedQuantityInPieces,
                                'remaining_quantity_in_pieces' => $remainingQuantityInPiecesAfterAllocation,
                            ];

                            Log::debug('Processed product allocation', [
                                'purchase_stock_return_id' => $id,
                                'product_id' => $productId,
                                'index' => $index,
                                'purchase_stock_product_id' => $allocation['purchase_stock_product_id'],
                                'allocated_quantity_in_pieces' => $allocatedQuantityInPieces,
                                'remaining_quantity_in_pieces' => $remainingQuantityInPiecesAfterAllocation,
                                'processed_product' => end($processedProducts),
                            ]);
                        }
                    }
                }

                $purchaseReturnData = collect($validated)->except(['purchase_return_products', 'return_entire_batch'])->filter()->toArray();
                $purchaseReturnData['company_id'] = $validated['company_id'];
                $totalAmount = array_sum(array_column($processedProducts, 'amount'));
                $purchaseReturnData['total_amount'] = $totalAmount;

                Log::debug('Updating purchase return', [
                    'purchase_stock_return_id' => $id,
                    'purchase_return_data' => $purchaseReturnData,
                    'total_amount' => $totalAmount,
                ]);

                $purchaseReturn->update($purchaseReturnData);

                $balanceUpdates = [];
                foreach ($processedProducts as $productData) {
                    $purchaseProductId = $productData['purchase_stock_product_id'];
                    $purchaseProduct = PurchaseStockProduct::findOrFail($purchaseProductId);
                    $purchaseId = $purchaseProduct->purchase_id;
                    $purchases[$purchaseId] = $purchases[$purchaseId] ?? Purchase::findOrFail($purchaseId);

                    $productDataFiltered = collect($productData)->except(['field_values', 'purchase_id', 'purchase_bill_number', 'allocated_quantity_in_pieces', 'remaining_quantity_in_pieces'])->filter()->toArray();
                    $productDataFiltered['company_id'] = $validated['company_id'];
                    $productDataFiltered['purchase_stock_return_id'] = $id;

                    Log::debug('Creating purchase return product', [
                        'purchase_stock_return_id' => $id,
                        'purchase_stock_product_id' => $purchaseProductId,
                        'product_data' => $productDataFiltered,
                    ]);

                    $purchaseReturnProduct = PurchaseStockProductReturn::create($productDataFiltered);

                    if (!empty($productData['field_values'])) {
                        Log::debug('Storing field_values for purchase return product', [
                            'purchase_stock_product_return_id' => $purchaseReturnProduct->id,
                            'field_values' => $productData['field_values'],
                        ]);
                        foreach ($productData['field_values'] as $arrayIndex => $fvSet) {
                            $quantityIndex = isset($fvSet[0]['quantity_index']) ? $fvSet[0]['quantity_index'] : $arrayIndex;
                            foreach ($fvSet as $fv) {
                                $fieldValue = PurchaseStockProductReturnFieldValue::create([
                                    'purchase_stock_product_return_id' => $purchaseReturnProduct->id,
                                    'product_field_id' => $fv['product_field_id'],
                                    'purchase_stock_product_id' => $fv['purchase_stock_product_id'],
                                    'value' => $fv['value'],
                                    'product_id' => $purchaseReturnProduct->product_id,
                                    'company_id' => $validated['company_id'],
                                    'branch_id' => $branchId,
                                    'quantity_index' => $quantityIndex,
                                    'quantity_type' => $fv['quantity_type'],
                                ]);
                                Log::debug('Created field value', [
                                    'purchase_stock_product_return_id' => $purchaseReturnProduct->id,
                                    'field_value_id' => $fieldValue->id,
                                    'field_value' => $fieldValue->toArray(),
                                ]);
                            }
                        }
                    }

                    $returnValue = ($productData['quantity'] * ($productData['price'] ?? 0)) - ($productData['discount_amount'] ?? 0);
                    $balanceUpdates[$purchaseId] = ($balanceUpdates[$purchaseId] ?? 0) + $returnValue;

                    Log::debug('Calculated balance update', [
                        'purchase_stock_return_id' => $id,
                        'purchase_id' => $purchaseId,
                        'purchase_stock_product_id' => $purchaseProductId,
                        'return_value' => $returnValue,
                        'balance_updates' => $balanceUpdates,
                    ]);
                }

                Log::debug('Balance updates prepared', [
                    'purchase_return_id' => $id,
                    'balance_updates' => $balanceUpdates,
                ]);




                $purchaseReturn->refresh();
                return response()->json([
                    'message' => 'Purchase Return Updated Successfully',
                    'data' => $purchaseReturn->load([
                        'purchaseStockProductReturns' => fn($query) => $query->select('id', 'purchase_stock_return_id', 'purchase_stock_product_id', 'product_id', 'product_name', 'purchase_product_code', 'quantity', 'free_quantity', 'price', 'discount_percent', 'discount_amount', 'amount', 'is_vatable', 'measure_unit_id', 'expiry_date', 'mfd', 'customer_id'),
                        'purchaseStockProductReturns.fieldValues' => fn($query) => $query->orderBy('quantity_index')->orderBy('product_field_id'),
                    ]),
                ], 200);
            });
        } catch (ModelNotFoundException $e) {

            Log::error('Purchase or related record not found', [
                'purchase_return_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Purchase or related record not found'], 404);
        } catch (QueryException $e) {
            Log::error('Database error updating purchase return', [
                'purchase_return_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Error updating purchase return', [
                'purchase_return_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }


    public function showQuantity(Request $request, $id): JsonResponse
    {
        try {
            // Step 1: Find purchase stock return
            $purchaseStockReturn = PurchaseStockReturn::findOrFail($id);
            $purchaseBillNumber = $purchaseStockReturn->purchase_bill_number;

            // Step 2: Prepare request for the service
            $request->merge([
                'purchase_bill_number' => $purchaseBillNumber,
                'company_id' => $request->company_id ?? null,
                'branch_id' => $request->branch_id ?? null,
            ]);

            // Step 3: Call the service method
            $response = AvailableQuantityService::getPurchaseAvailableByBillNumber($request, $purchaseBillNumber);

            // Step 4: Decode JsonResponse into array
            $responseData = $response->getData(true);

            // Step 5: Get purchase stock products
            $products = $responseData['data']['purchase_stock_products'] ?? [];

            // Step 6: Map to only product_id, product_name, remaining_quantity
            $filtered = collect($products)->map(function ($item) {
                return [
                    'product_id' => $item['product_id'],

                    'remaining_quantity' => $item['remaining_quantity'],
                ];
            })->values();

            // Step 7: Return simplified response
            return response()->json([
                'message' => 'Successful!!',
                'available_quantity' => $filtered
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error fetching available quantity',
                'error' => $e->getMessage(),
            ], 500);
        }
    }



    public function show(Request $request, $id): JsonResponse
    {
        try {
            // Step 1: Get PurchaseStockReturn with related models
            $item = PurchaseStockReturn::with([
                'purchaseStockProductReturns.fieldValues.productField'
            ])->findOrFail($id);

            // Step 2: Collect product IDs
            $productIds = $item->purchaseStockProductReturns->pluck('product_id')->unique();

            // Step 3: Load measure units
            $productMeasureUnits = ProductList::whereIn('product_id', $productIds)
                ->where('company_id', $request->company_id)
                ->with(['measureUnit:id,name,quantity'])
                ->get()
                ->groupBy('product_id');


            foreach ($item->purchaseStockProductReturns as $productReturn) {

                $units = $productMeasureUnits->get($productReturn->product_id, collect())
                    ->pluck('measureUnit');
                $productReturn->setRelation('measure_units', $units);
                $productID = $productReturn->product_id;

                $purchaseBillNumber = $item->purchase_bill_number;
                if ($purchaseBillNumber) {
                    $response = AvailableQuantityService::getPurchaseAvailableByBillNumber($request, $purchaseBillNumber);
                    $responseData = $response->getData(true);

                    // Step 5: Map product_id → remaining_quantity
                    $availableMap = collect($responseData['data']['purchase_stock_products'] ?? [])
                        ->mapWithKeys(function ($item) {
                            return [$item['product_id'] => $item['remaining_quantity']];
                        });
                } else {
                    $response = AvailableQuantityService::getProductDetailsByInput($request, $productID);
                    $responseData = $response->getData(true);

                    // Step 5: Map product_id → remaining_quantity
                    // BEST FIX: Sum remaining_quantity_in_pieces per product
                    $availableMap = collect($responseData['data'] ?? [])
                        ->keyBy('product_id')
                        ->mapWithKeys(function ($productData) {
                            return [$productData['product_id'] => $productData['available_quantity']];
                        });
                }
                $productReturn->product_code = $productReturn->purchase_product_code;
                unset($productReturn->purchase_product_code);


                // field_values (add field name)
                $productReturn->setRelation(
                    'field_values',
                    $productReturn->fieldValues->map(function ($fv) {
                        $fv->name = $fv->productField->name ?? null;
                        return $fv;
                    })
                );

                // inject remaining quantity
                $productReturn->remaining_quantity = $availableMap[$productReturn->product_id] ?? 0;
            }

            // Step 7: Return JSON identical to your current structure + remaining_quantity
            return response()->json([
                "message" => "Successful!!",
                "data" => $item
            ]);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected query error occurred'], 500);
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
            return response()->json(['error' => 'An Unexpected error occurred'], 500);
        }
    }
    public function filterByBarcode(Request $request): JsonResponse
    {
        try {
            \Log::info('Filter Purchase request: ', $request->all());

            // Validate request
            $validator = Validator::make($request->all(), [
                'barcode' => 'required_without:product_unique_id',
                'product_unique_id' => 'required_without:barcode',
                'company_id' => 'required|exists:companies,id'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $companyId = $request->company_id;
            $product = null;

            // Fetch product by barcode or unique id
            if ($request->filled('barcode')) {
                $productList = \App\Models\ProductList::where('company_id', $companyId)
                    ->where('barcode', $request->barcode)
                    ->first();

                if (!$productList) {
                    return response()->json([
                        'error' => 'No product found for this barcode',
                        'searched_value' => $request->barcode
                    ], 404);
                }

                $product = \App\Models\Product::with(['productLists.measureUnit', 'productFieldValues'])
                    ->find($productList->product_id);
            } else {
                $product = \App\Models\Product::with(['productLists.measureUnit', 'productFieldValues'])
                    ->where('company_id', $companyId)
                    ->where('product_unique_id', $request->product_unique_id)
                    ->first();

                if (!$product) {
                    return response()->json([
                        'error' => 'No product found for this product_unique_id',
                        'searched_value' => $request->product_unique_id
                    ], 404);
                }
            }

            $primary = $product->productLists->firstWhere('is_primary', true)?->measureUnit;

            // Fetch measure units for calculation
            $measureUnits = \App\Models\MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            $purchaseProducts = \App\Models\PurchaseProduct::where('company_id', $companyId)
                ->where('product_id', $product->id)
                ->whereNull('deleted_at')
                ->get();

            $purchaseProductIds = $purchaseProducts->pluck('id')->toArray();

            // Fetch returns, sales, and sales returns
            $purchaseProductReturns = \DB::table('purchase_product_returns')
                ->whereIn('purchase_product_id', $purchaseProductIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->groupBy('purchase_product_id');

            $saleProducts = \DB::table('sale_products')
                ->whereIn('purchase_product_id', $purchaseProductIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->groupBy('purchase_product_id');

            $salesReturnProducts = \DB::table('sales_return_products')
                ->join('sale_products', 'sales_return_products.sale_product_id', '=', 'sale_products.id')
                ->whereIn('sale_products.purchase_product_id', $purchaseProductIds)
                ->where('sales_return_products.company_id', $companyId)
                ->whereNull('sales_return_products.deleted_at')
                ->get()
                ->groupBy('purchase_product_id');

            // Calculate quantities per purchase product
            $purchaseProducts = $purchaseProducts->map(function ($pp) use ($measureUnits, $purchaseProductReturns, $saleProducts, $salesReturnProducts) {

                $unitQty = $measureUnits[$pp->measure_unit_id]->quantity ?? 1;

                $totalPurchased = ($pp->quantity + $pp->free_quantity) * $unitQty;

                $totalReturned = collect($purchaseProductReturns[$pp->id] ?? [])->sum(function ($ret) use ($measureUnits) {
                    $unitQty = $measureUnits[$ret->measure_unit_id]->quantity ?? 1;
                    return ($ret->quantity + $ret->free_quantity) * $unitQty;
                });

                $totalSold = collect($saleProducts[$pp->id] ?? [])->sum(function ($sale) use ($measureUnits) {
                    $unitQty = $measureUnits[$sale->measure_unit_id]->quantity ?? 1;
                    return ($sale->quantity + $sale->free_quantity) * $unitQty;
                });

                $totalSalesReturn = collect($salesReturnProducts[$pp->id] ?? [])->sum(function ($ret) use ($measureUnits) {
                    $unitQty = $measureUnits[$ret->measure_unit_id]->quantity ?? 1;
                    return ($ret->quantity + $ret->free_quantity) * $unitQty;
                });

                $available = max($totalPurchased - $totalReturned - $totalSold + $totalSalesReturn, 0);

                return (object) [
                    'purchase_product_id' => $pp->id,
                    'product_id' => $pp->product_id,
                    'measure_unit_id' => $pp->measure_unit_id,
                    'quantity' => $pp->quantity,
                    'free_quantity' => $pp->free_quantity,
                    'price' => $pp->price,
                    'is_vatable' => $pp->is_vatable,
                    'remaining_quantity_in_pieces' => $available,
                    'remaining_quantity_in_uom' => $available / ($unitQty ?: 1),
                    'total_purchased' => $totalPurchased,
                    'total_returned' => $totalReturned,
                    'total_sold' => $totalSold,
                    'total_sales_return' => $totalSalesReturn,
                ];
            });

            $availableQuantity = $purchaseProducts->sum('remaining_quantity_in_pieces');

            $data = [
                [
                    "product_id" => $product->id,
                    "product_name" => $product->name,
                    "product_code" => $product->product_unique_id,
                    "barcode" => $request->barcode ?? $product->productLists->first()?->barcode,
                    "original_price" => $product->purchase_rate,
                    "min_price" => $product->purchase_rate,
                    "avg_price" => $product->purchase_rate,
                    "latest_price" => $product->purchase_rate,
                    "measure_units_for_products" => $product->productLists->map(fn($pl) => [
                        "id" => $pl->measure_unit_id,
                        "name" => $pl->measureUnit?->name ?? null,
                        "measure_unit_quantity" => $pl->measureUnit?->quantity ?? 1,
                    ])->unique('id')->values()->toArray(),
                    "is_vatable" => (bool) $product->is_vatable,
                    "measure_unit_id" => $primary?->id ?? null,
                    "measure_unit_name" => $primary?->name ?? null,
                    "measure_unit_quantity" => $primary?->quantity ?? 1,
                    "purchased_quantity" => $purchaseProducts->sum('total_purchased'),
                    "return_quantity" => $purchaseProducts->sum('total_returned'),
                    "sale_quantity" => $purchaseProducts->sum('total_sold'),
                    "sales_return_quantity" => $purchaseProducts->sum('total_sales_return'),
                    "available_quantity" => $availableQuantity,
                    "expiry_dates" => [],
                    "field_values" => $product->productFieldValues->map(fn($fv, $index) => [
                        "purchase_id" => null,
                        "purchase_bill_number" => null,
                        "purchase_product_id" => null,
                        "product_field_id" => $fv->product_field_id,
                        "name" => $fv->name ?? null,
                        "value" => $fv->value ?? null,
                        "quantity_index" => $index,
                    ])->toArray(),
                    "purchase_products" => $purchaseProducts->map(fn($pp) => [
                        "purchase_product_id" => $pp->purchase_product_id,
                        "product_id" => $pp->product_id,
                        "quantity" => $pp->quantity,
                        "free_quantity" => $pp->free_quantity,
                        "price" => $pp->price,
                        "is_vatable" => (bool) $pp->is_vatable,
                        "measure_unit_id" => $pp->measure_unit_id,
                        "remaining_quantity_in_pieces" => $pp->remaining_quantity_in_pieces,
                        "remaining_quantity_in_uom" => $pp->remaining_quantity_in_uom,
                        "return_quantity" => $pp->total_returned,
                        "sale_quantity" => $pp->total_sold,
                        "sales_return_quantity" => $pp->total_sales_return,
                    ])->values()->toArray(),
                ]
            ];

            return response()->json([
                "message" => "Product details retrieved successfully",
                "data" => $data
            ]);
        } catch (\Exception $e) {
            \Log::error('Error in filterByBarcode: ' . $e->getMessage());
            return response()->json(['error' => 'Server error: ' . $e->getMessage()], 500);
        }
    }

}

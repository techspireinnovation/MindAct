<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Helpers\PurchaseReturnHelper;
use App\Models\MeasureUnit;
use App\Models\Product;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SalesProductFieldValue;
use App\Models\ProductList;

use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductFieldValue;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnHistory;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\SaleProduct;

use App\Models\SalesReturnProduct;
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
        $query = PurchaseReturn::query();

        if ($request->has('keywords')) {
            $query->where('purchase_bill_number', 'LIKE', '%' . $request->input('keywords') . '%')->orWhereHas('customer', function ($query) use ($request) {
                $query->where('party_name', 'LIKE', "%" . $request->input('keywords') . "%");
            });
        }

        return response()->json($query->paginate(50));
    }

    public function getAllPurchaseProductDetailsByName(Request $request): JsonResponse
    {
        try {


        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Item not Found!!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
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
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }





    public function getPurchaseBillNumber(Request $request): JsonResponse
    {
        try {
            // Validate inputs
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer|exists:companies,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['error' => $validator->errors()->first()], 422);
            }

            $companyId = $request->company_id;

            Log::debug('Input parameters for purchase bill numbers', [
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

            // Fetch purchases with products
            $purchases = Purchase::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->with([
                    'purchaseProducts' => function ($query) use ($companyId) {
                        $query->select([
                            'purchase_products.id',
                            'purchase_products.purchase_id',
                            'purchase_products.product_id',
                            'purchase_products.measure_unit_id',
                            'purchase_products.quantity',
                            'purchase_products.free_quantity',
                            'products.name as product_name',
                        ])
                            ->join('products', 'purchase_products.product_id', '=', 'products.id')
                            ->where('purchase_products.company_id', $companyId)
                            ->whereNull('purchase_products.deleted_at')
                            ->whereNull('products.deleted_at');
                    },
                    'purchaseProducts.fieldValues' => function ($query) use ($companyId) {
                        $query->select([
                            'purchase_product_field_values.purchase_product_id',
                            'purchase_product_field_values.product_field_id',
                            'purchase_product_field_values.quantity_index',
                            'purchase_product_field_values.value',
                            'product_fields.name',
                        ])
                            ->join('product_fields', 'purchase_product_field_values.product_field_id', '=', 'product_fields.id')
                            ->where('purchase_product_field_values.company_id', $companyId)
                            ->whereNull('purchase_product_field_values.deleted_at')
                            ->whereNull('product_fields.deleted_at');
                    },
                ])
                ->select(['id', 'company_id', 'purchase_bill_number'])
                ->get();

            if ($purchases->isEmpty()) {
                Log::warning('No purchases found', ['company_id' => $companyId]);
                return response()->json([
                    'data' => 'Successful !!',
                    'message' => 'No purchases with available products found',
                ], 200);
            }

            // Fetch purchase return products
            $purchaseProductIds = $purchases->pluck('purchaseProducts.*.id')->flatten()->unique()->toArray();
            $purchaseReturnProducts = PurchaseProductReturn::whereIn('purchase_product_id', $purchaseProductIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->select([
                    'id',
                    'purchase_product_id',
                    'quantity',
                    'free_quantity',
                    'measure_unit_id',
                ])
                ->get();

            // Fetch sale products
            $saleProducts = SaleProduct::whereIn('purchase_product_id', $purchaseProductIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->select([
                    'id',
                    'purchase_product_id',
                    'quantity',
                    'free_quantity',
                    'measure_unit_id',
                ])
                ->get();

            // Fetch sales return products linked to sale products
            $saleProductIds = $saleProducts->pluck('id')->unique()->toArray();
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

            // Fetch field values for returns and sales
            $purchaseReturnFieldValues = DB::table('purchase_return_product_field_values')
                ->join('purchase_product_returns', 'purchase_return_product_field_values.purchase_return_product_id', '=', 'purchase_product_returns.id')
                ->where('purchase_return_product_field_values.company_id', $companyId)
                ->whereNull('purchase_return_product_field_values.deleted_at')
                ->whereNull('purchase_product_returns.deleted_at')
                ->whereIn('purchase_product_returns.purchase_product_id', $purchaseProductIds)
                ->select([
                    'purchase_product_returns.purchase_product_id',
                    'purchase_return_product_field_values.product_field_id',
                    'purchase_return_product_field_values.quantity_index',
                    'purchase_return_product_field_values.value',
                ])
                ->get()
                ->groupBy('purchase_product_id');

            $salesFieldValues = DB::table('sales_product_field_values')
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

            $salesReturnFieldValues = DB::table('sale_return_product_field_values')
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

            // Aggregate bill numbers
            $billNumbers = [];
            foreach ($purchases as $purchase) {
                if ($purchase->purchaseProducts->isEmpty()) {
                    Log::warning('No available products for purchase', [
                        'purchase_id' => $purchase->id,
                        'purchase_bill_number' => $purchase->purchase_bill_number,
                        'company_id' => $companyId,
                    ]);
                    continue;
                }

                $hasAvailableProducts = false;
                foreach ($purchase->purchaseProducts as $purchaseProduct) {
                    $productId = $purchaseProduct->product_id;
                    // Use purchase product's measure unit or default if missing
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

                    // Calculate purchase quantity
                    $purchaseTotal = ($purchaseProduct->quantity + ($purchaseProduct->free_quantity ?? 0)) * $measureUnitQuantity;

                    // Calculate purchase return quantity
                    $returnProducts = $purchaseReturnProducts->where('purchase_product_id', $purchaseProduct->id);
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

                    // Calculate net sales quantity
                    $saleProductsForPurchase = $saleProducts->where('purchase_product_id', $purchaseProduct->id);
                    $netSales = 0;
                    foreach ($saleProductsForPurchase as $saleProduct) {
                        $saleMeasureUnitId = $saleProduct->measure_unit_id ?? null;
                        $saleMeasureUnitQuantity = isset($measureUnits[$saleMeasureUnitId]) ? $measureUnits[$saleMeasureUnitId]->quantity : 1;
                        $saleQuantity = ($saleProduct->quantity + ($saleProduct->free_quantity ?? 0)) * $saleMeasureUnitQuantity;

                        // Subtract sales returns for this sale product
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

                    // Handle field values
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

                            // Check purchase returns
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
                return response()->json([
                    'data' => 'Successful !!',
                    'message' => 'No purchases with available products found',
                ], 200);
            }

            Log::info('Final bill numbers prepared', [
                'bill_numbers' => $billNumbers,
                'count' => count($billNumbers),
            ]);

            return response()->json($billNumbers);

        } catch (QueryException $e) {
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
                'company_id' => 'required|integer|exists:companies,id',
                'purchase_bill_number' => 'nullable|string|max:255',
                'purchase_number' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                Log::warning('Validation failed', ['errors' => $validator->errors()]);
                return response()->json(['errors' => $validator->errors()], 422);
            }

            if (!$request->hasAny(['purchase_bill_number', 'purchase_number'])) {
                Log::warning('No valid search parameters provided', ['request' => $request->all()]);
                return response()->json(['error' => 'At least one of purchase_bill_number or purchase_number is required'], 422);
            }

            $companyId = $request->input('company_id');
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
                ->whereNull('deleted_at')
                ->when($purchaseBillNumber, fn($q) => $q->where('purchase_bill_number', $purchaseBillNumber))
                ->when($purchaseNumber, fn($q) => $q->where('purchase_number', $purchaseNumber))
                ->with([
                    'purchaseProducts' => function ($query) use ($companyId) {
                        $query->whereNull('deleted_at')
                            ->where('company_id', $companyId)
                            ->with([
                                'measureUnit' => fn($q) => $q->select('id', 'name', 'quantity')->whereNull('deleted_at'),
                                'fieldValues.productField' => fn($q) => $q->select('id', 'name')->whereNull('deleted_at'),
                                'purchaseProductReturns' => fn($subQuery) => $subQuery->whereNull('deleted_at')
                                    ->where('company_id', $companyId)
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

            if (empty($purchase->purchaseProducts)) {
                Log::info('No purchase products found', [
                    'purchase_id' => $purchase->id,
                    'company_id' => $companyId,
                    'query_log' => DB::getQueryLog(),
                ]);
                return response()->json(['error' => 'No available products for this purchase'], 404);
            }

            // Prepare response data
            $purchaseData = $purchase ? $purchase->toArray() : [];
            $payment = $purchase->payment;

            $purchaseData['payment'] = [
                'cash' => $payment['cash'] ?? null,
                'credit' => $payment['credit'] ?? null,
                'bank' => $payment['bank'] ?? null,
            ];

            $measureUnitsCalc = MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            $purchaseProducts = collect($purchaseData['purchase_products'])->filter(function ($product) use ($companyId, $measureUnitsCalc) {
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
                $totalQuantity = ($product['quantity'] ?? 0) + ($product['free_quantity'] ?? 0);
                $unitQuantity = $unitData['quantity'] ?? 1;
                $decimalStr = explode('.', (string) $totalQuantity);
                $quantityInt = floor($totalQuantity);
                $decimalDigits = isset($decimalStr[1]) ? (float) $decimalStr[1] : 0;
                $totalPurchaseQuantityInPieces = ($quantityInt * $unitQuantity) + $decimalDigits;

                // Calculate returned quantities
                $totalReturnedInPieces = collect($product['purchase_product_returns'] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retTotalQty = ($return['quantity'] ?? 0) + ($return['free_quantity'] ?? 0);
                    $retDecimalStr = explode('.', (string) $retTotalQty);
                    $retQtyInt = floor($retTotalQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (float) $retDecimalStr[1] : 0;
                    return ($retQtyInt * $unitQty) + $retQtyDec;
                });

                // Calculate sold quantities
                $totalSoldInPieces = collect($product['sale_products'] ?? [])->sum(function ($sale) use ($measureUnitsCalc) {
                    $unitId = $sale['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $saleTotalQty = ($sale['quantity'] ?? 0) + ($sale['free_quantity'] ?? 0);
                    $saleDecimalStr = explode('.', (string) $saleTotalQty);
                    $saleQtyInt = floor($saleTotalQty);
                    $saleQtyDec = isset($saleDecimalStr[1]) ? (float) $saleDecimalStr[1] : 0;
                    return ($saleQtyInt * $unitQty) + $saleQtyDec;
                });

                // Calculate sale returns
                $totalSaleReturnsInPieces = collect($product['sale_products'] ?? [])->flatMap(function ($sale) {
                    return $sale['sale_product_returns'] ?? [];
                })->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retTotalQty = ($return['quantity'] ?? 0) + ($return['free_quantity'] ?? 0);
                    $retDecimalStr = explode('.', (string) $retTotalQty);
                    $retQtyInt = floor($retTotalQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (float) $retDecimalStr[1] : 0;
                    return ($retQtyInt * $unitQty) + $retQtyDec;
                });

                $availableQuantityInPieces = $totalPurchaseQuantityInPieces - $totalReturnedInPieces - $totalSoldInPieces + $totalSaleReturnsInPieces;

                return $availableQuantityInPieces > 0;
            })->map(function ($product) use ($companyId, $measureUnitsCalc) {
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

                // Calculate quantities
                $unitQuantity = $unitData['quantity'] ?? 1;
                $quantity = $product['quantity'] ?? 0;
                $decimalStrforRegularQuantity = explode('.', (string) $quantity);
                $regularQuantityInt = floor($quantity);

                $regularDecimalDigits = isset($decimalStrforRegularQuantity[1]) ? (float) $decimalStrforRegularQuantity[1] : 0;
                $totalRegularQuantity = ($regularQuantityInt * $unitQuantity) + $regularDecimalDigits;
                $freeQuantity = $product['free_quantity'] ?? 0;
                $decimalStrforFreeQuantity = explode('.', (string) $freeQuantity);
                $freeQuantityInt = floor($freeQuantity);

                $freeDecimalDigits = isset($decimalStrforFreeQuantity[1]) ? (float) $decimalStrforFreeQuantity[1] : 0;
                $totalFreeQuantity = ($freeQuantityInt * $unitQuantity) + $freeDecimalDigits;


                //For Totral Remaining 
                $totalQuantity = $quantity + $freeQuantity;

                $decimalStr = explode('.', (string) $totalQuantity);
                $quantityInt = floor($totalQuantity);
                $decimalDigits = isset($decimalStr[1]) ? (float) $decimalStr[1] : 0;
                $totalPurchaseQuantityInPieces = ($quantityInt * $unitQuantity) + $decimalDigits;
                $totalPurchaseQuantityInUOM = $totalQuantity;



                $returnedRegularInPieces = collect($product['purchase_product_returns'] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retQty = $return['quantity'] ?? 0; // Regular quantity returned
                    $retDecimalStr = explode('.', (string) $retQty);
                    $retQtyInt = floor($retQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (float) $retDecimalStr[1] : 0;
                    return ($retQtyInt * $unitQty) + $retQtyDec;
                });

                $returnedFreeInPieces = collect($product['purchase_product_returns'] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retFreeQty = $return['free_quantity'] ?? 0; // Free quantity returned
                    $retDecimalStr = explode('.', (string) $retFreeQty);
                    $retQtyInt = floor($retFreeQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (float) $retDecimalStr[1] : 0;
                    return ($retQtyInt * $unitQty) + $retQtyDec;
                });

                // Adjust remaining quantities
                $remainingRegularQuantity = max($totalRegularQuantity - $returnedRegularInPieces, 0);
                $remainingFreeQuantity = max($totalFreeQuantity - $returnedFreeInPieces, 0);

                // For Total Remaining
                $totalQuantity = $quantity + $freeQuantity;
                $decimalStr = explode('.', (string) $totalQuantity);
                $quantityInt = floor($totalQuantity);
                $decimalDigits = isset($decimalStr[1]) ? (float) $decimalStr[1] : 0;
                $totalPurchaseQuantityInPieces = ($quantityInt * $unitQuantity) + $decimalDigits;
                $totalPurchaseQuantityInUOM = $totalQuantity;

                // Calculate returned quantities
                $totalReturnedInPieces = collect($product['purchase_product_returns'] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retTotalQty = ($return['quantity'] ?? 0) + ($return['free_quantity'] ?? 0);
                    $retDecimalStr = explode('.', (string) $retTotalQty);
                    $retQtyInt = floor($retTotalQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (float) $retDecimalStr[1] : 0;
                    return ($retQtyInt * $unitQty) + $retQtyDec;
                });

                // Calculate sold quantities
                $totalSoldInPieces = collect($product['sale_products'] ?? [])->sum(function ($sale) use ($measureUnitsCalc) {
                    $unitId = $sale['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $saleTotalQty = ($sale['quantity'] ?? 0) + ($sale['free_quantity'] ?? 0);
                    $saleDecimalStr = explode('.', (string) $saleTotalQty);
                    $saleQtyInt = floor($saleTotalQty);
                    $saleQtyDec = isset($saleDecimalStr[1]) ? (float) $saleDecimalStr[1] : 0;
                    return ($saleQtyInt * $unitQty) + $saleQtyDec;
                });

                // Calculate sale returns
                $totalSaleReturnsInPieces = collect($product['sale_products'] ?? [])->flatMap(function ($sale) {
                    return $sale['sale_product_returns'] ?? [];
                })->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return['measure_unit_id'] ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retTotalQty = ($return['quantity'] ?? 0) + ($return['free_quantity'] ?? 0);
                    $retDecimalStr = explode('.', (string) $retTotalQty);
                    $retQtyInt = floor($retTotalQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (float) $retDecimalStr[1] : 0;
                    return ($retQtyInt * $unitQty) + $retQtyDec;
                });

                $remainingQuantityInPieces = max($totalPurchaseQuantityInPieces - $totalReturnedInPieces - $totalSoldInPieces + $totalSaleReturnsInPieces, 0);
                $remainingQuantityInUOM = $remainingQuantityInPieces / ($unitData['quantity'] ?? 1);

                // Process field values
                $unavailableQuantityIndices = [];
                $groupedFieldValues = [];

                // Handle purchase returns
                if (!empty($product['purchase_product_returns'])) {
                    $returnIds = array_column($product['purchase_product_returns'], 'id');
                    $unavailableQuantityIndices = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $returnIds)
                        ->whereNull('deleted_at')
                        ->where('company_id', $companyId)
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
                            'purchase_product_id' => $fieldValue['purchase_product_id'] ?? null,
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

                // Log data for debugging
                Log::debug('Preparing product data', [
                    'product_id' => $product['id'] ?? 'unknown',
                    'measure_unit_id' => $product['measure_unit_id'] ?? 'null',
                    'grouped_field_values' => $groupedFieldValues,
                    'remaining_quantity_in_pieces' => $remainingQuantityInPieces,
                ]);
                $getOriginalPrice = Product::where('id', $product['product_id'])->pluck('purchase_rate')->first();


                $getProductForMeasureUnits = Product::with('productLists')
                    ->where('id', $product['product_id'])
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
                    echo 'Product not found';
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

                // Prepare product data, filtering out invalid values
                $productData = array_filter([
                    'purchase_product_id' => $product['id'] ?? null,
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
                    'measure_units_for_products' => $measureUnitsForProducts ?? [],
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

            $purchaseData['purchase_products'] = $purchaseProducts;

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
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer|exists:companies,id',
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
            $productCode = $request->input('product_code');
            $productName = trim(strtolower($request->input('product_name')));
            $barcode = $request->input('barcode');
            $purchaseBillNumber = $request->input('purchase_bill_number');

            Log::debug('Input parameters', [
                'company_id' => $companyId,
                'product_code' => $productCode,
                'product_name' => $productName,
                'barcode' => $barcode,
                'purchase_bill_number' => $purchaseBillNumber
            ]);

            // Enable query logging for debugging
            DB::enableQueryLog();

            // Fetch measure units for calculations
            $measureUnitsCalc = MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->get()
                ->keyBy('id');

            // Query purchase products with relations
            $purchaseProductsQuery = DB::table('purchase_products')
                ->select([
                    'purchase_products.id as purchase_product_id',
                    'purchase_products.purchase_id',
                    'purchase_products.product_id',
                    'purchase_products.product_name',
                    'purchase_products.product_code',
                    'purchase_products.quantity',
                    'purchase_products.free_quantity',
                    'purchase_products.expiry_date',
                    'purchase_products.price',
                    'purchase_products.is_vatable',
                    'purchase_products.measure_unit_id',
                    'measure_units.name as measure_unit_name',
                    'measure_units.quantity as measure_unit_quantity',
                    'purchases.purchase_bill_number',
                    'purchases.invoice_date',
                ])
                ->join('measure_units', 'purchase_products.measure_unit_id', '=', 'measure_units.id')
                ->leftJoin('purchases', function ($join) use ($companyId) {
                    $join->on('purchase_products.purchase_id', '=', 'purchases.id')
                        ->where('purchases.company_id', $companyId)
                        ->whereNull('purchases.deleted_at');
                })
                ->where('purchase_products.company_id', $companyId)
                ->whereNull('purchase_products.deleted_at')
                ->where('measure_units.company_id', $companyId)
                ->whereNull('measure_units.deleted_at')
                ->when($productCode, fn($q) => $q->where('purchase_products.product_code', $productCode))
                ->when($productName, fn($q) => $q->whereRaw('LOWER(purchase_products.product_name) LIKE ?', ["%{$productName}%"]))
                ->when($barcode, fn($q) => $q->whereIn('purchase_products.id', function ($subQuery) use ($barcode, $companyId) {
                    $subQuery->select('purchase_product_id')
                        ->from('purchase_product_field_values')
                        ->where('company_id', $companyId)
                        ->whereNull('deleted_at')
                        ->where('value', $barcode)
                        ->where('product_field_id', env('BARCODE_FIELD_ID', 1));
                }))
                ->when($purchaseBillNumber, fn($q) => $q->where('purchases.purchase_bill_number', $purchaseBillNumber))
                ->orderBy('purchases.invoice_date', 'ASC')
                ->orderBy('purchase_products.created_at', 'ASC');

            // Fetch purchase products
            $purchaseProducts = $purchaseProductsQuery->get();
            Log::debug('Purchase products query results', [
                'purchase_products' => $purchaseProducts,
                'query' => $purchaseProductsQuery->toSql(),
                'bindings' => $purchaseProductsQuery->getBindings()
            ]);

            if ($purchaseProducts->isEmpty()) {
                Log::info('No purchase products found', [
                    'company_id' => $companyId,
                    'filters' => $request->only(['product_code', 'product_name', 'barcode', 'purchase_bill_number']),
                    'query_log' => DB::getQueryLog()
                ]);
                return response()->json(['error' => 'No products found matching the criteria'], 404);
            }

            // Fetch related data for calculations
            $purchaseProductIds = $purchaseProducts->pluck('purchase_product_id')->toArray();

            $productId = $purchaseProducts->pluck('product_id')->unique()->toArray();


            $purchaseProductReturns = DB::table('purchase_product_returns')
                ->select([
                    'purchase_product_returns.purchase_product_id',
                    'purchase_product_returns.quantity',
                    'purchase_product_returns.free_quantity',
                    'purchase_product_returns.measure_unit_id',
                ])
                ->whereIn('purchase_product_returns.purchase_product_id', $purchaseProductIds)
                ->where('purchase_product_returns.company_id', $companyId)
                ->whereNull('purchase_product_returns.deleted_at')
                ->get()
                ->groupBy('purchase_product_id');

            $saleProducts = DB::table('sale_products')
                ->select([
                    'sale_products.purchase_product_id',
                    'sale_products.quantity',
                    'sale_products.free_quantity',
                    'sale_products.measure_unit_id',
                ])
                ->whereIn('sale_products.purchase_product_id', $purchaseProductIds)
                ->where('sale_products.company_id', $companyId)
                ->whereNull('sale_products.deleted_at')
                ->get()
                ->groupBy('purchase_product_id');

            $salesReturnProducts = DB::table('sales_return_products')
                ->select([
                    'sale_products.purchase_product_id',
                    'sales_return_products.quantity',
                    'sales_return_products.free_quantity',
                    'sales_return_products.measure_unit_id',
                ])
                ->join('sale_products', 'sales_return_products.sale_product_id', '=', 'sale_products.id')
                ->whereIn('sale_products.purchase_product_id', $purchaseProductIds)
                ->where('sales_return_products.company_id', $companyId)
                ->whereNull('sales_return_products.deleted_at')
                ->get()
                ->groupBy('purchase_product_id');

            // Fetch field values and quantity indexes
            $soldQuantityIndexes = DB::table('sales_product_field_values')
                ->select([
                    'sale_products.purchase_product_id',
                    'sales_product_field_values.quantity_index'
                ])
                ->join('sale_products', 'sales_product_field_values.sale_product_id', '=', 'sale_products.id')
                ->whereIn('sale_products.purchase_product_id', $purchaseProductIds)
                ->where('sale_products.company_id', $companyId)
                ->whereNull('sale_products.deleted_at')
                ->distinct()
                ->get()
                ->groupBy('purchase_product_id')
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            $returnedQuantityIndexes = DB::table('purchase_return_product_field_values')
                ->select([
                    'purchase_product_returns.purchase_product_id',
                    'purchase_return_product_field_values.quantity_index'
                ])
                ->join('purchase_product_returns', 'purchase_return_product_field_values.purchase_return_product_id', '=', 'purchase_product_returns.id')
                ->whereIn('purchase_product_returns.purchase_product_id', $purchaseProductIds)
                ->where('purchase_product_returns.company_id', $companyId)
                ->whereNull('purchase_product_returns.deleted_at')
                ->distinct()
                ->get()
                ->groupBy('purchase_product_id')
                ->map(fn($group) => $group->pluck('quantity_index')->toArray());

            $fieldValues = DB::table('purchase_product_field_values')
                ->select([
                    'purchase_product_field_values.purchase_product_id',
                    'purchase_product_field_values.product_field_id',
                    'product_fields.name as product_field_name',
                    'purchase_product_field_values.value',
                    'purchase_product_field_values.quantity_index',
                    'purchases.purchase_bill_number',
                    'purchases.id as purchase_id'
                ])
                ->leftJoin('product_fields', fn($join) => $join->on('purchase_product_field_values.product_field_id', '=', 'product_fields.id')
                    ->where('product_fields.company_id', $companyId)
                    ->whereNull('product_fields.deleted_at'))
                ->join('product_field_values', function ($join) use ($companyId, $productId) {
                    $join->on('purchase_product_field_values.product_field_id', '=', 'product_field_values.product_field_id')

                        ->where('product_field_values.company_id', $companyId)
                        ->whereIn('product_field_values.product_id', $productId)
                        ->whereNull('product_field_values.deleted_at');
                })
                ->leftJoin('purchase_products', 'purchase_product_field_values.purchase_product_id', '=', 'purchase_products.id')
                ->leftJoin('purchases', 'purchase_products.purchase_id', '=', 'purchases.id')
                ->whereIn('purchase_product_field_values.purchase_product_id', $purchaseProductIds)
                ->where('purchase_product_field_values.company_id', $companyId)
                ->whereNull('purchase_product_field_values.deleted_at')
                ->orderBy('purchase_product_field_values.quantity_index', 'ASC')
                ->get()
                ->groupBy('purchase_product_id');

            $saleReturnFieldValues = DB::table('sale_return_product_field_values')
                ->select([
                    'sale_products.purchase_product_id',
                    'sale_return_product_field_values.product_field_id',
                    'product_fields.name as product_field_name',
                    'sale_return_product_field_values.value',
                    'sale_return_product_field_values.quantity_index'
                ])
                ->join('sales_return_products', 'sale_return_product_field_values.sale_return_product_id', '=', 'sales_return_products.id')
                ->join('sale_products', 'sales_return_products.sale_product_id', '=', 'sale_products.id')
                ->leftJoin('product_fields', fn($join) => $join->on('sale_return_product_field_values.product_field_id', '=', 'product_fields.id')
                    ->where('product_fields.company_id', $companyId))
                ->whereIn('sale_products.purchase_product_id', $purchaseProductIds)
                ->where('sale_return_product_field_values.company_id', $companyId)
                ->whereNull('sale_return_product_field_values.deleted_at')
                ->get()
                ->groupBy('purchase_product_id');

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
                $totalReturnedInPieces = collect($purchaseProductReturns[$pp->purchase_product_id] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
                    $unitId = $return->measure_unit_id ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $retTotalQty = ($return->quantity ?? 0) + ($return->free_quantity ?? 0);
                    $retDecimalStr = explode('.', (string) $retTotalQty);
                    $retQtyInt = floor($retTotalQty);
                    $retQtyDec = isset($retDecimalStr[1]) ? (float) $retDecimalStr[1] : 0;
                    return ($retQtyInt * $unitQty) + $retQtyDec;
                });

                // Calculate sold quantities
                $totalSoldInPieces = collect($saleProducts[$pp->purchase_product_id] ?? [])->sum(function ($sale) use ($measureUnitsCalc) {
                    $unitId = $sale->measure_unit_id ?? null;
                    $unitQty = isset($measureUnitsCalc[$unitId]) ? $measureUnitsCalc[$unitId]->quantity : 1;
                    $saleTotalQty = ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0);
                    $saleDecimalStr = explode('.', (string) $saleTotalQty);
                    $saleQtyInt = floor($saleTotalQty);
                    $saleQtyDec = isset($saleDecimalStr[1]) ? (float) $saleDecimalStr[1] : 0;
                    return ($saleQtyInt * $unitQty) + $saleQtyDec;
                });

                // Calculate sale returns
                $totalSaleReturnsInPieces = collect($salesReturnProducts[$pp->purchase_product_id] ?? [])->sum(function ($return) use ($measureUnitsCalc) {
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
                    'purchase_product_id' => $pp->purchase_product_id,
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

                $purchaseProductsPrice = PurchaseProduct::where('product_id', $first->product_id)->orderBy('created_at', 'desc')->pluck('price');
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
                    if ($availableUnits > 0 && isset($fieldValues[$pp->purchase_product_id])) {
                        $soldIndexes = $soldQuantityIndexes[$pp->purchase_product_id] ?? [];
                        $returnedIndexes = $returnedQuantityIndexes[$pp->purchase_product_id] ?? [];
                        $excludedIndexes = array_unique(array_merge($soldIndexes, $returnedIndexes));

                        $ppFieldValues = $fieldValues[$pp->purchase_product_id]
                            ->filter(fn($fv) => !in_array($fv->quantity_index, $excludedIndexes))
                            ->groupBy('quantity_index')
                            ->take($availableUnits)
                            ->flatten(1)
                            ->map(fn($fv) => [
                                'purchase_id' => $fv->purchase_id,
                                'purchase_bill_number' => $fv->purchase_bill_number ?? '',
                                'purchase_product_id' => $fv->purchase_product_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->product_field_name ?? 'N/A',
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ])->values();
                        $productFieldValues = $productFieldValues->merge($ppFieldValues);
                    }

                    if ($availableUnits > 0 && isset($saleReturnFieldValues[$pp->purchase_product_id])) {
                        $ppSaleReturnFieldValues = $saleReturnFieldValues[$pp->purchase_product_id]
                            ->groupBy('quantity_index')
                            ->take($availableUnits)
                            ->flatten(1)
                            ->map(fn($fv) => [
                                'purchase_id' => null,
                                'purchase_bill_number' => '',
                                'purchase_product_id' => $fv->purchase_product_id,
                                'product_field_id' => $fv->product_field_id,
                                'name' => $fv->product_field_name ?? 'N/A',
                                'value' => $fv->value,
                                'quantity_index' => $fv->quantity_index
                            ])->values();
                        $productFieldValues = $productFieldValues->merge($ppSaleReturnFieldValues);
                    }

                    return [
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
                    'purchase_products' => $productPurchaseProducts
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
                'company_id' => 'required|integer|exists:companies,id',
                'customer_id' => 'nullable|integer|exists:customers,id',
                'customer_name' => 'nullable|string|max:255',
                'pan_number' => 'nullable|numeric|digits:10',
                'invoice_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('purchase_returns')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
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
                'purchase_return_products.*.purchase_product_id' => 'nullable|integer|exists:purchase_products,id',
                'purchase_return_products.*.product_name' => 'nullable|string|max:255',
                'purchase_return_products.*.mfd' => 'nullable|string|max:255',
                'purchase_return_products.*.customer_id' => 'nullable|integer|exists:customers,id',
                'purchase_return_products.*.quantity' => 'required|numeric|min:0',
                'purchase_return_products.*.free_quantity' => 'nullable|numeric|min:0',
                'purchase_return_products.*.price' => 'required|numeric|min:0', // Fixed missing validation
                'purchase_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'purchase_return_products.*.discount_amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.amount' => 'nullable|numeric|min:0',
                'purchase_return_products.*.is_vatable' => 'required|boolean',
                'purchase_return_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'purchase_return_products.*.expiry_date' => 'nullable|string|max:255',
                'purchase_return_products.*.field_values' => 'present|array',
                'purchase_return_products.*.field_values.*' => 'array|min:1',
                'purchase_return_products.*.field_values.*.*.purchase_product_id' => 'required_if:field_values,array|integer|exists:purchase_products,id',
                'purchase_return_products.*.field_values.*.*.product_field_id' => 'required_if:field_values,array|integer|exists:product_fields,id',
                'purchase_return_products.*.field_values.*.*.value' => 'required_if:field_values,array|string|max:255',
                'purchase_return_products.*.field_values.*.*.quantity_index' => 'required_if:field_values,array|integer|min:0',
                'purchase_return_products.*.field_values.*.*.quantity_type' => 'nullable|string|in:regular,free',
            ]);

            if ($validator->fails()) {
                return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
            }

            $validated = $validator->validated();
            Log::debug('Validated request data', ['data' => $validated]);

            // Process in transaction
            $purchaseReturn = DB::transaction(function () use ($validated) {
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

                    // Normalize field values with robust flattening
                    $fieldValuesFlat = $this->flattenFieldValues($productData['field_values'], $index);
                    Log::debug('Flattened field values', [
                        'product_id' => $productData['product_id'],
                        'index' => $index,
                        'field_values_flat' => $fieldValuesFlat
                    ]);

                    // Validate field values
                    collect($fieldValuesFlat)->each(function ($fv) use ($index) {
                        if (empty($fv['purchase_product_id']) || !is_numeric($fv['purchase_product_id'])) {
                            throw new \Exception("Invalid purchase_product_id in field_values at index {$index}");
                        }
                        if (!isset($fv['quantity_index']) || !is_numeric($fv['quantity_index']) || $fv['quantity_index'] < 0) {
                            throw new \Exception("Invalid quantity_index in field_values at index {$index}");
                        }
                    });

                    // Group field values
                    $groupedFieldValues = collect($fieldValuesFlat)
                        ->groupBy('purchase_product_id')
                        ->map(function ($group) {
                            return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                                return collect($fvGroup)->map(function ($fv) {
                                    return [
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

                    // Count field value sets by unique quantity_index and quantity_type
                    $regularFieldValueSets = collect($fieldValuesFlat)
                        ->filter(fn($fv) => ($fv['quantity_type'] ?? 'regular') === 'regular')
                        ->map(fn($fv) => "{$fv['purchase_product_id']}:{$fv['quantity_index']}")
                        ->unique()
                        ->count();
                    $freeFieldValueSets = collect($fieldValuesFlat)
                        ->filter(fn($fv) => ($fv['quantity_type'] ?? 'regular') === 'free')
                        ->map(fn($fv) => "{$fv['purchase_product_id']}:{$fv['quantity_index']}")
                        ->unique()
                        ->count();

                    $hasFieldValues = !empty($fieldValuesFlat);
                    $requiresFieldValues = !empty($purchaseProductIds = array_keys($groupedFieldValues)) && PurchaseProductFieldValue::whereIn('purchase_product_id', $purchaseProductIds)->whereNull('deleted_at')->exists();

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
                    $query = PurchaseProduct::where('product_id', $productData['product_id'])
                        ->where('company_id', $validated['company_id'])
                        ->whereNull('deleted_at')
                        ->with([
                            'purchase' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']),
                            'purchaseProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->with('measureUnit'),
                            'fieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']),
                            'saleProducts' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->with(['saleProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']), 'measureUnit'])
                        ]);

                    if ($hasFieldValues) {
                        $query->whereIn('id', $purchaseProductIds);
                    } elseif (isset($productData['purchase_product_id'])) {
                        $query->where('id', $productData['purchase_product_id']);
                    } else {
                        $query->whereNotExists(fn($subQuery) => $subQuery->select(DB::raw(1))->from('purchase_product_field_values')->whereColumn('purchase_product_id', 'purchase_products.id')->where('company_id', $validated['company_id'])->whereNull('deleted_at'));
                    }

                    $purchaseProducts = $query->orderBy('created_at')->distinct()->get();
                    Log::debug('Fetched PurchaseProducts', [
                        'product_id' => $productData['product_id'],
                        'index' => $index,
                        'purchase_product_ids' => $purchaseProducts->pluck('id')->toArray(),
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

                            Log::debug('Stock calculation for PurchaseProduct', [
                                'product_id' => $productData['product_id'],
                                'index' => $index,
                                'purchase_product_id' => $purchaseProductId,
                                'quantity' => $purchaseProduct->quantity,
                                'free_quantity' => $purchaseProduct->free_quantity,
                                'measure_unit_id' => $purchaseProduct->measure_unit_id,
                                'measure_unit_quantity' => $purchaseMeasureUnitQuantity,
                                'total_available_pieces' => $totalAvailablePieces
                            ]);

                            // Validate field values
                            $existingFieldValues = $purchaseProduct->fieldValues->groupBy('quantity_index')->map(fn($group) => $group->pluck('value', 'product_field_id')->toArray());
                            $unavailableQuantityIndices = $this->getUnavailableQuantityIndices($purchaseProduct, $validated['company_id']);
                            $salesReturnedIndices = SaleReturnProductFieldValue::whereIn('sale_return_product_id', $purchaseProduct->saleProducts->flatMap(fn($sp) => $sp->saleProductReturns->pluck('id')))->whereNull('deleted_at')->pluck('quantity_index')->toArray();
                            $unavailableQuantityIndices = array_diff($unavailableQuantityIndices, $salesReturnedIndices);

                            Log::debug('Field value validation', [
                                'product_id' => $productData['product_id'],
                                'index' => $index,
                                'purchase_product_id' => $purchaseProductId,
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

                            // Allocate pieces
                            // Fix: Use quantity_type from fvSet instead of fieldValuesFlat
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

                                [$allocateRegularQuantity, $allocateFreeQuantity] = $this->convertToTargetMeasureUnit($allocateRegularPieces, $allocateFreePieces, $targetMeasureUnitQuantity);

                                $allocations[] = [
                                    'purchase_product_id' => $purchaseProductId,
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

                                $remainingRegularPieces -= $allocateRegularPieces;
                                $remainingFreePieces -= $allocateFreePieces;

                                Log::debug('Allocation with field values', [
                                    'product_id' => $productData['product_id'],
                                    'index' => $index,
                                    'purchase_product_id' => $purchaseProductId,
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
                        $purchaseProduct = isset($productData['purchase_product_id']) ? $purchaseProducts->firstWhere('id', $productData['purchase_product_id']) : null;

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

                            Log::debug('Stock calculation for FIFO PurchaseProduct', [
                                'product_id' => $productData['product_id'],
                                'index' => $index,
                                'purchase_product_id' => $purchaseProduct->id,
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
                                    'purchase_product_id' => $purchaseProduct->id,
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

                                Log::debug('FIFO allocation', [
                                    'product_id' => $productData['product_id'],
                                    'index' => $index,
                                    'purchase_product_id' => $purchaseProduct->id,
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
                        $purchaseProduct = PurchaseProduct::findOrFail($allocation['purchase_product_id']);
                        $processedProducts[] = [
                            'purchase_product_id' => $allocation['purchase_product_id'],
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
                $purchaseReturnData = array_filter($validated, fn($key) => !in_array($key, ['purchase_return_products']), ARRAY_FILTER_USE_KEY);
                $purchaseReturnData['purchase_id'] = null;
                $purchaseReturn = PurchaseReturn::create($purchaseReturnData);

                foreach ($processedProducts as $productData) {
                    $productDataFiltered = array_filter($productData, fn($key) => !in_array($key, ['field_values', 'purchase_id', 'purchase_purchase_bill_number']), ARRAY_FILTER_USE_KEY);
                    $purchaseReturnProduct = $purchaseReturn->purchaseReturnProducts()->create(array_merge($productDataFiltered, ['company_id' => $purchaseReturn->company_id]));

                    if (!empty($productData['field_values'])) {
                        foreach ($productData['field_values'] as $fvSet) {
                            foreach ($fvSet as $fv) {
                                PurchaseReturnProductFieldValue::create([
                                    'purchase_return_product_id' => $purchaseReturnProduct->id,
                                    'product_field_id' => $fv['product_field_id'],
                                    'value' => $fv['value'],
                                    'product_id' => $purchaseReturnProduct->product_id,
                                    'company_id' => $purchaseReturnProduct->company_id,
                                    'quantity_index' => $fv['quantity_index'],
                                    'quantity_type' => $fv['quantity_type'], // Remove ?? null to ensure value is saved
                                ]);
                            }
                        }
                    }
                }

                // Note: PurchaseReturnHistory creation is missing in your code; adding it back
                PurchaseReturnHistory::create([
                    'purchase_return_id' => $purchaseReturn->id,
                    'action' => 'created',
                    'data' => array_merge($purchaseReturnData, ['purchase_return_products' => $processedProducts]),
                ]);

                Log::debug('Purchase return created', ['purchase_return_id' => $purchaseReturn->id, 'processed_products' => $processedProducts]);

                return $purchaseReturn->load([
                    'purchaseReturnProducts' => fn($query) => $query->select('id', 'purchase_return_id', 'purchase_product_id', 'product_id', 'product_name', 'purchase_product_code', 'quantity', 'free_quantity', 'price', 'discount_percent', 'discount_amount', 'amount', 'is_vatable', 'measure_unit_id', 'expiry_date', 'mfd', 'customer_id'),
                    'purchaseReturnProducts.fieldValues' => fn($query) => $query->select('id', 'purchase_return_product_id', 'product_field_id', 'value', 'quantity_index', 'quantity_type', 'product_id', 'company_id', 'created_at', 'updated_at', 'deleted_at')->orderBy('quantity_index')->orderBy('product_field_id')
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

    // Helper method for robust field value flattening
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
                    if (isset($item['purchase_product_id'], $item['product_field_id'], $item['value'], $item['quantity_index'])) {
                        // Valid field value object
                        $flattened[] = [
                            'purchase_product_id' => $item['purchase_product_id'],
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

    // Existing helper methods
    private function calculatePieces(float $quantity, float $measureUnitQuantity): float
    {
        $integerPart = floor($quantity);
        $decimalPart = $quantity - $integerPart;
        $decimalPieces = $decimalPart > 0 ? (int) str_replace('.', '', (string) $decimalPart) : 0;
        return ($integerPart * $measureUnitQuantity) + $decimalPieces;
    }

    private function calculateAvailablePieces($purchaseProduct, float $measureUnitQuantity, int $companyId): float
    {
        $regularPieces = $this->calculatePieces($purchaseProduct->quantity ?? 0, $measureUnitQuantity);
        $freePieces = $this->calculatePieces($purchaseProduct->free_quantity ?? 0, $measureUnitQuantity);

        $purchaseReturnedPieces = $purchaseProduct->purchaseProductReturns->reduce(fn($carry, $return) => $carry + $this->calculatePieces($return->quantity ?? 0, $return->measureUnit->quantity ?? 1) + $this->calculatePieces($return->free_quantity ?? 0, $return->measureUnit->quantity ?? 1), 0);

        $soldPieces = $purchaseProduct->saleProducts->reduce(fn($carry, $sale) => $carry + $this->calculatePieces($sale->quantity ?? 0, $sale->measureUnit->quantity ?? 1) + $this->calculatePieces($sale->free_quantity ?? 0, $sale->measureUnit->quantity ?? 1), 0);

        $salesReturnedPieces = SalesReturnProduct::where('product_id', $purchaseProduct->product_id)
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->with('measureUnit')
            ->get()
            ->reduce(fn($carry, $return) => $carry + $this->calculatePieces($return->quantity ?? 0, $return->measureUnit->quantity ?? 1) + $this->calculatePieces($return->free_quantity ?? 0, $return->measureUnit->quantity ?? 1), 0);

        return max(0, ($regularPieces + $freePieces) - $purchaseReturnedPieces - $soldPieces + $salesReturnedPieces);
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
        $freeDecimal = $freeRemainingPieces > 0 ? (float) ('0.' . (int) $regularRemainingPieces) : 0;
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
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer|exists:companies,id',
                'purchase_id' => 'nullable|integer|exists:purchases,id',
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
                'purchase_return_products.*.purchase_product_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('purchase_products', 'id')->where(function ($query) use ($request) {
                        $query->where('company_id', $request->input('company_id'));
                        if ($request->input('purchase_id')) {
                            $query->where('purchase_id', $request->input('purchase_id'));
                        }
                    }),
                ],
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
                'purchase_return_products.*.field_values.*.*.purchase_product_id' => 'required_if:field_values,array|integer|exists:purchase_products,id',
                'purchase_return_products.*.field_values.*.*.product_field_id' => 'required_if:field_values,array|integer|exists:product_fields,id',
                'purchase_return_products.*.field_values.*.*.value' => 'required_if:field_values,array|string|max:255',
                'purchase_return_products.*.field_values.*.*.quantity_index' => 'required_if:field_values,array|integer|min:0',
                'purchase_return_products.*.field_values.*.*.quantity_type' => 'required_if:field_values,array|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

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
            if ($validated['return_entire_batch'] ?? false) {
                if (!$validated['purchase_id']) {
                    return response()->json(['error' => 'purchase_id is required when return_entire_batch is true'], 422);
                }
                $purchase = Purchase::findOrFail($validated['purchase_id']);
                if ($validated['purchase_bill_number'] && $validated['purchase_bill_number'] !== $purchase->purchase_bill_number) {
                    return response()->json(['error' => 'Purchase bill number does not match purchase record'], 422);
                }
                $validated['purchase_return_products'] = $purchase->purchaseProducts()
                    ->with(['measureUnit', 'fieldValues'])
                    ->orderBy('created_at')
                    ->get()
                    ->map(function ($product) use ($validated, $calculateQuantityInPieces) {
                        $measureUnitId = $product->measure_unit_id ?? ($validated['purchase_return_products'][0]['measure_unit_id'] ?? null);
                        if (!$measureUnitId) {
                            throw new \Exception("No measure unit specified for purchase_product_id {$product->id}");
                        }
                        $measureUnit = MeasureUnit::findOrFail($measureUnitId);
                        $unitQuantity = $measureUnit->quantity ?? 1;

                        // Calculate purchased pieces
                        $purchasedQuantityInPieces = $calculateQuantityInPieces($product->quantity, $product->free_quantity, $unitQuantity);


                        // Calculate returned pieces
                        $totalReturnedInPieces = PurchaseProductReturn::where('purchase_product_id', $product->id)
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at')
                            ->sum(function ($return) use ($calculateQuantityInPieces) {
                            $mu = MeasureUnit::findOrFail($return->measure_unit_id);
                            return $calculateQuantityInPieces($return->quantity, $return->free_quantity, $mu->quantity ?? 1);
                        });

                        // Calculate sold pieces
                        $soldQuantityInPieces = SaleProduct::where('purchase_product_id', $product->id)
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at')
                            ->sum(function ($sale) use ($calculateQuantityInPieces) {
                            $mu = MeasureUnit::findOrFail($sale->measure_unit_id);
                            return $calculateQuantityInPieces($sale->quantity, $sale->free_quantity, $mu->quantity ?? 1);
                        });

                        // Calculate sale-returned pieces
                        $salesReturnedInPieces = SalesReturnProduct::where('product_id', $product->product_id)
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at')
                            ->sum(function ($return) use ($calculateQuantityInPieces) {
                            $mu = MeasureUnit::findOrFail($return->measure_unit_id);
                            return $calculateQuantityInPieces($return->quantity, $return->free_quantity, $mu->quantity ?? 1);
                        });

                        // Available pieces
                        $availableQuantityInPieces = ($purchasedQuantityInPieces - $soldQuantityInPieces) + $salesReturnedInPieces - $totalReturnedInPieces;
                        if ($availableQuantityInPieces <= 0) {
                            return null;
                        }

                        $fieldValues = $product->fieldValues->groupBy('quantity_index')->map(function ($group) {
                            return $group->map(function ($field) {
                                return [
                                    'purchase_product_id' => $field->purchase_product_id,
                                    'product_field_id' => $field->product_field_id,
                                    'value' => $field->value,
                                    'quantity_index' => $field->quantity_index,
                                    'quantity_type' => $field->quantity_type,
                                ];
                            })->toArray();
                        })->values()->take(ceil($availableQuantityInPieces))->toArray();

                        return [
                            'purchase_product_id' => $product->id,
                            'product_id' => $product->product_id,
                            'product_name' => $product->product_name,
                            'purchase_product_code' => $product->product_code,
                            'mfd' => $product->mfd,
                            'customer_id' => $product->customer_id,
                            'quantity' => $product->quantity,
                            'free_quantity' => $product->free_quantity,
                            'price' => $product->price,
                            'discount_percent' => $product->discount_percent,
                            'discount_amount' => $product->discount_amount,
                            'amount' => ($product->quantity * ($product->price ?? 0)) - ($product->discount_amount ?? 0),
                            'is_vatable' => $product->is_vatable,
                            'measure_unit_id' => $measureUnit->id,
                            'expiry_date' => $product->expiry_date,
                            'field_values' => $fieldValues,
                        ];
                    })->filter()->toArray();
            }

            // Process purchase return products
            foreach ($validated['purchase_return_products'] as $index => $productData) {
                $regularQuantity = (float) ($productData['quantity'] ?? 0);
                $freeQuantity = (float) ($productData['free_quantity'] ?? 0);
                $totalQuantityInUOM = $regularQuantity + $freeQuantity;

                // Validate measure unit
                $measureUnit = MeasureUnit::findOrFail($productData['measure_unit_id']);
                $unitQuantity = $measureUnit->quantity ?? 1;
                Log::debug('Requested measure unit for product at index ' . $index, [
                    'measure_unit_id' => $productData['measure_unit_id'],
                    'unit_quantity' => $unitQuantity,
                    'product_id' => $productData['product_id'],
                    'quantity' => $regularQuantity,
                    'free_quantity' => $freeQuantity,
                    'total_quantity_in_uom' => $totalQuantityInUOM,
                ]);

                // Calculate requested pieces for availability check
                $requestedQuantityInPieces = $calculateQuantityInPieces($regularQuantity, $freeQuantity, $unitQuantity);

                $remainingQuantityInPieces = $requestedQuantityInPieces;

                // Normalize field_values
                $fieldValuesFlat = collect($productData['field_values'])->flatMap(function ($item) {
                    return is_array($item) && isset($item[0]['product_field_id']) ? $item : [$item];
                })->toArray();

                $hasFieldValues = !empty($fieldValuesFlat);
                $allocations = [];
                $usedQuantityIndexes = [];

                // Calculate total available pieces for the product
                $totalAvailablePieces = 0;
                $purchaseProductsQuery = PurchaseProduct::where('product_id', $productData['product_id'])
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->with([
                        'purchase' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']),
                        'purchaseProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']),
                        'saleProducts' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->with([
                            'saleReturnProducts' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->with([
                                'fieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])
                            ])
                        ]),
                        'measureUnit'
                    ]);

                if ($hasFieldValues) {
                    $purchaseProductsQuery->whereExists(function ($query) use ($validated) {
                        $query->select(DB::raw(1))
                            ->from('purchase_product_field_values')
                            ->whereColumn('purchase_product_field_values.purchase_product_id', 'purchase_products.id')
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at');
                    });
                } else {
                    $purchaseProductsQuery->whereNotExists(function ($query) use ($validated) {
                        $query->select(DB::raw(1))
                            ->from('purchase_product_field_values')
                            ->whereColumn('purchase_product_field_values.purchase_product_id', 'purchase_products.id')
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at');
                    });
                }

                if ($validated['purchase_bill_number']) {
                    $purchaseProductsQuery->whereHas('purchase', function ($query) use ($validated) {
                        $query->where('purchase_bill_number', $validated['purchase_bill_number']);
                    });
                }

                $purchaseProducts = $purchaseProductsQuery->orderBy('created_at')->get();

                if ($purchaseProducts->isEmpty()) {
                    return response()->json(['error' => "No purchase products found for product ID {$productData['product_id']} at index {$index}"], 404);
                }

                foreach ($purchaseProducts as $purchaseProduct) {
                    $purchaseMeasureUnit = $purchaseProduct->measureUnit;
                    if (!$purchaseMeasureUnit) {
                        Log::error('No measure unit found for purchase_product_id ' . $purchaseProduct->id, [
                            'purchase_product' => $purchaseProduct->toArray(),
                            'request_measure_unit_id' => $productData['measure_unit_id']
                        ]);
                        return response()->json(['error' => "Measure unit not found for purchase_product_id {$purchaseProduct->id} at index {$index}"], 404);
                    }
                    $purchaseUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                    // Calculate available pieces
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

                    $totalAvailablePieces += $availableQuantityInPieces;


                    Log::debug('Availability check for purchase_product_id ' . $purchaseProduct->id, [
                        'purchased_pieces' => $purchasedQuantityInPieces,
                        'sold_pieces' => $soldQuantityInPieces,
                        'sales_returned_pieces' => $salesReturnedInPieces,
                        'total_returned_pieces' => $totalReturnedInPieces,
                        'available_pieces' => $availableQuantityInPieces,
                        'total_available_pieces' => $totalAvailablePieces,
                        'requested_pieces' => $requestedQuantityInPieces,
                    ]);
                }

                // Check if total requested pieces exceed total available pieces
                if ($requestedQuantityInPieces > $totalAvailablePieces + 0.0001) {
                    return response()->json([
                        'error' => "Insufficient stock for product ID {$productData['product_id']} at index {$index}. Requested: {$totalQuantityInUOM} ({$requestedQuantityInPieces} pieces), Available: {$totalAvailablePieces} pieces"
                    ], 422);
                }

                if ($hasFieldValues) {
                    // Validate field_values structure
                    foreach ($fieldValuesFlat as $fv) {
                        if (!isset($fv['purchase_product_id']) || !is_numeric($fv['purchase_product_id'])) {
                            return response()->json(['error' => "Invalid or missing purchase_product_id in field_values at index {$index}"], 422);
                        }
                    }

                    // Group field_values by purchase_product_id and quantity_index
                    $groupedFieldValues = collect($fieldValuesFlat)
                        ->groupBy('purchase_product_id')
                        ->map(function ($group) {
                            return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                                return $fvGroup->map(function ($fv) {
                                    return [
                                        'product_field_id' => $fv['product_field_id'],
                                        'value' => $fv['value'],
                                        'quantity_index' => $fv['quantity_index'],
                                        'quantity_type' => $fv['quantity_type'],
                                        'purchase_product_id' => $fv['purchase_product_id'],
                                    ];
                                })->toArray();
                            })->toArray();
                        })->toArray();

                    // Validate field value sets
                    $fieldValueSets = collect($groupedFieldValues)->flatMap(function ($fvByIndex) {
                        return array_keys($fvByIndex);
                    })->count();

                    $requiredFieldValueSets = ceil($requestedQuantityInPieces);

                    Log::debug('Validating field values for product', [
                        'product_id' => $productData['product_id'],
                        'index' => $index,
                        'field_value_sets' => $fieldValueSets,
                        'required_field_value_sets' => $requiredFieldValueSets,
                        'requested_pieces' => $requestedQuantityInPieces,
                        'total_quantity_in_uom' => $totalQuantityInUOM,
                    ]);

                    if ($fieldValueSets != $requiredFieldValueSets) {
                        return response()->json([
                            'error' => "Number of field_values sets ({$fieldValueSets}) must equal total pieces ({$requiredFieldValueSets}) for product ID {$productData['product_id']} at index {$index}"
                        ], 422);
                    }

                    $purchaseProductIds = array_keys($groupedFieldValues);
                    $requiresFieldValues = PurchaseProductFieldValue::whereIn('purchase_product_id', $purchaseProductIds)
                        ->where('company_id', $validated['company_id'])
                        ->whereNull('deleted_at')
                        ->exists();

                    if (!$requiresFieldValues) {
                        return response()->json([
                            'error' => "Field values provided for product ID {$productData['product_id']} at index {$index}, but no field values are required."
                        ], 422);
                    }

                    // Validate product_fields_bill if provided
                    if (isset($productData['product_fields_bill'])) {
                        foreach ($productData['product_fields_bill'] as $billSet) {
                            foreach ($billSet as $field) {
                                if ($field['purchase_product_id'] != $productData['purchase_product_id']) {
                                    return response()->json([
                                        'error' => "Incorrect purchase_product_id {$field['purchase_product_id']} in product_fields_bill for product ID {$productData['product_id']} at index {$index}"
                                    ], 422);
                                }
                            }
                        }
                    }

                    foreach ($purchaseProducts as $purchaseProduct) {
                        $fvByIndex = $groupedFieldValues[$purchaseProduct->id] ?? [];
                        if (empty($fvByIndex) || $remainingQuantityInPieces <= 0) {
                            continue;
                        }

                        $purchaseMeasureUnit = $purchaseProduct->measureUnit;
                        $purchaseUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                        // Calculate available pieces
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
                        if ($availableQuantityInPieces <= 0) {
                            continue;
                        }

                        // Validate field values
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
                        if ($purchaseProduct->purchaseProductReturns->isNotEmpty()) {
                            $returnIds = $purchaseProduct->purchaseProductReturns->pluck('id');
                            $unavailableQuantityIndices = PurchaseReturnProductFieldValue::whereIn('purchase_return_product_id', $returnIds)
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

                        foreach ($fvByIndex as $quantityIndex => $fvSet) {
                            if (in_array($quantityIndex, $unavailableQuantityIndices) || (!isset($existingFieldValues[$quantityIndex]) && !isset($saleReturnFieldValues[$quantityIndex]))) {
                                return response()->json(['error' => "Invalid or already returned/sold quantity_index {$quantityIndex} for purchase_product_id {$purchaseProduct->id} at index {$index}"], 422);
                            }
                            if (in_array($quantityIndex, $usedQuantityIndexes[$purchaseProduct->id] ?? [])) {
                                return response()->json(['error' => "Duplicate quantity_index {$quantityIndex} for purchase_product_id {$purchaseProduct->id} at index {$index}"], 422);
                            }
                            $providedFieldValues = collect($fvSet)->pluck('value', 'product_field_id')->toArray();
                            $expectedFieldValues = $existingFieldValues[$quantityIndex] ?? $saleReturnFieldValues[$quantityIndex] ?? [];
                            if ($providedFieldValues != $expectedFieldValues) {
                                return response()->json(['error' => "Field values for quantity_index {$quantityIndex} do not match for purchase_product_id {$purchaseProduct->id} at index {$index}"], 422);
                            }
                            foreach ($fvSet as $fv) {
                                if ($fv['quantity_type'] === 'free' && $freeQuantity == 0) {
                                    return response()->json(['error' => "quantity_type 'free' is not allowed when free_quantity is 0 for quantity_index {$quantityIndex} at index {$index}"], 422);
                                }
                                if ($fv['quantity_type'] === 'regular' && $regularQuantity == 0) {
                                    return response()->json(['error' => "quantity_type 'regular' is not allowed when quantity is 0 for quantity_index {$quantityIndex} at index {$index}"], 422);
                                }
                            }
                            $usedQuantityIndexes[$purchaseProduct->id][] = $quantityIndex;
                        }

                        // Allocate pieces
                        $allocateQuantityInPieces = min($remainingQuantityInPieces, $availableQuantityInPieces);
                        if ($allocateQuantityInPieces <= 0) {
                            continue;
                        }

                        // Save quantities directly from payload
                        $allocations[] = [
                            'purchase_product_id' => $purchaseProduct->id,
                            'quantity' => $regularQuantity,
                            'free_quantity' => $freeQuantity,
                            'field_values' => collect($fvByIndex)->take(ceil($allocateQuantityInPieces))->toArray(),
                            'mfd' => $purchaseProduct->mfd ?? ($productData['mfd'] ?? null),
                            'expiry_date' => $purchaseProduct->expiry_date ?? ($productData['expiry_date'] ?? null),
                        ];

                        $remainingQuantityInPieces -= $allocateQuantityInPieces;
                    }
                } else {
                    foreach ($purchaseProducts as $purchaseProduct) {
                        if ($remainingQuantityInPieces <= 0) {
                            break;
                        }

                        $purchaseMeasureUnit = $purchaseProduct->measureUnit;
                        if (!$purchaseMeasureUnit) {
                            Log::error('No measure unit found for purchase_product_id ' . $purchaseProduct->id, [
                                'purchase_product' => $purchaseProduct->toArray(),
                                'request_measure_unit_id' => $productData['measure_unit_id']
                            ]);
                            return response()->json(['error' => "Measure unit not found for purchase_product_id {$purchaseProduct->id} at index {$index}"], 404);
                        }
                        $purchaseUnitQuantity = $purchaseMeasureUnit->quantity ?? 1;

                        // Calculate available pieces
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
                        if ($availableQuantityInPieces <= 0) {
                            continue;
                        }

                        // Allocate pieces
                        $allocateQuantityInPieces = min($remainingQuantityInPieces, $availableQuantityInPieces);
                        if ($allocateQuantityInPieces <= 0) {
                            continue;
                        }

                        // Save quantities directly from payload
                        $allocations[] = [
                            'purchase_product_id' => $purchaseProduct->id,
                            'quantity' => $regularQuantity,
                            'free_quantity' => $freeQuantity,
                            'mfd' => $purchaseProduct->mfd ?? ($productData['mfd'] ?? null),
                            'expiry_date' => $purchaseProduct->expiry_date ?? ($productData['expiry_date'] ?? null),
                            'field_values' => [],
                        ];

                        $remainingQuantityInPieces -= $allocateQuantityInPieces;
                    }
                }

                if ($remainingQuantityInPieces > 0.0001) {
                    return response()->json([
                        'error' => "Insufficient stock for product ID {$productData['product_id']} at index {$index}. Requested: {$totalQuantityInUOM} ({$requestedQuantityInPieces} pieces), Available: {$totalAvailablePieces} pieces"
                    ], 422);
                }

                foreach ($allocations as $allocation) {
                    $purchaseProduct = PurchaseProduct::findOrFail($allocation['purchase_product_id']);
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
                        'purchase_product_id' => $allocation['purchase_product_id'],
                        'product_id' => $productData['product_id'],
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
                }
            }

            // Process transaction
            $purchaseReturn = DB::transaction(function () use ($validated, $purchases, $processedProducts) {
                $purchaseReturnData = collect($validated)->except(['purchase_return_products', 'return_entire_batch'])->filter()->toArray();
                $purchaseReturnData['company_id'] = $validated['company_id']; // Ensure correct company_id

                $purchaseReturn = PurchaseReturn::create($purchaseReturnData);

                $balanceUpdates = [];

                foreach ($processedProducts as $productData) {
                    $purchaseProductId = $productData['purchase_product_id'];
                    $purchaseProduct = PurchaseProduct::findOrFail($purchaseProductId);
                    $purchaseId = $purchaseProduct->purchase_id;
                    $purchase = $purchases[$purchaseId] ?? Purchase::findOrFail($purchaseId);

                    $productDataFiltered = collect($productData)->except(['field_values', 'purchase_id', 'purchase_bill_number', 'allocated_quantity_in_pieces', 'remaining_quantity_in_pieces'])->filter()->toArray();
                    $productDataFiltered['company_id'] = $validated['company_id'];

                    $purchaseReturnProduct = $purchaseReturn->purchaseReturnProducts()->create($productDataFiltered);

                    if (!empty($productData['field_values'])) {
                        Log::debug('Storing field_values for purchase return product', [
                            'purchase_return_product_id' => $purchaseReturnProduct->id,
                            'field_values' => $productData['field_values'],
                        ]);
                        foreach ($productData['field_values'] as $quantityIndex => $fvSet) {
                            foreach ($fvSet as $fv) {
                                PurchaseReturnProductFieldValue::create([
                                    'purchase_return_product_id' => $purchaseReturnProduct->id,
                                    'product_field_id' => $fv['product_field_id'],
                                    'value' => $fv['value'],
                                    'product_id' => $purchaseReturnProduct->product_id,
                                    'company_id' => $validated['company_id'],
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

                foreach ($balanceUpdates as $purchaseId => $returnValue) {
                    $purchase = $purchases[$purchaseId] ?? Purchase::findOrFail($purchaseId);
                    $purchase->balance -= $returnValue;
                    $purchase->save();
                }

                PurchaseReturnHistory::create([
                    'purchase_return_id' => $purchaseReturn->id,
                    'action' => 'created',
                    'data' => array_merge($purchaseReturnData, ['purchase_return_products' => $processedProducts]),
                ]);

                return $purchaseReturn->load([
                    'purchaseReturnProducts' => function ($query) {
                        $query->select('id', 'purchase_return_id', 'purchase_product_id', 'product_id', 'product_name', 'purchase_product_code', 'quantity', 'free_quantity', 'price', 'discount_percent', 'discount_amount', 'amount', 'is_vatable', 'measure_unit_id', 'expiry_date', 'mfd', 'customer_id');
                    },
                    'purchaseReturnProducts.fieldValues' => fn($query) => $query->orderBy('quantity_index')->orderBy('product_field_id'),
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


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'purchase_id' => 'required|integer|exists:purchases,id',
                'customer_id' => 'required|integer|exists:customers,id',
                'customer_name' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                'address' => 'nullable|string|max:255',
                'invoice_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('purchase_returns')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->company_id)
                                ->whereNull('deleted_at');

                        }),
                ],
                'customer_contact' => 'nullable|string|max:255',
                'purchase_return_type' => 'nullable|string|max:255',
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
                'invoice_date_bs' => 'nullable|string|max:255',
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
                'purchase_return_products.*.expiry_date' => 'nullable|string|max:255',
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
                        $returnedIndices = PurchaseReturnProductFieldValue::whereIn(
                            'purchase_return_product_id',
                            PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                                ->whereNull('deleted_at')
                                ->where('id', '!=', $productData['id'] ?? 0)
                                ->pluck('id')
                        )
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
                'data' => $item->load([
                    'purchaseReturnProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index')->orderBy('product_field_id');
                    }
                ])
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

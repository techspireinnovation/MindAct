<?php

namespace App\Http\Controllers;

use App\Models\ProductFieldValue;
use App\Helpers\Helper;
use App\Helpers\PurchaseReturnHelper;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\PurchaseProductFieldValue;
use App\Models\PurchaseReturnHistory;
use App\Models\SalesReturnProduct;
use App\Models\MeasureUnit;
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

            // Subquery for purchased quantities
            $purchaseSubQuery = DB::table('purchase_products')
                ->select([
                    'purchase_products.product_id',
                    DB::raw('MIN(purchase_products.product_name) as product_name'),
                    DB::raw('MIN(purchase_products.product_code) as product_code'),
                    DB::raw('MIN(products.measure_unit_id) as measure_unit_id'),
                    DB::raw('MIN(product_measure_units.name) as measure_unit_name'),
                    DB::raw('MIN(product_measure_units.quantity) as measure_unit_quantity'),
                    DB::raw('MIN(purchase_products.price) as min_price'),
                    DB::raw('MAX(purchase_products.is_vatable) as is_vatable'),
                    DB::raw('GROUP_CONCAT(DISTINCT purchase_products.expiry_date ORDER BY purchase_products.expiry_date) as expiry_dates'),
                    DB::raw('SUM((purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) * COALESCE(purchase_measure_units.quantity, 1)) as purchased_quantity')
                ])
                ->leftJoin('products', 'purchase_products.product_id', '=', 'products.id')
                ->leftJoin('measure_units as product_measure_units', 'products.measure_unit_id', '=', 'product_measure_units.id')
                ->join('measure_units as purchase_measure_units', 'purchase_products.measure_unit_id', '=', 'purchase_measure_units.id')
                ->join('purchases', 'purchase_products.purchase_id', '=', 'purchases.id')
                ->whereNull('purchase_products.deleted_at')
                ->where('purchase_products.company_id', $companyId)
                ->where('purchases.company_id', $companyId)
                ->whereNull('purchases.deleted_at')
                ->where(function ($query) use ($companyId) {
                    $query->where('products.company_id', $companyId)
                          ->orWhereNull('products.company_id');
                })
                ->whereNull('products.deleted_at')
                ->where('purchase_measure_units.company_id', $companyId)
                ->whereNull('purchase_measure_units.deleted_at');

            // Apply filters with proper bindings
            if ($productCode) {
                $purchaseSubQuery->where('purchase_products.product_code', $productCode);
            }

            if ($productName) {
                $purchaseSubQuery->where(function ($q) use ($productName) {
                    $q->whereRaw('LOWER(purchase_products.product_name) LIKE ?', ["%{$productName}%"])
                      ->orWhereRaw('LOWER(products.name) LIKE ?', ["%{$productName}%"]);
                });
            }

            if ($barcode) {
                $purchaseSubQuery->whereIn('purchase_products.id', function ($subQuery) use ($barcode, $companyId) {
                    $subQuery->select('purchase_product_id')
                             ->from('purchase_product_field_values')
                             ->where('company_id', $companyId)
                             ->whereNull('deleted_at')
                             ->where('value', $barcode)
                             ->where('product_field_id', env('BARCODE_FIELD_ID', 1));
                });
            }

            if ($purchaseBillNumber) {
                $purchaseSubQuery->where('purchases.purchase_bill_number', $purchaseBillNumber);
            }

            $purchaseSubQuery->groupBy('purchase_products.product_id');

            // Subquery for sale quantities
            $saleSubQuery = DB::table('sale_products')
                ->select([
                    'sale_products.product_id',
                    DB::raw('SUM((sale_products.quantity + COALESCE(sale_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)) as sale_quantity')
                ])
                ->join('measure_units', 'sale_products.measure_unit_id', '=', 'measure_units.id')
                ->whereNull('sale_products.deleted_at')
                ->where('sale_products.company_id', $companyId)
                ->where('measure_units.company_id', $companyId)
                ->whereNull('measure_units.deleted_at')
                ->groupBy('sale_products.product_id');

            // Subquery for purchase return quantities
            $returnSubQuery = DB::table('purchase_product_returns')
                ->select([
                    'purchase_products.product_id',
                    DB::raw('SUM((purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)) as return_quantity')
                ])
                ->join('purchase_products', 'purchase_product_returns.purchase_product_id', '=', 'purchase_products.id')
                ->join('measure_units', 'purchase_product_returns.measure_unit_id', '=', 'measure_units.id')
                ->whereNull('purchase_product_returns.deleted_at')
                ->where('purchase_product_returns.company_id', $companyId)
                ->where('measure_units.company_id', $companyId)
                ->whereNull('measure_units.deleted_at')
                ->groupBy('purchase_products.product_id');

            // Subquery for sales return quantities
            $salesReturnSubQuery = DB::table('sales_return_products')
                ->select([
                    'sales_return_products.product_id',
                    DB::raw('SUM((sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)) as sales_return_quantity')
                ])
                ->join('measure_units', 'sales_return_products.measure_unit_id', '=', 'measure_units.id')
                ->whereNull('sales_return_products.deleted_at')
                ->where('sales_return_products.company_id', $companyId)
                ->where('measure_units.company_id', $companyId)
                ->whereNull('measure_units.deleted_at')
                ->groupBy('sales_return_products.product_id');

            // Main query to combine totals
            $mainQuery = DB::table(DB::raw("({$purchaseSubQuery->toSql()}) as purchase_totals"))
                ->select([
                    'purchase_totals.product_id',
                    DB::raw('COALESCE(products.name, purchase_totals.product_name) as product_name'),
                    'purchase_totals.product_code',
                    'purchase_totals.min_price',
                    'purchase_totals.is_vatable',
                    'purchase_totals.measure_unit_id',
                    'purchase_totals.measure_unit_name',
                    'purchase_totals.measure_unit_quantity',
                    'purchase_totals.purchased_quantity',
                    'purchase_totals.expiry_dates',
                    DB::raw('COALESCE(return_totals.return_quantity, 0) as return_quantity'),
                    DB::raw('COALESCE(sale_subquery.sale_quantity, 0) as sale_quantity'),
                    DB::raw('COALESCE(sales_return_totals.sales_return_quantity, 0) as sales_return_quantity'),
                    DB::raw('purchase_totals.purchased_quantity - 
                             COALESCE(return_totals.return_quantity, 0) - 
                             COALESCE(sale_subquery.sale_quantity, 0) + 
                             COALESCE(sales_return_totals.sales_return_quantity, 0) as available_quantity')
                ])
                ->mergeBindings($purchaseSubQuery)
                ->leftJoin('products', function ($join) use ($companyId) {
                    $join->on('purchase_totals.product_id', '=', 'products.id')
                         ->where('products.company_id', '=', $companyId)
                         ->whereNull('products.deleted_at');
                })
                ->leftJoin(DB::raw("({$saleSubQuery->toSql()}) as sale_subquery"), 'purchase_totals.product_id', '=', 'sale_subquery.product_id')
                ->mergeBindings($saleSubQuery)
                ->leftJoin(DB::raw("({$returnSubQuery->toSql()}) as return_totals"), 'purchase_totals.product_id', '=', 'return_totals.product_id')
                ->mergeBindings($returnSubQuery)
                ->leftJoin(DB::raw("({$salesReturnSubQuery->toSql()}) as sales_return_totals"), 'purchase_totals.product_id', '=', 'sales_return_totals.product_id')
                ->mergeBindings($salesReturnSubQuery)
                ->havingRaw('available_quantity > 0');

            // Log main query results for debugging
            $products = $mainQuery->get();
            Log::debug('Main query results', [
                'products' => $products,
                'query' => $mainQuery->toSql(),
                'bindings' => $mainQuery->getBindings()
            ]);

            if ($products->isEmpty()) {
                Log::info('No products found in main query', [
                    'company_id' => $companyId,
                    'filters' => $request->only(['product_code', 'product_name', 'barcode', 'purchase_bill_number']),
                    'query_log' => DB::getQueryLog()
                ]);
                return response()->json(['error' => 'No products found matching the criteria'], 404);
            }

            $productIds = $products->pluck('product_id')->toArray();

            // Fetch purchase products with FIFO ordering
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
                    DB::raw('COALESCE(SUM((purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)) * COALESCE(return_measure_units.quantity, 1)), 0) as return_quantity'),
                    DB::raw('COALESCE(SUM((sale_products.quantity + COALESCE(sale_products.free_quantity, 0)) * COALESCE(sale_measure_units.quantity, 1)), 0) as sale_quantity'),
                    DB::raw('COALESCE(SUM((sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)) * COALESCE(sales_return_measure_units.quantity, 1)), 0) as sales_return_quantity'),
                    DB::raw('(
                        ((purchase_products.quantity + COALESCE(purchase_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1))
                        - COALESCE(SUM((purchase_product_returns.quantity + COALESCE(purchase_product_returns.free_quantity, 0)) * COALESCE(return_measure_units.quantity, 1)), 0)
                        - COALESCE(SUM((sale_products.quantity + COALESCE(sale_products.free_quantity, 0)) * COALESCE(sale_measure_units.quantity, 1)), 0)
                        + COALESCE(SUM((sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)) * COALESCE(sales_return_measure_units.quantity, 1)), 0)
                    ) as available_quantity')
                ])
                ->join('measure_units', 'purchase_products.measure_unit_id', '=', 'measure_units.id')
                ->leftJoin('purchases', function ($join) use ($companyId) {
                    $join->on('purchase_products.purchase_id', '=', 'purchases.id')
                         ->where('purchases.company_id', $companyId)
                         ->whereNull('purchases.deleted_at');
                })
                ->leftJoin('purchase_product_returns', function ($join) use ($companyId) {
                    $join->on('purchase_products.id', '=', 'purchase_product_returns.purchase_product_id')
                         ->whereNull('purchase_product_returns.deleted_at')
                         ->where('purchase_product_returns.company_id', $companyId);
                })
                ->leftJoin('measure_units as return_measure_units', 'purchase_product_returns.measure_unit_id', '=', 'return_measure_units.id')
                ->leftJoin('sale_products', function ($join) use ($companyId) {
                    $join->on('purchase_products.id', '=', 'sale_products.purchase_product_id')
                         ->whereNull('sale_products.deleted_at')
                         ->where('sale_products.company_id', $companyId);
                })
                ->leftJoin('sales_return_products', function ($join) use ($companyId) {
                    $join->on('sale_products.id', '=', 'sales_return_products.sale_product_id')
                         ->whereNull('sales_return_products.deleted_at')
                         ->where('sales_return_products.company_id', $companyId);
                })
                ->leftJoin('measure_units as sale_measure_units', 'sale_products.measure_unit_id', '=', 'sale_measure_units.id')
                ->leftJoin('measure_units as sales_return_measure_units', 'sales_return_products.measure_unit_id', '=', 'sales_return_measure_units.id')
                ->whereIn('purchase_products.product_id', $productIds)
                ->where('purchase_products.company_id', $companyId)
                ->whereNull('purchase_products.deleted_at')
                ->where('measure_units.company_id', $companyId)
                ->whereNull('measure_units.deleted_at')
                ->groupBy([
                    'purchase_products.id',
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
                    'measure_units.name',
                    'measure_units.quantity',
                    'purchases.purchase_bill_number',
                    'purchases.invoice_date'
                ])
                ->havingRaw('available_quantity > 0')
                ->orderBy('purchases.invoice_date', 'ASC')
                ->orderBy('purchase_products.created_at', 'ASC');

            // Log purchase products query results
            $purchaseProducts = $purchaseProductsQuery->get();
            Log::debug('Purchase products query results', [
                'purchase_products' => $purchaseProducts,
                'query' => $purchaseProductsQuery->toSql(),
                'bindings' => $purchaseProductsQuery->getBindings()
            ]);

            // Fetch sold quantity indexes
            $soldQuantityIndexes = DB::table('sales_product_field_values')
                ->select([
                    'sale_products.purchase_product_id',
                    'sales_product_field_values.quantity_index'
                ])
                ->join('sale_products', 'sales_product_field_values.sale_product_id', '=', 'sale_products.id')
                ->whereIn('sale_products.purchase_product_id', $purchaseProducts->pluck('purchase_product_id'))
                ->where('sale_products.company_id', $companyId)
                ->whereNull('sale_products.deleted_at')
                ->distinct()
                ->get()
                ->groupBy('purchase_product_id')
                ->map(function ($group) {
                    return $group->pluck('quantity_index')->toArray();
                });

            // Fetch purchase return quantity indexes
            $returnedQuantityIndexes = DB::table('purchase_return_product_field_values')
                ->select([
                    'purchase_product_returns.purchase_product_id',
                    'purchase_return_product_field_values.quantity_index'
                ])
                ->join('purchase_product_returns', 'purchase_return_product_field_values.purchase_return_product_id', '=', 'purchase_product_returns.id')
                ->whereIn('purchase_product_returns.purchase_product_id', $purchaseProducts->pluck('purchase_product_id'))
                ->where('purchase_product_returns.company_id', $companyId)
                ->whereNull('purchase_product_returns.deleted_at')
                ->distinct()
                ->get()
                ->groupBy('purchase_product_id')
                ->map(function ($group) {
                    return $group->pluck('quantity_index')->toArray();
                });

            Log::debug('Quantity indexes', [
                'sold_quantity_indexes' => $soldQuantityIndexes,
                'returned_quantity_indexes' => $returnedQuantityIndexes
            ]);

            // Fetch field values
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
                ->leftJoin('product_fields', function ($join) use ($companyId) {
                    $join->on('purchase_product_field_values.product_field_id', '=', 'product_fields.id')
                         ->where('product_fields.company_id', $companyId);
                })
                ->leftJoin('purchase_products', 'purchase_product_field_values.purchase_product_id', '=', 'purchase_products.id')
                ->leftJoin('purchases', 'purchase_products.purchase_id', '=', 'purchases.id')
                ->whereIn('purchase_product_field_values.purchase_product_id', $purchaseProducts->pluck('purchase_product_id'))
                ->where('purchase_product_field_values.company_id', $companyId)
                ->whereNull('purchase_product_field_values.deleted_at')
                ->orderBy('purchase_product_field_values.quantity_index', 'ASC')
                ->get()
                ->groupBy('purchase_product_id');

            // Fetch sales return field values
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
                ->leftJoin('product_fields', function ($join) use ($companyId) {
                    $join->on('sale_return_product_field_values.product_field_id', '=', 'product_fields.id')
                         ->where('product_fields.company_id', $companyId);
                })
                ->whereIn('sale_products.purchase_product_id', $purchaseProducts->pluck('purchase_product_id'))
                ->where('sale_return_product_field_values.company_id', $companyId)
                ->whereNull('sale_return_product_field_values.deleted_at')
                ->get()
                ->groupBy('purchase_product_id');

            // Build response
            $result = $products->map(function ($product) use ($purchaseProducts, $fieldValues, $saleReturnFieldValues, $soldQuantityIndexes, $returnedQuantityIndexes, $companyId) {
                $productId = $product->product_id;
                $productFieldValues = collect();

                $productPurchaseProducts = $purchaseProducts->filter(function ($pp) use ($productId) {
                    return $pp->product_id == $productId;
                })->map(function ($pp) use ($fieldValues, $saleReturnFieldValues, $soldQuantityIndexes, $returnedQuantityIndexes, &$productFieldValues) {
                    $availableUnits = (int) $pp->available_quantity;
                    if ($availableUnits > 0) {
                        if (isset($fieldValues[$pp->purchase_product_id])) {
                            $soldIndexes = $soldQuantityIndexes[$pp->purchase_product_id] ?? [];
                            $returnedIndexes = $returnedQuantityIndexes[$pp->purchase_product_id] ?? [];
                            $excludedIndexes = array_unique(array_merge($soldIndexes, $returnedIndexes));

                            $ppFieldValues = $fieldValues[$pp->purchase_product_id]
                                ->filter(function ($fv) use ($excludedIndexes) {
                                    return !in_array($fv->quantity_index, $excludedIndexes);
                                })
                                ->groupBy('quantity_index')
                                ->take($availableUnits)
                                ->flatten(1)
                                ->map(function ($fv) {
                                    return [
                                        'purchase_id' => $fv->purchase_id,
                                        'purchase_bill_number' => $fv->purchase_bill_number ?? '',
                                        'purchase_product_id' => $fv->purchase_product_id,
                                        'product_field_id' => $fv->product_field_id,
                                        'name' => $fv->product_field_name ?? 'N/A',
                                        'value' => $fv->value,
                                        'quantity_index' => $fv->quantity_index
                                    ];
                                })->values();
                            $productFieldValues = $productFieldValues->merge($ppFieldValues);
                        }

                        if (isset($saleReturnFieldValues[$pp->purchase_product_id])) {
                            $ppSaleReturnFieldValues = $saleReturnFieldValues[$pp->purchase_product_id]
                                ->groupBy('quantity_index')
                                ->take($availableUnits)
                                ->flatten(1)
                                ->map(function ($fv) {
                                    return [
                                        'purchase_id' => null,
                                        'purchase_bill_number' => '',
                                        'purchase_product_id' => $fv->purchase_product_id,
                                        'product_field_id' => $fv->product_field_id,
                                        'name' => $fv->product_field_name ?? 'N/A',
                                        'value' => $fv->value,
                                        'quantity_index' => $fv->quantity_index
                                    ];
                                })->values();
                            $productFieldValues = $productFieldValues->merge($ppSaleReturnFieldValues);
                        }
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
                        'available_quantity' => max($pp->available_quantity, 0),
                        'return_quantity' => $pp->return_quantity,
                        'sale_quantity' => $pp->sale_quantity,
                        'sales_return_quantity' => $pp->sales_return_quantity,
                        'expiry_date' => $pp->expiry_date
                    ];
                })->filter(function ($pp) {
                    return $pp['available_quantity'] > 0;
                })->values()->toArray();

                if (empty($productPurchaseProducts)) {
                    Log::info('No purchase products found', [
                        'product_id' => $productId,
                        'company_id' => $companyId
                    ]);
                    return null;
                }

                return [
                    'product_id' => $product->product_id,
                    'product_name' => $product->product_name,
                    'product_code' => $product->product_code,
                    'min_price' => $product->min_price,
                    'is_vatable' => (bool) $product->is_vatable,
                    'measure_unit_id' => $product->measure_unit_id,
                    'measure_unit_name' => $product->measure_unit_name,
                    'measure_unit_quantity' => $product->measure_unit_quantity,
                    'purchased_quantity' => $product->purchased_quantity,
                    'return_quantity' => $product->return_quantity,
                    'sale_quantity' => $product->sale_quantity,
                    'sales_return_quantity' => $product->sales_return_quantity,
                    'available_quantity' => max($product->available_quantity, 0),
                    'expiry_dates' => array_filter(explode(',', $product->expiry_dates)),
                    'field_values' => $productFieldValues->values()->toArray(),
                    'purchase_products' => $productPurchaseProducts
                ];
            })->filter()->values()->toArray();

            if (empty($result)) {
                Log::info('No products with available quantity found', [
                    'company_id' => $companyId,
                    'filters' => $request->only(['product_code', 'product_name', 'barcode', 'purchase_bill_number']),
                    'query_log' => DB::getQueryLog()
                ]);
                return response()->json(['error' => 'No products with available quantity found'], 404);
            }

            return response()->json([
                'message' => 'Product details retrieved successfully',
                'data' => $result,
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
            return response()->json(['error' => 'An unexpected error occurred: ' . (config('app.debug') ? $e->getMessage() : 'An error occurred')], 500);
        } finally {
            DB::disableQueryLog();
        }
    }


    public function storePurchaseReturnByInput(Request $request): JsonResponse
    {
        try {
            // Define validation rules
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer|exists:companies,id', // Made required to match error context
                'customer_id' => 'nullable|integer|exists:customers,id',
                'customer_name' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                'address' => 'nullable|string|max:255',
                'customer_contact' => 'nullable|string|max:255',
                'purchase_number' => 'nullable|string|max:255',
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reason' => 'nullable|string|in:damaged,defective,incorrect,expired,other',
                'store_id' => 'required|integer|exists:stores,id',
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
                'purchase_return_products.*.quantity' => 'required|numeric|min:1',
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

            // Process purchase return products
            foreach ($validated['purchase_return_products'] as $index => $productData) {
                $totalQuantity = $productData['quantity'];
                $requestedQuantity = $totalQuantity + ($productData['free_quantity'] ?? 0);

                // Normalize field_values to a flat array
                $fieldValuesFlat = collect($productData['field_values'])->flatMap(function ($item) {
                    return is_array($item) && isset($item[0]['product_field_id']) ? $item : [$item];
                })->toArray();

                // Validate field_values for purchase_product_id
                foreach ($fieldValuesFlat as $fv) {
                    if (!isset($fv['purchase_product_id']) || !is_numeric($fv['purchase_product_id'])) {
                        return response()->json([
                            'error' => "Invalid or missing purchase_product_id in field_values at index {$index}"
                        ], 422);
                    }
                }

                // Group by purchase_product_id and quantity_index
                $groupedFieldValues = collect($fieldValuesFlat)
                    ->groupBy('purchase_product_id')
                    ->map(function ($group) {
                        return $group->groupBy('quantity_index')->map(function ($fvGroup) {
                            return $fvGroup->map(function ($fv) {
                                return [
                                    'product_field_id' => $fv['product_field_id'],
                                    'value' => $fv['value'],
                                    'quantity_index' => $fv['quantity_index'],
                                    'purchase_product_id' => $fv['purchase_product_id'],
                                ];
                            })->toArray();
                        })->toArray();
                    })->toArray();

                $hasFieldValues = !empty($fieldValuesFlat);
                $requiresFieldValues = false;
                $purchaseProductIds = array_keys($groupedFieldValues);

                if (!empty($purchaseProductIds)) {
                    $requiresFieldValues = PurchaseProductFieldValue::whereIn('purchase_product_id', $purchaseProductIds)
                        ->whereNull('deleted_at')
                        ->exists();
                }

                if (!$hasFieldValues && $requiresFieldValues) {
                    return response()->json([
                        'error' => "Field values are required for product ID {$productData['product_id']} at index {$index} as it has associated field values."
                    ], 422);
                }

                if ($hasFieldValues && !$requiresFieldValues) {
                    return response()->json([
                        'error' => "Field values provided for product ID {$productData['product_id']} at index {$index}, but no field values are required."
                    ], 422);
                }

                $remainingQuantity = $totalQuantity;
                $allocations = [];
                $usedQuantityIndexes = [];

                if ($hasFieldValues) {
                    $fieldValueSets = collect($groupedFieldValues)->flatMap(function ($fvByIndex) {
                        return array_keys($fvByIndex);
                    })->count();

                    if ($fieldValueSets != $totalQuantity) {
                        return response()->json([
                            'error' => "Number of field_values sets ({$fieldValueSets}) must equal quantity to return ({$totalQuantity}) at index {$index}"
                        ], 422);
                    }

                    foreach ($groupedFieldValues as $purchaseProductId => $fvByIndex) {
                        $purchaseProduct = PurchaseProduct::where('id', $purchaseProductId)
                            ->where('purchase_products.product_id', $productData['product_id'])
                            ->where('purchase_products.company_id', $validated['company_id'])
                            ->whereNull('purchase_products.deleted_at')
                            ->with([
                                'purchase' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']),
                                'purchaseProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']),
                                'fieldValues' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']),
                                'saleProducts' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->with(['saleProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])]),
                            ])
                            ->first();

                        if (!$purchaseProduct) {
                            return response()->json([
                                'error' => "Purchase product ID {$purchaseProductId} not found for product ID {$productData['product_id']} at index {$index}"
                            ], 404);
                        }

                        $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;

                        // Calculate available quantity in pieces
                        $measureUnit = \App\Models\MeasureUnit::find($purchaseProduct->measure_unit_id);
                        if (!$measureUnit) {
                            return response()->json([
                                'error' => "Measure unit not found for purchase product ID {$purchaseProductId} at index {$index}"
                            ], 404);
                        }
                        $purchasedQuantityInPieces = ($purchaseProduct->quantity + ($purchaseProduct->free_quantity ?? 0)) * ($measureUnit->quantity ?? 1);
                        $purchaseReturnedQuantity = \App\Models\PurchaseProductReturn::where('purchase_product_id', $purchaseProductId)
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at')
                            ->sum(DB::raw('(quantity + COALESCE(free_quantity, 0)) * COALESCE((SELECT quantity FROM measure_units WHERE id = purchase_product_returns.measure_unit_id), 1)'));
                        $soldQuantity = \App\Models\SaleProduct::where('purchase_product_id', $purchaseProductId)
                            ->where('sale_products.company_id', $validated['company_id'])
                            ->whereNull('sale_products.deleted_at')
                            ->join('measure_units', 'sale_products.measure_unit_id', '=', 'measure_units.id')
                            ->sum(DB::raw('(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)'));
                        $salesReturnedQuantity = \App\Models\SalesReturnProduct::where('product_id', $productData['product_id'])
                            ->where('sales_return_products.company_id', $validated['company_id'])
                            ->whereNull('sales_return_products.deleted_at')
                            ->join('measure_units', 'sales_return_products.measure_unit_id', '=', 'measure_units.id')
                            ->sum(DB::raw('(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)'));

                        $availableQuantityInPieces = $purchasedQuantityInPieces - $purchaseReturnedQuantity - $soldQuantity + $salesReturnedQuantity;
                        $availableQuantityInUOM = floor($availableQuantityInPieces / ($measureUnit->quantity ?? 1));

                        // Validate field values and quantity_index
                        $existingFieldValues = $purchaseProduct->fieldValues
                            ->groupBy('quantity_index')
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
                            if (in_array($quantityIndex, $unavailableQuantityIndices) || !isset($existingFieldValues[$quantityIndex])) {
                                return response()->json([
                                    'error' => "Invalid or already returned/sold quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} at index {$index}"
                                ], 422);
                            }
                            if (in_array($quantityIndex, $usedQuantityIndexes[$purchaseProductId] ?? [])) {
                                return response()->json([
                                    'error' => "Duplicate quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} at index {$index}"
                                ], 422);
                            }
                            $providedFieldValues = collect($fvSet)->pluck('value', 'product_field_id')->toArray();
                            if ($providedFieldValues != $existingFieldValues[$quantityIndex]) {
                                return response()->json([
                                    'error' => "Field values for quantity_index {$quantityIndex} for purchase_product_id {$purchaseProductId} do not match at index {$index}"
                                ], 422);
                            }
                            $usedQuantityIndexes[$purchaseProductId][] = $quantityIndex;
                        }

                        // Validate measure unit
                        if ($productData['measure_unit_id'] != $purchaseProduct->measure_unit_id) {
                            return response()->json([
                                'error' => "Measure unit ID {$productData['measure_unit_id']} does not match purchase_product_id {$purchaseProductId} at index {$index}"
                            ], 422);
                        }

                        $allocateQuantity = min($remainingQuantity, $availableQuantityInUOM, count($fvByIndex));
                        if ($allocateQuantity <= 0) {
                            continue;
                        }

                        $allocations[] = [
                            'purchase_product_id' => $purchaseProductId,
                            'quantity' => $allocateQuantity,
                            'field_values' => collect($fvByIndex)->take($allocateQuantity)->toArray(),
                            'mfd' => $purchaseProduct->mfd ?? ($productData['mfd'] ?? null),
                            'expiry_date' => $purchaseProduct->expiry_date ?? ($productData['expiry_date'] ?? null),
                        ];

                        $remainingQuantity -= $allocateQuantity;
                    }
                } else {
                    // Handle case with no field values
                    $purchaseProducts = PurchaseProduct::where('purchase_products.product_id', $productData['product_id'])
                        ->where('purchase_products.company_id', $validated['company_id'])
                        ->whereNull('purchase_products.deleted_at')
                        ->whereNotExists(function ($query) use ($validated) {
                            $query->select(DB::raw(1))
                                  ->from('purchase_product_field_values')
                                  ->whereColumn('purchase_product_field_values.purchase_product_id', 'purchase_products.id')
                                  ->where('purchase_product_field_values.company_id', $validated['company_id'])
                                  ->whereNull('purchase_product_field_values.deleted_at');
                        })
                        ->with([
                            'purchase' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']),
                            'purchaseProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id']),
                            'saleProducts' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])->with(['saleProductReturns' => fn($q) => $q->whereNull('deleted_at')->where('company_id', $validated['company_id'])]),
                        ])
                        ->select('purchase_products.*')
                        ->distinct()
                        ->get();

                    if ($purchaseProducts->isEmpty()) {
                        return response()->json([
                            'error' => "No purchase products without field values found for product ID {$productData['product_id']} at index {$index}"
                        ], 404);
                    }

                    $measureUnit = \App\Models\MeasureUnit::find($productData['measure_unit_id']);
                    if (!$measureUnit) {
                        return response()->json([
                            'error' => "Measure unit not found for ID {$productData['measure_unit_id']} at index {$index}"
                        ], 404);
                    }
                    $quantityInPieces = $requestedQuantity * ($measureUnit->quantity ?? 1);
                    $remainingQuantityInPieces = $quantityInPieces;

                    foreach ($purchaseProducts as $purchaseProduct) {
                        if ($remainingQuantityInPieces <= 0) {
                            break;
                        }

                        $purchases[$purchaseProduct->purchase_id] = $purchaseProduct->purchase;

                        $purchaseMeasureUnit = \App\Models\MeasureUnit::find($purchaseProduct->measure_unit_id);
                        if (!$purchaseMeasureUnit) {
                            return response()->json([
                                'error' => "Measure unit not found for purchase_product_id {$purchaseProduct->id} at index {$index}"
                            ], 404);
                        }
                        $purchasedQuantityInPieces = ($purchaseProduct->quantity + ($purchaseProduct->free_quantity ?? 0)) * ($purchaseMeasureUnit->quantity ?? 1);
                        $purchaseReturnedQuantity = \App\Models\PurchaseProductReturn::where('purchase_product_id', $purchaseProduct->id)
                            ->where('company_id', $validated['company_id'])
                            ->whereNull('deleted_at')
                            ->sum(DB::raw('(quantity + COALESCE(free_quantity, 0)) * COALESCE((SELECT quantity FROM measure_units WHERE id = purchase_product_returns.measure_unit_id), 1)'));
                        $soldQuantity = \App\Models\SaleProduct::where('purchase_product_id', $purchaseProduct->id)
                            ->where('sale_products.company_id', $validated['company_id'])
                            ->whereNull('sale_products.deleted_at')
                            ->join('measure_units', 'sale_products.measure_unit_id', '=', 'measure_units.id')
                            ->sum(DB::raw('(sale_products.quantity + COALESCE(sale_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)'));
                        $salesReturnedQuantity = \App\Models\SalesReturnProduct::where('product_id', $productData['product_id'])
                            ->where('sales_return_products.company_id', $validated['company_id'])
                            ->whereNull('sales_return_products.deleted_at')
                            ->join('measure_units', 'sales_return_products.measure_unit_id', '=', 'measure_units.id')
                            ->sum(DB::raw('(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0)) * COALESCE(measure_units.quantity, 1)'));

                        $availableQuantityInPieces = $purchasedQuantityInPieces - $purchaseReturnedQuantity - $soldQuantity + $salesReturnedQuantity;
                        if ($availableQuantityInPieces <= 0) {
                            continue;
                        }

                        

                        $allocateQuantityInPieces = min($remainingQuantityInPieces, $availableQuantityInPieces);
                        $allocateQuantityInUOM = $allocateQuantityInPieces / ($measureUnit->quantity ?? 1);
                        $quantityInUOM = floor($allocateQuantityInUOM);
                        $freeQuantityInUOM = $allocateQuantityInUOM - $quantityInUOM;

                        $allocations[] = [
                            'purchase_product_id' => $purchaseProduct->id,
                            'quantity' => $quantityInUOM,
                            'free_quantity' => $freeQuantityInUOM,
                            'mfd' => $purchaseProduct->mfd ?? $productData['mfd'],
                            'expiry_date' => $purchaseProduct->expiry_date ?? ($productData['expiry_date'] ?? null),
                            'field_values' => [],
                        ];

                        $remainingQuantityInPieces -= $allocateQuantityInPieces;
                        $remainingQuantity -= $quantityInUOM;
                    }
                }

                if ($remainingQuantity > 0) {
                    return response()->json([
                        'error' => "Insufficient stock for product ID {$productData['product_id']} at index {$index}. Requested: {$totalQuantity}, Allocated: " . ($totalQuantity - $remainingQuantity)
                    ], 422);
                }

                foreach ($allocations as $allocation) {
                    $purchaseProduct = PurchaseProduct::findOrFail($allocation['purchase_product_id']);
                    $processedProducts[] = [
                        'purchase_product_id' => $allocation['purchase_product_id'],
                        'product_id' => $productData['product_id'],
                        'product_name' => $productData['product_name'] ?? ($purchaseProduct->product->name ?? ''),
                        'purchase_product_code' => $productData['purchase_product_code'] ?? ($purchaseProduct->product_code ?? ''),
                        'mfd' => $allocation['mfd'],
                        'customer_id' => $productData['customer_id'] ?? ($purchaseProduct->customer_id ?? null),
                        'quantity' => $allocation['quantity'],
                        'free_quantity' => $allocation['free_quantity'] ?? ($productData['free_quantity'] ?? 0),
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
                    ];
                }
            }

            // Process transaction
            $purchaseReturn = DB::transaction(function () use ($validated, $purchases, $processedProducts) {
                $purchaseReturnData = array_filter(
                    $validated,
                    fn($key) => !in_array($key, ['purchase_return_products']),
                    ARRAY_FILTER_USE_KEY
                );

                // Set purchase_id to null for multi-purchase returns
                $purchaseReturnData['purchase_id'] = null;
                $purchaseReturn = PurchaseReturn::create($purchaseReturnData);

                $balanceUpdates = [];

                foreach ($processedProducts as $productData) {
                    $purchaseProductId = $productData['purchase_product_id'];
                    $purchaseProduct = PurchaseProduct::findOrFail($purchaseProductId);
                    $purchaseId = $purchaseProduct->purchase_id;
                    $purchase = $purchases[$purchaseId] ?? Purchase::findOrFail($purchaseId);

                    // Prepare PurchaseProductReturn data
                    $productDataFiltered = array_filter(
                        $productData,
                        fn($key) => !in_array($key, ['field_values', 'purchase_id', 'purchase_bill_number']),
                        ARRAY_FILTER_USE_KEY
                    );
                    $purchaseReturnProduct = $purchaseReturn->purchaseReturnProducts()->create(
                        array_merge($productDataFiltered, [
                            'company_id' => $purchaseReturn->company_id,
                        ])
                    );

                    // Store field values
                    if (!empty($productData['field_values'])) {
                        foreach ($productData['field_values'] as $quantityIndex => $fvSet) {
                            foreach ($fvSet as $fv) {
                                PurchaseReturnProductFieldValue::create([
                                    'purchase_return_product_id' => $purchaseReturnProduct->id,
                                    'product_field_id' => $fv['product_field_id'],
                                    'value' => $fv['value'],
                                    'product_id' => $purchaseReturnProduct->product_id,
                                    'company_id' => $purchaseReturnProduct->company_id,
                                    'quantity_index' => $quantityIndex,
                                ]);
                            }
                        }
                    }

                    // Calculate return value for balance update
                    $returnValue = ($productData['quantity'] * ($productData['price'] ?? 0)) - ($productData['discount_amount'] ?? 0);
                    $balanceUpdates[$purchaseId] = ($balanceUpdates[$purchaseId] ?? 0) + $returnValue;
                }

                // Update purchase balances
                foreach ($balanceUpdates as $purchaseId => $returnValue) {
                    $purchase = $purchases[$purchaseId] ?? Purchase::findOrFail($purchaseId);
                    $purchase->balance -= $returnValue;
                    $purchase->save();
                }

                // Log history
                PurchaseReturnHistory::create([
                    'purchase_return_id' => $purchaseReturn->id,
                    'action' => 'created',
                    'data' => array_merge($purchaseReturnData, ['purchase_return_products' => $processedProducts]),
                ]);

                // Load relationships for response
                return $purchaseReturn->load([
                    'purchaseReturnProducts' => function ($query) {
                        $query->select('id', 'purchase_return_id', 'purchase_product_id', 'product_id', 'product_name', 'purchase_product_code', 'quantity', 'free_quantity', 'price', 'discount_percent', 'discount_amount', 'amount', 'is_vatable', 'measure_unit_id', 'expiry_date', 'mfd', 'customer_id');
                    },
                    'purchaseReturnProducts.fieldValues' => fn($query) => $query->orderBy('quantity_index')->orderBy('product_field_id')
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
            return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error('Error creating purchase return: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Internal server error occurred: ' . $e->getMessage()], 500);
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
                'purchase_return_type' => 'nullable|string|max:255',
                'purchase_number' => 'nullable|string|max:255',
                'purchase_bill_number' => 'required|string|max:255',
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reason' => 'nullable|string|in:damaged,defective,incorrect,expired,other',
                'store_id' => 'nullable|integer|exists:stores,id',
                'location_id' => 'nullable|integer|exists:locations,id',
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
                'purchase_return_products.*.expiry_date' => 'nullable|string|max:255',
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
                        $returnedIndices = PurchaseReturnProductFieldValue::whereIn(
                            'purchase_return_product_id',
                            PurchaseProductReturn::where('purchase_product_id', $productData['purchase_product_id'])
                                ->whereNull('deleted_at')
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
                'data' => $item->load([
                    'purchaseReturnProducts.fieldValues' => function ($query) {
                        $query->orderBy('quantity_index')->orderBy('product_field_id');
                    }
                ])
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

<?php

namespace App\Helpers;

use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\PurchaseProduct;
use App\Models\PurchaseStockProduct;
use App\Models\ProductList;
use App\Models\Product;

class PurchaseReturnHelper
{



    public static function getPurchaseProductforPurchaseReturn(array $productIds, int $companyId, int $branchId, ?string $purchaseType = null): array
    {
        try {
            $query = PurchaseStockProduct::whereIn('product_id', $productIds)
                ->where('purchase_stock_products.company_id', $companyId)
                ->where('purchase_stock_products.branch_id', $branchId)

                ->where('purchase_stock_products.purchase_type', $purchaseType)
                ->whereNull('purchase_stock_products.deleted_at');
            // ->join('purchases', 'purchase_products.purchase_id', '=', 'purchases.id')
            // ->whereNull('purchases.deleted_at');


            // Add purchase_type filter if provided
            if ($purchaseType !== null) {
                $query->where('purchase_stock_products.purchase_type', $purchaseType);
            }

            // dd($companyId,$branchId,$purchaseType);

            $productIds = $query->whereRaw('
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
                        WHERE sale_products.purchase_stock_product_id = purchase_stock_products.id
                        AND sale_products.deleted_at IS NULL
                    ), 0) + 
                    COALESCE((
                        SELECT SUM(sales_return_products.quantity + COALESCE(sales_return_products.free_quantity, 0))
                        FROM sales_return_products
                        WHERE sales_return_products.sale_product_id IN (
                            SELECT id FROM sale_products
                            WHERE sale_products.purchase_stock_product_id = purchase_stock_products.id
                            AND sale_products.deleted_at IS NULL
                        )
                        AND sales_return_products.deleted_at IS NULL
                    ), 0)
                ) > 0
            ')
                ->groupBy('product_id')
                ->pluck('product_id')
                ->unique()
                ->toArray();

            if (empty($productIds)) {
                return [];
            }

            $productNames = Product::whereIn('id', $productIds)->pluck('name')->toArray();

            return array_values(array_unique($productNames));
        } catch (QueryException $e) {

            \Log::error('Database error in get PurchaseProductforPurchaseReturn: ' . $e->getMessage());
            return ['error' => 'Database error occurred'];
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getPurchaseProductforPurchaseReturn: ' . $e->getMessage());
            return ['error' => 'An unexpected error occurred'];
        }
    }

    public static function getPurchaseProductforPurchaseReturnByPrductId(array $productCodes, int $companyId, int $branchId, ?string $purchaseType = null): array
    {
        try {

            $query = PurchaseStockProduct::whereIn('product_code', $productCodes)
                ->where('purchase_stock_products.company_id', $companyId)
                ->where('purchase_stock_products.branch_id', $branchId)
                ->where('purchase_stock_products.purchase_type', $purchaseType)
                ->whereNull('purchase_stock_products.deleted_at');


            $productCodes = $query->whereRaw('
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
                ->groupBy('product_code')
                ->pluck('product_code')
                ->unique()
                ->toArray();

            if (empty($productCodes)) {
                return [];
            }

            // Get product codes for the filtered product IDs
            $productCodes = PurchaseStockProduct::whereIn('product_code', $productCodes)
                ->pluck('product_code')
                ->unique()
                ->values()
                ->toArray();

            return $productCodes;
        } catch (QueryException $e) {
          
            \Log::error('Database error in getPurchaseProductforPurchaseReturnByPrductId: ' . $e->getMessage());
            return ['error' => 'Database error occurred'];
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getPurchaseProductforPurchaseReturnByPrductId: ' . $e->getMessage());
            return ['error' => 'An unexpected error occurred'];
        }
    }


    public static function getPurchaseProductforPurchaseReturnByBarcode(array $productIds, int $companyId,int $branchId,  ?string $purchaseType = null): array
    {
        try {
            if (empty($productIds)) {
                return ['error' => 'No product IDs provided'];
            }

            // Get product IDs with available quantities
            $query = PurchaseStockProduct::whereIn('purchase_stock_products.product_id', $productIds)
                ->where('purchase_stock_products.company_id', $companyId)
                ->where('purchase_stock_products.branch_id', $branchId)
                 ->where('purchase_stock_products.purchase_type', $purchaseType)
                ->whereNull('purchase_stock_products.deleted_at');

            
                

           

            $availableProductIds = $query->whereRaw('
                (
                    (purchase_stock_products.quantity + COALESCE(purchase_stock_products.free_quantity, 0)) - 
                    COALESCE((
                        SELECT SUM(purchase_stock_product_returns.quantity + COALESCE(purchase_stock_product_returns.free_quantity, 0))
                        FROM purchase_stock_product_returns
                        WHERE purchase_stock_product_returns.purchase_product_id = purchase_stock_products.id
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
                ->groupBy('purchase_stock_products.product_id')
                ->pluck('purchase_stock_products.product_id')
                ->unique()
                ->toArray();

             

            if (empty($availableProductIds)) {
                return ['error' => 'No products with available quantities found'];
            }


            // Get barcodes for the filtered product IDs
            $barcodes = ProductList::whereIn('product_id', $availableProductIds)
                ->whereNotNull('barcode')
                ->pluck('barcode')
                ->unique()
                ->values()
                ->toArray();

          



            if (empty($barcodes)) {
                return [];
            }

            return $barcodes;
        } catch (QueryException $e) {
            \Log::error('Database error in getPurchaseProductforPurchaseReturnByBarcode', [
                'company_id' => $companyId,
                'product_ids' => $productIds,
                'purchase_type' => $purchaseType,
                'error' => $e->getMessage()
            ]);
            return ['error' => 'Database error occurred'];
        } catch (ModelNotFoundException $e) {
            \Log::error('Model not found in getPurchaseProductforPurchaseReturnByBarcode', [
                'company_id' => $companyId,
                'product_ids' => $productIds,
                'purchase_type' => $purchaseType,
                'error' => $e->getMessage()
            ]);
            return ['error' => 'Item not found'];
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getPurchaseProductforPurchaseReturnByBarcode', [
                'company_id' => $companyId,
                'product_ids' => $productIds,
                'purchase_type' => $purchaseType,
                'error' => $e->getMessage()
            ]);
            return ['error' => 'An unexpected error occurred'];
        }
    }


}
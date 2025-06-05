<?php

namespace App\Helpers;

use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\PurchaseProduct;
use App\Models\ProductList;
use App\Models\Product;

class PurchaseReturnHelper{



    public static function getPurchaseProductforPurchaseReturn(array $productIds, int $companyId): array
    { 
        try {
            // Get product names for products with available quantities
            $productIds = PurchaseProduct::whereIn('product_id', $productIds)
                ->where('company_id', $companyId)
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
                ->groupBy('product_id')
                ->pluck('product_id')
                ->unique()
                ->toArray();

            if (empty($productIds)) {
                return ['error' => 'No products with available quantities found'];
            }
           

            $productNames = Product::whereIn('id',$productIds)->pluck('name')->toArray();
            

            return array_values(array_unique($productNames));
        } catch (QueryException $e) {
            \Log::error('Database error in getPurchaseProductforPurchaseReturn: ' . $e->getMessage());
            return ['error' => 'Database error occurred'];
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getPurchaseProductforPurchaseReturn: ' . $e->getMessage());
           
            return ['error' => 'An unexpected error occurred'];
        }
    }

    public static function getPurchaseProductforPurchaseReturnByPrductId(array $productIds, int $companyId): array
    {
        try {
            // Get product names for products with available quantities
            $productIds = PurchaseProduct::whereIn('product_id', $productIds)
                ->where('company_id', $companyId)
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
                ->groupBy('product_code')
                ->pluck('product_code')
                ->unique()
                ->toArray();

            if (empty($productIds)) {
                return ['error' => 'No products with available quantities found'];
            }
           

            $productCodes = PurchaseProduct::whereIn('product_id',$productIds)->pluck('product_code')->toArray();
            
            

            return array_values(array_unique($productCodes));
        } catch (QueryException $e) {
            \Log::error('Database error in getPurchaseProductforPurchaseReturn: ' . $e->getMessage());
            return ['error' => 'Database error occurred'];
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getPurchaseProductforPurchaseReturn: ' . $e->getMessage());
          
            return ['error' => 'An unexpected error occurred'];
        }
    }



   public static function getPurchaseProductforPurchaseReturnByBarcode(array $productIds, int $companyId): array
    {
        try {
          
            if (empty($productIds)) {
                return ['error' => 'No product IDs provided'];
            }

           
            $barcodes = ProductList::whereIn('product_id', $productIds)
                ->whereNotNull('barcode')
                ->pluck('barcode')
                ->unique()
                ->toArray();

            
            if (empty($barcodes)) {
                return ['error' => 'No valid barcodes found for the provided product IDs'];
            }

            return array_values($barcodes);
        } catch (QueryException $e) {
            \Log::error('Database error in getPurchaseProductforPurchaseReturnByBarcode', [
                'company_id' => $companyId,
                'product_ids' => $productIds,
                'error' => $e->getMessage()
            ]);
            return ['error' => 'Database error occurred'];
        }catch(ModelNotFoundException $e){
            \Log::error($e);
            return response()->json(["error"=>"Item not Found!!"],404);
        }catch(QueryException $e){
            Log::error($e);
            return response()->json(["error"=>"Database error occurred !!"],500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getPurchaseProductforPurchaseReturnByBarcode', [
                'company_id' => $companyId,
                'product_ids' => $productIds,
                'error' => $e->getMessage()
            ]);
            return ['error' => 'An unexpected error occurred'];
        }
    }


}
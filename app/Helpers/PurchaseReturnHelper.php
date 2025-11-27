<?php

namespace App\Helpers;

use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\PurchaseProduct;
use App\Models\MeasureUnit;
use Illuminate\Support\Facades\Log;
use App\Models\PurchaseStockProduct;
use App\Models\ProductList;
use App\Models\Product;

class PurchaseReturnHelper
{

    public static function calculatePieces(string $quantity, float $measureUnitQuantity): float
    {
        if ($measureUnitQuantity <= 0) {
            Log::warning('Invalid measure unit quantity', ['measureUnitQuantity' => $measureUnitQuantity]);
            return 0;
        }

        // Split integer and decimal parts WITHOUT float
        [$integerPart, $decimalPart] = array_pad(explode('.', $quantity), 2, '0');

        $integer = (int) $integerPart;
        $decimalPieces = (int) $decimalPart;

        return ($integer * $measureUnitQuantity) + $decimalPieces;
    }


     public static function sumQuantityAndFree($quantity, $freeQuantity): string
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



    public static function getAvailableProductNamesForPurchaseReturn(
        int $companyId,
        int $branchId,
        string $purchaseType
    ): array {
        try {
            // Load measure units once
            $measureUnits = MeasureUnit::where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->pluck('quantity', 'id'); // id => pieces per unit

            if ($measureUnits->isEmpty()) {
                return [];
            }

            // Helper to safely get pieces per unit
            $getUnitQty = fn($unitId) => $measureUnits[$unitId] ?? 1;

            // Your existing accurate method (now used everywhere)
            $toPieces = function ($qty, $freeQty = 0, $unitId = null) use ($getUnitQty) {
                $totalQty = self::sumQuantityAndFree($qty ,  $freeQty);
                $unitQty = $getUnitQty($unitId);
                return self::calculatePieces($totalQty, $unitQty);
            };

            // Get all purchase stock products of this type
            $purchaseProducts = PurchaseStockProduct::where([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'purchase_type' => $purchaseType,
            ])
                ->whereNull('deleted_at')
                ->select('id', 'product_id', 'quantity', 'free_quantity', 'measure_unit_id')
                ->get();

            if ($purchaseProducts->isEmpty()) {
                return [];
            }

            $pspIds = $purchaseProducts->pluck('id')->toArray();

            // Bulk load all related records
            $returns = DB::table('purchase_stock_product_returns')
                ->whereIn('purchase_stock_product_id', $pspIds)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->select('purchase_stock_product_id', 'quantity', 'free_quantity', 'measure_unit_id')
                ->get()
                ->groupBy('purchase_stock_product_id');

            $sales = DB::table('sale_products')
                ->whereIn('purchase_stock_product_id', $pspIds)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->whereNull('deleted_at')
                ->select('purchase_stock_product_id', 'quantity', 'free_quantity', 'measure_unit_id')
                ->get()
                ->groupBy('purchase_stock_product_id');

            $salesReturns = DB::table('sales_return_products')
                ->join('sale_products', 'sales_return_products.sale_product_id', '=', 'sale_products.id')
                ->whereIn('sale_products.purchase_stock_product_id', $pspIds)
                ->where('sales_return_products.company_id', $companyId)
                ->where('sales_return_products.branch_id', $branchId)
                ->whereNull('sales_return_products.deleted_at')
                ->select('sale_products.purchase_stock_product_id', 'sales_return_products.quantity', 'sales_return_products.free_quantity', 'sales_return_products.measure_unit_id')
                ->get()
                ->groupBy('purchase_stock_product_id');

            $adjustments = DB::table('stock_adjusteds')
                ->whereIn('purchase_stock_product_id', $pspIds)
                ->where('company_id', $companyId)
                ->where('branch_id', $branchId)
                ->where('adjusted_type', 'subtract')
                ->whereNull('deleted_at')
                ->select('purchase_stock_product_id', 'quantity', 'measure_unit_id')
                ->get()
                ->groupBy('purchase_stock_product_id');

            // Filter products with remaining stock in pieces
            $validProductIds = $purchaseProducts->filter(function ($pp) use ($toPieces, $returns, $sales, $salesReturns, $adjustments) {
                $id = $pp->id;

                $purchased = $toPieces($pp->quantity, $pp->free_quantity, $pp->measure_unit_id);
                $returned = $returns->get($id, collect())->sum(fn($r) => $toPieces($r->quantity, $r->free_quantity, $r->measure_unit_id));
                $sold = $sales->get($id, collect())->sum(fn($s) => $toPieces($s->quantity, $s->free_quantity, $s->measure_unit_id));
                $salesReturn = $salesReturns->get($id, collect())->sum(fn($sr) => $toPieces($sr->quantity, $sr->free_quantity, $sr->measure_unit_id));
                $adjusted = $adjustments->get($id, collect())->sum(fn($a) => $toPieces($a->quantity, 0, $a->measure_unit_id));

                $remaining = $purchased - $returned - $sold + $salesReturn - $adjusted;

                return $remaining > 0;
            })
                ->pluck('product_id')
                ->unique()
                ->values();

            if ($validProductIds->isEmpty()) {
                return [];
            }

            // Return unique product names
            return Product::whereIn('id', $validProductIds)
                ->where('company_id', $companyId)
                ->whereNull('deleted_at')
                ->pluck('name')
                ->unique()
                ->values()
                ->toArray();

        } catch (\Exception $e) {
            \Log::error('Error in getAvailableProductNamesForPurchaseReturn: ' . $e->getMessage(), [
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'purchase_type' => $purchaseType,
                'trace' => $e->getTraceAsString()
            ]);
            return ['error' => 'Failed to fetch available products'];
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


    public static function getPurchaseProductforPurchaseReturnByBarcode(array $productIds, int $companyId, int $branchId, ?string $purchaseType = null): array
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
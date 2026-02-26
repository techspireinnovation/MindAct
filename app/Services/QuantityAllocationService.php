<?php
namespace App\Services;


use App\Models\StockProductFieldValue;
use App\Models\StockProduct;
use App\Models\StockTransaction;

use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
class QuantityAllocationService
{


    public function allocateBillWiseQuantity($purchaseStockId, $productID, $baseQuantity)
    {
        if ($purchaseStockId <= 0 || $productID <= 0 || $baseQuantity <= 0) {
            throw new \Exception("Invalid purchase stock, product, or quantity.");
        }

        $allocated = [];
        $remaining = $baseQuantity;


        $stockProducts = StockProduct::where('stock_id', $purchaseStockId)
            ->where('product_id', $productID)
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'source_type' => 'stock_product',
                    'stock_product_id' => $item->id,
                    'stock_movement_id' => null,
                    'original_quantity' => $item->quantity,
                    'created_at' => $item->created_at,
                    'product_id' => $item->product_id
                ];
            });


        $stockMovements = StockMovement::where('stock_id', $purchaseStockId)
            ->where('product_id', $productID)
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'source_type' => 'stock_movement',
                    'stock_product_id' => $item->stock_product_id,
                    'stock_movement_id' => $item->id,
                    'original_quantity' => $item->quantity,
                    'created_at' => $item->created_at,
                    'product_id' => $item->product_id
                ];
            });


        $combined = $stockProducts
            ->merge($stockMovements)
            ->sortBy('created_at')
            ->values();


        foreach ($combined as $row) {

            if ($remaining <= 0) {
                break;
            }

            if ($row['source_type'] === 'stock_product') {

                $sourceId = $row['stock_product_id'];


                $soldQty = StockTransaction::where('stock_product_id', $sourceId)
                    ->where('type', 'sale')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $purchaseReturnQty = StockTransaction::where('stock_product_id', $sourceId)

                    ->where('type', 'purchase_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $salesReturnQty = StockTransaction::where('source_id', $sourceId)
                    ->where('type', 'sales_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');

            } else {

                $sourceId = $row['stock_movement_id'];


                $soldQty = StockMovement::where('stock_product_id', $sourceId)
                    ->where('stock_type', 'free')
                    ->where('type', 'sale')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $purchaseReturnQty = StockMovement::where('stock_product_id', $sourceId)
                    ->where('stock_type', 'free')
                    ->where('type', 'purchase_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $salesReturnQty = StockMovement::where('source_id', $sourceId)
                    ->where('source_type', 'stock_movement')
                    ->where('type', 'sales_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');
            }


            $availableQty =
                $row['original_quantity']
                - $soldQty
                - $purchaseReturnQty
                + $salesReturnQty;

            if ($availableQty <= 0) {
                continue;
            }


            $useQty = min($availableQty, $remaining);

            $allocated[] = [
                'source' => $row['source_type'],
                'stock_product_id' => $row['stock_product_id'],
                'stock_movement_id' => $row['stock_movement_id'],
                'quantity' => $useQty,
                'product_id' => $row['product_id']
            ];

            $remaining -= $useQty;
        }


        if ($remaining > 0) {
            throw new \Exception(
                "Purchase return quantity exceeds available stock for product ID: {$productID}"
            );
        }

        return $allocated;
    }

    public function allocateItemWiseWiseQuantity($productID, $baseQuantity)
    {
        if ($productID <= 0 || $baseQuantity <= 0) {
            throw new \Exception("Invalid purchase stock, product, or quantity.");
        }

        $allocated = [];
        $remaining = $baseQuantity;


        $stockProducts = StockProduct::where('product_id', $productID)
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'source_type' => 'stock_product',
                    'stock_product_id' => $item->id,
                    'stock_movement_id' => null,
                    'original_quantity' => $item->quantity,
                    'created_at' => $item->created_at,
                    'product_id' => $item->product_id
                ];
            });


        $stockMovements = StockMovement::where('product_id', $productID)
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'source_type' => 'stock_movement',
                    'stock_product_id' => $item->stock_product_id,
                    'stock_movement_id' => $item->id,
                    'original_quantity' => $item->quantity,
                    'created_at' => $item->created_at,
                    'product_id' => $item->product_id
                ];
            });


        $combined = $stockProducts
            ->merge($stockMovements)
            ->sortBy('created_at')
            ->values();


        foreach ($combined as $row) {

            if ($remaining <= 0) {
                break;
            }

            if ($row['source_type'] === 'stock_product') {

                $sourceId = $row['stock_product_id'];


                $soldQty = StockTransaction::where('stock_product_id', $sourceId)
                    ->where('type', 'sale')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $purchaseReturnQty = StockTransaction::where('stock_product_id', $sourceId)

                    ->where('type', 'purchase_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $salesReturnQty = StockTransaction::where('source_id', $sourceId)
                    ->where('type', 'sales_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');

            } else {

                $sourceId = $row['stock_movement_id'];


                $soldQty = StockMovement::where('stock_product_id', $sourceId)
                    ->where('stock_type', 'free')
                    ->where('type', 'sale')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $purchaseReturnQty = StockMovement::where('stock_product_id', $sourceId)
                    ->where('stock_type', 'free')
                    ->where('type', 'purchase_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');


                $salesReturnQty = StockMovement::where('source_id', $sourceId)
                    ->where('source_type', 'stock_movement')
                    ->where('type', 'sales_return')
                    ->whereNull('deleted_at')
                    ->sum('quantity');
            }


            $availableQty =
                $row['original_quantity']
                - $soldQty
                - $purchaseReturnQty
                + $salesReturnQty;

            if ($availableQty <= 0) {
                continue;
            }


            $useQty = min($availableQty, $remaining);

            $allocated[] = [
                'source' => $row['source_type'],
                'stock_product_id' => $row['stock_product_id'],
                'stock_movement_id' => $row['stock_movement_id'],
                'quantity' => $useQty,
                'product_id' => $row['product_id']
            ];

            $remaining -= $useQty;
        }


        if ($remaining > 0) {
            throw new \Exception(
                "Purchase return quantity exceeds available stock for product ID: {$productID}"
            );
        }

        return $allocated;
    }

    public function allocateSaleQuantity($productID, $baseQuantity)
    {
        $allocated = [];
        $remaining = $baseQuantity;


        $stockProductIds = StockProduct::where('product_id', $productID)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->toArray();

        if (empty($stockProductIds)) {
            throw new \Exception("No stock available for product ID: {$productID}");
        }


        $transactions = StockTransaction::select(
            'stock_product_id',
            'type',
            DB::raw('SUM(quantity) as total')
        )
            ->whereIn('stock_product_id', $stockProductIds)
            ->groupBy('stock_product_id', 'type')
            ->get()
            ->groupBy('stock_product_id');

        StockProduct::whereIn('id', $stockProductIds)
            ->whereNull('deleted_at')
            ->orderBy('id', 'asc')
            ->chunk(1000, function ($stockProductsChunk) use (&$allocated, &$remaining, $transactions) {

                foreach ($stockProductsChunk as $sp) {
                    if ($remaining <= 0)
                        break;

                    $txs = $transactions->get($sp->id);

                    $purchaseReturnQty = $txs ? $txs->where('type', 'purchase_return')->sum('total') : 0;
                    $saleQty = $txs ? $txs->where('type', 'sale')->sum('total') : 0;
                    $saleReturnQty = $txs ? $txs->where('type', 'sale_return')->sum('total') : 0;

                    $availableQty = $sp->quantity - $purchaseReturnQty - $saleQty + $saleReturnQty;

                    if ($availableQty <= 0)
                        continue;

                    $useQty = min($availableQty, $remaining);

                    $allocated[] = [
                        'source' => 'stock_product',
                        'product_id' => $sp->product_id,
                        'stock_product_id' => $sp->id,
                        'batch_no' => $sp->batch_no,
                        'expiry_date' => $sp->expiry_date,
                        'stock_movement_id' => null,
                        'quantity' => $useQty
                    ];

                    $remaining -= $useQty;
                }
            });


        $freeMovements = StockMovement::select(
            'stock_product_id',
            'direction',
            DB::raw('SUM(quantity) as total')
        )
            ->where('product_id', $productID)
            ->whereNull('deleted_at')
            ->groupBy('stock_product_id', 'direction')
            ->get()
            ->groupBy('stock_product_id');

        foreach ($freeMovements as $stockProductId => $movs) {
            if ($remaining <= 0)
                break;

            $totalIn = $movs->where('direction', 'in')->sum('total');
            $totalOut = $movs->where('direction', 'out')->sum('total');

            $availableQty = $totalIn - $totalOut;

            if ($availableQty <= 0)
                continue;

            $useQty = min($availableQty, $remaining);

            $sp = StockProduct::find($stockProductId);

            $allocated[] = [
                'source' => 'stock_movement',
                'product_id' => $sp ? $sp->product_id : $productID,
                'stock_product_id' => $stockProductId,
                'batch_no' => $sp->batch_no ?? null,
                'expiry_date' => $sp->expiry_date ?? null,
                'stock_movement_id' => null,
                'quantity' => $useQty
            ];

            $remaining -= $useQty;
        }


        if ($remaining > 0) {
            throw new \Exception("Not enough stock available to allocate for product ID: {$productID}");
        }

        return $allocated;
    }



    public function allocateSalesReturnQuantity($salesStockId, $productID, $baseQuantity)
    {
        $allocated = [];
        $remaining = $baseQuantity;


        $saleTransactions = StockTransaction::where('stock_id', $salesStockId)
            ->where('product_id', $productID)
            ->where('type', 'sale')

            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'source_type' => 'stock_transaction',
                    'stock_product_id' => $item->stock_product_id,
                    'quantity' => $item->quantity,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'created_at' => $item->created_at,
                ];
            });


        $saleMovements = StockMovement::where('stock_id', $salesStockId)
            ->where('product_id', $productID)
            ->where('type', 'sale')
            ->where('stock_type', 'free')
            ->where('direction', 'out')
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'source_type' => 'stock_movement',
                    'stock_product_id' => $item->stock_product_id,
                    'quantity' => $item->quantity,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'created_at' => $item->created_at,
                ];
            });


        $combined = $saleTransactions
            ->merge($saleMovements)
            ->sortBy('created_at')
            ->values();


        foreach ($combined as $row) {

            if ($remaining <= 0)
                break;


            if ($row['source_type'] === 'stock_transaction') {

                $returnedQty = StockTransaction::where('source_id', $row['id'])
                    ->where('source_type', 'stock_transaction')
                    ->where('type', 'sales_return')
                    ->sum('quantity');

            } else {

                $returnedQty = StockMovement::where('source_id', $row['id'])
                    ->where('source_type', 'stock_movement')
                    ->where('type', 'sales_return')
                    ->sum('quantity');
            }

            $availableQty = $row['quantity'] - $returnedQty;

            if ($availableQty <= 0)
                continue;

            $useQty = min($availableQty, $remaining);

            $allocated[] = [
                'source_id' => $row['id'],
                'source_type' => $row['source_type'],
                'stock_product_id' => $row['stock_product_id'],
                'batch_no' => $row['batch_no'],
                'expiry_date' => $row['expiry_date'],
                'quantity' => $useQty,
            ];

            $remaining -= $useQty;
        }


        if ($remaining > 0) {
            throw new \Exception(
                "Return quantity exceeds sold quantity for product ID: {$productID}"
            );
        }

        return $allocated;
    }




    public function allocateSalesReturnItemWiseQuantity($productID = 0, $baseQuantity = 0)
    {
        if ($productID <= 0 || $baseQuantity <= 0) {
            throw new \Exception("Invalid product ID or quantity for allocation.");
        }

        $allocated = [];
        $remaining = $baseQuantity;


        $saleTransactions = StockTransaction::where('product_id', $productID)
            ->where('type', 'sale')

            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'source_type' => 'stock_transaction',
                    'stock_product_id' => $item->stock_product_id,
                    'quantity' => $item->quantity,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'created_at' => $item->created_at,
                ];
            });


        $saleMovements = StockMovement::where('product_id', $productID)
            ->where('type', 'sale')
            ->where('stock_type', 'free')
            ->where('direction', 'out')
            ->whereNull('deleted_at')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'source_type' => 'stock_movement',
                    'stock_product_id' => $item->stock_product_id,
                    'quantity' => $item->quantity,
                    'batch_no' => $item->batch_no,
                    'expiry_date' => $item->expiry_date,
                    'created_at' => $item->created_at,
                ];
            });


        $combined = $saleTransactions
            ->merge($saleMovements)
            ->sortBy('created_at')
            ->values();


        foreach ($combined as $row) {

            if ($remaining <= 0)
                break;


            if ($row['source_type'] === 'stock_transaction') {

                $returnedQty = StockTransaction::where('source_id', $row['id'])
                    ->where('source_type', 'stock_transaction')
                    ->where('type', 'sales_return')
                    ->sum('quantity');

            } else {

                $returnedQty = StockMovement::where('source_id', $row['id'])
                    ->where('source_type', 'stock_movement')
                    ->where('type', 'sales_return')
                    ->sum('quantity');
            }

            $availableQty = $row['quantity'] - $returnedQty;

            if ($availableQty <= 0)
                continue;

            $useQty = min($availableQty, $remaining);

            $allocated[] = [
                'source_id' => $row['id'],
                'source_type' => $row['source_type'],
                'stock_product_id' => $row['stock_product_id'],
                'batch_no' => $row['batch_no'],
                'expiry_date' => $row['expiry_date'],
                'quantity' => $useQty,
            ];

            $remaining -= $useQty;
        }


        if ($remaining > 0) {
            throw new \Exception(
                "Return quantity exceeds sold quantity for product ID: {$productID}"
            );
        }

        return $allocated;
    }



}
?>
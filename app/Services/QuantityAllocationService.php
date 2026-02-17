<?php
namespace App\Services;


use App\Models\StockProductFieldValue;
use App\Models\StockProduct;
use App\Models\StockMovement;
class QuantityAllocationService
{


    public function allocateBillWiseQuantity($purchaseStockId, $productID, $baseQuantity)
    {
        $allocated = [];
        $remaining = $baseQuantity;

      
        $stockProducts = StockProduct::where('stock_id', $purchaseStockId)
            ->where('product_id', $productID)
            ->where('quantity', '>', 0)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'asc')
            ->get();

       
        $stockMovements = StockMovement::where('stock_id', $purchaseStockId)
            ->where('product_id', $productID)
            ->where('quantity', '>', 0)
            ->whereNull('deleted_at')
            ->orderBy('created_at', 'asc')
            ->get();

      
        foreach ($stockProducts as $sp) {
            if ($remaining <= 0)
                break;

            $useQty = min($sp->quantity, $remaining);

            $allocated[] = [
                'source' => 'stock_product',
                'stock_product_id' => $sp->id,
                'stock_movement_id' => null,
                'quantity' => $useQty,
                'product_id' => $productID
            ];

           

            $remaining -= $useQty;
        }

        
        foreach ($stockMovements as $sm) {
            if ($remaining <= 0)
                break;

            $useQty = min($sm->quantity, $remaining);

            $allocated[] = [
                'source' => 'stock_movement',
                'stock_product_id' => $sm->stock_product_id ?? null, 
                'stock_movement_id' => $sm->id,
                'quantity' => $useQty,
                'product_id' => $productID
            ];

           

            $remaining -= $useQty;
        }

        if ($remaining > 0) {
            throw new \Exception("Not enough stock to allocate for product ID: {$productID}");
        }

        return $allocated; 
    }


}
?>
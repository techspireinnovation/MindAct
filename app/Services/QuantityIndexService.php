<?php
namespace App\Services;


use App\Models\StockProductFieldValue;

class QuantityIndexService
{
    


     public function getNextQuantityIndex($stockProductId)
    {
        $maxIndex = StockProductFieldValue::where('stock_product_id', $stockProductId)
            
            ->whereNull('deleted_at')
            ->max('quantity_index');

        return $maxIndex !== null ? $maxIndex + 1 : 0;
    }
}
?>
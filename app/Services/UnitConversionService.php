<?php
namespace App\Services;

use App\Models\MeasureUnit;
use App\Models\Product;

class UnitConversionService
{
    public function convertToBaseUnit($measureUnitId, $quantity)
    {
        $unit = MeasureUnit::findOrFail($measureUnitId);


        $unitQuantity = $unit->quantity;

        $baseQuantity = $quantity * $unitQuantity;

        return $baseQuantity;
    }
}
?>
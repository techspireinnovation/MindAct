<?php
namespace App\Services;


use App\Models\StockProductFieldValue;

class CurrencyFormatService
{



    public function cleanCurrency($value)
    {
      
        $clean = preg_replace('/[^0-9.]/', '', $value);

      
        $parts = explode('.', $clean);

        if (count($parts) > 1) {
            $clean = $parts[0] . '.' . $parts[1];
        }

        return $clean;
    }
}
?>
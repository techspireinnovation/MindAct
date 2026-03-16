<?php
namespace App\Services;


use App\Models\StockProductFieldValue;
use DateTime;

class DateFormatService
{



    public function cleanDate($value)
    {
        function isValidDate($date)
        {
            $d = DateTime::createFromFormat('Y-m-d', $date);
            return $d && $d->format('Y-m-d') === $date;
        }

        
        $date = "2026-02-13";


    }
}
?>
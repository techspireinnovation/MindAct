<?php
namespace App\Services;


use App\Models\StockProductFieldValue;

class TransactionImplementService
{

    public function transactionImplement($percentage = 0, $amount = 0)
    {

        $chargedAmount = ($percentage / 100) * $amount;

        return $chargedAmount;
    }

}
?>
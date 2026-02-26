<?php
namespace App\Services;

use Exception;
use Pratiksh\Nepalidate\Services\NepaliDate;
use App\Models\StockProductFieldValue;
use App\Models\Product;
use Carbon\Carbon;

use App\Models\Stock;

class GenerateBillNumbers
{


    public function getProductID($companyId)
    {
        if (!$companyId) {
            throw new Exception("Please provide company name!");
        }
        $latestProduct = Product::withTrashed()
            ->where('company_id', $companyId)
            ->orderBy('id', 'desc')
            ->first();



        if ($latestProduct && preg_match('/PID-(\d+)/', $latestProduct->product_unique_id, $matches)) {
            $nextNumber = (int) $matches[1] + 1;
        } else {
            $nextNumber = 1;
        }



        $productID = 'PID-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);


        while (
            Product::withTrashed()
                ->where('company_id', $companyId)
                ->where('product_code', $productID)
                ->exists()
        ) {
            $nextNumber++;
            $productID = 'PID-' . str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
        }

        return $productID;
    }



    public function getOpeningStockBillNumbers($branchId)
    {
        if (!$branchId) {
            throw new Exception("Branch ID is required.");
        }

        $bsDate = NepaliDate::create(Carbon::now())->toBS();
        [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

        $currentBsYear = (int) $currentBsYear;
        $currentBsMonth = (int) $currentBsMonth;


        $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


        $startYear = substr($fiscalYear, -2);
        $endYear = substr($fiscalYear + 1, -2);

        $fiscalYearCode = $startYear . $endYear;



        if (!$branchId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Branch not provided.'
            ], 400);
        }

        $lastStock = Stock::where('type', 'opening_stock')
            ->where('bill_number', 'like', "OS{$fiscalYearCode}-{$branchId}-%")
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastStock
            ? (int) substr($lastStock->bill_number, -7)
            : 0;

        $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

        $billNumber = "OS{$fiscalYearCode}-{$branchId}-{$newNumber}";

        return $billNumber;


    }




    public function getPurchaseBillNumber($branchId)
    {
        $bsDate = NepaliDate::create(Carbon::now())->toBS();
        [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

        $currentBsYear = (int) $currentBsYear;
        $currentBsMonth = (int) $currentBsMonth;


        $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


        $startYear = substr($fiscalYear, -2);
        $endYear = substr($fiscalYear + 1, -2);

        $fiscalYearCode = $startYear . $endYear;



        if (!$branchId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Branch not provided!'
            ], 400);
        }

        $lastStock = Stock::where('type', 'purchase')
            ->where('bill_number', 'like', "P{$fiscalYearCode}-{$branchId}-%")
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastStock
            ? (int) substr($lastStock->bill_number, -7)
            : 0;

        $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

        $billNumber = "P{$fiscalYearCode}-{$branchId}-{$newNumber}";

        return $billNumber;

    }

    function getPurchaseReturnBillNumber($branchId)
    {
        $bsDate = NepaliDate::create(Carbon::now())->toBS();
        [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

        $currentBsYear = (int) $currentBsYear;
        $currentBsMonth = (int) $currentBsMonth;


        $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


        $startYear = substr($fiscalYear, -2);
        $endYear = substr($fiscalYear + 1, -2);

        $fiscalYearCode = $startYear . $endYear;



        if (!$branchId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Branch not provided.'
            ], 400);
        }

        $lastStock = Stock::where('type', 'purchase_return')
            ->where('bill_number', 'like', "PR{$fiscalYearCode}-{$branchId}-%")
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastStock
            ? (int) substr($lastStock->bill_number, -7)
            : 0;

        $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

        $billNumber = "PR{$fiscalYearCode}-{$branchId}-{$newNumber}";

        return $billNumber;

    }


    function getSalesBillNumber($branchId)
    {
        $bsDate = NepaliDate::create(Carbon::now())->toBS();
        [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

        $currentBsYear = (int) $currentBsYear;
        $currentBsMonth = (int) $currentBsMonth;


        $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


        $startYear = substr($fiscalYear, -2);
        $endYear = substr($fiscalYear + 1, -2);

        $fiscalYearCode = $startYear . $endYear;


        if (!$branchId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Branch not provided.'
            ], 400);
        }

        $lastStock = Stock::where('type', 'sale')
            ->where('bill_number', 'like', "S{$fiscalYearCode}-{$branchId}-%")
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastStock
            ? (int) substr($lastStock->bill_number, -7)
            : 0;

        $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

        $billNumber = "S{$fiscalYearCode}-{$branchId}-{$newNumber}";

        return $billNumber;

    }


    function getSalesReturnBillNumber($branchId)
    {
        $bsDate = NepaliDate::create(Carbon::now())->toBS();
        [$currentBsYear, $currentBsMonth] = explode('-', $bsDate);

        $currentBsYear = (int) $currentBsYear;
        $currentBsMonth = (int) $currentBsMonth;


        $fiscalYear = $currentBsMonth >= 4 ? $currentBsYear : $currentBsYear - 1;


        $startYear = substr($fiscalYear, -2);
        $endYear = substr($fiscalYear + 1, -2);

        $fiscalYearCode = $startYear . $endYear;



        if (!$branchId) {
            return response()->json([
                'status' => 'error',
                'message' => 'Branch not provided.'
            ], 400);
        }

        $lastStock = Stock::where('type', 'sales_return')
            ->where('bill_number', 'like', "S{$fiscalYearCode}-{$branchId}-%")
            ->whereNull('deleted_at')
            ->orderByDesc('id')
            ->first();

        $lastNumber = $lastStock
            ? (int) substr($lastStock->bill_number, -7)
            : 0;

        $newNumber = str_pad($lastNumber + 1, 7, '0', STR_PAD_LEFT);

        $billNumber = "SR{$fiscalYearCode}-{$branchId}-{$newNumber}";

        return $billNumber;

    }
}

?>
<?php

namespace App\Http\Controllers\CompanyDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Helpers\NepaliCalendar;

class TransactionSummaryController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        try {
            // Get today's and yesterday's AD dates
            $today = now()->toDateString();
            $yesterday = now()->subDay()->toDateString();

            // Helper to calculate totals
            $getTotals = function($model, $date = null) {
                if ($date) {
                    return $model::where('invoice_date', $date)->sum('total_amount');
                }
                return $model::sum('total_amount'); // all-time total
            };

            // Helper to calculate growth
            $calcGrowth = function ($todayValue, $yesterdayValue) {
                if ($yesterdayValue == 0 && $todayValue == 0) return '0%';
                if ($yesterdayValue == 0) return '+100%';
                $change = (($todayValue - $yesterdayValue) / $yesterdayValue) * 100;
                $symbol = $change >= 0 ? '+' : '-';
                return $symbol . number_format(abs($change), 2) . '%';
            };

            // === Sales ===
            $salesToday = $getTotals(Sale::class, $today);
            $salesYesterday = $getTotals(Sale::class, $yesterday);
            $salesAllTime = $getTotals(Sale::class);

            // === Sales Return ===
            $salesReturnToday = $getTotals(SalesReturn::class, $today);
            $salesReturnYesterday = $getTotals(SalesReturn::class, $yesterday);
            $salesReturnAllTime = $getTotals(SalesReturn::class);

            // === Purchase ===
            $purchaseToday = $getTotals(Purchase::class, $today);
            $purchaseYesterday = $getTotals(Purchase::class, $yesterday);
            $purchaseAllTime = $getTotals(Purchase::class);

            // === Purchase Return ===
            $purchaseReturnToday = $getTotals(PurchaseReturn::class, $today);
            $purchaseReturnYesterday = $getTotals(PurchaseReturn::class, $yesterday);
            $purchaseReturnAllTime = $getTotals(PurchaseReturn::class);

            // Prepare JSON response
            $summary = [
                'message' => "Here's what's happening with your store.",
                'data' => [
                    'sales' => [
                        'today' => 'Rs. ' . number_format($salesToday),
                        'yesterday' => 'Rs. ' . number_format($salesYesterday),
                        'all_time' => 'Rs. ' . number_format($salesAllTime),
                        'growth' => $calcGrowth($salesToday, $salesYesterday),
                    ],
                    'sales_return' => [
                        'today' => 'Rs. ' . number_format($salesReturnToday),
                        'yesterday' => 'Rs. ' . number_format($salesReturnYesterday),
                        'all_time' => 'Rs. ' . number_format($salesReturnAllTime),
                        'growth' => $calcGrowth($salesReturnToday, $salesReturnYesterday),
                    ],
                    'purchase' => [
                        'today' => 'Rs. ' . number_format($purchaseToday),
                        'yesterday' => 'Rs. ' . number_format($purchaseYesterday),
                        'all_time' => 'Rs. ' . number_format($purchaseAllTime),
                        'growth' => $calcGrowth($purchaseToday, $purchaseYesterday),
                    ],
                    'purchase_return' => [
                        'today' => 'Rs. ' . number_format($purchaseReturnToday),
                        'yesterday' => 'Rs. ' . number_format($purchaseReturnYesterday),
                        'all_time' => 'Rs. ' . number_format($purchaseReturnAllTime),
                        'growth' => $calcGrowth($purchaseReturnToday, $purchaseReturnYesterday),
                    ],
                ]
            ];

            return response()->json($summary);

        } catch (\Exception $e) {
            \Log::error('TransactionSummaryController Error: '.$e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}

<?php

namespace App\Http\Controllers\CompanyDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Models\Customer;
use App\Models\Sale;
use Carbon\Carbon;

class OverallInformationController extends Controller
{
    public function index(): JsonResponse
    {
        // Total customers (ledger_type = customer or both)
        $totalCustomers = Customer::whereIn('ledger_type', ['customer', 'both'])->count();

        // Total vendors (ledger_type = vendor or both)
        $totalVendors = Customer::whereIn('ledger_type', ['vendor', 'both'])->count();

        // Total sales orders
        $totalSalesOrders = Sale::count(); // Count all sales

        return response()->json([
            'total_customers' => $totalCustomers,
            'total_vendors' => $totalVendors,
            'total_sales_orders' => $totalSalesOrders,
        ]);
    }

      public function customerGrowth(): JsonResponse
    {
        $today = Carbon::today();
        $weekStart = Carbon::now()->startOfWeek();
        $monthStart = Carbon::now()->startOfMonth();

        // Helper function to calculate growth %
        $calculateGrowth = fn($current, $previous) => $previous > 0 
            ? round((($current - $previous) / $previous) * 100, 2)
            : 100;

        // Query for first-time customers and 'both'
        $totalFirstTimeToday = Customer::whereIn('ledger_type', ['customer', 'both'])
            ->whereDate('created_at', $today)
            ->count();

        $totalFirstTimeWeek = Customer::whereIn('ledger_type', ['customer', 'both'])
            ->whereBetween('created_at', [$weekStart, $today])
            ->count();

        $totalFirstTimeMonth = Customer::whereIn('ledger_type', ['customer', 'both'])
            ->whereBetween('created_at', [$monthStart, $today])
            ->count();

        // Query for repeat customers and 'both'
        $totalRepeat = Customer::select('id')
            ->whereIn('ledger_type', ['customer', 'both'])
            ->groupBy('id')
            ->havingRaw('COUNT(id) > 1')
            ->get()
            ->count();

        // Example: growth compared to previous period (yesterday, last week, last month)
        $prevDay = $today->copy()->subDay();
        $prevWeekStart = $weekStart->copy()->subWeek();
        $prevMonthStart = $monthStart->copy()->subMonth();
        
        $prevFirstTimeToday = Customer::whereIn('ledger_type', ['customer', 'both'])
            ->whereDate('created_at', $prevDay)
            ->count();

        $prevFirstTimeWeek = Customer::whereIn('ledger_type', ['customer', 'both'])
            ->whereBetween('created_at', [$prevWeekStart, $weekStart->subDay()])
            ->count();

        $prevFirstTimeMonth = Customer::whereIn('ledger_type', ['customer', 'both'])
            ->whereBetween('created_at', [$prevMonthStart, $monthStart->subDay()])
            ->count();

        return response()->json([
            'today' => [
                'first_time' => $totalFirstTimeToday,
                'repeat' => $totalRepeat,
                'growth_percentage' => $calculateGrowth($totalFirstTimeToday, $prevFirstTimeToday) . '%',
            ],
            'weekly' => [
                'first_time' => $totalFirstTimeWeek,
                'repeat' => $totalRepeat,
                'growth_percentage' => $calculateGrowth($totalFirstTimeWeek, $prevFirstTimeWeek) . '%',
            ],
            'monthly' => [
                'first_time' => $totalFirstTimeMonth,
                'repeat' => $totalRepeat,
                'growth_percentage' => $calculateGrowth($totalFirstTimeMonth, $prevFirstTimeMonth) . '%',
            ],
        ]);
    }
}

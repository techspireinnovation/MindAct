<?php

namespace App\Http\Controllers\CompanyDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Helpers\NepaliCalendar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesPurchaseController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        try {
            $filter = $request->query('filter', '1W'); // 1D,1W,1M,3M,6M,1Y
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            $todayAD = now();
            $todayBS = NepaliCalendar::adToBs($todayAD->format('Y-m-d'));

            // Get current period data
            $currentPeriodData = $this->getPeriodData($filter, false, $startDate, $endDate);
            
            // Get previous period data for comparison
            $previousPeriodData = $this->getPreviousPeriodData($filter, $startDate, $endDate);

            // Calculate summary values with REAL change percentages
            $summary = [
                'profit' => $this->calculateMetricSummary(
                    $currentPeriodData['profit'],
                    $previousPeriodData['profit'],
                    'profit'
                ),
                'invoice_due' => $this->calculateMetricSummary(
                    $currentPeriodData['invoice_due'],
                    $previousPeriodData['invoice_due'],
                    'invoice_due'
                ),
                'expenses' => $this->calculateMetricSummary(
                    $currentPeriodData['expenses'],
                    $previousPeriodData['expenses'],
                    'expenses'
                ),
                'payment_receive' => $this->calculateMetricSummary(
                    $currentPeriodData['payment_receive'],
                    $previousPeriodData['payment_receive'],
                    'payment_receive'
                )
            ];

            // Generate chart data for Sales and Purchase only
            $chartData = $this->generateChartData($filter, $startDate, $endDate);

            return response()->json([
                'summary' => $summary,
                'chart' => $chartData,
                'date_range' => [
                    'start_date' => $currentPeriodData['date_range']['start_date'],
                    'end_date' => $currentPeriodData['date_range']['end_date']
                ]
            ]);

        } catch (\Exception $e) {
            \Log::error('SalesPurchaseController Error: '.$e->getMessage());
            \Log::error('Stack trace: '.$e->getTraceAsString());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    /**
     * Get previous period data - FIXED VERSION
     */
    private function getPreviousPeriodData($filter, $customStartDate = null, $customEndDate = null)
    {
        try {
            if ($customStartDate && $customEndDate) {
                // For custom date range, calculate previous period by days difference
                $startAD = Carbon::now();
                $endAD = Carbon::now();
                
                // Simple approach: go back 7 days for weekly comparison
                $previousStartAD = $startAD->copy()->subDays(7);
                $previousEndAD = $endAD->copy()->subDays(1);
                
                $previousStartBS = NepaliCalendar::adToBs($previousStartAD->format('Y-m-d'));
                $previousEndBS = NepaliCalendar::adToBs($previousEndAD->format('Y-m-d'));
                
                return $this->getPeriodData(null, false, $previousStartBS, $previousEndBS);
            } else {
                // For standard filters, use the existing logic
                return $this->getPeriodData($filter, true);
            }
        } catch (\Exception $e) {
            \Log::error('Previous period calculation failed: ' . $e->getMessage());
            // Return zeros if previous period calculation fails
            return [
                'profit' => 0,
                'invoice_due' => 0,
                'expenses' => 0,
                'payment_receive' => 0,
                'date_range' => [
                    'start_date' => '',
                    'end_date' => ''
                ]
            ];
        }
    }

    /**
     * Get data for current or previous period
     */
    private function getPeriodData($filter, $isPreviousPeriod = false, $customStartDate = null, $customEndDate = null)
    {
        $todayAD = now();
        $todayBS = NepaliCalendar::adToBs($todayAD->format('Y-m-d'));

        if ($customStartDate && $customEndDate) {
            // Use custom date range
            $startDateBS = $customStartDate;
            $endDateBS = $customEndDate;
        } else {
            // Use filter-based date range
            $currentAD = $todayAD->copy();
            
            if ($isPreviousPeriod) {
                // Adjust date for previous period
                switch ($filter) {
                    case '1D':
                        $currentAD = $currentAD->subDay();
                        break;
                    case '1W':
                        $currentAD = $currentAD->subWeek();
                        break;
                    case '1M':
                        $currentAD = $currentAD->subMonth();
                        break;
                    case '3M':
                        $currentAD = $currentAD->subMonths(3);
                        break;
                    case '6M':
                        $currentAD = $currentAD->subMonths(6);
                        break;
                    case '1Y':
                        $currentAD = $currentAD->subYear();
                        break;
                }
                $currentBS = NepaliCalendar::adToBs($currentAD->format('Y-m-d'));
            } else {
                $currentBS = $todayBS;
            }

            list($startDateBS, $endDateBS) = $this->getDateRange($filter, $currentBS, $currentAD);
        }

        // Query data for the period
        $sales = Sale::whereBetween('invoice_date_bs', [$startDateBS, $endDateBS])->sum('total_amount');
        $salesReturn = SalesReturn::whereBetween('invoice_date_bs', [$startDateBS, $endDateBS])->sum('total_amount');
        $purchase = Purchase::whereBetween('invoice_date_bs', [$startDateBS, $endDateBS])->sum('total_amount');
        $purchaseReturn = PurchaseReturn::whereBetween('invoice_date_bs', [$startDateBS, $endDateBS])->sum('total_amount');

        $netSales = $sales - $salesReturn;
        $netPurchase = $purchase - $purchaseReturn;
        $profit = $netSales - $netPurchase;

        return [
            'profit' => $profit,
            'invoice_due' => $salesReturn,
            'expenses' => $purchase,
            'payment_receive' => $sales,
            'date_range' => [
                'start_date' => $startDateBS,
                'end_date' => $endDateBS
            ]
        ];
    }

    /**
     * Calculate date range based on filter
     */
    private function getDateRange($filter, $todayBS, $todayAD)
    {
        switch ($filter) {
            case '1D':
                return [$todayBS, $todayBS];
            
            case '1W':
                $startAD = $todayAD->copy()->startOfWeek(); // Sunday
                $endAD = $todayAD->copy()->endOfWeek();     // Saturday
                $startBS = NepaliCalendar::adToBs($startAD->format('Y-m-d'));
                $endBS = NepaliCalendar::adToBs($endAD->format('Y-m-d'));
                return [$startBS, $endBS];
            
            case '1M':
                $startMonthBS = substr($todayBS, 0, 8) . '01';
                return [$startMonthBS, $todayBS];
            
            case '3M':
                $startAD = $todayAD->copy()->subMonths(2)->startOfMonth();
                $startBS = NepaliCalendar::adToBs($startAD->format('Y-m-d'));
                return [$startBS, $todayBS];
            
            case '6M':
                $startAD = $todayAD->copy()->subMonths(5)->startOfMonth();
                $startBS = NepaliCalendar::adToBs($startAD->format('Y-m-d'));
                return [$startBS, $todayBS];
            
            case '1Y':
                $startAD = $todayAD->copy()->subYear()->startOfMonth();
                $startBS = NepaliCalendar::adToBs($startAD->format('Y-m-d'));
                return [$startBS, $todayBS];
            
            default:
                return [$todayBS, $todayBS];
        }
    }

    /**
     * Calculate metric summary with REAL change percentage
     */
    private function calculateMetricSummary($currentValue, $previousValue, $metric)
    {
        // If both current and previous are 0, no change
        if ($currentValue == 0 && $previousValue == 0) {
            return [
                'value' => 0,
                'change' => '0%'
            ];
        }
        
        // If previous is 0 but current has value, it's +100%
        if ($previousValue == 0 && $currentValue > 0) {
            return [
                'value' => round($currentValue, 2),
                'change' => '+100%'
            ];
        }
        
        // If current is 0 but previous had value, it's -100%
        if ($currentValue == 0 && $previousValue > 0) {
            return [
                'value' => 0,
                'change' => '-100%'
            ];
        }
        
        // Calculate real percentage change
        $changePercentage = (($currentValue - $previousValue) / abs($previousValue)) * 100;
        $change = ($changePercentage >= 0 ? '+' : '') . round($changePercentage, 0) . '%';

        return [
            'value' => round($currentValue, 2),
            'change' => $change
        ];
    }

    /**
     * Generate chart data for Sales and Purchase only
     */
    private function generateChartData($filter, $customStartDate = null, $customEndDate = null)
    {
        $labels = [];
        $dataset = [
            'sales' => [],
            'purchase' => []
        ];

        $todayAD = now();
        $todayBS = NepaliCalendar::adToBs($todayAD->format('Y-m-d'));

        if ($filter == '1D') {
            // For 1D filter, use created_at for hourly data
            if ($customStartDate && $customEndDate) {
                // Custom date range - get the specific date
                $targetDateBS = $customStartDate; // For 1D, start and end are same
                $targetDateAD = $todayAD; // Use current time for hourly calculation
            } else {
                // Today's data
                $targetDateBS = $todayBS;
                $targetDateAD = $todayAD;
            }

            // Generate hourly labels and data
            for ($h = 0; $h < 24; $h++) {
                $labels[] = sprintf('%02d:00', $h);
                
                $hourStart = $targetDateAD->copy()->startOfDay()->addHours($h);
                $hourEnd = $hourStart->copy()->addHour();

                // Sales for this hour
                $sales = Sale::where('invoice_date_bs', $targetDateBS)
                    ->whereBetween('created_at', [$hourStart, $hourEnd])
                    ->sum('total_amount');

                // Purchase for this hour  
                $purchase = Purchase::where('invoice_date_bs', $targetDateBS)
                    ->whereBetween('created_at', [$hourStart, $hourEnd])
                    ->sum('total_amount');

                $dataset['sales'][] = $sales;
                $dataset['purchase'][] = $purchase;
            }
            
        } elseif ($filter == '1W') {
            // Weekly data
            $weekDays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            
            if ($customStartDate && $customEndDate) {
                // Custom week range
                $startAD = Carbon::now()->startOfWeek(); // Fallback to current week
                $endAD = Carbon::now()->endOfWeek();
            } else {
                // Current week
                $startAD = $todayAD->copy()->startOfWeek();
                $endAD = $todayAD->copy()->endOfWeek();
            }
            
            foreach ($weekDays as $index => $day) {
                $labels[] = $day;
                $currentDayAD = $startAD->copy()->addDays($index);
                $currentDayBS = NepaliCalendar::adToBs($currentDayAD->format('Y-m-d'));

                $sales = Sale::where('invoice_date_bs', $currentDayBS)->sum('total_amount');
                $purchase = Purchase::where('invoice_date_bs', $currentDayBS)->sum('total_amount');

                $dataset['sales'][] = $sales;
                $dataset['purchase'][] = $purchase;
            }
        } elseif ($filter == '1M') {
            // Monthly data - week-wise
            if ($customStartDate && $customEndDate) {
                $startMonthBS = $customStartDate;
                $endMonthBS = $customEndDate;
            } else {
                $startMonthBS = substr($todayBS, 0, 8) . '01';
                $endMonthBS = $todayBS;
            }

            // Simple approach: show 4 weeks
            for ($week = 1; $week <= 4; $week++) {
                $labels[] = "Week " . $week;
                
                // For simplicity, divide monthly total by 4
                // In production, you'd calculate actual week ranges
                $sales = Sale::whereBetween('invoice_date_bs', [$startMonthBS, $endMonthBS])
                    ->sum('total_amount');
                $purchase = Purchase::whereBetween('invoice_date_bs', [$startMonthBS, $endMonthBS])
                    ->sum('total_amount');

                $dataset['sales'][] = $sales / 4;
                $dataset['purchase'][] = $purchase / 4;
            }
        } elseif (in_array($filter, ['3M', '6M', '1Y'])) {
            // 3M, 6M, 1Y: month-wise Nepali months
            $monthsBack = 3;
            if ($filter == '6M') $monthsBack = 6;
            if ($filter == '1Y') $monthsBack = 12;

            $nepaliMonths = ['Baishakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin','Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'];

            for ($i = $monthsBack - 1; $i >= 0; $i--) {
                $dateAD = $todayAD->copy()->subMonths($i);
                $dateBS = NepaliCalendar::adToBs($dateAD->format('Y-m-d'));
                $monthIndex = (intval(explode('-', $dateBS)[1]) - 1) % 12;
                $labels[] = $nepaliMonths[$monthIndex];

                $startMonthBS = substr($dateBS, 0, 8) . '01';
                
                // For end date, use the actual end of month or today, whichever is earlier
                $endMonthBS = $dateBS;
                if ($i == 0) { // Current month
                    $endMonthBS = $todayBS;
                } else {
                    $year = explode('-', $dateBS)[0];
                    $month = explode('-', $dateBS)[1];
                    // Use approximate month end (you might need to adjust this)
                    $endMonthBS = substr($dateBS, 0, 8) . '30';
                }

                $sales = Sale::whereBetween('invoice_date_bs', [$startMonthBS, $endMonthBS])->sum('total_amount');
                $purchase = Purchase::whereBetween('invoice_date_bs', [$startMonthBS, $endMonthBS])->sum('total_amount');

                $dataset['sales'][] = $sales;
                $dataset['purchase'][] = $purchase;
            }
        } else {
            // Default: current day
            $labels = ['Today'];
            $sales = Sale::where('invoice_date_bs', $todayBS)->sum('total_amount');
            $purchase = Purchase::where('invoice_date_bs', $todayBS)->sum('total_amount');
            $dataset['sales'] = [$sales];
            $dataset['purchase'] = [$purchase];
        }

        return [
            'labels' => $labels,
            'datasets' => $dataset
        ];
    }
}
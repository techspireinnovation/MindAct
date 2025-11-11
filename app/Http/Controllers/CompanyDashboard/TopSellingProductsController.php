<?php

namespace App\Http\Controllers\CompanyDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Models\SaleProduct;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class TopSellingProductsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filter = $request->query('filter', 'daily'); // default: daily
        $now = Carbon::now();

        // Determine period
        switch ($filter) {
            case 'daily':
                $startDate = Carbon::today();
                $endDate = Carbon::today()->endOfDay();
                $prevStart = Carbon::yesterday();
                $prevEnd = Carbon::yesterday()->endOfDay();
                break;

            case 'weekly':
                $startDate = Carbon::now()->startOfWeek();
                $endDate = $now;
                $prevStart = Carbon::now()->startOfWeek()->subWeek();
                $prevEnd = $startDate->copy()->subDay();
                break;

            case 'monthly':
                $startDate = Carbon::now()->startOfMonth();
                $endDate = $now;
                $prevStart = Carbon::now()->startOfMonth()->subMonth();
                $prevEnd = $startDate->copy()->subDay();
                break;

            default:
                return response()->json(['error' => 'Invalid filter'], 400);
        }

        // Top products for current period
        $topProducts = SaleProduct::select(
                DB::raw('COALESCE(product_name, name) as product_name'),
                DB::raw('SUM(quantity) as total_quantity'),
                DB::raw('SUM(quantity * price) as total_revenue')
            )
            ->whereBetween('created_at', [$startDate, $endDate])
            ->groupBy(DB::raw('COALESCE(product_name, name)'))
            ->orderByDesc('total_quantity')
            ->limit(10)
            ->get();

        // Previous period for growth calculation
        $prevData = SaleProduct::select(
                DB::raw('COALESCE(product_name, name) as product_name'),
                DB::raw('SUM(quantity) as total_quantity')
            )
            ->whereBetween('created_at', [$prevStart, $prevEnd])
            ->groupBy(DB::raw('COALESCE(product_name, name)'))
            ->pluck('total_quantity', 'product_name');

        // Map response
        $response = $topProducts->map(function ($item) use ($prevData) {
            $prevQty = $prevData->get($item->product_name, 0);
            $growth = $prevQty > 0 ? round((($item->total_quantity - $prevQty) / $prevQty) * 100, 2) : 100;

            return [
                'product' => $item->product_name,
                'revenue' => 'Rs.' . round($item->total_revenue),
                'total_sales' => $item->total_quantity . '+ Sales',
                'growth_percentage' => ($growth >= 0 ? '+' : '') . $growth . '%'
            ];
        });

        return response()->json($response);
    }
}

<?php

namespace App\Http\Controllers\CompanyDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Models\Sale;
use Illuminate\Support\Carbon;

class RecentSalesController extends Controller
{
    public function index(): JsonResponse
    {
        // Get the 10 most recent sales
        $recentSales = Sale::with(['saleProducts.product.category'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $response = [];

        foreach ($recentSales as $sale) {
            foreach ($sale->saleProducts as $product) {
                $response[] = [
                    'product_name' => $product->product_name ?? ($product->product->name ?? 'N/A'),
                    'category' => $product->product->category->name ?? 'N/A', // only category name
                    'amount' => 'Rs.' . ($product->quantity * $product->price),
                    'date' => $this->formatDate($sale->created_at),
                    'status' => $sale->status ?? 'completed', // replace with actual status column if exists
                ];
            }
        }

        return response()->json($response);
    }

    private function formatDate($date)
    {
        $date = Carbon::parse($date);
        $today = Carbon::today();
        $yesterday = Carbon::yesterday();

        if ($date->isToday()) return 'Today';
        if ($date->isYesterday()) return 'Yesterday';

        return $date->format('d M Y');
    }
}

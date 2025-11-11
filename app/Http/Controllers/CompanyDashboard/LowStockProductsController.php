<?php

namespace App\Http\Controllers\CompanyDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Models\Product;

class LowStockProductsController extends Controller
{
    /**
     * Get Top 10 Low Stock Products based on priority (lowest stock ratio first)
     */
    public function index(): JsonResponse
    {
        try {
            // Fetch all active products with minimum_stock value
            $products = Product::where('is_active', 1)
                ->whereNotNull('minimum_stock')
                ->get()
                ->map(function ($product) {
                    // Calculate stock ratio for priority (lower = higher priority)
                    $currentStock = $product->getProductStockQuantityAttribute();
                    $minStock = $product->minimum_stock ?: 1; // avoid divide by zero

                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'brand' => $product->brand->name ?? null,
                        'category' => $product->category->name ?? null,
                        'current_stock' => $currentStock,
                        'minimum_stock' => $minStock,
                        'stock_gap' => $minStock - $currentStock,
                        'priority_ratio' => round($currentStock / $minStock, 2),
                    ];
                })
                // Sort by ratio (lowest first = more urgent)
                ->sortBy('priority_ratio')
                ->take(10)
                ->values();

            return response()->json([
                'status' => true,
                'message' => 'Top 10 low stock products fetched successfully.',
                'data' => $products
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => false,
                'message' => 'Error fetching low stock products.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}

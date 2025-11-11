<?php

namespace App\Http\Controllers\CompanyDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Purchase;
use App\Models\PurchaseReturn;

class RecentInvoiceHistoryController extends Controller
{
    public function index(): JsonResponse
    {
        $totalSalesInvoice = Sale::count();
        $totalSalesReturn = SalesReturn::count();
        $totalPurchase = Purchase::count();
        $totalPurchaseReturn = PurchaseReturn::count();

        $response = [
            'Total Sales Invoice' => $totalSalesInvoice,
            'Total Sales Return' => $totalSalesReturn,
            'Total Purchase' => $totalPurchase,
            'Total Purchase Return' => $totalPurchaseReturn,
        ];

        return response()->json($response);
    }
}

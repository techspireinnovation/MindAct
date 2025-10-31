<?php

namespace App\Http\Controllers\CompanyDashboard;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DashboardAction;
use Illuminate\Support\Facades\DB;


class QuickActionController extends Controller
{
    public function index()
    {
        $actions = [
            // Product Master Actions
            ["id" => 1, "main" => "Product Master", "submain" => "Create Product Category", "route" => "/create-product-category"],
            ["id" => 2, "main" => "Product Master", "submain" => "Create Product Type", "route" => "/create-product-type"],
            ["id" => 3, "main" => "Product Master", "submain" => "Create Branch", "route" => "/create-branch"],
            ["id" => 4, "main" => "Product Master", "submain" => "Create Area", "route" => "/create-area"],
            ["id" => 5, "main" => "Product Master", "submain" => "Create Cash", "route" => "/create-cash"],
            ["id" => 6, "main" => "Product Master", "submain" => "Create Unit Measurement", "route" => "/create-unit-measurement"],
            ["id" => 7, "main" => "Product Master", "submain" => "Create Product Sub Category", "route" => "/create-product-sub-category"],
            ["id" => 8, "main" => "Product Master", "submain" => "Create Brand", "route" => "/create-brand"],
            ["id" => 9, "main" => "Product Master", "submain" => "Create Product Field", "route" => "/create-product-field"],
            ["id" => 10, "main" => "Product Master", "submain" => "Create Location", "route" => "/create-location"],
            ["id" => 11, "main" => "Product Master", "submain" => "Create Store List", "route" => "/create-store-list"],
            ["id" => 12, "main" => "Product Master", "submain" => "Create Unit Conversion", "route" => "/create-unit-conversion"],
            ["id" => 13, "main" => "Product Master", "submain" => "Create Product", "route" => "/create-new-product"],
            ["id" => 14, "main" => "Product Master", "submain" => "Create Purchase", "route" => "/create-purchase"],
            ["id" => 15, "main" => "Product Master", "submain" => "Create Purchase Return", "route" => "/create-purchase-return"],
            ["id" => 16, "main" => "Product Master", "submain" => "Create Additional Purchase Bill", "route" => "/create-additional-purchase-bill"],
            ["id" => 17, "main" => "Product Master", "submain" => "Create Sales", "route" => "/create-sales"],
            ["id" => 18, "main" => "Product Master", "submain" => "Create Sales Return", "route" => "/create-sales-return"],
            ["id" => 19, "main" => "Product Master", "submain" => "Create Customer", "route" => "/create-customer"],
            ["id" => 20, "main" => "Product Master", "submain" => "Create Sales Man", "route" => "/create-sales-man"],
            ["id" => 21, "main" => "Product Master", "submain" => "Create Shift", "route" => "/create-shift"],
            ["id" => 22, "main" => "Product Master", "submain" => "Create Noozle", "route" => "/create-noozle"],
            // Account Master Actions
            ["id" => 23, "main" => "Account Master", "submain" => "Create Main Group", "route" => "/create-main-group"],
            ["id" => 24, "main" => "Account Master", "submain" => "Create Sub Group", "route" => "/create-sub-group"],
            ["id" => 25, "main" => "Account Master", "submain" => "Create Account Group", "route" => "/create-account-group"],
            ["id" => 26, "main" => "Account Master", "submain" => "Create Account Head", "route" => "/create-account-head"],
            ["id" => 27, "main" => "Account Master", "submain" => "Create Bank Voucher", "route" => "/create-bank-voucher"],
            ["id" => 28, "main" => "Account Master", "submain" => "Create Bank Ledger", "route" => "/create-bank-ledger"],
            ["id" => 29, "main" => "Account Master", "submain" => "Create Journal Voucher", "route" => "/create-journal-voucher"],
            ["id" => 30, "main" => "Account Master", "submain" => "Create Receipt Voucher", "route" => "/create-receipt-list"],
            ["id" => 31, "main" => "Account Master", "submain" => "Create Payment Voucher", "route" => "/create-payment-voucher"],
            ["id" => 32, "main" => "Account Master", "submain" => "Create Project List", "route" => "/create-project"],
            ["id" => 33, "main" => "Account Master", "submain" => "Create Fixed Asset Group", "route" => "/create-fixed-assets-group"],
            ["id" => 34, "main" => "Account Master", "submain" => "Create Fixed Asset Account", "route" => "/create-fixed-assets-account"],
            //Stock
             ["id" => 35, "main" => "Stock", "submain" => "Create Open Stock Entry", "route" => "/create-open-stock-entry"],
             ["id" => 36, "main" => "Stock", "submain" => "Create Stock Adjustment", "route" => "/create-stock-adjustment"],
             ["id" => 37, "main" => "Stock", "submain" => "Create Stock Transfer", "route" => "/create-stock-transfer"],
             ["id" => 38, "main" => "Stock", "submain" => "Create Stock Receive", "route" => "/create-stock-receive"],
             ["id" => 39, "main" => "Stock", "submain" => "Create Stock Reconciliation", "route" => "/create-stock-reconciliation"],
             ["id" => 40, "main" => "Stock", "submain" => "Create Production Assamble", "route" => "/create-production-assamble"],
             ["id" => 41, "main" => "Stock", "submain" => "Create Working And Shrinking", "route" => "/create-shrink-work-loss"],
             ["id" => 42, "main" => "Stock", "submain" => "Meter Reading Form", "route" => "/meter-reading-form"],
             //Report
             ["id" => 43, "main" => "Report", "submain" => "Stock Register Details", "route" => "/stock-register-details"],
                ["id" => 44, "main" => "Report", "submain" => "Product List Details", "route" => "/product-list-details"],
                ["id" => 45, "main" => "Report", "submain" => "Stock Ledger", "route" => "/stock-ledger-report"],
                ["id" => 46, "main" => "Report", "submain" => "Purchase Sale Book", "route" => "/purchase-sale-book"],
                ["id" => 47, "main" => "Report", "submain" => "Vendor Suppliers", "route" => "/vendor-suppliers"],
                ["id" => 48, "main" => "Report", "submain" => "Item Wise Purchase Sales Price Details", "route" => "/item-wise-pur-sale-price-detials"],
                ["id" => 49, "main" => "Report", "submain" => "Gross Profit Ratio", "route" => "/gross-profit-ratio"],
                ["id" => 50, "main" => "Report", "submain" => "Gross Margin", "route" => "/gross-margin"],
                ["id" => 51, "main" => "Report", "submain" => "VAT Return Data", "route" => "/vat-return-data"],
                ["id" => 52, "main" => "Report", "submain" => "VAT Return File", "route" => "/vat-return-file"],
                ["id" => 53, "main" => "Report", "submain" => "Voucher Report List", "route" => "/voucher-report-list"],
                ["id" => 54, "main" => "Report", "submain" => "Charts of Account", "route" => "/charts-of-account"],
                ["id" => 55, "main" => "Report", "submain" => "Ledger", "route" => "/ledger"],
                ["id" => 56, "main" => "Report", "submain" => "Cash Bank", "route" => "/cash-bank"],
        ];

         // Default active actions
    $defaultTrue = [
        'Create Sales', 
        'Create Sales Return', 
        'Create Purchase', 
        'Create Purchase Return', 
        'Create Product', 
        'Create Bank Ledger'
    ];

    // Update or create actions in DB
    foreach ($actions as $data) {
        $isActive = in_array($data['submain'], $defaultTrue);
        DashboardAction::updateOrCreate(
            ['submain' => $data['submain']],
            array_merge($data, ['active' => $isActive])
        );
    }

    // Fetch all actions from DB
    $allActions = DashboardAction::all();

    // Convert active to boolean
    $allActions->transform(function ($action) {
        $action->active = (bool) $action->active;
        return $action;
    });

    $totalTrue = DashboardAction::where('active', true)->count();
    $totalFalse = DashboardAction::where('active', false)->count();

    return response()->json([
        'success' => true,
        'data' => $allActions,
        'total_true' => $totalTrue,
        'total_false' => $totalFalse,
    ]);
    }

public function toggle(Request $request)
{
    $data = $request->all();

    if (empty($data)) {
        return response()->json([
            'success' => false,
            'message' => 'No action provided.'
        ], 400);
    }

    // Fetch all actions from DB and normalize keys
    $dbActions = DB::table('dashboard_actions')
        ->select('id', 'submain', 'active')
        ->get()
        ->mapWithKeys(function ($row) {
            $key = strtolower(str_replace(' ', '_', $row->submain));
            return [
                $key => [
                    'id' => $row->id,
                    'submain' => $row->submain,
                    'active' => (bool) $row->active
                ]
            ];
        })->toArray();

    $requestedActivations = [];
    $requestedDeactivations = [];
    $notFoundKeys = [];

    // Determine which actions to activate/deactivate
    foreach ($data as $key => $value) {
        $normKey = strtolower($key);

        if (!isset($dbActions[$normKey])) {
            $notFoundKeys[] = $key;
            continue;
        }

        $currentActive = $dbActions[$normKey]['active'];

        if ($value === true && !$currentActive) {
            $requestedActivations[] = $normKey;
        } elseif ($value === false && $currentActive) {
            $requestedDeactivations[] = $normKey;
        }
    }

    // Count currently active actions
    $currentActiveCount = collect($dbActions)
        ->filter(fn($v) => $v['active'] === true)
        ->count();

    // Calculate final total if applied
    $finalTotal = $currentActiveCount - count($requestedDeactivations) + count($requestedActivations);

    if ($finalTotal > 6) {
        return response()->json([
            'success' => false,
            'message' => 'Only 6 actions can be active at a time.',
            'details' => [
                'current_active' => $currentActiveCount,
                'requested_activations' => $requestedActivations,
                'requested_deactivations' => $requestedDeactivations,
                'final_total_if_applied' => $finalTotal,
            ]
        ], 400);
    }

    // Apply updates
    foreach ($requestedActivations as $k) {
        DB::table('dashboard_actions')
            ->where('id', $dbActions[$k]['id'])
            ->update(['active' => true]);
    }

    foreach ($requestedDeactivations as $k) {
        DB::table('dashboard_actions')
            ->where('id', $dbActions[$k]['id'])
            ->update(['active' => false]);
    }

    // Final counts
    $totalTrue = DB::table('dashboard_actions')->where('active', true)->count();
    $totalFalse = DB::table('dashboard_actions')->where('active', false)->count();

    return response()->json([
        'success' => true,
        'message' => 'Active statuses updated successfully.',
        'updated' => [
            'activated' => array_values($requestedActivations),
            'deactivated' => array_values($requestedDeactivations),
            'not_found_keys' => $notFoundKeys
        ],
        'total_true' => $totalTrue,
        'total_false' => $totalFalse,
    ]);
}

public function getStatus()
{
    // Get all actions from DB
    $actions = DashboardAction::all();

    // Format the response: keep id, name (submain), main, route, and active
    $formatted = $actions->map(function ($action) {
        return [
            'id' => $action->id,
            'main' => $action->main,
            'submain' => $action->submain,
            'route' => $action->route,
            'active' => (bool) $action->active,
        ];
    });

    // Count totals
    $totalTrue = $actions->where('active', true)->count();
    $totalFalse = $actions->where('active', false)->count();

    return response()->json([
        'success' => true,
        'data' => $formatted,
        'total_true' => $totalTrue,
        'total_false' => $totalFalse,
    ]);
}




}

<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\PurchaseReturn;
use Illuminate\Http\Request;

use function PHPUnit\Framework\isFalse;
use function PHPUnit\Framework\isNull;

class AutoNumberController extends Controller
{
    function getAutoNumbers()
    {
        $lastPurchase = Purchase::latest('id')->first();
        $lastPurchaseId = ($lastPurchase) ? $lastPurchase->id : 1;

        $lastPurchaseReturn = PurchaseReturn::latest('id')->first();
        $lastPurchaseReturnId = ($lastPurchaseReturn) ? $lastPurchaseReturn->id : 1;
        return response()->json([
            'purchase' => "P-" . $lastPurchaseId,
            'purchaseReturn' => "P-" . $lastPurchaseReturnId,
        ], 200);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use Illuminate\Http\Request;

class AutoNumberController extends Controller
{
    function getAutoNumbers()
    {
        $lastPurchase = Purchase::latest('id')->first();
        //$salePurchase = Purchase::latest('id')->first();
        return response()->json([
            'purchase' => "P-" . $lastPurchase->id + 1,
            //'sale' => "S-" . $salePurchase->id+1
        ], 200);
    }
}

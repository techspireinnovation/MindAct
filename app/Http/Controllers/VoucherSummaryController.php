<?php

namespace App\Http\Controllers;

use App\Models\VoucherSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Validator;

class VoucherSummaryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'nullable|string|in:PURCHASE,SALE,PURCHASE_RETURN,SALE_RETURN',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }


        $vouchers = VoucherSummary::selectRaw('
                   
                    date_bs,
                    date,
                    voucher_number,
                    a.name as account_head,
                    particulars,
                    debit,
                    credit
        ')
            ->leftJoin('account_heads as a', 'account_head_id', '=', 'a.id')
            //->where('v.type', 'your_voucher_type')
            ->orderBy('date', 'desc')
            ->paginate(200);

        return response()->json($vouchers);


    }
}

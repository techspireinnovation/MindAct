<?php

namespace App\Http\Controllers;

use App\Models\VoucherSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Validator;

class VoucherSummaryController extends Controller
{
    public function ledgerList(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_date' => 'required|string',
            'to_date' => 'required|string',
            'account_head_id' => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $vouchers = VoucherSummary::selectRaw('
                    date_bs,
                    date,
                    voucher_number,
                    a.name AS account_head,
                    particulars,
                    debit,type,
                    credit
        ')->leftJoin('account_heads as a', 'account_head_id', '=', 'a.id')->when($request->has('type'), function ($rr) use ($request) {
            $rr->where('type', $request->type);
        })->orderBy('date', 'desc')->paginate(200);

        return response()->json($vouchers);

    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string|in:PURCHASE,SALE,PURCHASE_RETURN,SALE_RETURN,DEBIT,CREDIT,RECEIPT,PAYMENT,ABVT,PRODUCTION',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $vouchers = VoucherSummary::selectRaw('
                    date_bs,
                    date,
                    voucher_number,
                    a.name AS account_head,
                    particulars,
                    debit,type,
                    credit
        ')->leftJoin('account_heads as a', 'account_head_id', '=', 'a.id')->when($request->has('type'), function ($rr) use ($request) {
            $rr->where('type', $request->type);
        })->orderBy('date', 'desc')->paginate(200);

        return response()->json($vouchers);

    }
}

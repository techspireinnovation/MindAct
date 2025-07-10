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
            'account_head_id' => 'nullable|numeric',
            'account_group_id' => 'nullable|numeric',
            'payment_type' => 'nullable|string|in:cash,bank',
            'voucher_number' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $vouchers = VoucherSummary::selectRaw('
                    date_bs,
                    date,
                    tr_bill_number,
                    voucher_number,
                    a.name AS account_head,
                    b.name AS account_group,
                    particulars,
                    debit,type,payment_type,
                    credit
        ')->leftJoin('account_groups as b', 'account_group_id', '=', 'b.id')->leftJoin('account_heads as a', 'account_head_id', '=', 'a.id')->when($request->has('account_head_id'), function ($rr) use ($request) {
            $rr->where('account_head_id', $request->account_head_id);
        })->when($request->has('account_group_id'), function ($rr) use ($request) {
            $rr->where('voucher_summaries.account_group_id', $request->account_group_id);
        })->when($request->has('payment_type'), function ($rr) use ($request) {
            $rr->where('payment_type', operator: strtoupper($request->payment_type));
        })->when($request->has('voucher_number'), function ($rr) use ($request) {
            $rr->where('voucher_number', ($request->voucher_number));
        })->orderBy('date', 'desc')->paginate(200);

        return response()->json($vouchers);

    }

    public function index(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $vouchers = VoucherSummary::selectRaw('
                    date_bs,
                    date,
                    voucher_number,
                    a.name AS account_head,
                    tr_bill_number,
                    particulars,
                    debit,type,
                    credit
        ')->leftJoin('account_heads as a', 'account_head_id', '=', 'a.id')->when($request->has('type'), function ($rr) use ($request) {
            $requestIdentifier = $request->type;
            $requestIdentifierArry = explode(",", $requestIdentifier);
            if (!in_array('ALL', $requestIdentifierArry))
                $rr->whereIn('type', $requestIdentifierArry);
        })->orderBy('date', 'desc')->paginate(200);

        return response()->json($vouchers);

    }
}

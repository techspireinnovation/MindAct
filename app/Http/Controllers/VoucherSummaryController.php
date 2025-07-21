<?php

namespace App\Http\Controllers;

use App\Models\VoucherSummary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
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
                    voucher_summaries.date_bs, voucher_summaries.id,
                    voucher_summaries.date,
                    voucher_summaries.tr_bill_number,
                    voucher_summaries.ref_bill_number,
                    voucher_summaries.voucher_number,
                    a.name AS account_head,
                    b.name AS account_group,
                    voucher_summaries.particulars,
                    voucher_summaries.debit,voucher_summaries.type,voucher_summaries.payment_type,
                    voucher_summaries.credit
        ')->leftJoin('voucher_summary_details as vsd', 'voucher_summaries.id', '=', 'vsd.voucher_summary_id')->leftJoin('account_groups as b', 'voucher_summaries.account_group_id', '=', 'b.id')->leftJoin('account_heads as a', 'voucher_summaries.account_head_id', '=', 'a.id')->when($request->has('account_head_id'), function ($rr) use ($request) {
            $rr->where('voucher_summaries.account_head_id', $request->account_head_id);
        })->when($request->has('account_group_id'), function ($rr) use ($request) {
            $rr->where('voucher_summaries.account_group_id', $request->account_group_id);
        })->when($request->has('payment_type'), function ($rr) use ($request) {
            $rr->where('vsd.payment_type', operator: strtoupper($request->payment_type));
        })->when($request->has('voucher_number'), function ($rr) use ($request) {
            $rr->where('voucher_summaries..voucher_number', ($request->voucher_number));
        })->orderBy('voucher_summaries.date', 'desc')->paginate(250);

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
                    date_bs, voucher_summaries.id,
                    date,
                    voucher_number,
                    ref_bill_number,
                    a.name AS account_head,
                    b.name AS account_group,
                    tr_bill_number,
                    particulars,
                    debit,type,
                    credit
        ')->with(['voucherSummaryInnerDetail', 'voucherSummaryDetail.accountHead:id,name', 'voucherSummaryDetail.accountGroup:id,name'])->leftJoin('account_groups as b', 'account_group_id', '=', 'b.id')->leftJoin('account_heads as a', 'account_head_id', '=', 'a.id')->when($request->has('type'), function ($rr) use ($request) {
            $requestIdentifier = $request->type;
            $requestIdentifierArry = explode(",", $requestIdentifier);
            if (!in_array('ALL', $requestIdentifierArry))
                $rr->whereIn('type', $requestIdentifierArry);
        })->orderBy('date', 'desc')->paginate(200);

        return response()->json($vouchers);
    }


    public function show($id): JsonResponse
    {
        try {
            $item = VoucherSummary::with(['accountHead:id,name', 'accountGroup:id,name'])->findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

}

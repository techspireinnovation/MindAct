<?php

namespace App\Http\Controllers;

use App\Models\ReceiptVoucher;
use App\Models\ReceiptVoucherDetail;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReceiptVoucherController extends Controller
{
    public function index(Request $request)
    {
        $query = ReceiptVoucher::query();
        if ($request->has('keywords')) {
            $query->where('receipt_voucher_number', 'LIKE', '%' . $request->input('keywords') . '%')->orWhere('reference_number', 'LIKE', '%' . $request->input('keywords') . '%');
        }
        return response()->json($query->paginate(10));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'date_ad' => 'nullable|date',
                'date_bs' => 'nullable|string',
                'reference_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('receipt_vouchers')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'receipt_voucher_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('receipt_vouchers')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'receipt_voucher_list' => 'nullable|array',
                'receipt_voucher_list.*.id' => 'nullable|integer|exists:receipt_voucher_details,id',
                'receipt_voucher_list.*.customer_id' => [
                    'nullable',
                    'integer',
                    Rule::exists('customers', 'id')->where(function ($query) use ($request) {
                        $query->where('company_id', $request->company_id);
                    }),
                ],
                'receipt_voucher_list.*.party_name' => 'nullable|string',
                'receipt_voucher_list.*.amount' => 'nullable|numeric',
                'receipt_voucher_list.*.contra_account' => 'nullable|string',
                'receipt_voucher_list.*.remarks' => 'nullable|string',
                'receipt_voucher_list.*.cheque_slip' => 'nullable|string',
                'receipt_voucher_list.*.remaining_balance' => 'nullable|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $companyId = $request->company_id; // Set by middleware

            $receiptVoucher = DB::transaction(function () use ($validated, $id, $companyId) {
                $receiptVoucher = ReceiptVoucher::findOrFail($id);

                // Ensure the receipt voucher belongs to the company
                if ($receiptVoucher->company_id !== $companyId) {
                    throw new \Exception('Receipt Voucher does not belong to the specified company');
                }

                $validated['company_id'] = $companyId;
                $receiptVoucher->update($validated);

                if (isset($validated['receipt_voucher_list'])) {
                    $existingDetailIds = $receiptVoucher->receiptVoucherDetails()->pluck('id')->toArray();
                    $incomingDetailIds = collect($validated['receipt_voucher_list'])->pluck('id')->filter()->toArray();

                    foreach ($validated['receipt_voucher_list'] as $detailData) {
                        $detailData['company_id'] = $companyId; // Set company_id for details
                        if (isset($detailData['id']) && in_array($detailData['id'], $existingDetailIds)) {
                            $detail = ReceiptVoucherDetail::find($detailData['id']);
                            $detail->update($detailData);
                        } else {
                            $receiptVoucher->receiptVoucherDetails()->create($detailData);
                        }
                    }

                    $detailsToDelete = array_diff($existingDetailIds, $incomingDetailIds);
                    ReceiptVoucherDetail::whereIn('id', $detailsToDelete)->delete();
                }

                return $receiptVoucher;
            });

            return response()->json([
                'message' => 'Receipt Voucher Updated',
                'item' => $receiptVoucher->load('receiptVoucherDetails'),
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Receipt Voucher not found'], 404);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['error' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }





    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'date_ad' => 'nullable|date',
                'date_bs' => 'nullable|string',
                'reference_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('receipt_vouchers')


                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'receipt_voucher_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('receipt_vouchers')


                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'receipt_voucher_list' => 'nullable|array',
                'receipt_voucher_list.*.id' => 'nullable',
                'receipt_voucher_list.*.customer_id' => 'nullable|integer|exists:customers,id',
                'receipt_voucher_list.*.party_name' => 'nullable|string',
                'receipt_voucher_list.*.amount' => 'nullable|numeric',
                'receipt_voucher_list.*.contra_account' => 'nullable|string',
                'receipt_voucher_list.*.remarks' => 'nullable|string',
                'receipt_voucher_list.*.cheque_slip' => 'nullable|string',
                'receipt_voucher_list.*.remaining_balance' => 'nullable|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $companyId = $request->company_id; // Set by middleware

            $receiptVoucher = DB::transaction(function () use ($validated, $companyId) {
                $validated['company_id'] = $companyId;
                $receiptVoucher = ReceiptVoucher::create($validated);

                if (isset($validated['receipt_voucher_list'])) {
                    $details = array_map(function ($detail) use ($companyId) {
                        $detail['company_id'] = $companyId; // Override or set company_id
                        return $detail;
                    }, $validated['receipt_voucher_list']);
                    $receiptVoucher->receiptVoucherDetails()->createMany($details);
                }

                return $receiptVoucher;
            });

            return response()->json([
                'message' => 'Receipt Voucher Created',
                'item' => $receiptVoucher->load('receiptVoucherDetails'),
            ], 201);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['error' => 'Creation failed: ' . $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id): JsonResponse
    {
        try {
            $ReceiptVoucher = ReceiptVoucher::where('company_id', $request->company_id)
                ->with([
                    'receiptVoucherDetails'

                ])
                ->findOrFail($id);


            return response()->json([
                'ReceiptVoucher' => $ReceiptVoucher
            ]);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Receipt Voucher not found!'], 404);

        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database query error occurred!'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = ReceiptVoucher::findOrFail($id);
            $item->receiptVoucherDetails()->delete();
            $item->delete();

            return response()->json(['message' => 'Receipt Voucher deleted!!']);


        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {

            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);

            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


}

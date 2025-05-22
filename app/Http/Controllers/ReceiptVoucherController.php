<?php

namespace App\Http\Controllers;

use App\Events\ReceiptVoucher;

use App\Models\ReceiptVoucherDetail;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class ReceiptVoucherController extends Controller
{
    public function index(Request $request){

         $query = ReceiptVoucher::query();
         return response()->json($query->paginate(10));  

    }
        public function update(Request $request, $id): JsonResponse

  {
    
    try {
        $item = ReceiptVoucher::findOrFail($id);
        $validator=Validator::make($request->all(), [
            'company_id' => 'integer|exists:companies,id',
            'date_ad' => 'nullable|date',
            'date_bs' => 'nullable|date',
            'receipt_voucher_number' => [
                'string',
                Rule::unique('receipt_vouchers')->ignore($item->id),
            ],
            'receipt_voucher_list' => 'nullable||array',
            'receipt_voucher_list.*.id' => 'nullable',
      
            'receipt_voucher_list.*.company_id' => 'nullable||integer|exists:companies,id',
            'receipt_voucher_list.*.customer_id' => 'nullable||integer|exists:customers,id',
          
            'receipt_voucher_list.*.party_name' => 'string|nullable|',
            'receipt_voucher_list.*.amount' => 'nullable|numeric',
            'receipt_voucher_list.*.contra_account' => 'nullable',
            'receipt_voucher_list.*.remarks' => 'nullable|string',
            'receipt_voucher_list.*.cheque_slip' => 'nullable|string',
            'receipt_voucher_list.*.remaining_balance' => 'nullable||numeric',

        ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $validated = $validator->validated();

            $ReceiptVoucher = null;

            DB::transaction(function () use ($validated, $id, &$ReceiptVoucher) {
                $ReceiptVoucher = ReceiptVoucher::findOrFail($id);
                $ReceiptVoucher->update($validated);

                // Handle field values
                $existingReceiptVoucherIds = $ReceiptVoucher->receiptVoucherDetails()->pluck('id')->toArray();
                $incomingReceiptVoucherIds = collect($validated['receipt_voucher_list'] ?? [])->pluck('id')->filter()->toArray();

                foreach ($validated['receipt_voucher_list'] ?? [] as $data) {
                    if (isset($data['id'])) {
                        $voucherReceipt = ReceiptVoucher::find($data['id']);
                        if ($voucherReceipt) {
                            $voucherReceipt->update([
                                'receipt_voucher_id' => $data['receipt_voucher_id'],
                                'value' => $data['value'],
                            ]);
                        }
                    } else {
                        $ReceiptVoucher->receiptVoucherDetails()->create($data);
                    }
                }

                $voucherToDelete = array_diff($existingReceiptVoucherIds, $incomingReceiptVoucherIds);
                ReceiptVoucherDetail::whereIn('id', $voucherToDelete)->delete();

            });

            

            return response()->json(['message' => 'ReceiptVoucher Updated', 'ReceiptVoucher' => $ReceiptVoucher->load(['ReceiptVoucherFieldValues', 'ReceiptVoucherLists'])]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['error' => 'Update failed: ' . $e->getMessage()], 500);
        }
    }



    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(),[
            'company_id' => 'integer|exists:companies,id',
            'date_ad' => 'nullable|date',
            'date_bs' => 'nullable|date',
            'receipt_voucher_number' => 'string|unique:receipt_vouchhers,receipt_voucher_number',
            'receipt_voucher_list' => 'nullable||array',
            'receipt_voucher_list.*.id' => 'nullable',
      
            'receipt_voucher_list.*.company_id' => 'nullable||integer|exists:companies,id',
            'receipt_voucher_list.*.customer_id' => 'nullable||integer|exists:customers,id',
          
            'receipt_voucher_list.*.party_name' => 'string|nullable|',
            'receipt_voucher_list.*.amount' => 'nullable|numeric',
            'receipt_voucher_list.*.contra_account' => 'nullable',
            'receipt_voucher_list.*.remarks' => 'nullable|string',
            'receipt_voucher_list.*.cheque_slip' => 'nullable|string',
            'receipt_voucher_list.*.remaining_balance' => 'nullable||numeric',
           
        ]);

        $item = ReceiptVoucher::create($validated);


        if (isset($validated['receipt_voucher_list'])) {
            $item->receiptVoucherDetails()->createMany($validated['receipt_voucher_list']);
        }
      
        return response()->json([
            'item' => $item->load('ReceiptVoucherLists'),
           
        ], 201);
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

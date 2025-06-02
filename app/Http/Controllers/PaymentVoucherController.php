<?php

namespace App\Http\Controllers;


use App\Models\PaymentVoucher;

use App\Models\PaymentVoucherDetail;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class PaymentVoucherController extends Controller
{
    public function index(Request $request){

         $query = PaymentVoucher::query();
         return response()->json($query->paginate(10));  

    }

    public function update(Request $request, $id): JsonResponse
{
    try {
        
        $validator = Validator::make($request->all(), [
            'company_id' => 'integer|exists:companies,id',
            'date_ad' => 'nullable|date',
            'date_bs' => 'nullable|date',
            'reference_number' => [
                'string',
                Rule::unique('payment_vouchers')->ignore($id),
            ],
            'payment_voucher_number' => [
                'string',
                Rule::unique('payment_vouchers')->ignore($id),
            ],
            'payment_voucher_list' => 'nullable|array',
            'payment_voucher_list.*.id' => 'nullable|integer|exists:payment_voucher_details,id',
            'payment_voucher_list.*.company_id' => 'nullable|integer|exists:companies,id',
            'payment_voucher_list.*.customer_id' => 'nullable|integer|exists:customers,id',
            'payment_voucher_list.*.party_name' => 'nullable|string',
            'payment_voucher_list.*.amount' => 'nullable|numeric',
            'payment_voucher_list.*.contra_acount' => 'nullable|string', 
            'payment_voucher_list.*.remarks' => 'nullable|string',
            'payment_voucher_list.*.cheque_slip' => 'nullable|string',
            'payment_voucher_list.*.remaining_balance' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
             return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
        }

        $validated = $validator->validated();

        $PaymentVoucher = DB::transaction(function () use ($validated, $id) {
            $PaymentVoucher = PaymentVoucher::findOrFail($id);
            $PaymentVoucher->update($validated);

            if (isset($validated['payment_voucher_list'])) {
                $existingDetailIds = $PaymentVoucher->PaymentVoucherDetails()->pluck('id')->toArray();
                $incomingDetailIds = collect($validated['payment_voucher_list'])->pluck('id')->filter()->toArray();
                foreach ($validated['payment_voucher_list'] as &$detailData) {
                    $detailData['company_id'] = $validated['company_id'] ?? null;
                }
                unset($detailData);
                unset($detailData); // break reference
                $validated['payment_voucher_list']=$validated['company_id']; // Ensure company_id is set
                foreach ($validated['payment_voucher_list'] as &$detailData) {
                    if (isset($detailData['id']) && in_array($detailData['id'], $existingDetailIds)) {
                        $detail = PaymentVoucherDetail::find($detailData['id']);
                        $detail->update($detailData);
                    } else {
                        $PaymentVoucher->PaymentVoucherDetails()->create($detailData);
                    }
                }

                $detailsToDelete = array_diff($existingDetailIds, $incomingDetailIds);
                PaymentVoucherDetail::whereIn('id', $detailsToDelete)->delete();
            }

            return $PaymentVoucher;
        });

        return response()->json([
            'message' => 'Payment Voucher Updated',
            'item' => $PaymentVoucher->load('paymentVoucherDetails'),
        ], 200);
    } catch (ModelNotFoundException $e) {
        \Log::error($e);
        return response()->json(['error' => 'Payment Voucher not found'], 404);
    }catch(QueryException $e){
        \Log::error($e);
        return response()->json(['error' => 'Database query error occurred!'], 500);
    } catch (\Exception $e) {
        Log::error($e);
        return response()->json(['error' => 'Update failed: ' . $e->getMessage()], 500);
    }
}
    


    public function store(Request $request): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'company_id' => 'integer|exists:companies,id',
            'date_ad' => 'nullable|date',
            'date_bs' => 'nullable|date',
            'reference_number' => 'nullable|string|unique:payment_vouchers,reference_number',
            'payment_voucher_number' => 'string|unique:payment_vouchers,payment_voucher_number',
            'payment_voucher_list' => 'nullable|array',
            'payment_voucher_list.*.id' => 'nullable',
            'payment_voucher_list.*.company_id' => 'nullable|integer|exists:companies,id',
            'payment_voucher_list.*.customer_id' => 'nullable|integer|exists:customers,id',
            'payment_voucher_list.*.party_name' => 'nullable|string',
            'payment_voucher_list.*.amount' => 'nullable|numeric',
            'payment_voucher_list.*.contra_account' => 'nullable|string', 
            'payment_voucher_list.*.remarks' => 'nullable|string',
            'payment_voucher_list.*.cheque_slip' => 'nullable|string',
            'payment_voucher_list.*.remaining_balance' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        $PaymentVoucher = DB::transaction(function () use ($validated) {
            $PaymentVoucher = PaymentVoucher::create($validated);
            
            if (isset($validated['payment_voucher_list'])) {
                foreach($validated['payment_voucher_list'] as &$detailData){
                    if(!isset($detailData['company_id'])){
                        $detailData['company_id'] = $validated['company_id'];
                    }
                }
                unset($detailData); // break reference

                $PaymentVoucher->PaymentVoucherDetails()->createMany($validated['payment_voucher_list']);
            }

            return $PaymentVoucher;
        });

        return response()->json([
            'message' => 'Payment Voucher Created',
            'item' => $PaymentVoucher->load('PaymentVoucherDetails'),
        ], 201);

    } catch(ModelNotFoundException $e){
        return response()->json(['error' => 'Payment Voucher not found'], 404);
    } catch (QueryException $e) {
        return response()->json(['error' => 'Database query error occurred!'], 500);
    } catch (\Exception $e) {
        Log::error($e);
        return response()->json(['error' => 'Creation failed: ' . $e->getMessage()], 500);
    }
}



public function show(Request $request, $id): JsonResponse
{
    try {
        $PaymentVoucher = PaymentVoucher::where('company_id', $request->company_id)
            ->with([
                'PaymentVoucherDetails'
                
            ])
            ->findOrFail($id);

        
            return response()->json([
                'PaymentVoucher' => $PaymentVoucher
            ]);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Payment Voucher not found!'], 404);

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
        $item = PaymentVoucher::findOrFail($id);
        $item->PaymentVoucherDetails()->delete();
        $item->delete();
       
        return response()->json(['message' => 'Payment Voucher deleted!!']);


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

<?php

namespace App\Http\Controllers;

use App\Models\BankVoucher;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Validator;

class BankVoucherController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $query = BankVoucher::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = BankVoucher::findOrFail($id);
            $validator = Validator::make($request->all(), [
                'balance' => 'numeric',
                'balance_dr' => 'numeric',
                'voucher_number' => 'string|max:255',
                'cheque_number' => 'string|max:255',
                'cash' => 'string|max:255',
                'bank_id' => 'nullable|integer|exists:banks,id',
                'amount' => 'numeric',
                'remarks' => 'string|max:255',
                'date' => 'required|string|max:255',
                'options' => 'string|in:deposit,withdrawal,transfer',
                'company_id' => 'required|integer|exists:companies,id'
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
            $validated = $validator->validated();

            $item->update($validated);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An Unexpected error occurred'], 500);

        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'balance' => 'numeric',
            'balance_dr' => 'numeric',
            'voucher_number' => 'string|max:255',
            'cheque_number' => 'string|max:255',
            'cash' => 'string|max:255',
            'bank_id' => 'nullable|integer|exists:banks,id',
            'amount' => 'numeric',
            'remarks' => 'string|max:255',
            'date' => 'required|string|max:255',
            'options' => 'string|in:deposit,withdrawal,transfer',
            'company_id' => 'required|integer|exists:companies,id'
        ]);

        $item = BankVoucher::create($validated);
        return response()->json($item, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $item = BankVoucher::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = BankVoucher::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Bank deleted']);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}

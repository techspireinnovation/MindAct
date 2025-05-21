<?php

namespace App\Http\Controllers;

use App\Models\ShrinkingWorkingLoss;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShrinkingWorkingLossController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ShrinkingWorkingLoss::query();
    
    
        return response()->json($query->paginate(10));
    }
    

    public function store(Request $request): JsonResponse
{
    try {
        
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'date_from' => 'nullable|string|max:255',
            'date_to' => 'nullable|string|max:255',
            'product_id' => 'nullable|exists:products,id',
            'shrinking_loss_percent' => 'nullable|numeric',
            'working_loss_percent' => 'nullable|numeric',
            'internal_loss_percent' => 'nullable|numeric',
            'adjustment_ref_no' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('shrinking_working_losses')
                    ->where(function ($query) use ($request){
                        return $query->where('company_id', $request->input('company_id'))
                        ->whereNull('deleted_at');
                    })
            ],
            'product_details' => 'nullable|array',
            'product_details.*.purchase_date' => 'required_with:product_details',
            'product_details.*.purchase_bill_number' => 'required_with:product_details',
            'product_details.*.ref_bill_number' => 'required_with:product_details',
            'product_details.*.purchase_quantity' => 'required_with:product_details',
            'product_details.*.shrinking_loss' => 'required_with:product_details',
            'product_details.*.working_loss' => 'required_with:product_details',
            'product_details.*.internal_loss' => 'required_with:product_details',
            'product_details.*.total_loss' => 'required_with:product_details',
            'total_purchase_quantity' => 'nullable|numeric',
            'total_loss_quantity' => 'nullable|numeric'
            
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $ShrinkingWorkingLoss = ShrinkingWorkingLoss::create($validator->validated());

        return response()->json([
            'message' => 'Shrinking Working Loss created successfully',
            'data' => $ShrinkingWorkingLoss,
        ], 201);

    } catch (QueryException $e) {
        \Log::error('Database error in Shrinking Working Loss  store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
       
        return response()->json(['message' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
        \Log::error('Unexpected error in Shrinking Working Loss store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Unexpected error occurred.'], 500);
    }
}
    
    

public function show($id):JsonResponse
{
    try {
        $item = ShrinkingWorkingLoss::findOrFail($id);
        return response()->json($item);
    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Item not found'], 404);
    } catch (QueryException $e) {
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    }
}

public function update(Request $request, $id): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'date_from' => 'nullable|string|max:255',
            'date_to' => 'nullable|string|max:255',
            'product_id' => 'nullable|exists:products,id',
            'shrinking_loss_percent' => 'nullable|numeric',
            'working_loss_percent' => 'nullable|numeric',
            'internal_loss_percent' => 'nullable|numeric',
            'adjustment_ref_no' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('shrinking_working_losses')
                    ->where(function ($query) use ($request){
                        return $query->where('company_id', $request->input('company_id'))
                        ->whereNull('deleted_at');
                    })
                ->ignore($id)
            ],
            'product_details' => 'nullable|array',
            'product_details.*.purchase_date' => 'required_with:product_details',
            'product_details.*.purchase_bill_number' => 'required_with:product_details',
            'product_details.*.ref_bill_number' => 'required_with:product_details',
            'product_details.*.purchase_quantity' => 'required_with:product_details',
            'product_details.*.shrinking_loss' => 'required_with:product_details',
            'product_details.*.working_loss' => 'required_with:product_details',
            'product_details.*.internal_loss' => 'required_with:product_details',
            'product_details.*.total_loss' => 'required_with:product_details',
            'total_purchase_quantity' => 'nullable|numeric',
            'total_loss_quantity' => 'nullable|numeric'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $ShrinkingWorkingLoss = ShrinkingWorkingLoss::findOrFail($id);
        
        $ShrinkingWorkingLoss->update($data);

        return response()->json([
            'message' => 'Shrinking Working Loss updated successfully',
            'data' => $ShrinkingWorkingLoss,
        ], 200);

    } catch (QueryException $e) {
         
        \Log::error('Database error in Shrinking Working Loss update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Database error occurred.'], 500);
    } catch (\Exception $e) {
        // Log the error with sensitive data excluded
        \Log::error('Unexpected error in Shrinking Working Loss update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
        return response()->json(['message' => 'Unexpected error occurred.'], 500);
    }
}



    public function destroy($id): JsonResponse
    {
        try {
            $item = ShrinkingWorkingLoss::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Shrinking Working Loss deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Shrinking Working Loss not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

}

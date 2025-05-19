<?php

namespace App\Http\Controllers;
use App\Models\Salesman;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


class SalesmanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Salesman::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(10));
    }
    

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'salesman_id' => 'required|string|max:255|unique:salesmen,salesman_id',
                'pan_number' => 'required|string|max:255|unique:salesmen,pan_number',
                'name' => 'required|string|max:255',
                'address' => 'nullable|string',
                'mobile' => 'nullable|string|max:20',
                'email' => 'nullable|email|unique:salesmen,email|max:255',
                'working_office' => 'nullable|string|max:255',
                'joining_date' => 'nullable|date',
                'designation' => 'nullable|string|max:255',
                'dob' => 'nullable|date',
                'citizenship_number' => 'nullable|string|max:255',
                'nationality' => 'nullable|string|max:100',
                'zone' => 'nullable|string|max:100',
                'district' => 'nullable|string|max:100',
                'vdc_municipality' => 'nullable|string|max:255', // Renamed to match schema
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $salesman = Salesman::create($validator->validated());

            return response()->json([
                'message' => 'Salesman created successfully',
                'data' => $salesman
            ], 201);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred.'], 500);
        }
    }
    
    

public function show($id):JsonResponse
{
    try {
        $item = Salesman::findOrFail($id);
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
            $salesman = Salesman::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'salesman_id' => 'sometimes|required|string|max:255|unique:salesmen,salesman_id,' . $salesman->id,
                'pan_number' => 'sometimes|required|string|max:255|unique:salesmen,pan_number,' . $salesman->id,
                'name' => 'sometimes|required|string|max:255',
                'address' => 'nullable|string',
                'mobile' => 'nullable|string|max:20',
                'email' => 'nullable|email|unique:salesmen,email,' . $salesman->id . '|max:255',
                'working_office' => 'nullable|string|max:255',
                'joining_date' => 'nullable|date',
                'designation' => 'nullable|string|max:255',
                'dob' => 'nullable|date',
                'citizenship_number' => 'nullable|string|max:255',
                'nationality' => 'nullable|string|max:100',
                'zone' => 'nullable|string|max:100',
                'district' => 'nullable|string|max:100',
                'vdc_municipality' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $salesman->update($validator->validated());

            return response()->json([
                'message' => 'Salesman updated successfully',
                'data' => $salesman->fresh() // Reload the model to get the updated data
            ], 200);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json(['error' => 'Salesman not found.'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred.'], 500);
        }
    }




    public function destroy($id): JsonResponse
    {
        try {
            $item = Salesman::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Salesman deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Salesman not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

}

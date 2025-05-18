<?php

namespace App\Http\Controllers;
use App\Models\Brand;
use App\Models\Salesman;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesmenController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Salesman::paginate(50));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = Brand::findOrFail($id);
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'is_active' => 'sometimes|boolean|required',
                'quantity' => 'integer',
                'symbol' => 'string|max:255',
                'company_id' => 'integer|exists:companies,id'
            ]);
            $item->update($validated);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'string|max:255',
            'mobile' => 'string|max:255',
            'working_offce' => 'string|max:255',
            'nationality' => 'string|max:255',
            'zone' => 'string|max:255',
            'vdc_municipality' => 'string|max:255',
            'pan_number' => 'string|max:255',
            'district' => 'string|max:255',
            'citizenship_number' => 'string|max:255',
            'joining_date' => 'string|max:255',
            'desigation' => 'string|max:255',
            'dob' => 'string|max:255',
            'company_id' => 'integer|exists:companies,id'
        ]);

        $item = Salesman::create($validated);
        return response()->json($item, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $item = Brand::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = Brand::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Brand deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}

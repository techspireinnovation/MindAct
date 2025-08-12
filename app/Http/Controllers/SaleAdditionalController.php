<?php

namespace App\Http\Controllers;

use App\Models\SaleAdditional;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class SaleAdditionalController extends Controller
{

    public function index(): JsonResponse
    {
        return response()->json(SaleAdditional::paginate(50));
    }



    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'required|exists:companies,id',
            'sale_id' => 'required|exists:sales,id',
            'place' => 'nullable|string|max:255',
            'transport' => 'nullable|string|max:255',
            'vehicle_number' => 'nullable|string|max:255',
            'vehicle_name' => 'nullable|string|max:255',
            'driver_name' => 'nullable|string|max:255',
            'dispatch_code' => 'required|string|max:255|unique:sale_additionals,dispatch_code',
            'driver_contact_number' => 'nullable|string|max:20',
            'delivery_date' => 'nullable|date',
            'delivery_time' => 'nullable|date_format:H:i:s',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $saleAdditional = SaleAdditional::create($validator->validated());

            return response()->json([
                'message' => 'Sale additional created successfully',
                'data' => $saleAdditional
            ]);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error occurred.'], 500);
        }
    }


    public function show($id): JsonResponse
    {
        try {
            $item = SaleAdditional::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    /**
     * Update an existing SaleProduct.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'sale_id' => 'required|exists:sales,id',
                'place' => 'nullable|string|max:255',
                'transport' => 'nullable|string|max:255',
                'vehicle_number' => 'nullable|string|max:255',
                'vehicle_name' => 'nullable|string|max:255',
                'driver_name' => 'nullable|string|max:255',
                'dispatch_code' => 'required|string|max:255|unique:sale_additionals,dispatch_code,' . $id,
                'driver_contact_number' => 'nullable|string|max:20',
                'delivery_date' => 'nullable|date',
                'delivery_time' => 'nullable|date_format:H:i:s',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $saleAdditional = SaleAdditional::findOrFail($id);
            $saleAdditional->update($validator->validated());

            return response()->json([
                'message' => 'Sale additional updated successfully',
                'data' => $saleAdditional
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Sale additional not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error occurred.'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = SaleAdditional::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Sale Product deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}

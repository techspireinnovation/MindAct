<?php

namespace App\Http\Controllers;


use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

use App\Models\Nozzle;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;


class NozzleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Nozzle::where('company_id', $request->company_id);


        return response()->json([
            'message' => 'Data Retrived Successfully!!',
            'data' => $query->paginate(50)
        ]);

    }

    



    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'title' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('nozzles')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'nozzle_number' => 'nullable|string|max:255',
                'fuel_type' => 'nullable|string|max:255',
                'is_active' => 'boolean|nullable',
                'is_primary' => 'boolean|nullable',
                'company_id' => 'integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'error' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
                Nozzle::where('company_id', $validated['company_id'])
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $validated['is_primary'] = $validated['is_primary'] ?? false;
            $validated['is_active'] = $validated['is_active'] ?? false;
            $validated['company_id'] = $request->input('company_id', $request->company_id);

            $nozzle = Nozzle::create($validated);

            return response()->json([
                'message' => 'Data created Successfully!',
                'data' => $nozzle
            ], 201);
        } catch (ModelNotFoundException $e) {
            \Log::error('ModelNotFoundException in store: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Nozzle not found!'], 404);
        } catch (QueryException $e) {
            \Log::error('QueryException in store: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Database error occurred!'], 500);
        } catch (Exception $e) {
            \Log::error('Exception in store: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'An unexpected error occurred!'], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $nozzle = Nozzle::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'title' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('nozzles')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'nozzle_number' => 'nullable|string|max:255',
                'fuel_type' => 'nullable|string|max:255',
                'is_active' => 'boolean|nullable',
                'is_primary' => 'boolean|nullable',
                'company_id' => 'integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();

            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
                Nozzle::where('company_id', $validated['company_id'])
                    ->where('id', '!=', $id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $validated['is_primary'] = $validated['is_primary'] ?? false;
            $validated['is_active'] = $validated['is_active'] ?? false;
            $validated['company_id'] = $request->input('company_id', $request->company_id);

            $nozzle->update($validated);

            return response()->json([
                'message' => 'Data updated Successfully!',
                'data' => $nozzle
            ], 200);
        } catch (ModelNotFoundException $e) {
            \Log::error('ModelNotFoundException in update: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Nozzle not found!'], 404);
        } catch (QueryException $e) {
            \Log::error('QueryException in update: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Database error occurred!'], 500);
        } catch (Exception $e) {
            \Log::error('Exception in update: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'An unexpected error occurred!'], 500);
        }
    }


    public function show(Request $request, $id): JsonResponse
    {
        try {
            $nozzle = Nozzle::where('id', $id)
                ->where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->first();
            if (!$nozzle) {
                return response()->json(['error' => 'Item Not Found'], 404);
            }
            return response()->json([
                'message' => 'Nozzle Retrieved successfully!',
                'data' => $nozzle
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item Not Found', 'exception' => $e->getMessage()], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred', 'exception' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred', 'exception' => $e->getMessage()], 500);
        }


    }


    // public function destroy($id)
    // {
    //     try {
    //         $nozzle = Nozzle::find($id);

    //         if (!$nozzle) {
    //             return response()->json(['error' => 'Item Not Found'], 404);
    //         }

    //         $nozzle->delete();

    //         return response()->json([
    //             'message' => 'Work shift deleted successfully!'
    //         ]);

    //     } catch (ModelNotFoundException $e) {
    //         return response()->json(['error' => 'Item Not Found', 'exception' => $e->getMessage()], 404);
    //     } catch (QueryException $e) {
    //         return response()->json(['error' => 'Database error occurred', 'exception' => $e->getMessage()], 500);
    //     } catch (\Exception $e) {
    //         return response()->json(['error' => 'An unexpected error occurred', 'exception' => $e->getMessage()], 500);
    //     }
    // }

public function destroy($id)
{
    try {
        $nozzle = Nozzle::find($id);

        if (!$nozzle) {
            return response()->json(['error' => 'Nozzle not found'], 404);
        }

        // Soft delete
        $nozzle->delete();

        return response()->json([
            'message' => 'Nozzle deleted successfully (soft delete)!'
        ]);

    } catch (\Exception $e) {
        \Log::error('Error deleting nozzle: ' . $e->getMessage());
        return response()->json(['error' => 'An unexpected error occurred!'], 500);
    }
}




    public function activeNozzles(): JsonResponse
{
    try {
        $nozzles = Nozzle::where('is_active', 1)   // ✅ Only active nozzles
            ->whereNull('deleted_at')
            ->get(['id', 'title as name', 'fuel_type', 'nozzle_number', 'is_active']);

        if ($nozzles->isEmpty()) {
            return response()->json([
                'message' => 'No active nozzles found',
                'data' => []
            ], 404);
        }

        return response()->json([
            'message' => 'Active nozzles retrieved successfully!',
            'data' => $nozzles
        ], 200);

    } catch (\Exception $e) {
        \Log::error('Exception in activeNozzles: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        return response()->json(['error' => 'An unexpected error occurred!'], 500);
    }
}








}

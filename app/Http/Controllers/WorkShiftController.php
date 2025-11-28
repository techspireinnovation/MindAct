<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;


use Illuminate\Support\Facades\Validator;
use App\Models\WorkShift;
use Illuminate\Http\Request;

class WorkShiftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Workshift::where('company_id',$request->company_id);
       
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
                    Rule::unique('work_shifts')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'time_from' => 'nullable|string',
                'time_to' => 'nullable|string',
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
                WorkShift::where('company_id', $validated['company_id'])
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $validated['is_primary'] = $validated['is_primary'] ?? false;
            $validated['is_active'] = $validated['is_active'] ?? false;
            $validated['company_id'] = $request->input('company_id', $request->company_id);

            if (!empty($validated['time_from'])) {
                $validated['time_from'] = date("H:i:s", strtotime($validated['time_from']));
            }
            if (!empty($validated['time_to'])) {
                $validated['time_to'] = date("H:i:s", strtotime($validated['time_to']));
            }

            $workShift = WorkShift::create($validated);

            return response()->json([
                'message' => 'Data created Successfully!',
                'data' => $workShift
            ], 201);
        } catch (ModelNotFoundException $e) {
            \Log::error('ModelNotFoundException in store: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Work shift not found!'], 404);
        } catch (QueryException $e) {
            \Log::error('QueryException in store: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'Database error occurred!'], 500);
        } catch (Exception $e) {
            \Log::error('Exception in store: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json(['error' => 'An unexpected error occurred!'], 500);
        }
    }

        public function show($id)
{
    try {
        $shift = WorkShift::find($id);

        if (!$shift) {
            return response()->json(['error' => 'Item Not Found'], 404);
        }

       

        return response()->json([
            'message' => 'Work shift retrieved successfully!',
            'data'=> $shift
        ]);

    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Item Not Found', 'exception' => $e->getMessage()], 404);
    } catch (QueryException $e) {
        return response()->json(['error' => 'Database error occurred', 'exception' => $e->getMessage()], 500);
    } catch (Exception $e) {
        return response()->json(['error' => 'An unexpected error occurred', 'exception' => $e->getMessage()], 500);
    }
}



public function update(Request $request, $id): JsonResponse
{
    try {
        $shift = WorkShift::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('work_shifts')
                    ->ignore($id)
                    ->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id', $request->company_id))
                            ->whereNull('deleted_at');
                    }),
            ],
            'time_from' => 'nullable|string',
            'time_to' => 'nullable|string',
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
            WorkShift::where('company_id', $validated['company_id'])
                ->where('id', '!=', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $validated['is_primary'] = $validated['is_primary'] ?? false;
        $validated['is_active'] = $validated['is_active'] ?? false;
        $validated['company_id'] = $request->input('company_id', $request->company_id);

        if (!empty($validated['time_from'])) {
            $validated['time_from'] = date("H:i:s", strtotime($validated['time_from']));
        }
        if (!empty($validated['time_to'])) {
            $validated['time_to'] = date("H:i:s", strtotime($validated['time_to']));
        }

        $shift->update($validated);

        return response()->json([
            'message' => 'Data updated Successfully!',
            'data' => $shift
        ], 200);
    } catch (ModelNotFoundException $e) {
        \Log::error('ModelNotFoundException in update: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        return response()->json(['error' => 'Work shift not found!'], 404);
    } catch (QueryException $e) {
        \Log::error('QueryException in update: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        return response()->json(['error' => 'Database error occurred!'], 500);
    } catch (Exception $e) {
        \Log::error('Exception in update: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
        return response()->json(['error' => 'An unexpected error occurred!'], 500);
    }
}


    public function destroy($id)
{
    try {
        $shift = WorkShift::find($id);

        if (!$shift) {
            return response()->json(['error' => 'Item Not Found'], 404);
        }

        $shift->delete();

        return response()->json([
            'message' => 'Work shift deleted successfully!'
        ]);

    } catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Item Not Found', 'exception' => $e->getMessage()], 404);
    } catch (QueryException $e) {
        return response()->json(['error' => 'Database error occurred', 'exception' => $e->getMessage()], 500);
    } catch (\Exception $e) {
        return response()->json(['error' => 'An unexpected error occurred', 'exception' => $e->getMessage()], 500);
    }
}

public function activeWorkShiftList(Request $request): JsonResponse
{
    try {
        $workShifts = WorkShift::where('company_id', $request->company_id)
            ->whereNull('deleted_at')
            ->where('is_active', 1) // Only active work shifts
            ->get(['id', 'title', 'time_from', 'time_to']);

        return response()->json([
            'message' => 'Active Work Shifts Retrieved Successfully!!',
            'data' => $workShifts
        ]);
    } catch (ModelNotFoundException $e) {
       
        return response()->json(['error' => 'Work shifts not found!'], 404);
    } catch (QueryException $e) {
       
        return response()->json(['error' => 'Database error occurred!'], 500);
    } catch (Exception $e) {
       
        return response()->json(['error' => 'An unexpected error occurred!'], 500);
    }
}


}
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
                'is_active' => 'boolean|nullable'

            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'error' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            if (!empty($validated['time_to'])) {
                $validated['time_from'] = date("H:i:s", strtotime($validated['time_from']));
            }
            if (!empty($validated['time_to'])) {
                $validated['time_to'] = date("H:i:s", strtotime($validated['time_to']));
            }
            $validated['company_id'] = $request->company_id;

            $workShift = WorkShift::create($validated);
            return response()->json([
                'message' => 'Data created Successfully!!',
                'data' => $workShift
            ]);


        } catch (ModelNotFoundException $e) {
            \Log::error('Item Not Found', ['Item not Found' => $e]);
            return response()->json(['error' => 'Item Not Found'], 404);
        } catch (QueryException $e) {
          
            \Log::error('Database error occured', ['Query Exception' => $e]);
            return response()->json(['error' => 'Database error occured'], 500);
        } catch (\Exception $e) {

            \Log::error('Unexpected error', ['exception' => $e]);
            return response()->json(['error' => 'An unexpected error occured'], 500);


        }

    }



    public function update(Request $request, $id): JsonResponse
    {
        try {
            $shift = WorkShift::find($id);
            $validator = Validator::make($request->all(), [
                'title' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('work_shifts')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $shift) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');

                        }),
                ],
                'time_from' => 'nullable|string',
                'time_to' => 'nullable|string',
                'is_active' => 'boolean|nullable'

            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),

                ]);

            }
            $validated = $validator->validated();
            if (!empty($validated['time_to'])) {
                $validated['time_from'] = date("H:i:s", strtotime($validated['time_from']));
            }
            if (!empty($validated['time_to'])) {
                $validated['time_to'] = date("H:i:s", strtotime($validated['time_to']));
            }
            $validated['company_id'] = $request->company_id;

            $shift->update($validated);

            return response()->json([
                'message' => 'Data updated Successfully!!',
                'data' => $shift
            ]);


        } catch (ModelNotFoundException $e) {
            \Log::error('Item Not Found', ['Item not Found' => $e]);
            return response()->json(['error' => 'Item Not Found'], 404);
        } catch (QueryException $e) {
            \Log::error('Database error occured', ['Query Exception' => $e]);
            return response()->json(['error' => 'Database error occured'], 500);
        } catch (\Exception $e) {

            \Log::error('Unexpected error', ['exception' => $e]);
            return response()->json(['error' => 'An unexpected error occured'], 500);


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

}
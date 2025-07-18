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
                'is_active' => 'boolean|nullable'

            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'error' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $validated['company_id'] = $request->company_id;


            $nozzle = Nozzle::create($validated);
            return response()->json([
                'message' => 'Data created Successfully!!',
                'data' => $nozzle
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
            $nozzle = Nozzle::find($id);
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
                'nozzle_number' => 'nullable|string',
                'fuel_type' => 'nullable|string',
                'is_active' => 'boolean|nullable'

            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),

                ]);

            }
            $validated = $validator->validated();

            $validated['company_id'] = $request->company_id;

            $nozzle->update($validated);

            return response()->json([
                'message' => 'Data updated Successfully!!',
                'data' => $nozzle
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


    public function destroy($id)
    {
        try {
            $nozzle = Nozzle::find($id);

            if (!$nozzle) {
                return response()->json(['error' => 'Item Not Found'], 404);
            }

            $nozzle->delete();

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

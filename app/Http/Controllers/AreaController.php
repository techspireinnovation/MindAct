<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use App\Models\Area;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AreaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Area::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $area = Area::findOrFail($id);
            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('areas')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');

                        }),
                ],

                'company_id' => 'integer|exists:companies,id',
                'is_active' => 'boolean|nullable',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }
            $validated = $validator->validated();

            $area->update($validated);
            return response()->json($area);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Area not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {

            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('areas')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at');

                    }),

                ],

                'company_id' => 'integer|exists:companies,id',

                'is_active' => 'boolean|nullable',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }
            $validated = $validator->validated();
            $area = Area::create($validated);
            return response()->json($area, 201);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Area not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database  error occurred!!'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $area = Area::findOrFail($id);
            return response()->json($area);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Area not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {

            $area = Area::findOrFail($id);
            $area->delete();
            return response()->json(['message' => 'Area deleted!!']);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Area not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}

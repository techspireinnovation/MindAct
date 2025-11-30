<?php

namespace App\Http\Controllers;

use App\Models\Cash;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;




class CashController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Cash::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $cash = Cash::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('cashes')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'is_active' => 'boolean|required',
                'is_primary' => 'boolean',
                'company_id' => 'integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
                Cash::where('company_id', $validated['company_id'])
                    ->where('id', '!=', $id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $validated['is_primary'] = $validated['is_primary'] ?? false;

            $cash->update($validated);

            return response()->json($cash, 200);
        } catch (ModelNotFoundException $e) {
           
            return response()->json(['error' => 'Cash not found!'], 404);
        } catch (QueryException $e) {
           
            return response()->json(['error' => 'An unexpected error occurred!'], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'An unexpected error occurred!'], 500);
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
                    Rule::unique('cashes')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at');
                    }),
                ],
                'is_active' => 'boolean|required',
                'is_primary' => 'boolean',
                'company_id' => 'integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
                Cash::where('company_id', $validated['company_id'])
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

            $validated['is_primary'] = $validated['is_primary'] ?? false;

            $cash = Cash::create($validated);

            return response()->json($cash, 201);
        } catch (ModelNotFoundException $e) {
            
            return response()->json(['error' => 'Cash not found!'], 404);
        } catch (QueryException $e) {
           
            return response()->json(['error' => 'An unexpected error occurred!'], 500);
        } catch (\Exception $e) {
            
            return response()->json(['error' => 'An unexpected error occurred!'], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $cash = Cash::findOrFail($id);
            return response()->json($cash);
        } catch (ModelNotFoundException $e) {
           
            return response()->json(['error' => 'Cash not found!!'], 404);
        } catch (QueryException $e) {
           
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {


            $cash = Cash::findOrFail($id);
            $cash->delete();
            return response()->json(['message' => 'Cash deleted!!']);
        } catch (ModelNotFoundException $e) {
           
            return response()->json(['error' => 'Cash not found!!'], 404);
        } catch (QueryException $e) {
           
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function activeCashList(Request $request): JsonResponse
{
    try {
        $cashes = Cash::where('company_id', $request->company_id)
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->get(['id', 'name'])
            ->map(fn($cash) => ['id' => $cash->id, 'name' => $cash->name])
            ->values()
            ->toArray();

        return response()->json([
            "message" => "Active Cash List Received !!",
            "data" => $cashes
        ]);
    } catch (ModelNotFoundException $e) {
       
        return response()->json(["error" => "Cash not Found !!"], 404);
    } catch (QueryException $e) {
       
        return response()->json(["error" => "Database error occurred !!"], 500);
    } catch (\Exception $e) {
       
        return response()->json(["error" => "An unexpected error occurred !!"], 500);
    }
}



}

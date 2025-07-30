<?php

namespace App\Http\Controllers;
use App\Models\Store;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Store::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
        return response()->json($query->paginate(50));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = Store::findOrFail($id);

            $validated = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stores')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $item) {
                            return $query->where('company_id', $request->input('company_id', $item->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'is_active' => 'sometimes|boolean|required',
                'quantity' => 'integer',
                'is_primary' => 'boolean',
                'symbol' => 'string|max:255',
                'company_id' => 'integer|exists:companies,id'
            ]);

            // Use the existing company_id if not in request
            $companyId = $validated['company_id'] ?? $item->company_id;

            // If the request says this store should be primary, make others non-primary
            if (isset($validated['is_primary']) && $validated['is_primary']) {
                Store::where('company_id', $companyId)
                    ->where('id', '!=', $id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }

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
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('stores')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id)
                        ->whereNull('deleted_at');

                }),
            ],
            'is_active' => 'boolean|required',
            'is_primary' => 'boolean',
            'quantity' => 'integer',
            'symbol' => 'string|max:255',
            'company_id' => 'integer|exists:companies,id'
        ]);

        if (!empty($validated['is_primary'])) {
            Store::where('company_id', $validated['company_id'])
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $item = Store::create($validated);
        return response()->json($item, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $item = Store::findOrFail($id);
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
            $item = Store::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Store deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}

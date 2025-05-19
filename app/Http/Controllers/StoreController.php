<?php

namespace App\Http\Controllers;
use App\Models\Store;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class StoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Store::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
        return response()->json(Store::paginate(10));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = Store::findOrFail($id);
            $validated = $request->validate([
                'name' => ['required',
                          'string',
                          'max:255',
                        Rule::unique('stores')
                        ->ignore($id)
                        ->where(function ($query) use ($request ,$item){
                            return $query->where('company_id', $request->input('company_id',$item->company_id));

                        }),
                    ],                
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
            'name' => ['required',
                       'string',
                       'max:255',
                       Rule::unique('stores')->where(function ($query) use ($request){
                        return $query->where('company_id',$request->company_id);

                       }),
                    ],
            'is_active' => 'boolean|required',
            'quantity' => 'integer',
            'symbol' => 'string|max:255',
            'company_id' => 'integer|exists:companies,id'
        ]);

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

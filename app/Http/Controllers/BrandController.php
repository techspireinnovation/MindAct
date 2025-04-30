<?php

namespace App\Http\Controllers;
use App\Models\Brand;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Brand::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(50));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = Brand::findOrFail($id);
            $validated = $request->validate([
                'name' => 'required|string|max:255|unique:brands,name,' . $id,
                'is_active' => 'sometimes|boolean|required',
                'is_primary' => 'sometimes|boolean',
                'quantity' => 'integer',
                'symbol' => 'string|max:255',
                'company_id' => 'required|integer|exists:companies,id'
            ]);
             
            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
            Brand::where('company_id', $item->company_id)
                ->where('id', '!=', $id) 
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        // Explicit boolean handling (optional, since validation ensures boolean)
        if ($request->has('is_active')) {
            $validated['is_active'] = (bool) $request->input('is_active');
        }
        if ($request->has('is_primary')) {
            $validated['is_primary'] = (bool) $request->input('is_primary');
        }
        

            $item->update($validated);
            $item->refresh();

           
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }catch(\Exception $e){
            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:brands,name',
            'is_active' => 'boolean|required',
            'is_primary' =>'boolean',
            'quantity' => 'integer',
            'symbol' => 'string|max:255',
            'company_id' => 'required|integer|exists:companies,id'
        ]);
       
        if (!empty($validated['is_primary'])) {
            Brand::where('company_id', $validated['company_id'])
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
        }
            
        $validated['is_primary'] = $validated['is_primary'] ?? false;
        $validated['is_active'] = $validated['is_active'] ?? true;

        $item = Brand::create($validated);
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

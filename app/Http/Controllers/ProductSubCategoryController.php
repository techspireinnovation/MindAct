<?php

namespace App\Http\Controllers;
use App\Models\ProductSubCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

use Illuminate\Http\Request;

class ProductSubCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductSubCategory::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    

        return response()->json($query->paginate(50));
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = ProductSubCategory::findOrFail($id);
            $validator = Validator::make($request->all(),[
                'name' => ['required',
                           'string',
                           'max:255',
                           Rule::unique('product_sub_categories')
                                 ->ignore($id)
                                 ->where(function ($query) use ($request, $item){
                                   return $query->where('company_id',$request->input('company_id',$item->company_id))
                                   ->whereNull('deleted_at');
                                }),
                ],
                'is_active' => 'boolean|required',
                'category_id' => 'integer|exists:product_categories,id',
                'company_id' => 'integer|exists:companies,id'
            ]);
            if($validator->fails()){
                return response()->json($validator->errors(),422);
            }
            $validated = $validator->validated();
            
            $item->update($validated);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {

        $validated = $request->validate([
            'name' => ['required',
                       'string','max:255',
                       Rule::unique('product_sub_categories')->where(function ($query) use ($request){
                        return $query->where('company_id',$request->company_id)
                        ->whereNull('deleted_at');

                       }),
                    ],
            'is_active' => 'boolean|required',
            'category_id' => 'integer|exists:product_categories,id',
            'company_id' => 'integer|exists:companies,id'
        ]);

        $item = ProductSubCategory::create($validated);
        return response()->json($item, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $item = ProductSubCategory::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = ProductSubCategory::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Product Sub Category deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

}

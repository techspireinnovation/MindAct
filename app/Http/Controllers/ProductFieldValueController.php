<?php

namespace App\Http\Controllers;

use App\Models\ProductFieldValue;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ProductFieldValueController extends Controller
{

    public function index(): JsonResponse
    {
        try {
            $query = ProductFieldValue::query();
            if (request()->has('keywords')) {
                $query->where('value', 'LIKE', '%' . request()->input('keywords') . '%');
            }

            $fieldValues = $query->paginate(50);
            $transformed = $fieldValues->getCollection()->map(function ($fieldValue) {
                return [
                    'id' => $fieldValue->id,
                    'company_id' => $fieldValue->company_id,
                    'product_field_id' => $fieldValue->product_field_id,
                    'product_id' => $fieldValue->product_id,
                    'product_name' => optional($fieldValue->product)->name,
                    'value' => $fieldValue->value,                   
                    'is_active' => $fieldValue->is_active,                    
                ];

            });
            $fieldValues->setCollection($transformed);

            return response()->json($fieldValues);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Field Value not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
    public function productFieldValueList(Request $request){
        try{

            $productFieldValues = ProductFieldValue::where('company_id',$request->company_id)
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->get(['id', 'value'])
            ->map(fn($productFieldValue) => ['id' => $productFieldValue->id, 'value' => $productFieldValue->value])
            ->values()
            ->toArray();
            return response()->json(["message"=>"Product Field List Received !!",
                                       "data"=>$productFieldValues
                                    ]);

        }catch(ModelNotFoundException $e){
            \Log::error($e);
            return response()->json(["error"=>"Product Field Value not Found !!"],404);
        }catch(QueryException $e){
            \Log::error($e);
            return response()->json(["error"=>"Database error occurred !!"],500);
        }catch(Exception $e){
            \Log::error($e);
            return response()->json(["error"=>"An unexpected error occurred !!"],500);
        }
    }
    public function productFieldValueDetails(Request $request){
        try{

           $companyId  = $request->company_id;
           if(!$companyId){
            return response()->json(["error"=>"No Company Logged In !!"],404);
           }

           $productFieldValue = $request->product_field_value;
           $productFieldValueDetails = ProductFieldValue::where('company_id',$request->company_id)
                                         ->where('value',$productFieldValue)
                                       ->whereNull('deleted_at')
                                       ->firstorFail();   
           return response()->json(["message"=>"Product Field Value Details Received !!",
                                    "data"=>$productFieldValueDetails
                                ],200);


        }catch(ModelNotFoundException $e){
            return response()->json(["error"=>"Product Field Value not Found !!"],404);
        }catch(QueryException $e){
            return response()->json(["error"=>"Database error occurred !!"],500);
        }catch(\Exception $e){
            return response()->json(["error"=>"An unexpected error occurred !!"],500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'company_id' => 'integer|exists:companies,id',
            'product_id' => 'integer|required|exists:products,id',
            'product_field_id' => 'integer|exists:product_fields,id',
            'value' => 'string|max:255'

        ]);
        if ($validator->fails()) {
            return response()->json([
                'error' => $validator->errors()
            ], 422);

        }
        $validated = $validator->validated();

        $field_value = ProductFieldValue::create($validated);
        return response()->json($field_value, 201);
    }



    public function show($id): JsonResponse
    {
        try {
            $field_value = ProductFieldValue::findOrFail($id);
            return response()->json($field_value);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Field Value not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }




    public function update(Request $request, $id): JsonResponse
    {
        try {
            $field_value = ProductFieldValue::findOrFail($id);
            $validated = $request->validate([


                'company_id' => 'integer|exists:companies,id',
                'product_field_id' => 'integer|exists:product_fields,id',
                'value' => 'string|max:255'

            ]);

            $field_value->update($validated);
            return response()->json($field_value);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }



    public function destroy($id): JsonResponse
    {
        try {
            $field_value = ProductFieldValue::findOrFail($id);
            $field_value->delete();
            return response()->json(['message' => 'Product Field Value deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Field Value not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}

<?php

namespace App\Http\Controllers;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;

use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Location::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(50));
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = Location::findOrFail($id);
            $validator = Validator::make($request->all(),[
                'name' => ['required',
                           'string',
                           'max:255',
                           Rule::unique('locations')
                           ->ignore($id)
                           ->where(function ($query) use ($request, $item){
                              return $query->where('company_id',$request->input('company_id',$item->company_id));

                           }),
            ],
                'is_active' => 'boolean|required',
                'is_primary' =>'boolean',
                'company_id' => 'integer|exists:companies,id'
            ]);
            
            if($validator->fails()){
                return response()->json($validator->errors(), 422);
            }

            $validated = $validator->validated();
            // Explicit boolean handling (optional, since validation ensures boolean)
            if ($request->has('is_active')) {
                $validated['is_active'] = (bool) $request->input('is_active');
            }
            if ($request->has('is_primary')) {
                $validated['is_primary'] = (bool) $request->input('is_primary');
            }
            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
                Location::where('company_id', $item->company_id)
                    ->where('id', '!=', $id) 
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
    
            
    
            $item->update($validated);
            $item->refresh();
 
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Location not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try{

            $validator = Validator::make($request->all(),[
                'name' => ['required',
                            'string',
                            'max:255',
                            Rule::unique('locations')

                            ->where(function ($query) use ($request){
                                return $query->where('company_id',$request->company_id);

                            }),
                        
                        ],
                'is_active' => 'boolean|required',
                'is_primary' =>'boolean',
                'company_id' => 'integer|exists:companies,id'
            ]);
            if($validator->fails()){
                return response()->json($validator->errors(), 422);
            }
            $validated = $validator->validated();

            $validated['is_primary'] = $validated['is_primary'] ?? false;
            $validated['is_active'] = $validated['is_active'] ?? true;

            if (!empty($validated['is_primary'])) {
                Location::where('company_id', $validated['company_id'])
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
            }
            
           
            
            $item = Location::create($validated);
            return response()->json($item, 201);
        }catch(ModelNotFoundException $e){
            return response()->json(['error' => 'Item not found'], 404);
        }catch(QueryException $e){
            dd($e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }catch(\Exception $e){
            dd($e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function show($id): JsonResponse
    {
        try {
            $item = Location::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Location not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = Location::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Location deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Location not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}

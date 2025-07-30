<?php

namespace App\Http\Controllers;

use App\Models\MeasureUnit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeasureUnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MeasureUnit::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(50));
    }



    public function unitList(Request $request){
        try{

            $units = MeasureUnit::where('company_id',$request->company_id)
            ->whereNull('deleted_at')
            ->get(['id', 'name'])
            ->map(fn($unit) => ['id' => $unit->id, 'name' => $unit->name])
            ->values()
            ->toArray();
            return response()->json(["message"=>"Measure Unit List Received !!",
                                       "data"=>$units
                                    ]);

        }catch(ModelNotFoundException $e){
            \Log::error($e);
            return response()->json(["error"=>"Measure Unit not Found !!"],404);
        }catch(QueryException $e){
            \Log::error($e);
            return response()->json(["error"=>"Database error occurred !!"],500);
        }catch(\Exception $e){
            \Log::error($e);
            return response()->json(["error"=>"An unexpected error occurred !!"],500);
        }
    }


    public function unitDetails(Request $request){
        try{

           $companyId  = $request->company_id;
           if(!$companyId){
            return response()->json(["error"=>"No Company Logged In !!"],404);
           }

           $unit = $request->measure_unit;
           $unitDetails = MeasureUnit::where('company_id',$request->company_id)
                                         ->where('name',$unit)
                                         ->whereNull('deleted_at')
                                         ->firstorFail();   
           return response()->json(["message"=>"Measure Unit Details Received !!",
                                    "data"=>$unitDetails
                                ],200);


        }catch(ModelNotFoundException $e){
            return response()->json(["error"=>"Measure Unit not Found !!"],404);
        }catch(QueryException $e){
            return response()->json(["error"=>"Database error occurred !!"],500);
        }catch(\Exception $e){
            return response()->json(["error"=>"An unexpected error occurred !!"],500);
        }
    } 

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = MeasureUnit::findOrFail($id);
            $validated = $request->validate([
                'name' => ['required',
                           'string',
                           'max:255',
                           Rule::unique('measure_units')
                           ->ignore($id)
                           ->where(function ($query) use ($request,$item){
                            return $query->where('company_id', $request->input('company_id',$item->company_id))
                            ->whereNull('deleted_at');

                           }),
                        ],
                'is_active' => 'boolean|required',
                'is_primary' =>'boolean',
                'quantity' => 'integer',
                'symbol' => 'string|max:255',
                'company_id' => 'integer|exists:companies,id'
            ]);
            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
                MeasureUnit::where('company_id', $item->company_id)
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
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required',
                       'string',
                       'max:255',
                       Rule::unique('measure_units')->where(function ($query) use ($request){
                        return $query->where('company_id',$request->company_id)
                        ->whereNull('deleted_at');
                    }),
                    ],
            'is_active' => 'boolean|required',
            'is_primary' =>'boolean',
            'quantity' => 'integer',
            'symbol' => 'string|max:255',
            'company_id' => 'integer|exists:companies,id'
        ]);
        if (!empty($validated['is_primary'])) {
            MeasureUnit::where('company_id', $validated['company_id'])
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
        }
            
        $validated['is_primary'] = $validated['is_primary'] ?? false;
        $validated['is_active'] = $validated['is_active'] ?? true;


        $item = MeasureUnit::create($validated);
        return response()->json($item, 201);
    }

    public function show($id): JsonResponse
    {
        try {
            $item = MeasureUnit::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = MeasureUnit::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Unit of Measurement deleted!!']);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}

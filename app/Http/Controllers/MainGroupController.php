<?php

namespace App\Http\Controllers;

use App\Models\MainGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;

use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class MainGroupController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $query = MainGroup::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(50));
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $group = MainGroup::findOrFail($id);
            $validator = Validator::make($request->all(),[
                'name' => ['required',
                             'string',
                             'max:255',
                             Rule::unique('main_groups')
                             ->ignore($id)
                             ->where(function ($query) use ($request, $group){
                                return $query->where('company_id',$request->input('company_id',$request->company_id))
                                ->whereNull('deleted_at');

                             }),

                ],
                'is_active' => 'boolean|required',
                'is_primary' =>'boolean',
                'company_id' => 'integer|exists:companies,id'
            ]);
            if($validator->fails()){
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
                MainGroup::where('company_id', $group->company_id)
                    ->where('id', '!=', $id) 
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
    
            
            if ($request->has('is_primary')) {
                $validated['is_primary'] = (bool) $request->input('is_primary');
            }

            
    
            $group->update($validated);
            return response()->json($group, 200);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Main Group not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }catch(\Exception $e){
            \Log::error($e);
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
                        Rule::unique('main_groups')
                        ->where(function ($query) use ($request){
                            return $query->where('company_id',$request->company_id)
                            ->whereNull('deleted_at');

                        }),
                    ],
            'is_active' => 'boolean|required',
            'is_primary' =>'boolean',
            'company_id' => 'integer|exists:companies,id'
        ]);
        if($validator->fails()){
            return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
        }

        $validated = $validator->validated();
        if (!empty($validated['is_primary'])) {
            MainGroup::where('company_id', $validated['company_id'])
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
        }
        $validated['is_primary'] = $validated['is_primary'] ?? false;

        $group = MainGroup::create($validated);
        return response()->json($group, 201);
    }catch (ModelNotFoundException $e) {
        \Log::error($e);
        return response()->json(['error' => 'Location not found!!'], 404);
    } catch (QueryException $e) {
        \Log::error($e);
        return response()->json(['error' => 'An unexpected error occurred!!'], 500);
    }catch(\Exception $e){
        \Log::error($e);
        return response()->json(['error' => 'An unexpected error occurred!!'], 500);
    }
    }

    public function show($id): JsonResponse
    {
        try {
            $group = MainGroup::findOrFail($id);
            return response()->json($group);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Main Group not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $group = MainGroup::findOrFail($id);
            $group->delete();
            return response()->json(['message' => 'Main Group deleted!!']);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Main Group not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}

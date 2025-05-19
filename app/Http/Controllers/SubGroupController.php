<?php

namespace App\Http\Controllers;

use App\Models\SubGroup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;

class SubGroupController extends Controller
{

    public function index(Request $request): JsonResponse
    {
         $query = SubGroup::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
        return response()->json($query->paginate(50));
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $group = SubGroup::findOrFail($id);
            $validator = Validator::make($request->all(),[
                'name' => ['required',
                           'string',
                           'max:255',
                        Rule::unique('sub_groups')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $group){
                            return $query->where('company_id',$request->input('company_id',$request->company_id));

                        }),
                    ],
                'is_active' => 'boolean|required',
                'company_id' => 'integer|exists:companies,id',
                'main_group_id' => ['integer',
                                   Rule::exists('main_groups','id')->where(function ($query) use ($request){
                                    return $query->where('company_id',$request->company_id);

                                   }),
                                   ],
                'code' => 'string|max:255',
                'ranking_for_trial' => 'integer|max:255'
            ]);
            if($validator->fails()){
                return response()->json($validator->errors(),422);
            }

            $validated = $validator->validated();
            $group->update($validated);
            return response()->json($group);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Sub Group not found!!'], 404);
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
                    Rule::unique('sub_groups')->where(function ($query) use ($request){
                        return $query->where('company_id',$request->company_id);

                    }),
                    
                ],
            'is_active' => 'boolean|required',
            'is_primary' =>'boolean',
            'company_id' => 'integer|exists:companies,id',
            'main_group_id' => [
                'required',
                'integer',
                Rule::exists('main_groups', 'id')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->input('company_id'));
                }),
            ],
            'code' => 'string|max:255',
            'ranking_for_trial' => 'string|max:255'
        ]);
        if($validator->fails()){
            return response()->json($validator->errors(),422);
        }

        $validated = $validator->validated();

        if (!empty($validated['is_primary'])) {
            SubGroup::where('company_id', $validated['company_id'])
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
        }
            
        $validated['is_primary'] = $validated['is_primary'] ?? false;
        

        $group = SubGroup::create($validated);
        return response()->json($group, 201);
    }catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Sub Group not found!!'], 404);
    } catch (QueryException $e) {
        return response()->json(['error' => 'An unexpected error occurred!!'], 500);
    }catch(\Exception $e){
        return response()->json(['error' => 'An unexpected error occurred!!'], 500);
    }
    }

    public function show($id): JsonResponse
    {
        try {
            $group = SubGroup::findOrFail($id);
            return response()->json($group);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Sub Group not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $group = SubGroup::findOrFail($id);
            $group->delete();
            return response()->json(['message' => 'Sub Group deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Sub Group not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}

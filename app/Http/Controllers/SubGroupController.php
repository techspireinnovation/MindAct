<?php

namespace App\Http\Controllers;

use App\Models\AccountGroup;
use App\Models\SubGroup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SubGroupController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $query = SubGroup::with('mainGroup:id,name');

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        $subGroups = $query->paginate(50);

        $transformed = $subGroups->getCollection()->map(function ($subGroup) {
            return [
                'id' => $subGroup->id,
                'name' => $subGroup->name,
                'main_group_id' => optional($subGroup->mainGroup)->id,
                'main_group_name' => optional($subGroup->mainGroup)->name,
                'is_active' => $subGroup->is_active,
                'is_primary' => $subGroup->is_primary,
                'company_id' => $subGroup->company_id,
                'code' => $subGroup->code,
                'ranking_for_trial' => $subGroup->ranking_for_trial
            ];
        });

        $subGroups->setCollection($transformed);

        return response()->json($subGroups);
    }
    public function subGroupList(Request $request){
        try{

            $subGroups = SubGroup::where('company_id',$request->company_id)
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->get(['id', 'name'])
            ->map(fn($subGroup) => ['id' => $subGroup->id, 'name' => $subGroup->name])
            ->values()
            ->toArray();
            return response()->json(["message"=>"Sub Group List Received !!",
                                       "data"=>$subGroups
                                    ]);

        }catch(ModelNotFoundException $e){
            \Log::error($e);
            return response()->json(["error"=>"Sub Group not Found !!"],404);
        }catch(QueryException $e){
            \Log::error($e);
            return response()->json(["error"=>"Database error occurred !!"],500);
        }catch(\Exception $e){
            \Log::error($e);
            return response()->json(["error"=>"An unexpected error occurred !!"],500);
        }
    }
    public function subGroupDetails(Request $request){
        try{

           $companyId  = $request->company_id;
           if(!$companyId){
            return response()->json(["error"=>"No Company Logged In !!"],404);
           }

           $subGroup = $request->sub_group_name;
           $subGroupDetails = SubGroup::where('company_id',$request->company_id)
                                         ->where('name',$subGroup)
                                       ->whereNull('deleted_at')
                                       ->firstorFail();   
           return response()->json(["message"=>"Sub Group Details Received !!",
                                    "data"=>$subGroupDetails
                                ],200);


        }catch(ModelNotFoundException $e){
            return response()->json(["error"=>"Sub Group not Found !!"],404);
        }catch(QueryException $e){
            return response()->json(["error"=>"Database error occurred !!"],500);
        }catch(\Exception $e){
            return response()->json(["error"=>"An unexpected error occurred !!"],500);
        }
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $group = SubGroup::findOrFail($id);
            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('sub_groups')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $group) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');

                        }),
                ],
                'is_active' => 'boolean|required',
                'company_id' => 'integer|exists:companies,id',
                'main_group_id' => [
                    'integer',
                    Rule::exists('main_groups', 'id')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id);

                    }),
                ],
                'code' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('sub_groups')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $group) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');

                        }),
                ],
                'ranking_for_trial' => 'integer|max:255'
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            if ($this->checkIfUsed($id))
                return response()->json(['error' => 'Cannot not modify. The item has already been used'], 406);


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
        try {
            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('sub_groups')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at');

                    }),

                ],
                'is_active' => 'boolean|required',
                'is_primary' => 'boolean',
                "code" => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('sub_groups')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at');

                    }),

                ],

                'company_id' => 'integer|exists:companies,id',
                'main_group_id' => [
                    'required',
                    'integer',
                    Rule::exists('main_groups', 'id')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id'));
                    }),
                ],
                'ranking_for_trial' => 'string|max:255'
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $lastSubGroup = SubGroup::where(['main_group_id' => $validated['main_group_id']])->orderBy('code', 'DESC')->first();
            $validated['code'] = $lastSubGroup ? (int) ($lastSubGroup->code) + 1 : 1;
            $group = SubGroup::create($validated);
            return response()->json($group, 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Sub Group not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        } catch (\Exception $e) {
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
            if ($this->checkIfUsed($id))
                return response()->json(['error' => 'Cannot not modify. The item has already been used'], 406);

            $group = SubGroup::findOrFail($id);
            $group->delete();
            return response()->json(['message' => 'Sub Group deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Sub Group not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    private function checkIfUsed($id): bool
    {
        if (AccountGroup::where('sub_group_id', $id)->first()) {
            return true;
        }
        return false;

    }
}

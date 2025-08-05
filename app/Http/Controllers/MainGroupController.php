<?php

namespace App\Http\Controllers;

use App\Models\AccountGroup;
use App\Models\MainGroup;
use App\Models\SubGroup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class MainGroupController extends Controller
{

    public function mainGroupList(Request $request): JsonResponse
    {
        $mainGroupList = MainGroup::whereNull('deleted_at')
                                   ->where('company_id',$request->company_id)
                                   ->whereHas('subGroups', function($query){
                                         $query->whereNull('deleted_at');

                                   })
                                   ->get(['id','name']);

        
        return response()->json($mainGroupList);
    }


     public function mainGroupListDetails(Request $request): JsonResponse
    {
        try {

            $mainGroup = MainGroup::where('company_id', $request->company_id)
                ->where('is_active', 1)->get();


            return response()->json([
                'message' => 'List Received Sucessfully !!',
                'data' => $mainGroup
            ], 200);



        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database Error Ocurred!!'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An unexpected error Ocurred!!'], 500);
        }

    }


public function draggable(Request $request): JsonResponse
{
    try {
        $mainGroupId = $request->main_group_id;
        $subGroups = $request->sub_groups;

        if (!$mainGroupId || empty($subGroups)) {
            return response()->json(["error" => "Main Group ID or Subgroup list is missing!"], 400);
        }

        $updatedData = [];

        foreach ($subGroups as $item) {
            if (!isset($item['id']) || !isset($item['ranking_for_trial'])) {
                continue;
            }

            $subgroup = SubGroup::where('id', $item['id'])
                ->where('main_group_id', $mainGroupId)
                ->first();

            if ($subgroup) {
                $subgroup->ranking_for_trial = $item['ranking_for_trial'];
                $subgroup->save();

                $updatedData[] = $subgroup; // Store updated model
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Subgroup rankings updated successfully for Main Group ID: ' . $mainGroupId,
            'data' => $updatedData
        ]);

    } catch (ModelNotFoundException $e) {
        return response()->json(["error" => 'Subgroup not found!'], 404);
    } catch (QueryException $e) {
        return response()->json(["error" => 'Database error occurred!'], 500);
    } catch (\Exception $e) {
        return response()->json(["error" => 'Unexpected error occurred!'], 500);
    }
}




    public function subGroupOfMainGroup(Request $request): JsonResponse
{
    try {
        $mainGroup = $request->main_group_id;

        if (!$mainGroup) {
            return response()->json(['error' => 'Main Group Id not Found!!'], 404);
        }

        $subGroupList = SubGroup::whereNull('deleted_at')
            ->where('company_id', $request->company_id)
            ->where('main_group_id', $mainGroup)
            ->get(['id', 'name', 'ranking_for_trial']);

        return response()->json($subGroupList);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Something went wrong!',
            'message' => $e->getMessage()
        ], 500);
    }
}


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
            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('main_groups')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $group) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');

                        }),

                ],
                'is_active' => 'boolean|required',
                'company_id' => 'integer|exists:companies,id'
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
            return response()->json($group, 200);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Main Group not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
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
                    Rule::unique('main_groups')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->company_id)
                                ->whereNull('deleted_at');

                        }),
                ],
                'is_active' => 'boolean|required',
                'is_primary' => 'boolean',
                'company_id' => 'integer|exists:companies,id'
            ]);
            if ($validator->fails()) {
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
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Main Group not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        } catch (\Exception $e) {
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

            if ($this->checkIfUsed($id))
                return response()->json(['error' => 'Cannot not modify. The item has already been used'], 406);

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

    private function checkIfUsed($id): bool
    {
        if (AccountGroup::where('main_group_id', $id)->first()) {
            return true;
        }
        return false;

    }
}

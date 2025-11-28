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
use Illuminate\Support\Facades\Log;

class SubGroupController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $query = SubGroup::with('mainGroup:id,name');

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
        $query->orderBy('main_group_id', 'asc')
            ->orderBy('ranking_for_trial', 'asc');


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


    public function subGroupList(Request $request): JsonResponse
    {
        try {

            $subGroup = SubGroup::where('company_id', $request->company_id)
                ->where('is_active', 1)->get();


            return response()->json([
                'message' => 'List Received Sucessfully !!',
                'data' => $subGroup
            ], 200);



        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database Error Ocurred!!'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An unexpected error Ocurred!!'], 500);
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
                'company_id' => 'integer',
                'main_group_id' => [
                    'integer',
                    Rule::exists('main_groups', 'id')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id);

                    }),
                ],
                'code' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('sub_groups')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $group) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');

                        }),
                ],

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

    // public function store(Request $request): JsonResponse
    // {
    //     try {
    //         $validator = Validator::make($request->all(), [
    //             'name' => [
    //                 'required',
    //                 'string',
    //                 'max:255',
    //                 Rule::unique('sub_groups')->where(function ($query) use ($request) {
    //                     return $query->where('company_id', $request->company_id)
    //                         ->whereNull('deleted_at');

    //                 }),

    //             ],
    //             'is_active' => 'boolean|required',
    //             'is_primary' => 'boolean',
    //             "code" => [
    //                 'nullable',
    //                 'string',
    //                 'max:255',
    //                 Rule::unique('sub_groups')->where(function ($query) use ($request) {
    //                     return $query->where('company_id', $request->company_id)
    //                         ->whereNull('deleted_at');

    //                 }),

    //             ],

    //             'company_id' => 'integer',
    //             'main_group_id' => [
    //                 'required',
    //                 'integer',
    //                 Rule::exists('main_groups', 'id')->where(function ($query) use ($request) {
    //                     return $query->where('company_id', $request->input('company_id'));
    //                 }),
    //             ],

    //         ]);
    //         if ($validator->fails()) {
    //             return response()->json([
    //                 'message' => $validator->errors()->first(),
    //                 'errors' => $validator->errors()
    //             ], 422);
    //         }

    //         $validated = $validator->validated();
    //         $lastSubGroup = SubGroup::where(['main_group_id' => $validated['main_group_id']])->orderBy('code', 'DESC')->first();

    //         $rankingForTrial = Subgroup::where('company_id', $request->company_id)
    //             ->where('main_group_id', $validated['main_group_id'])
    //             ->orderBy('ranking_for_trial', 'desc')
    //             ->first();
    //         $newrankingForTrial = $rankingForTrial ? $rankingForTrial->ranking_for_trial + 1 : 1;
    //         $validated['ranking_for_trial'] = $newrankingForTrial;

    //         $validated['code'] = $lastSubGroup ? (int) ($lastSubGroup->code) + 1 : 1;

    //         $group = SubGroup::create($validated);
    //         return response()->json($group, 201);
    //     } catch (ModelNotFoundException $e) {
    //         return response()->json(['error' => 'Sub Group not found!!'], 404);
    //     } catch (QueryException $e) {
    //         dd($e->getMessage());
    //         return response()->json(['error' => 'An unexpected error occurred!!'], 500);
    //     } catch (\Exception $e) {
    //         dd($e->getMessage());
    //         return response()->json(['error' => 'An unexpected error occurred!!'], 500);
    //     }
    // }

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
                'nullable',
                'string',
                'max:255',
                Rule::unique('sub_groups')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id)
                        ->whereNull('deleted_at');
                }),
            ],
            'company_id' => 'integer',
            'main_group_id' => [
                'required',
                'integer',
                Rule::exists('main_groups', 'id')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->input('company_id'));
                }),
            ],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        // DEBUG: Log the input
        Log::info('SubGroup Store - Input', [
            'company_id' => $request->company_id,
            'main_group_id' => $validated['main_group_id'],
            'name' => $validated['name']
        ]);

        // Get the last code for this main group
        $lastSubGroup = SubGroup::where(['main_group_id' => $validated['main_group_id']])
            ->orderBy('code', 'DESC')
            ->first();

        $validated['code'] = $lastSubGroup ? (int) ($lastSubGroup->code) + 1 : 1;

        // DEBUG: Log the last sub group found
        Log::info('SubGroup Store - Last SubGroup', [
            'last_subgroup' => $lastSubGroup ? $lastSubGroup->toArray() : 'null'
        ]);

        // Get the last ranking_for_trial for this main group
        $lastRanking = SubGroup::where('company_id', $request->company_id)
            ->where('main_group_id', $validated['main_group_id'])
            ->orderBy('ranking_for_trial', 'desc')
            ->first();

        // DEBUG: Log the last ranking found
        Log::info('SubGroup Store - Last Ranking', [
            'last_ranking' => $lastRanking ? $lastRanking->toArray() : 'null',
            'query_conditions' => [
                'company_id' => $request->company_id,
                'main_group_id' => $validated['main_group_id']
            ]
        ]);
        
        // Set the new ranking_for_trial (increment from the last one in the same main group)
        $validated['ranking_for_trial'] = $lastRanking ? $lastRanking->ranking_for_trial + 1 : 1;

        // DEBUG: Log the final ranking
        Log::info('SubGroup Store - Final Ranking', [
            'final_ranking' => $validated['ranking_for_trial']
        ]);

        $group = SubGroup::create($validated);
        
        // DEBUG: Log the created record
        Log::info('SubGroup Store - Created', $group->toArray());
        
        return response()->json($group, 201);
        
    } catch (ModelNotFoundException $e) {
        Log::error('SubGroup Store - Model Not Found', ['error' => $e->getMessage()]);
        return response()->json(['error' => 'Sub Group not found!!'], 404);
    } catch (QueryException $e) {
        Log::error('SubGroup Store - Query Exception', ['error' => $e->getMessage()]);
        return response()->json(['error' => 'An unexpected error occurred!!'], 500);
    } catch (\Exception $e) {
        Log::error('SubGroup Store - General Exception', ['error' => $e->getMessage()]);
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
            $subMainGroup = SubGroup::findOrFail($id);

            $usedIn = [];


            if ($subMainGroup->accountGroups()->exists()) {
                $usedIn[] = 'account groups';
            }
            if ($subMainGroup->journalVoucherTransactions()->exists()) {
                $usedIn[] = 'journal voucher transactions';
            }

            if (!empty($usedIn)) {
                return response()->json([
                    'error' => 'cannot delete, in use',
                    'message' => 'Sub Main Group cannot be deleted because it is used in: ' . implode(', ', $usedIn),
                    'used_in' => $usedIn
                ], 400);
            }

            $subMainGroup->delete();

            return response()->json([
                'success' => true,
                'message' => 'Sub Main Group deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {
           
            return response()->json([
                'error' => 'not_found',
                'message' => 'Sub Main Group not found!'
            ], 404);

        } catch (QueryException $e) {
           
            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the mainGroup.'
            ], 500);

        } catch (\Exception $e) {
           
            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the mainGroup.'
            ], 500);
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

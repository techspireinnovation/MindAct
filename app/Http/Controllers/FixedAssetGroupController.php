<?php

namespace App\Http\Controllers;

use App\Models\FixedAssetGroup;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Log;

class FixedAssetGroupController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FixedAssetGroup::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }
    public function fixedAssetGroupList(Request $request): JsonResponse
    {
        try {
            $fixedAssetGroups = FixedAssetGroup::where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->where('is_active', 1)
                ->get(['id', 'name'])
                ->map(fn($fixedAssetGroup) => ['id' => $fixedAssetGroup->id, 'name' => $fixedAssetGroup->name])
                ->values()
                ->toArray();

            return response()->json([
                "message" => "Fixed Asset Group List Received !!",
                "data" => $fixedAssetGroups
            ]);
        } catch (ModelNotFoundException $e) {
           
            return response()->json(["error" => "Fixed Asset Group not Found !!"], 404);
        } catch (QueryException $e) {
            
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
           
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }

    public function fixedAssetGroupDetails(Request $request): JsonResponse
    {
        try {
            $companyId = $request->company_id;
            if (!$companyId) {
                return response()->json(["error" => "No Company Logged In !!"], 404);
            }

            $groupName = $request->group_name;
            $fixedAssetGroupDetails = FixedAssetGroup::where('company_id', $request->company_id)
                ->where('name', $groupName)
                ->whereNull('deleted_at')
                ->firstOrFail();

            return response()->json([
                "message" => "Fixed Asset Group Details Received !!",
                "data" => $fixedAssetGroupDetails
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Fixed Asset Group not Found !!"], 404);
        } catch (QueryException $e) {
           
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
           
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = FixedAssetGroup::findOrFail($id);
            $validated = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('fixed_asset_groups')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $item) {
                            return $query->where('company_id', $request->input('company_id', $item->company_id))
                                ->whereNull('deleted_at');

                        }),
                ],
                'account_group_id' => 'integer|exists:account_groups,id',
                'code' => 'nullable|string|max:255',
                'depreciation_percent' => 'nullable|numeric',
                'status' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
                'is_primary' => 'nullable|boolean',
                'company_id' => 'integer|exists:companies,id'
            ]);

            DB::transaction(function () use ($validated, $id, &$item) {
                $item = FixedAssetGroup::findOrFail($id);
                $item->update($validated);
            });

            return response()->json(['message' => 'Fixed Asset Group Updated']);

        } catch (ModelNotFoundException $e) {
           
            return response()->json(['error' => 'Item not found'], 404);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'Update failed: ' . $e->getMessage()], 500);
        }

    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('fixed_asset_groups')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id)
                        ->whereNull('deleted_at');

                }),
            ],
            'account_group_id' => 'integer|exists:account_groups,id',
            'code' => 'nullable|string|max:255',
            'depreciation_percent' => 'nullable|numeric',
            'status' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'is_primary' => 'nullable|boolean',
            'company_id' => 'integer|exists:companies,id'
        ]);

        $item = FixedAssetGroup::create($validated);
        return response()->json([
            'item' => $item,
            'action' => 'created',
        ], 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        try {
            $product = FixedAssetGroup::where('company_id', $request->company_id)->findOrFail($id);
            return response()->json([
                'item' => $product
            ]);
        } catch (ModelNotFoundException $e) {
           
            return response()->json(['error' => 'Fixed Asset Group not found!'], 404);
        } catch (QueryException $e) {
           
            return response()->json(['error' => 'Database query error occurred!'], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'Unexpected error occurred!'], 500);
        }
    }



    public function destroy($id): JsonResponse
    {
        try {
            $group = FixedAssetGroup::findOrFail($id);

            $usedIn = [];

            if ($group->fixedAssetAccounts()->exists()) {
                $usedIn[] = 'fixed asset accounts';
            }

            if (!empty($usedIn)) {
                return response()->json([
                    'error' => 'in_use',
                    'message' => 'Fixed Asset Group cannot be deleted because it is used in: ' . implode(', ', $usedIn),
                    'used_in' => $usedIn
                ], 400);
            }

            $group->delete();

            return response()->json([
                'success' => true,
                'message' => 'Fixed Asset Group deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {
           
            return response()->json([
                'error' => 'not_found',
                'message' => 'Fixed Asset Group not found!'
            ], 404);

        } catch (QueryException $e) {
           
            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the Fixed Asset Group.'
            ], 500);

        } catch (\Exception $e) {
          
            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the Fixed Asset Group.'
            ], 500);
        }
    }

}

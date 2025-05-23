<?php

namespace App\Http\Controllers;

use App\Models\FixedAssetGroup;
use App\Models\JournalVoucher;
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

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = FixedAssetGroup::findOrFail($id);
            $validated = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('brands')
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
            Log::error($e);
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
            $item = FixedAssetGroup::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Fixed Asset Group deleted!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}

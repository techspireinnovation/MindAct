<?php

namespace App\Http\Controllers;

use App\Models\FixedAssetAccount;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Log;

class FixedAssetAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FixedAssetAccount::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('fixed_asset_accounts')->where(function ($query) use ($request) {
                    return $query->where('company_id', $request->company_id)
                        ->whereNull('deleted_at');

                }),
            ],
            'fixed_asset_group_id' => 'integer|exists:fixed_asset_groups,id',
            'code' => 'nullable|string|max:255',
            'status' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'is_primary' => 'nullable|boolean',
            'company_id' => 'integer|exists:companies,id'
        ]);

        $item = FixedAssetAccount::create($validated);
        return response()->json([
            'item' => $item,
            'action' => 'created',
        ], 201);
    }

    public function show(Request $request, $id): JsonResponse
    {
        try {
            $product = FixedAssetAccount::where('company_id', $request->company_id)->findOrFail($id);
            return response()->json([
                'item' => $product
            ]);
        } catch (ModelNotFoundException $e) {
            Log::error($e);
            return response()->json(['error' => 'Fixed Asset Account Group not found!'], 404);
        } catch (QueryException $e) {
            Log::error($e);
            return response()->json(['error' => 'Database query error occurred!'], 500);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred!'], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = FixedAssetAccount::findOrFail($id);
            $validated = $request->validate([
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('fixed_asset_accounts')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $item) {
                            return $query->where('company_id', $request->input('company_id', $item->company_id))
                                ->whereNull('deleted_at');

                        }),
                ],
                'fixed_asset_group_id' => 'integer|exists:fixed_asset_groups,id',
                'code' => 'nullable|string|max:255',
                'status' => 'nullable|boolean',
                'is_active' => 'nullable|boolean',
                'is_primary' => 'nullable|boolean',
                'company_id' => 'integer|exists:companies,id'
            ]);

            DB::transaction(function () use ($validated, $id, &$item) {
                $item = FixedAssetAccount::findOrFail($id);
                $item->update($validated);
            });
            return response()->json(['message' => 'Fixed Asset Group Updated']);
        } catch (ModelNotFoundException $e) {
            Log::error('Fixed Asset Account update model not found exception ' . $e->getMessage());
            return response()->json(['error' => 'Item not found'], 404);
        } catch (\Exception $e) {
            Log::error('Fixed Asset Account destroy exception ' . $e->getMessage());
            return response()->json(['error' => 'Update failed: ' . $e->getMessage()], 500);
        }

    }
    public function destroy($id): JsonResponse
    {
        try {
            $item = FixedAssetAccount::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Fixed Asset Account deleted!']);
        } catch (ModelNotFoundException $e) {
            Log::error('Fixed Asset Account destroy model not found exception ' . $e->getMessage());
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            Log::error('Fixed Asset Account destroy query exception ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            Log::error('Fixed Asset Account destroy exception ' . $e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}

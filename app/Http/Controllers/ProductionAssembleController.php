<?php

namespace App\Http\Controllers;

use App\Models\ProductionAssemble;
use App\Models\ProductionSetting;
use App\Models\Product;
use App\Models\ProductList;
use App\Models\MeasureUnit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductionAssembleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductionAssemble::query();


        return response()->json($query->paginate(10));
    }


    public function getProductionSettingList(Request $request): JsonResponse
    {
        try {
            $productionSettings = ProductionSetting::select('id', 'product_id', 'product_name', 'quantity')
                ->whereNull('deleted_at')
                ->where('company_id', $request->company_id)
                ->get();

            return response()->json([
                'message' => 'Production Settings fetched successfully.',
                'data' => $productionSettings
            ], 200);

        } catch (ModelNotFoundException $e) {
            \Log::error('Item not Found in getProductionSettingList', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Item Not Found !!'], 404);
        } catch (QueryException $e) {
            \Log::error('Database error in getProductionSettingList', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getProductionSettingList', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }


    public function getProductionSettingDetail(Request $request): JsonResponse
    {
        try {
            $productionSettingId = $request->input('production_setting_id');
            $item = ProductionSetting::with('settingDetail')->findOrFail($productionSettingId);

            $mainProductId = $item->product_id;
            $settingDetails = $item->settingDetail ?? [];

            // Collect product IDs
            $detailProductIds = collect($settingDetails)->pluck('product_id')->filter()->unique()->toArray();
            $allProductIds = $detailProductIds;

            if ($mainProductId) {
                $allProductIds[] = $mainProductId;
            }

            $allProductIds = array_unique($allProductIds);

            // Get measure_unit_ids from Product table
            $productUnits = Product::whereIn('id', $allProductIds)->get(['id', 'measure_unit_id']);
            $productUnitsMap = [];
            foreach ($productUnits as $p) {
                if ($p->measure_unit_id) {
                    $productUnitsMap[$p->id][] = $p->measure_unit_id;
                }
            }

            // Get measure_unit_ids from ProductList table
            $productListUnits = ProductList::whereIn('product_id', $allProductIds)->get(['product_id', 'measure_unit_id']);
            $productListUnitsMap = [];
            foreach ($productListUnits as $pl) {
                if ($pl->measure_unit_id) {
                    $productListUnitsMap[$pl->product_id][] = $pl->measure_unit_id;
                }
            }

            // Include explicit unit from settingDetail
            $detailUnitsMap = [];
            foreach ($settingDetails as $detail) {
                $productId = $detail->product_id;
                $units = array_merge(
                    $productUnitsMap[$productId] ?? [],
                    $productListUnitsMap[$productId] ?? [],
                    $detail->measure_unit_id ? [$detail->measure_unit_id] : []
                );
                $detailUnitsMap[$productId] = array_unique($units);
            }

            // Main product units
            $mainUnits = array_unique(array_merge(
                $productUnitsMap[$mainProductId] ?? [],
                $productListUnitsMap[$mainProductId] ?? [],
                $item->measure_unit_id ? [$item->measure_unit_id] : [] // Include root measure_unit_id
            ));

            $allMeasureUnitIds = array_unique(array_merge(
                $mainUnits,
                ...array_values($detailUnitsMap)
            ));

            $measureUnits = MeasureUnit::whereIn('id', $allMeasureUnitIds)
                ->get(['id', 'name', 'quantity'])
                ->keyBy('id');

            // Map main used measure units
            $mainUsedMeasureUnits = collect($mainUnits)
                ->filter(fn($id) => isset($measureUnits[$id]))
                ->map(fn($id) => [
                    'id' => $id,
                    'name' => $measureUnits[$id]->name,
                    'quantity' => $measureUnits[$id]->quantity,
                ])
                ->values()
                ->toArray();

            // Map detail used measure units
            $detailUsedMeasureUnits = [];
            foreach ($detailUnitsMap as $productId => $unitIds) {
                $detailUsedMeasureUnits[$productId] = collect($unitIds)
                    ->filter(fn($id) => isset($measureUnits[$id]))
                    ->map(fn($id) => [
                        'id' => $id,
                        'name' => $measureUnits[$id]->name,
                        'quantity' => $measureUnits[$id]->quantity,
                    ])
                    ->values()
                    ->toArray();
            }

            // Enrich settingDetail
            $enrichedDetails = collect($settingDetails)->map(function ($detail) use ($detailUsedMeasureUnits) {
                return array_merge($detail->toArray(), [
                    'used_measure_units' => $detailUsedMeasureUnits[$detail->product_id] ?? [],
                ]);
            });

            // Final response
            $mainData = $item->toArray();
            $mainData['measure_unit_id'] = $item->measure_unit_id; // Add root measure_unit_id
            $mainData['used_measure_units'] = $mainUsedMeasureUnits;
            $mainData['setting_detail'] = $enrichedDetails;

            return response()->json([
                'message' => 'Production Settings fetched successfully.',
                'data' => $mainData,
            ], 200);

        } catch (ModelNotFoundException $e) {
            \Log::error('Item not Found in getProductionSettingDetail', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Item Not Found !!'], 404);
        } catch (QueryException $e) {
            \Log::error('Database error in getProductionSettingDetail', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in getProductionSettingDetail', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }


    public function store(Request $request): JsonResponse
    {
        try {

            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'production_id' => 'nullable',
                'product_name' => 'nullable|string|max:255',
                'product_location_id' => 'nullable|exists:locations,id',
                'measure_unit_id' => 'nullable|exists:measure_units,id',
                'product_quantity' => 'nullable|numeric',
                'production_date' => 'nullable|string|max:255',
                'production_no' => 'nullable|string|max:255',

                'document_no' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('production_assembles')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id'))
                                ->whereNull('deleted_at');
                        })
                ],
                'batch_no' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('production_assembles')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id'));
                        })
                ],

                'total_rm_amount' => 'nullable|numeric',
                'product_damage_quantity' => 'nullable|numeric',
                'finish_product_qauntity' => 'nullable|numeric',
                'finish_cost_per_unit' => 'nullable|numeric',
                'product_defect_quantity' => 'nullable|numeric',
                'total_product_cost' => 'nullable|numeric',

                'product_details' => 'nullable|array',
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.quantity' => 'required_with:product_details|numeric',
                'product_details.*.damage_lost' => 'required_with:product_details|numeric',
                'product_details.*.rate' => 'required_with:product_details|numeric',
                'product_details.*.amount' => 'required_with:product_details|numeric',

            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $ProductionAssemble = ProductionAssemble::create($validator->validated());

            return response()->json([
                'message' => 'Production Assemble  created successfully',
                'data' => $ProductionAssemble,
            ], 201);

        } catch (QueryException $e) {
            \Log::error('Database error in Production Assemble store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
            dd($e->getMessage());
            return response()->json(['message' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error('Unexpected error in Production Assemble store', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }



    public function show($id): JsonResponse
    {
        try {
            $item = ProductionAssemble::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'production_id' => 'nullable',
                'product_name' => 'nullable|string|max:255',
                'product_location_id' => 'nullable|exists:locations,id',
                'measure_unit_id' => 'nullable|exists:measure_units,id',
                'product_quantity' => 'nullable|numeric',
                'production_date' => 'nullable|string|max:255',
                'production_no' => 'nullable|string|max:255',

                'document_no' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('production_assembles')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id'))
                                ->whereNull('deleted_at');
                        })

                ],
                'batch_no' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('production_assembles')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id'))
                                ->whereNull('deleted_at');
                        })

                ],
                'total_rm_amount' => 'nullable|numeric',
                'product_damage_quantity' => 'nullable|numeric',
                'finish_product_qauntity' => 'nullable|numeric',
                'finish_cost_per_unit' => 'nullable|numeric',
                'product_defect_quantity' => 'nullable|numeric',
                'total_product_cost' => 'nullable|numeric',

                'product_details' => 'nullable|array',
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.quantity' => 'required_with:product_details|numeric',
                'product_details.*.damage_lost' => 'required_with:product_details|numeric',
                'product_details.*.rate' => 'required_with:product_details|numeric',
                'product_details.*.amount' => 'required_with:product_details|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();

            $ProductionAssemble = ProductionAssemble::findOrFail($id);

            $ProductionAssemble->update($data);

            return response()->json([
                'message' => 'Production Assemble updated successfully',
                'data' => $ProductionAssemble,
            ], 200);

        } catch (QueryException $e) {
             dd($e->getMessage());
            \Log::error('Database error in Production Assemble update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
            return response()->json(['message' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            // Log the error with sensitive data excluded
            \Log::error('Unexpected error in Production Assemble update', ['error' => $e->getMessage(), 'request' => $request->except(['sensitive_field'])]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }



    public function destroy($id): JsonResponse
    {
        try {
            $item = ProductionAssemble::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Production Assemble deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Production Assemble not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

}

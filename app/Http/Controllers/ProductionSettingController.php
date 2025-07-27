<?php

namespace App\Http\Controllers;

use App\Models\ProductionSetting;
use App\Models\Product;
use App\Models\ProductList;
use App\Models\MeasureUnit;
use App\Models\ProductionSettingDetail;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


class ProductionSettingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductionSetting::query();


        return response()->json($query->paginate(10));
    }


    public function store(Request $request): JsonResponse
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'date' => 'nullable|string|max:255',
                'document_no' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('production_settings')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id'))
                            ->whereNull('deleted_at');
                    }),
                ],
                'product_name' => 'nullable|string|max:255',
                'quantity' => 'nullable|numeric',
                'measure_unit_id' => 'nullable|exists:measure_units,id',
                'product_id' => 'nullable|integer|exists:products,id',
                'product_details' => 'nullable|array',
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.quantity' => 'required_with:product_details|numeric',
                'product_details.*.uom' => 'required_with:product_details|exists:measure_units,id',
                'product_details.*.price' => 'required_with:product_details|numeric',
                'product_details.*.amount' => 'required_with:product_details|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $validated = $validator->validated();


            // Create the main production setting
            $productionSetting = ProductionSetting::create($validated);

            // Create related product details
            if (!empty($validated['product_details'])) {
                foreach ($validated['product_details'] as $detail) {
                    ProductionSettingDetail::create([
                        'company_id' => $validated['company_id'],
                        'production_setting_id' => $productionSetting->id,
                        'product_id' => $detail['product_id'],
                        'product_name' => $detail['product_name'],
                        'quantity' => $detail['quantity'],
                        'measure_unit_id' => $detail['uom'],
                        'price' => $detail['price'],
                        'amount' => $detail['amount'],
                    ]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Production Setting created successfully',
                'data' => $productionSetting->load('settingDetail'),
            ], 201);

        } catch (QueryException $e) {
            DB::rollBack();
           
            \Log::error('Database error in Production Setting store', [
                'error' => $e->getMessage(),
                'request' => $request->except(['sensitive_field'])
            ]);
            return response()->json(['message' => 'Database error occurred.'], 500);

        } catch (\Exception $e) {
           
            DB::rollBack();
            \Log::error('Unexpected error in Production Setting store', [
                'error' => $e->getMessage(),
                'request' => $request->except(['sensitive_field'])
            ]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $item = ProductionSetting::with('settingDetail')->findOrFail($id);

            $mainProductId = $item->product_id;
            $settingDetails = $item->settingDetail ?? [];

            // Collect all product IDs (main + detail)
            $detailProductIds = collect($settingDetails)->pluck('product_id')->filter()->unique()->toArray();
            $allProductIds = $detailProductIds;

            if ($mainProductId) {
                $allProductIds[] = $mainProductId;
            }

            $allProductIds = array_unique($allProductIds);

            // 1) From Product table: get all measure_unit_ids for all products
            $productUnits = Product::whereIn('id', $allProductIds)
                ->get(['id', 'measure_unit_id']);
            $productUnitsMap = [];
            foreach ($productUnits as $p) {
                if ($p->measure_unit_id) {
                    $productUnitsMap[$p->id][] = $p->measure_unit_id;
                }
            }

            // 2) From ProductList table: get all measure_unit_ids for all products
            $productListUnits = ProductList::whereIn('product_id', $allProductIds)
                ->get(['product_id', 'measure_unit_id']);
            $productListUnitsMap = [];
            foreach ($productListUnits as $pl) {
                if ($pl->measure_unit_id) {
                    $productListUnitsMap[$pl->product_id][] = $pl->measure_unit_id;
                }
            }

            // 3) From settingDetail explicit measure_unit_id
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

            // Combine measure units for main product
            $mainUnits = array_unique(array_merge(
                $productUnitsMap[$mainProductId] ?? [],
                $productListUnitsMap[$mainProductId] ?? []
            ));

            // Get all measure unit ids used anywhere (main + details)
            $allMeasureUnitIds = array_unique(array_merge(
                $mainUnits,
                ...array_values($detailUnitsMap)
            ));

            // Fetch measure unit info
            $measureUnits = MeasureUnit::whereIn('id', $allMeasureUnitIds)
                ->get(['id', 'name', 'quantity'])
                ->keyBy('id');

            // Format main product used units
            $mainUsedMeasureUnits = collect($mainUnits)
                ->filter(fn($id) => isset($measureUnits[$id]))
                ->map(fn($id) => [
                    'id' => $id,
                    'name' => $measureUnits[$id]->name,
                    'quantity' => $measureUnits[$id]->quantity,
                ])
                ->values()
                ->toArray();

            // Format detail products used units
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

            // Enrich settingDetail with used_measure_units
            $enrichedDetails = collect($settingDetails)->map(function ($detail) use ($detailUsedMeasureUnits) {
                return array_merge($detail->toArray(), [
                    'used_measure_units' => $detailUsedMeasureUnits[$detail->product_id] ?? [],
                ]);
            });

            // Add used_measure_units to main product data
            $mainData = $item->toArray();
            $mainData['used_measure_units'] = $mainUsedMeasureUnits;
            $mainData['setting_detail'] = $enrichedDetails;

            return response()->json([
                'data' => $mainData,
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function update(Request $request, $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'date' => 'nullable|string|max:255',
                'document_no' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('production_settings')->ignore($id)->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->input('company_id'))
                            ->whereNull('deleted_at');
                    })
                ],
                'product_name' => 'nullable|string|max:255',
                'product_id' => 'nullable|integer|exists:products,id',
                'quantity' => 'nullable|numeric',
                'measure_unit_id' => 'nullable|exists:measure_units,id',

                'product_details' => 'nullable|array',
                'product_details.*.id' => 'nullable|integer|exists:production_setting_details,id',
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.quantity' => 'required_with:product_details|numeric',
                'product_details.*.uom' => 'required_with:product_details|exists:measure_units,id',
                'product_details.*.price' => 'required_with:product_details|numeric',
                'product_details.*.amount' => 'required_with:product_details|numeric',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => 'Validation failed',
                    'errors' => $validator->errors(),
                ], 422);
            }

            $data = $validator->validated();


            $productionSetting = ProductionSetting::findOrFail($id);
            $productionSetting->update($data);

            $existingDetailIds = ProductionSettingDetail::where('production_setting_id', $id)->pluck('id')->toArray();
            $receivedDetailIds = [];

            if (!empty($data['product_details'])) {
                foreach ($data['product_details'] as $detail) {
                    if (isset($detail['id'])) {
                        // Update existing
                        $receivedDetailIds[] = $detail['id'];

                        $existingDetail = ProductionSettingDetail::where('id', $detail['id'])
                            ->where('production_setting_id', $id)
                            ->first();

                        if ($existingDetail) {
                            $existingDetail->update([
                                'company_id' => $data['company_id'],
                                'product_id' => $detail['product_id'],
                                'product_name' => $detail['product_name'],
                                'quantity' => $detail['quantity'],
                                'measure_unit_id' => $detail['uom'],
                                'price' => $detail['price'],
                                'amount' => $detail['amount'],
                            ]);
                        }
                    } else {
                        // Create new
                        $newDetail = ProductionSettingDetail::create([
                            'company_id' => $data['company_id'],
                            'production_setting_id' => $productionSetting->id,
                            'product_id' => $detail['product_id'],
                            'product_name' => $detail['product_name'],
                            'quantity' => $detail['quantity'],
                            'measure_unit_id' => $detail['uom'],
                            'price' => $detail['price'],
                            'amount' => $detail['amount'],
                        ]);
                        $receivedDetailIds[] = $newDetail->id;
                    }
                }

                // Delete details that were not in the request
                $idsToDelete = array_diff($existingDetailIds, $receivedDetailIds);
                ProductionSettingDetail::whereIn('id', $idsToDelete)->delete();
            }

            DB::commit();

            return response()->json([
                'message' => 'Production Setting updated successfully',
                'data' => $productionSetting->load('settingDetail'),
            ], 200);

        } catch (QueryException $e) {
            DB::rollBack();
            \Log::error('Database error in Production Setting update', [
                'error' => $e->getMessage(),
                'request' => $request->except(['sensitive_field'])
            ]);
            return response()->json(['message' => 'Database error occurred.'], 500);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Unexpected error in Production Setting update', [
                'error' => $e->getMessage(),
                'request' => $request->except(['sensitive_field'])
            ]);
            return response()->json(['message' => 'Unexpected error occurred.'], 500);
        }
    }


    public function destroy($id): JsonResponse
    {
        try {
            $item = ProductionSetting::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Production Setting deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Production Setting not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

}

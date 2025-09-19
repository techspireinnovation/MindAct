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
    // public function index(Request $request): JsonResponse
    // {
    //     $query = ProductionAssemble::query();


    //     return response()->json($query->paginate(50));
    // }





public function index(Request $request): JsonResponse
{
    $query = ProductionAssemble::query();
    $assemblies = $query->paginate(50);

    // Collect all product_ids from all product_details + main product
    $allProductIds = [];
    foreach ($assemblies as $assembly) {
        if (!empty($assembly->product_details)) {
            foreach ($assembly->product_details as $detail) {
                $allProductIds[] = $detail['product_id'];
            }
        }
        if (!empty($assembly->product_id)) {
            $allProductIds[] = $assembly->product_id;
        }
    }

    $allProductIds = array_unique($allProductIds);

    // Fetch products
    $products = \App\Models\Product::whereIn('id', $allProductIds)
        ->get(['id', 'product_unique_id', 'measure_unit_id']);
    $productsMap = $products->keyBy('id');

    // Fetch product list units
    $productListUnits = \App\Models\ProductList::whereIn('product_id', $allProductIds)
        ->get(['product_id', 'measure_unit_id']);
    $productListUnitsMap = [];
    foreach ($productListUnits as $pl) {
        if ($pl->measure_unit_id) {
            $productListUnitsMap[$pl->product_id][] = $pl->measure_unit_id;
        }
    }

    // Collect all measure unit ids
    $allMeasureUnitIds = [];
    foreach ($products as $p) {
        if ($p->measure_unit_id) {
            $allMeasureUnitIds[] = $p->measure_unit_id;
        }
    }
    foreach ($productListUnitsMap as $ids) {
        $allMeasureUnitIds = array_merge($allMeasureUnitIds, $ids);
    }
    $allMeasureUnitIds = array_unique($allMeasureUnitIds);

    // Fetch measure units
    $measureUnits = \App\Models\MeasureUnit::whereIn('id', $allMeasureUnitIds)
        ->get(['id', 'name', 'quantity'])
        ->keyBy('id');

    // Transform each assembly
    $assemblies->getCollection()->transform(function ($assembly) use ($productsMap, $productListUnitsMap, $measureUnits) {
        // Enrich product_details
        $enrichedDetails = collect($assembly->product_details ?? [])->map(function ($detail) use ($productsMap, $productListUnitsMap, $measureUnits) {
            $product = $productsMap[$detail['product_id']] ?? null;

            $unitIds = [];
            if ($product && $product->measure_unit_id) {
                $unitIds[] = $product->measure_unit_id;
            }
            if (isset($productListUnitsMap[$detail['product_id']])) {
                $unitIds = array_merge($unitIds, $productListUnitsMap[$detail['product_id']]);
            }

            $units = collect($unitIds)->unique()->filter(fn($id) => isset($measureUnits[$id]))
                ->map(fn($id) => [
                    'id' => $id,
                    'name' => $measureUnits[$id]->name,
                    'measure_unit_quantity' => $measureUnits[$id]->quantity,
                ])->values()->toArray();

            return array_merge($detail, [
                'product_unique_id' => $product->product_unique_id ?? null,
                'measure_units' => $units
            ]);
        });

        // Enrich main product
        $mainProduct = $productsMap[$assembly->product_id] ?? null;
        $mainMeasureUnits = [];
        if ($mainProduct) {
            $unitIds = [];
            if ($mainProduct->measure_unit_id) {
                $unitIds[] = $mainProduct->measure_unit_id;
            }
            if (isset($productListUnitsMap[$mainProduct->id])) {
                $unitIds = array_merge($unitIds, $productListUnitsMap[$mainProduct->id]);
            }

            $mainMeasureUnits = collect($unitIds)->unique()->filter(fn($id) => isset($measureUnits[$id]))
                ->map(fn($id) => [
                    'id' => $id,
                    'name' => $measureUnits[$id]->name,
                    'measure_unit_quantity' => $measureUnits[$id]->quantity,
                ])->values()->toArray();
        }

        // Response for each assembly
        $responseData = $assembly->toArray();
        $responseData['product_unique_id'] = $mainProduct->product_unique_id ?? null;
        $responseData['measure_units'] = $mainMeasureUnits;
        $responseData['product_details'] = $enrichedDetails;

        return $responseData;
    });

    return response()->json([
        'message' => 'Production Assemblies fetched successfully',
        'data' => $assemblies
    ], 200);
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


    // public function getProductionSettingDetail(Request $request): JsonResponse
    // {
    //     try {
    //         $productionSettingId = $request->input('production_setting_id');
    //         $item = ProductionSetting::with('settingDetail')->findOrFail($productionSettingId);

    //         $mainProductId = $item->product_id;
    //         $settingDetails = $item->settingDetail ?? [];

    //         $detailProductIds = collect($settingDetails)->pluck('product_id')->filter()->unique()->toArray();
    //         $allProductIds = $detailProductIds;

    //         if ($mainProductId) {
    //             $allProductIds[] = $mainProductId;
    //         }

    //         $allProductIds = array_unique($allProductIds);

    //         $productUnits = Product::whereIn('id', $allProductIds)->get(['id', 'measure_unit_id']);
    //         $productUnitsMap = [];
    //         foreach ($productUnits as $p) {
    //             if ($p->measure_unit_id) {
    //                 $productUnitsMap[$p->id][] = $p->measure_unit_id;
    //             }
    //         }

    //         $productListUnits = ProductList::whereIn('product_id', $allProductIds)->get(['product_id', 'measure_unit_id']);
    //         $productListUnitsMap = [];
    //         foreach ($productListUnits as $pl) {
    //             if ($pl->measure_unit_id) {
    //                 $productListUnitsMap[$pl->product_id][] = $pl->measure_unit_id;
    //             }
    //         }

    //         $detailUnitsMap = [];
    //         foreach ($settingDetails as $detail) {
    //             $productId = $detail->product_id;
    //             $units = array_merge(
    //                 $productUnitsMap[$productId] ?? [],
    //                 $productListUnitsMap[$productId] ?? [],
    //                 $detail->measure_unit_id ? [$detail->measure_unit_id] : []
    //             );
    //             $detailUnitsMap[$productId] = array_unique($units);
    //         }

    //         $mainUnits = array_unique(array_merge(
    //             $productUnitsMap[$mainProductId] ?? [],
    //             $productListUnitsMap[$mainProductId] ?? [],
    //             $item->measure_unit_id ? [$item->measure_unit_id] : []
    //         ));

    //         $allMeasureUnitIds = array_unique(array_merge(
    //             $mainUnits,
    //             ...array_values($detailUnitsMap)
    //         ));

    //         $measureUnits = MeasureUnit::whereIn('id', $allMeasureUnitIds)
    //             ->get(['id', 'name', 'quantity'])
    //             ->keyBy('id');

    //         $mainUsedMeasureUnits = collect($mainUnits)
    //             ->filter(fn($id) => isset($measureUnits[$id]))
    //             ->map(fn($id) => [
    //                 'id' => $id,
    //                 'name' => $measureUnits[$id]->name,
    //                 'quantity' => $measureUnits[$id]->quantity,
    //             ])
    //             ->values()
    //             ->toArray();

    //         $detailUsedMeasureUnits = [];
    //         foreach ($detailUnitsMap as $productId => $unitIds) {
    //             $detailUsedMeasureUnits[$productId] = collect($unitIds)
    //                 ->filter(fn($id) => isset($measureUnits[$id]))
    //                 ->map(fn($id) => [
    //                     'id' => $id,
    //                     'name' => $measureUnits[$id]->name,
    //                     'quantity' => $measureUnits[$id]->quantity,
    //                 ])
    //                 ->values()
    //                 ->toArray();
    //         }

    //         $enrichedDetails = collect($settingDetails)->map(function ($detail) use ($detailUsedMeasureUnits) {
    //             return array_merge($detail->toArray(), [
    //                 'used_measure_units' => $detailUsedMeasureUnits[$detail->product_id] ?? [],
    //             ]);
    //         });

    //         $measureUnitDetails = null;
    //         if ($item->measure_unit_id && isset($measureUnits[$item->measure_unit_id])) {
    //             $measureUnit = $measureUnits[$item->measure_unit_id];
    //             $measureUnitDetails = [
    //                 'id' => $measureUnit->id,
    //                 'name' => $measureUnit->name,
    //                 'quantity' => $measureUnit->quantity,
    //             ];
    //         }

    //         $mainData = $item->toArray();
    //         unset($mainData['measure_unit_id']); // Remove measure_unit_id
    //         $mainData['measure_unit'] = $measureUnitDetails; // Add measure_unit with full details
    //         $mainData['used_measure_units'] = $mainUsedMeasureUnits;
    //         $mainData['setting_detail'] = $enrichedDetails;

    //         return response()->json([
    //             'message' => 'Production Settings fetched successfully.',
    //             'data' => $mainData,
    //         ], 200);

    //     } catch (ModelNotFoundException $e) {
    //         \Log::error('Item not Found in getProductionSettingDetail', ['error' => $e->getMessage()]);
    //         return response()->json(['message' => 'Item Not Found !!'], 404);
    //     } catch (QueryException $e) {
    //         \Log::error('Database error in getProductionSettingDetail', ['error' => $e->getMessage()]);
    //         return response()->json(['message' => 'Database error occurred.'], 500);
    //     } catch (\Exception $e) {
    //         \Log::error('Unexpected error in getProductionSettingDetail', ['error' => $e->getMessage()]);
    //         return response()->json(['message' => 'Unexpected error occurred.'], 500);
    //     }
    // }

public function getProductionSettingDetail(Request $request): JsonResponse
{
    try {
        $productionSettingId = $request->input('production_setting_id');
        $item = ProductionSetting::with('settingDetail')->findOrFail($productionSettingId);

        $mainProductId = $item->product_id;
        $settingDetails = $item->settingDetail ?? [];

        // Collect all product IDs (main + details)
        $detailProductIds = collect($settingDetails)->pluck('product_id')->filter()->unique()->toArray();
        $allProductIds = array_unique(array_merge($detailProductIds, [$mainProductId]));

        // Fetch products
        $products = Product::whereIn('id', $allProductIds)
            ->get(['id', 'product_unique_id', 'name', 'measure_unit_id'])
            ->keyBy('id');

        // Fetch product list for measure units + barcode
        $productLists = ProductList::whereIn('product_id', $allProductIds)
            ->get(['product_id', 'measure_unit_id', 'barcode']);

        $productListUnitsMap = [];
        $productBarcodesMap = [];
        foreach ($productLists as $pl) {
            if ($pl->measure_unit_id) {
                $productListUnitsMap[$pl->product_id][] = $pl->measure_unit_id;
            }
            if ($pl->barcode) {
                $productBarcodesMap[$pl->product_id][] = $pl->barcode;
            }
        }

        // Collect measure unit IDs
        $allMeasureUnitIds = [];
        foreach ($products as $p) {
            if ($p->measure_unit_id) $allMeasureUnitIds[] = $p->measure_unit_id;
            if (isset($productListUnitsMap[$p->id])) $allMeasureUnitIds = array_merge($allMeasureUnitIds, $productListUnitsMap[$p->id]);
        }
        $allMeasureUnitIds = array_unique($allMeasureUnitIds);

        // Fetch measure units
        $measureUnits = MeasureUnit::whereIn('id', $allMeasureUnitIds)
            ->get(['id', 'name', 'quantity'])
            ->keyBy('id');

        // Map main product measure units
        $mainUnitIds = array_unique(array_merge(
            $products[$mainProductId]->measure_unit_id ? [$products[$mainProductId]->measure_unit_id] : [],
            $productListUnitsMap[$mainProductId] ?? []
        ));

        $mainUsedMeasureUnits = collect($mainUnitIds)
            ->filter(fn($id) => isset($measureUnits[$id]))
            ->map(fn($id) => [
                'id' => $id,
                'name' => $measureUnits[$id]->name,
                'quantity' => $measureUnits[$id]->quantity
            ])->values()->toArray();

        // Enrich setting details with measure units, product_unique_id, and barcode
        $enrichedDetails = collect($settingDetails)->map(function ($detail) use ($products, $productListUnitsMap, $measureUnits, $productBarcodesMap) {
            $product = $products[$detail->product_id] ?? null;
            $unitIds = array_merge(
                $product->measure_unit_id ? [$product->measure_unit_id] : [],
                $productListUnitsMap[$detail->product_id] ?? []
            );
            $usedUnits = collect($unitIds)
                ->filter(fn($id) => isset($measureUnits[$id]))
                ->map(fn($id) => [
                    'id' => $id,
                    'name' => $measureUnits[$id]->name,
                    'quantity' => $measureUnits[$id]->quantity
                ])->values()->toArray();

            return array_merge($detail->toArray(), [
                'used_measure_units' => $usedUnits,
                'product_unique_id' => $product->product_unique_id ?? null,
                'barcode' => $productBarcodesMap[$detail->product_id] ?? []
            ]);
        });

        // Main product
        $mainProduct = $products[$mainProductId] ?? null;

        $mainData = [
            'id' => $item->id,
            'company_id' => $item->company_id,
            'date' => $item->date,
            'document_no' => $item->document_no,
            'product_id' => $mainProductId,
            'product_unique_id' => $mainProduct->product_unique_id ?? null,
            'product_name' => $mainProduct->name ?? $item->product_name,
            'barcode' => $productBarcodesMap[$mainProductId] ?? [],
            'quantity' => $item->quantity,
            'measure_unit_id' => $item->measure_unit_id,
            'product_details' => $item->product_details ?? [],
            'deleted_at' => $item->deleted_at,
            'created_at' => $item->created_at,
            'updated_at' => $item->updated_at,
            'setting_detail' => $enrichedDetails,
            'measure_unit' => $mainUsedMeasureUnits[0] ?? null,
            'used_measure_units' => $mainUsedMeasureUnits
        ];

        return response()->json([
            'message' => 'Production Settings fetched successfully.',
            'data' => $mainData
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



    // public function show($id): JsonResponse
    // {
    //     try {
    //         $item = ProductionAssemble::findOrFail($id);
    //         return response()->json($item);
    //     } catch (ModelNotFoundException $e) {
    //         return response()->json(['error' => 'Item not found'], 404);
    //     } catch (QueryException $e) {
    //         return response()->json(['error' => 'An unexpected error occurred'], 500);
    //     }
    // }

public function show($id): JsonResponse
{
    try {
        $item = ProductionAssemble::findOrFail($id);

        // Collect all product IDs from product_details + main product
        $detailProductIds = collect($item->product_details ?? [])->pluck('product_id')->filter()->unique()->toArray();
        $allProductIds = array_unique(array_merge($detailProductIds, [$item->product_id]));

        // Fetch products with measure units
        $products = \App\Models\Product::whereIn('id', $allProductIds)->get(['id', 'product_unique_id', 'measure_unit_id']);
        $productsMap = $products->keyBy('id');

        // Fetch product list units
        $productListUnits = \App\Models\ProductList::whereIn('product_id', $allProductIds)
            ->get(['product_id', 'measure_unit_id']);

        $productListUnitsMap = [];
        foreach ($productListUnits as $pl) {
            if ($pl->measure_unit_id) {
                $productListUnitsMap[$pl->product_id][] = $pl->measure_unit_id;
            }
        }

        // Collect all measure_unit_ids
        $allMeasureUnitIds = [];
        foreach ($products as $p) {
            if ($p->measure_unit_id) {
                $allMeasureUnitIds[] = $p->measure_unit_id;
            }
        }
        foreach ($productListUnitsMap as $ids) {
            $allMeasureUnitIds = array_merge($allMeasureUnitIds, $ids);
        }

        $allMeasureUnitIds = array_unique($allMeasureUnitIds);

        // Fetch measure units
        $measureUnits = \App\Models\MeasureUnit::whereIn('id', $allMeasureUnitIds)
            ->get(['id', 'name', 'quantity'])
            ->keyBy('id');

        // Enrich product_details with product_unique_id + measure_units
        $enrichedDetails = collect($item->product_details ?? [])->map(function ($detail) use ($productsMap, $productListUnitsMap, $measureUnits) {
            $product = $productsMap[$detail['product_id']] ?? null;

            // Get all measure unit ids for this product
            $unitIds = [];
            if ($product && $product->measure_unit_id) {
                $unitIds[] = $product->measure_unit_id;
            }
            if (isset($productListUnitsMap[$detail['product_id']])) {
                $unitIds = array_merge($unitIds, $productListUnitsMap[$detail['product_id']]);
            }

            // Map measure units
            $units = collect($unitIds)->unique()->filter(fn($id) => isset($measureUnits[$id]))
                ->map(fn($id) => [
                    'id' => $id,
                    'name' => $measureUnits[$id]->name,
                    'measure_unit_quantity' => $measureUnits[$id]->quantity,
                ])->values()->toArray();

            return array_merge($detail, [
                'product_unique_id' => $product->product_unique_id ?? null,
                'measure_units' => $units
            ]);
        });

        // Enrich main product
        $mainProduct = $productsMap[$item->product_id] ?? null;
        $mainMeasureUnits = [];
        if ($mainProduct) {
            $unitIds = [];
            if ($mainProduct->measure_unit_id) {
                $unitIds[] = $mainProduct->measure_unit_id;
            }
            if (isset($productListUnitsMap[$mainProduct->id])) {
                $unitIds = array_merge($unitIds, $productListUnitsMap[$mainProduct->id]);
            }

            $mainMeasureUnits = collect($unitIds)->unique()->filter(fn($id) => isset($measureUnits[$id]))
                ->map(fn($id) => [
                    'id' => $id,
                    'name' => $measureUnits[$id]->name,
                    'measure_unit_quantity' => $measureUnits[$id]->quantity,
                ])->values()->toArray();
        }

        // Prepare response
        $responseData = $item->toArray();
        $responseData['product_unique_id'] = $mainProduct->product_unique_id ?? null;
        $responseData['measure_units'] = $mainMeasureUnits;
        $responseData['product_details'] = $enrichedDetails;

        return response()->json([
            'message' => 'Production Assemble fetched successfully',
            'data' => $responseData
        ], 200);

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

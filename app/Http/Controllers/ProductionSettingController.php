<?php

namespace App\Http\Controllers;

use App\Models\ProductionSetting;
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
            dd($e->getMessage());
            \Log::error('Database error in Production Setting store', [
                'error' => $e->getMessage(),
                'request' => $request->except(['sensitive_field'])
            ]);
            return response()->json(['message' => 'Database error occurred.'], 500);

        } catch (\Exception $e) {
            dd($e->getMessage());
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
            $item = ProductionSetting::findOrFail($id);
            return response()->json($item);
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

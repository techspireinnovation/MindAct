<?php

namespace App\Http\Controllers;


use App\Models\StockReceive;
use App\Models\StockReceiveDetail;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class StockReceiveController extends Controller
{

    public function index(Request $request): JsonResponse
    {

        $query = StockReceive::query();


        return response()->json($query->paginate(50));
    }




    public function update(Request $request, $id): JsonResponse
    {
        try {
            // Find the existing record
            $item = StockReceive::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'reference_no' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stock_receives')->ignore($id)->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at');
                    }),
                ],
                'transfer_ref_no' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stock_receives')->ignore($id)->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at');
                    }),
                ],
                'receive_from' => 'nullable|numeric|max:255',
                'current_location' => 'nullable|numeric|max:255',
                'address' => 'nullable|string|max:255',
                'document_no' => 'nullable|string|max:255',
                'current_date' => 'nullable|string|max:255',
                'current_date_bs' => 'nullable|string|max:255',
                'stock_transfer_date' => 'nullable|string|max:255',
                'stock_transfer_date_bs' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'reasons' => 'nullable|string|max:255',
                'product_details' => 'nullable|array',
                'product_details.*.id' => 'nullable|integer|exists:stock_receive_details,id', // Validate ID if provided
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.quantity' => 'required_with:product_details|numeric',
                'product_details.*.measure_unit_id' => 'required_with:product_details|integer|exists:measure_units,id',
                'product_details.*.batch_no' => 'required_with:product_details|string|max:255',
                'product_details.*.price' => 'required_with:product_details|numeric',
                'product_details.*.amount' => 'required_with:product_details|numeric',
                'company_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $item = DB::transaction(function () use ($item, $validated) {
                if (isset($validated['product_details'])) {
                    $validated['product_details'] = json_encode($validated['product_details']);
                }

                // Update the StockReceive record
                $item->update($validated);

                // Handle StockReceiveDetails
                if (isset($validated['product_details'])) {
                    $productDetails = json_decode($validated['product_details'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \Exception('Invalid product details format: ' . json_last_error_msg());
                    }


                    $existingDetails = $item->stockReceiveDetails->keyBy('id');

                    $detailsToCreate = [];
                    $idsToKeep = [];

                    foreach ($productDetails as $detail) {
                        $detail['stock_transfer_id'] = $item->id;
                        $detail['company_id'] = $validated['company_id'];

                        if (isset($detail['id']) && isset($existingDetails[$detail['id']])) {

                            $existingDetails[$detail['id']]->update($detail);
                            $idsToKeep[] = $detail['id'];
                        } else {

                            unset($detail['id']);
                            $detailsToCreate[] = $detail;
                        }
                    }


                    $item->StockReceiveDetails()
                        ->whereNotIn('id', $idsToKeep)
                        ->delete();

                    // Create new details
                    if (!empty($detailsToCreate)) {
                        $item->StockReceiveDetails()->createMany($detailsToCreate);
                    }
                }

                return $item;
            });

            return response()->json($item->load('StockReceiveDetails'), 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock receive record not found'], 404);
        } catch (QueryException $e) {
          
            return response()->json(['error' => 'An unexpected database error occurred'], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'reference_no' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stock_receives')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at');
                    }),
                ],
                'transfer_ref_no' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('stock_receives')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at');
                    }),
                ],
                'receive_from' => 'nullable|numeric|max:255',
                'current_location' => 'nullable|numeric|max:255',
                'address' => 'nullable|string|max:255',
                'document_no' => 'nullable|string|max:255',
                'current_date' => 'nullable|string|max:255',
                'current_date_bs' => 'nullable|string|max:255',
                'stock_transfer_date' => 'nullable|string|max:255',
                'stock_transfer_date_bs' => 'nullable|string|max:255',

                'remarks' => 'nullable|string|max:255',
                'reasons' => 'nullable|string|max:255',
                'product_details' => 'nullable|array',
                'product_details.*.product_id' => 'required_with:product_details|integer|exists:products,id',
                'product_details.*.product_name' => 'required_with:product_details|string|max:255',
                'product_details.*.quantity' => 'required_with:product_details|numeric',

                'product_details.*.measure_unit_id' => 'required_with:product_details|string|max:50|exists:measure_units,id',
                'product_details.*.batch_no' => 'required_with:product_details|string|max:255',
                'product_details.*.price' => 'required_with:product_details|numeric',
                'product_details.*.amount' => 'required_with:product_details|numeric',
                'company_id' => 'required|integer',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $item = DB::Transaction(function () use ($validated) {

                if (isset($validated['product_details'])) {
                    $validated['product_details'] = json_encode($validated['product_details']);
                }
                $item = StockReceive::create($validated);


                if (isset($validated['product_details'])) {
                    $productDetails = json_decode($validated['product_details'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \Exception('Invalid product details format: ' . json_last_error_msg());
                    }
                    $details = [];
                    foreach ($productDetails as $detail) {
                        $detail['stock_transfer_id'] = $item->id;
                        $detail['company_id'] = $validated['company_id'];
                        $details[] = $detail;
                    }
                    $item->StockReceiveDetails()->createMany($details);
                }
                return $item;

            });


            return response()->json($item->load('StockReceiveDetails'), 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
           
            dd($e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            dd($e->getMessage());
          

            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function show($id): JsonResponse
    {
        try {
            $item = StockReceive::with('stockReceiveDetails')->findOrFail($id);
            return response()->json($item, 200);
        } catch (ModelNotFoundException $e) {
           
            return response()->json(['error' => 'Stock Transfer not found!!'], 404);
        } catch (QueryException $e) {
           
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        } catch (\Exception $e) {
            
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = StockReceive::with('StockReceiveDetails')->findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Stock Transfer deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Stock Tranfer not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

}

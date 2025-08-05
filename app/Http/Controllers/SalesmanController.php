<?php

namespace App\Http\Controllers;
use App\Models\Salesman;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


class SalesmanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Salesman::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(10));
    }



    public function salesmenList(Request $request)
    {
        try {

            $salesmen = Salesman::where('company_id', $request->company_id)
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->get(['id', 'name'])
            ->map(fn($salesman) => ['id' => $salesman->id, 'name' => $salesman->name])
            ->values()
            ->toArray();
            return response()->json([
                "message" => "Sales men List Received !!",
                "data" => $salesmen
            ]);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(["error" => "Sales men not Found !!"], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


    public function salesmenDetails(Request $request)
    {
        try {

            $companyId = $request->company_id;
            if (!$companyId) {
                return response()->json(["error" => "No Company Logged In !!"], 404);
            }

            $salesman = $request->salesman_name;
            $salesmanDetails = Salesman::where('company_id', $request->company_id)
                ->where('name', $salesman)
                ->whereNull('deleted_at')
                ->firstorFail();
            return response()->json([
                "message" => "Sales man Details Received !!",
                "data" => $salesmanDetails
            ], 200);


        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Sales man not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'salesman_id' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('salesmen')

                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'pan_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('salesmen')

                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'name' => 'required|string|max:255',
                'address' => 'nullable|string',
                'country' => 'nullable|string',
                'state' => 'nullable|string',
                'ward_no' => 'nullable|integer',
                'area' => 'nullable|string',
                'mobile' => 'required|string|max:20',
                'email' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('salesmen')

                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'working_office' => 'nullable|string|max:255',
                'joining_date' => 'nullable|date',
                'designation' => 'nullable|string|max:255',
                'dob' => 'nullable|date',
                'citizenship_number' => 'nullable|string|max:255',
                'nationality' => 'nullable|string|max:100',
                'zone' => 'nullable|string|max:100',
                'district' => 'nullable|string|max:100',
                'vdc_municipality' => 'nullable|string|max:255', // Renamed to match schema
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $salesman = Salesman::create($validator->validated());

            return response()->json([
                'message' => 'Salesman created successfully',
                'data' => $salesman
            ], 201);
        } catch (QueryException $e) {
            \Log::error($e);

            return response()->json(['error' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred.'], 500);
        }
    }



    public function show($id): JsonResponse
    {
        try {
            $item = Salesman::findOrFail($id);
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
            $salesman = Salesman::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'salesman_id' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('salesmen')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'pan_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('salesmen')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'name' => 'sometimes|required|string|max:255',
                'address' => 'nullable|string',
                'country' => 'nullable|string',
                'state' => 'nullable|string',
                'ward_no' => 'nullable|integer',
                'area' => 'nullable|string',
                'mobile' => 'required|string|max:20',
                'email' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('salesmen')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'working_office' => 'nullable|string|max:255',
                'joining_date' => 'nullable|date',
                'designation' => 'nullable|string|max:255',
                'dob' => 'nullable|date',
                'citizenship_number' => 'nullable|string|max:255',
                'nationality' => 'nullable|string|max:100',
                'zone' => 'nullable|string|max:100',
                'district' => 'nullable|string|max:100',
                'vdc_municipality' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $salesman->update($validator->validated());

            return response()->json([
                'message' => 'Salesman updated successfully',
                'data' => $salesman->fresh() // Reload the model to get the updated data
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Salesman not found.'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred.'], 500);
        }
    }




    public function destroy($id): JsonResponse
    {
        try {
            $item = Salesman::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Salesman deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Salesman not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

}

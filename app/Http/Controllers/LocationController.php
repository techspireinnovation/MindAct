<?php

namespace App\Http\Controllers;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;

use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Location::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }
    public function locationList(Request $request)
    {
        try {

            $locations = Location::where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->get(['id', 'name'])
                ->map(fn($location) => ['id' => $location->id, 'name' => $location->name])
                ->values()
                ->toArray();
            return response()->json([
                "message" => "Location List Received !!",
                "data" => $locations
            ]);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(["error" => "Location not Found !!"], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


    public function locationDetails(Request $request)
    {
        try {

            $companyId = $request->company_id;
            if (!$companyId) {
                return response()->json(["error" => "No Company Logged In !!"], 404);
            }

            $location = $request->location_name;
            $locationDetails = Location::where('company_id', $request->company_id)
                ->where('name', $location)
                ->whereNull('deleted_at')
                ->firstorFail();
            return response()->json([
                "message" => "Location Details Received !!",
                "data" => $locationDetails
            ], 200);


        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Location not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = Location::findOrFail($id);
            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('locations')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $item) {
                            return $query->where('company_id', $request->input('company_id', $item->company_id))
                                ->whereNull('deleted_at');

                        }),
                ],
                'is_active' => 'boolean|required',
                'is_primary' => 'boolean',
                'company_id' => 'integer'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            // Explicit boolean handling (optional, since validation ensures boolean)
            if ($request->has('is_active')) {
                $validated['is_active'] = (bool) $request->input('is_active');
            }
            if ($request->has('is_primary')) {
                $validated['is_primary'] = (bool) $request->input('is_primary');
            }
            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
                Location::where('company_id', $item->company_id)
                    ->where('id', '!=', $id)
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }



            $item->update($validated);
            $item->refresh();

            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Location not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {

            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('locations')

                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->company_id)
                                ->whereNull('deleted_at');

                        }),

                ],
                'is_active' => 'boolean|required',
                'is_primary' => 'boolean',
                'company_id' => 'integer'
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }
            $validated = $validator->validated();

            $validated['is_primary'] = $validated['is_primary'] ?? false;
            $validated['is_active'] = $validated['is_active'] ?? true;

            if (!empty($validated['is_primary'])) {
                Location::where('company_id', $validated['company_id'])
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }



            $item = Location::create($validated);
            return response()->json($item, 201);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
           
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            
            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function show($id): JsonResponse
    {
        try {
            $item = Location::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Location not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $location = Location::findOrFail($id);

            // Track where it's being used
            $usedIn = [];

            if ($location->products()->exists()) {
                $usedIn[] = 'products';
            }
            if ($location->purchases()->exists()) {
                $usedIn[] = 'purchases';
            }
            if ($location->sales()->exists()) {
                $usedIn[] = 'sales';
            }
            if ($location->productionAssembles()->exists()) {
                $usedIn[] = 'production_assembles';
            }
            if ($location->stockAdjustments()->exists()) {
                $usedIn[] = 'stock_adjustments';
            }

            if (!empty($usedIn)) {
                return response()->json([
                    'error' => 'in_use',
                    'message' => 'Location cannot be deleted because it is in use by: ' . implode(', ', $usedIn) . '.',
                    'used_in' => $usedIn
                ], 400);
            }

            $location->delete();

            return response()->json([
                'success' => true,
                'message' => 'Location deleted successfully!'
            ]);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'not_found',
                'message' => 'Location not found!'
            ], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the location.'
            ], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the location.'
            ], 500);
        }
    }




    public function activeLocations(Request $request): JsonResponse
    {
        try {
            $locations = Location::where('company_id', $request->company_id) // filter by company
                ->where('is_active', 1) // only active
                ->whereNull('deleted_at') // ignore deleted
                ->get(['id', 'name', 'is_primary']) // ✅ include is_primary
                ->map(fn($location) => [
                    'id' => $location->id,
                    'name' => $location->name,
                    'is_primary' => $location->is_primary, // ✅ add in response
                ])
                ->values()
                ->toArray();

            if (empty($locations)) {
                return response()->json([
                    'message' => 'No active locations found',
                    'data' => []
                ], 404);
            }

            return response()->json([
                'message' => 'Active locations retrieved successfully',
                'data' => $locations
            ], 200);

        } catch (\Exception $e) {
            \Log::error('Exception in activeLocations: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


}

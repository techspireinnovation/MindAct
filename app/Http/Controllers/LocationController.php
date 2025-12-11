<?php

namespace App\Http\Controllers;
use App\Interfaces\LocationRepositoryInterface;
use App\Http\Resources\LocationCollection;
use App\Http\Resources\LocationResource;
use App\Http\Requests\LocationRequest\StoreRequest;
use App\Http\Requests\LocationRequest\UpdateRequest;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Validator;

use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class LocationController extends Controller
{

    protected $repository;

    public function __construct(LocationRepositoryInterface $repository)
    {

        $this->repository = $repository;

    }
    public function index(Request $request)
    {
        try {
            $filters = $request->only('keywords');

            $items = $this->repository->list($filters);
            return new LocationCollection($items);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }



    public function locationDetails(Request $request)
    {
        try {

            $location = $request->location_name;
            $locationDetails = $this->repository->locationDetails($location);
            return new LocationResource($locationDetails);


        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Location not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }

    public function update(UpdateRequest $request, $id): JsonResponse
    {
        try {

            $data = $request->validated();
            $item = $this->repository->update($id, $data);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Location not found!!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function store(StoreRequest $request): JsonResponse
    {
        try {


            $data = $request->validated();

            $item = $this->repository->create($data);
            return response()->json($item, 201);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {


            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {


            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function show($id)
    {
        try {
            $item = $this->repository->show($id);
            return new LocationResource($item);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Location not found!!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {

            $this->repository->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Location deleted successfully!'
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'not_found',
                'message' => 'Location not found!'
            ], 404);
        } catch (QueryException $e) {

            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the location.'
            ], 500);
        } catch (\Exception $e) {

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the location.'
            ], 500);
        }
    }




    public function activeLocationList(Request $request)
    {
        try {
            $locations = $this->repository->activeLocationList();

            return LocationResource::collection($locations)
                ->map(fn($location) => [
                    'id' => $location->id,
                    'name' => $location->name,
                ]);

        } catch (\Exception $e) {
          

            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


}

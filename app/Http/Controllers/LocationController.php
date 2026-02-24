<?php

namespace App\Http\Controllers;
use App\Interfaces\LocationRepositoryInterface;
use App\Http\Resources\LocationCollection;
use App\Http\Resources\LocationResource;
use App\Http\Requests\LocationRequest\ListRequest;
use App\Http\Requests\LocationRequest\DetailRequest;
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
    public function index(ListRequest $request)
    {
        try {

            $items = $this->repository->list($request->validated());
            return response()->json([
                'message' => 'Location List!',
                'status' => 200,
                'data' => $items['data'],
                'meta' => $items['meta'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }



    public function locationDetails(DetailRequest $request)
    {
        try {

            $locationDetails = $this->repository->locationDetails($request->validated());
            return response()->json([
                'message' => 'Brand Details !',
                'status' => 200,
                'data' => $locationDetails
            ]);


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

            $item = $this->repository->update($id, $request->validated());
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

            $item = $this->repository->create($request->validated());
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

            return response()->json([
                'message' => 'Location Details !',
                'status' => 200,
                'data' => $item
            ]);
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

            return response()->json([
                'message' => 'Location List !',
                'status' => 200,
                'data' => $locations
            ]);


        } catch (\Exception $e) {


            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


}

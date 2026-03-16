<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Interfaces\AreaRepositoryInterface;

use App\Http\Requests\AreaRequest\ListRequest;
use App\Http\Requests\AreaRequest\DetailRequest;
use App\Http\Requests\AreaRequest\StoreRequest;
use App\Http\Requests\AreaRequest\UpdateRequest;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use App\Models\Area;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AreaController extends Controller
{
    protected $repository;

    public function __construct(AreaRepositoryInterface $repository)
    {

        $this->repository = $repository;

    }
    public function index(ListRequest $request): JsonResponse
    {
        try {
            $items = $this->repository->list($request->validated());
            return response()->json([
                'message' => 'Area List!',
                'status' => 200,
                'data' => $items['data'],
                'meta' => $items['meta'],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);

        }
    }

    public function activeAreaList(Request $request)
    {
        try {

            $areas = $this->repository->activeAreaList();

            return response()->json([
                "message" => "Area List !!",
                'status' => 200,
                "data" => $areas
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json(["error" => "Area not Found !!"], 404);
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }
    public function areaDetails(DetailRequest $request)
    {
        try {
            $areaDetails = $this->repository->areaDetails($request->validated());

            return response()->json([
                "message" => "Area Details !!",
                'status' => 200,
                "data" => $areaDetails
            ], 200);


        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Area not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }

    public function update(UpdateRequest $request, $id): JsonResponse
    {
        try {
            $area = $this->repository->update($id, $request->validated());
            return response()->json([
                'message' => 'Area Updated !!',
                'status' => 200,
                'data' => $area
            ]);
        } catch (ModelNotFoundException $e) {
           
            return response()->json(['error' => 'Area not found!!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function store(StoreRequest $request): JsonResponse
    {
        try {

            $area = $this->repository->create($request->validated());
            return response()->json([
                'message' => 'Area Created !!',
                'status' => 201,
                'data' => $area
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Area not found!!'], 404);
        } catch (QueryException $e) {


            return response()->json(['error' => 'Database  error occurred!!'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $item = $this->repository->show($id);
            return response()->json([
                'message' => 'Store Details !!',
                'status' => 200,
                'data' => $item
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Area not found!!'], 404);
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
                'message' => 'Area deleted successfully!'
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Area not found!!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
              dd($e->getMessage());
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}

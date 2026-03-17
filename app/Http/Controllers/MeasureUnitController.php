<?php

namespace App\Http\Controllers;

use App\Interfaces\MeasureUnitRepositoryInterface;

use App\Http\Resources\MeasureUnitCollection;
use App\Http\Resources\MeasureUnitResource;
use App\Http\Requests\MeasureUnitRequest\ListRequest;
use App\Http\Requests\MeasureUnitRequest\DetailRequest;
use App\Http\Requests\MeasureUnitRequest\StoreRequest;
use App\Http\Requests\MeasureUnitRequest\UpdateRequest;
use App\Models\MeasureUnit;
use App\Models\ProductList;
use App\Models\Product;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeasureUnitController extends Controller
{

    protected $repository;


    public function __construct(MeasureUnitRepositoryInterface $repository)
    {

        $this->repository = $repository;

    }

    public function index(ListRequest $request)
    {
        try {

            $items = $this->repository->list($request->validated());

            return response()->json([
                'message' => 'Measure Unit List!',
                'status' => 200,
                'data' => $items['data'],
                'meta' => $items['meta'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }






    public function unitDetails(DetailRequest $request)
    {
        try {

            $unitDetails = $this->repository->measureUnitDetails($request->validated());
            return response()->json([
                'message' => 'Measure Unit Details !',
                'status' => 200,
                'data' => $unitDetails
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Measure Unit not Found !!"], 404);
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

            return response()->json([
                'success' => 'Updated Successfully !!',
                'data' => $item
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!!'], 404);
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
            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !'], 500);
        }
    }

    public function show($id)
    {
        try {
            $item = $this->repository->show($id);
            return response()->json([
                'message' => 'Measure Unit Details !',
                'status' => 200,
                'data' => $item
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Item not found!!'], 404);
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
                'message' => 'Measure Unit deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'not_found',
                'message' => 'Measure Unit not found!'
            ], 404);

        } catch (QueryException $e) {

            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the Measure Unit.'
            ], 500);

        } catch (\Exception $e) {

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the Measure Unit.'
            ], 500);
        }
    }



    public function activeUnitList(Request $request)
    {
        try {
            $units = $this->repository->activeMeasureUnitList();

            return response()->json([
                'message' => 'Measure Unit List !',
                'status' => 200,
                'data' => $units
            ]);

        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


}

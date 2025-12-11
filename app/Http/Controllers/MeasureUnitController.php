<?php

namespace App\Http\Controllers;

use App\Interfaces\MeasureUnitRepositoryInterface;

use App\Http\Resources\MeasureUnitCollection;
use App\Http\Resources\MeasureUnitResource;
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

    public function index(Request $request)
    {
        try {
            $filters = $request->only('keywords');


            $items = $this->repository->list($filters);

            return new MeasureUnitCollection($items);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }






    public function unitDetails(Request $request)
    {
        try {



            $unit = $request->measure_unit;
            $unitDetails = $this->repository->measureUnitDetails($unit);
            return new MeasureUnitResource($unitDetails);


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

            $data = $request->validated();

            $item = $this->repository->update($id, $data);

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
        $data = $request->validated();
        $item = $this->repository->create($data);
        return response()->json($item, 201);
    }

    public function show($id)
    {
        try {
            $item = $this->repository->show($id);
            return new MeasureUnitResource($item);
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

            return MeasureUnitResource::collection($units)
                ->map(fn($unit) => [
                    'id' => $unit->id,
                    'name' => $unit->name,
                ]);

        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


}

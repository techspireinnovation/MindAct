<?php

namespace App\Http\Controllers;
use App\Interfaces\SalesmanRepositoryInterface;
use App\Http\Requests\SalesmanRequest\StoreRequest;
use App\Http\Requests\SalesmanRequest\UpdateRequest;

use App\Http\Resources\SalesmanCollection;
use App\Http\Resources\SalesmanResource;

use App\Models\Salesman;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;


class SalesmanController extends Controller
{

    protected $repository;

    public function __construct(SalesmanRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }



    public function index(Request $request)
    {
        try {
            $filters = $request->only('keywords');

            $items = $this->repository->list($filters);


            return new SalesmanCollection($items);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }



    public function activesalesmenList(Request $request)
    {
        try {

            $salesmen = $this->repository->activeSalesmanList();


            return SalesmanResource::collection($salesmen)
                ->map(fn($salesman) => [
                    'id' => $salesman->id,
                    'name' => $salesman->name,
                ]);

        } catch (ModelNotFoundException $e) {

            return response()->json(["error" => "Sales men not Found !!"], 404);
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


    public function salesmenDetails(Request $request)
    {
        try {



            $salesman = $request->salesman_name;
            $salesmanDetails = $this->repository->salesmanDetails($salesman);

            return new SalesmanResource($salesmanDetails);


        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Sales man not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


    public function store(StoreRequest $request): JsonResponse
    {
        try {


            $data = $request->validated();

            $salesman = $this->repository->create($data);

            return response()->json([
                'message' => 'Salesman created successfully',
                'data' => $salesman
            ], 201);
        } catch (QueryException $e) {


            return response()->json(['error' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Unexpected error occurred.'], 500);
        }
    }



    public function show($id)
    {
        try {
            $item = $this->repository->show($id);
            return new SalesmanResource($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function update(UpdateRequest $request, $id): JsonResponse
    {
        try {

            $data = $request->validated();


            $salesman = $this->repository->update($id, $data);

            return response()->json([
                'message' => 'Salesman updated successfully',
                'data' => $salesman->fresh()
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Salesman not found.'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occurred.'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Unexpected error occurred.'], 500);
        }
    }


    public function destroy($id): JsonResponse
    {
        try {
            $this->repository->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Salesman deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'not_found',
                'message' => 'Salesman not found!'
            ], 404);

        } catch (QueryException $e) {

            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the salesman.'
            ], 500);

        } catch (\Exception $e) {

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the salesman.'
            ], 500);
        }
    }




}

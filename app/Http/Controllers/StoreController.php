<?php

namespace App\Http\Controllers;
use App\Interfaces\StoreRepositoryInterface;
use App\Models\Store;
use App\Http\Resources\StoreCollection;
use App\Http\Resources\StoreResource;

use App\Http\Requests\StoreRequest\ListRequest;
use App\Http\Requests\StoreRequest\DetailRequest;
use App\Http\Requests\StoreRequest\StoreRequest;
use App\Http\Requests\StoreRequest\UpdateRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoreController extends Controller
{

    protected $repository;

    public function __construct(StoreRepositoryInterface $repository)
    {

        $this->repository = $repository;

    }
    public function index(ListRequest $request): JsonResponse
    {
        try {
            $items = $this->repository->list($request->validated());
            return response()->json([
                'message' => 'Store List!',
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







    public function storeDetails(DetailRequest $request)
    {
        try {
            $storeDetails = $this->repository->storeDetails($request->validated());
            return response()->json([
                'message' => 'Store Details !!',
                'status' => 200,
                'data' => $storeDetails
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json(["error" => "Store not Found !!"], 404);
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
                'message' => 'Store Updated !!',
                'status' => 200,
                'data' => $item
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function store(StoreRequest $request): JsonResponse
    {
        try {

            $item = $this->repository->create($request->validated());
            return response()->json([
                'message' => 'Store Created !!',
                'status' => 201,
                'data' => $item
            ]);



        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item Not Found !!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occured !!'], 500);
        }
    }

    public function show($id)
    {
        try {
            $item = $this->repository->show($id);
            return response()->json([
                'message' => 'Store Details !!',
                'status' => 200,
                'data' => $item
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    public function destroy($id): JsonResponse
    {
        try {

            $this->repository->delete($id);


            return response()->json([
                'success' => true,
                'message' => 'Store deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'true',
                'message' => 'Store not found!'
            ], 404);

        } catch (QueryException $e) {

            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the Store.'
            ], 500);

        } catch (\Exception $e) {

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the Store.'
            ], 500);
        }
    }


    public function activeStoreList(Request $request)
    {
        try {
            $stores = $this->repository->activeStoreList();

            return response()->json([
                'message' => 'Store List !',
                'status' => 200,
                'data' => $stores
            ]);

        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


}

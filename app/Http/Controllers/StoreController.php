<?php

namespace App\Http\Controllers;
use App\Interfaces\StoreRepositoryInterface;
use App\Models\Store;
use App\Http\Resources\StoreCollection;
use App\Http\Resources\StoreResource;

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
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only('keywords');

            $items = $this->repository->list($filters);
            return response()->json($items, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }







    public function storeDetails(Request $request)
    {
        try {

            $store = $request->store_name;
            $storeDetails = $this->repository->storeDetails($store);
            return new StoreResource($storeDetails);


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

            $data = $request->validated();

            $item = $this->repository->update($id, $data);

            return response()->json($item);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function store(StoreRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $item = $this->repository->create($data);


            return response()->json($item, 201);
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
            return new StoreResource($item);
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

            return StoreResource::collection($stores)
                ->map(fn($store) => [
                    'id' => $store->id,
                    'name' => $store->name,
                ]);

        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


}

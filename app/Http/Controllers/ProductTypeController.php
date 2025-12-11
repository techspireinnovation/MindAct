<?php

namespace App\Http\Controllers;

use App\Interfaces\ProductTypeRepositoryInterface;
use App\Models\ProductType;
use App\Models\Product;

use App\Http\Resources\ProductTypeCollection;
use App\Http\Resources\ProductTypeResource;


use App\Http\Requests\ProductTypeRequest\StoreRequest;
use App\Http\Requests\ProductTypeRequest\UpdateRequest;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductTypeController extends Controller
{

    protected $repository;

    public function __construct(ProductTypeRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }
    public function index(Request $request)
    {
        try {
            $filters = $request->only('keywords');

            $productTypes = $this->repository->list($filters);

            return new ProductTypeCollection($productTypes);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexepected error occurred!!'], 500);
        }
    }



    public function productTypeDetails(Request $request)
    {
        try {

            $type = $request->type_name;

            $typeDetails = $this->repository->productTypeDetails($type);

            return new ProductTypeResource($typeDetails);


        } catch (ModelNotFoundException $e) {

            return response()->json(["error" => "Not Item Found !!"], 404);
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
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred!!'], 500);
        } catch (\Exception $e) {
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
            return response()->json(['error' => 'Item Not Found !!'], 404);
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
            return new ProductTypeResource($item);
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
                'message' => 'Product Type deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'not_found',
                'message' => 'Product Type not found!'
            ], 404);

        } catch (QueryException $e) {

            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the product type.'
            ], 500);

        } catch (\Exception $e) {

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the product type.'
            ], 500);
        }
    }


    public function activeProductTypeList(Request $request)
    {
        try {
            $types = $this->repository->activeProductTypeList();

            return ProductTypeResource::collection($types)
                ->map(fn($type) => [
                    'id' => $type->id,
                    'name' => $type->name,
                ]);

        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


}

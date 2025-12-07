<?php

namespace App\Http\Controllers;

use App\Interfaces\ProductTypeRepositoryInterface;
use App\Models\ProductType;
use App\Models\Product;
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
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only('keywords');

        $productTypes = $this->repository->list($filters);

        return response()->json($productTypes, 200);
    }

    public function productTypeList(Request $request)
    {


        try {

            $types = $this->repository->productTypeList();


            return response()->json([
                "message" => "Product Type List Received !!",
                "data" => $types
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json(["error" => "Product Type not Found !!"], 404);
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


    public function productTypeDetails(Request $request)
    {
        try {

            $type = $request->type_name;

            $typeDetails = $this->repository->productTypeDetails($type);

            return response()->json([
                "message" => "Product Type Details Received !!",
                "data" => $typeDetails
            ], 200);


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



    public function show($id): JsonResponse
    {
        try {
            $item = $this->repository->show($id);
            return response()->json($item);
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


    public function activeProductTypeList(Request $request): JsonResponse
    {
        try {
            $types = $this->repository->activeProductTypeList();

            return response()->json([
                "message" => "Active product types received !!",
                "data" => $types
            ], 200);

        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


}

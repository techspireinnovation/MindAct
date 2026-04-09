<?php

namespace App\Http\Controllers;

use App\Interfaces\ProductTypeRepositoryInterface;
use App\Models\ProductType;
use App\Models\Product;

use App\Http\Resources\ProductTypeCollection;
use App\Http\Resources\ProductTypeResource;

use App\Http\Requests\ProductTypeRequest\ListRequest;
use App\Http\Requests\ProductTypeRequest\DetailRequest;
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
    public function index(ListRequest $request)
    {
        try {


            $productTypes = $this->repository->list($request->validated());

            return response()->json([
                'message' => 'Product Type List!',
                'status' => 200,
                'data' => $productTypes['data'],
                'meta' => $productTypes['meta'],
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occured !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexepected error occurred!!'], 500);
        }
    }



    public function productTypeDetails(DetailRequest $request)
    {
        try {


            $typeDetails = $this->repository->productTypeDetails($request->validated());

            return response()->json([
                'message' => 'Product Type Details !!',
                'status' => 200,
                'data' => $typeDetails
            ]);


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


            $item = $this->repository->update($id, $request->validated());

            return response()->json([
                'message' => 'Product Type Updated !!',
                'status' => 200,
                'data' => $item
            ]);

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


            $item = $this->repository->create($request->validated());

            return response()->json([
                'message' => 'Product Type Created !!',
                'status' => 201,
                'data' => $item
            ]);
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
            return response()->json([
                'message' => 'Product Type Details !',
                'status' => 200,
                'data' => $item
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred!!'], 500);
        } catch (\Exception $e) {
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
            ], 200);

        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'not_found',
                'message' => 'Product Type not found!'
            ], 404);

        } catch (\Exception $e) {

            $message = $e->getMessage();

            // Handle specific business exceptions
            if (str_starts_with($message, 'in_use:')) {
                $usedIn = explode(',', str_replace('in_use:', '', $message));
                return response()->json([
                    'error' => 'in_use',
                    'message' => 'Cannot delete this product type because it is currently in use.',
                    'used_in' => $usedIn
                ], 422);
            }

            if ($message === 'cannot_delete') {
                return response()->json([
                    'error' => 'cannot_delete',
                    'message' => 'This product type cannot be deleted.'
                ], 422);
            }

            // Log the unexpected error for debugging
            \Log::error('ProductType Delete Error: ' . $e->getMessage(), [
                'id' => $id,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the product type !'
            ], 500);
        }
    }

    public function activeProductTypeList(Request $request)
    {
        try {
            $types = $this->repository->activeProductTypeList();

            return response()->json([
                'message' => 'Product Type List !',
                'status' => 200,
                'data' => $types
            ]);
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


}

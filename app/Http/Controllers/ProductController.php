<?php

namespace App\Http\Controllers;


use App\Helpers\Helper;
use App\Interfaces\ProductRepositoryInterface;

use App\Http\Requests\ProductRequest\StoreRequest;
use App\Http\Requests\ProductRequest\UpdateRequest;
use App\Http\Requests\ProductRequest\SearchRequest;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product;
use App\Models\ProductField;
use App\Models\ProductFieldValue;
use App\Models\ProductionSetting;
use App\Models\ProductList;
use App\Models\ProductType;
use DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;


use App\Models\ProductCategory;
use App\Models\ProductSubCategory;
use App\Models\Brand;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\MeasureUnit;





class ProductController extends Controller
{

    protected $repository;


    public function __construct(ProductRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }



    public function productList(Request $request)
    {
        try {

            $products = $this->repository->productList();
            return response()->json([
                "message" => "Product List Received !!",
                "data" => $products
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json(["error" => "Product Name not Found !!"], 404);
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


    public function productDetails(Request $request): JsonResponse
    {
        try {

            $productId = $request->product_id;
            $productName = $request->product_name;
            if (!$productId && !$productName) {
                return response()->json(['error' => 'Product Name of ID parameter is required'], 422);
            }

            $productDetail = $this->repository->productDetails($productId, $productName);


            return response()->json([
                'product' => $productDetail
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database query error occurred!'], 500);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error occurred!'], 500);
        }
    }

    public function index(SearchRequest $request): JsonResponse
    {
        try {

            $filters = $request->validated();


            $perPage = $request->input('per_page', 50);


            $result = $this->repository->list($filters, $perPage);

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server error occurred',
                'details' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }



    public function search(Request $request): JsonResponse
    {
        try {
            $filters = $request->validated();


            $products = $this->repository->search($filters);

            return response()->json([
                'data' => $products
            ]);

        } catch (\Exception $e) {


            return response()->json([
                'error' => 'Server error occurred',
                'details' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }



    public function update(UpdateRequest $request, $id): JsonResponse
    {
        try {
            $data = $request->validated();

            $item = $this->repository->update($id, $data);

            return response()->json(['message' => 'Product Updated', 'product' => $item], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not SaleCfound'], 404);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Update failed: ' . $e->getMessage()], 500);
        }

    }

    public function store(StoreRequest $request)
    {

        $data = $request->validated();

        $item = $this->repository->create($data);



        return response()->json([
            'item' => $item,
            'action' => 'created',

        ], 201);
    }



    public function show($id): JsonResponse
    {
        try {

            $product = $this->repository->show($id);

            return response()->json([
                'product' => $product
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database query error occurred!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error occurred!'], 500);
        }
    }


    public function destroy($id): JsonResponse
    {
        try {
            $this->repository->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Product deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'not_found',
                'message' => 'Product not found!'
            ], 404);

        } catch (QueryException $e) {
            

            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the product.'
            ], 500);

        } catch (\Exception $e) {
            

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the product.'
            ], 500);
        }
    }



}








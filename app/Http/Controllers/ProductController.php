<?php

namespace App\Http\Controllers;


use App\Helpers\Helper;
use App\Interfaces\ProductRepositoryInterface;

use App\Http\Resources\ProductCollection;
use App\Http\Resources\ProductResource;

use App\Http\Requests\ProductRequest\ListRequest;
use App\Http\Requests\ProductRequest\DetailRequest;
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



    public function activeProductList(Request $request)
    {
        try {

            $products = $this->repository->activeProductList();
            return response()->json([
                'message' => 'Product List !!',
                'status' => 200,
                'data' => $products
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json(["error" => "Product Name not Found ."], 404);
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }


    public function productDetails(DetailRequest $request)
    {
        try {

            $productDetail = $this->repository->productDetails($request->validated());


            return response()->json([
                'message' => 'Product Details !',
                'status' => 200,
                'data' => $productDetail
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database query error occurred!'], 500);

        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error occurred!'], 500);
        }
    }

    public function index(SearchRequest $request)
    {
        try {

            $result = $this->repository->list($request->validated());

            return response()->json([
                'message' => 'Products List!',
                'status' => 200,
                'data' => $result['data'],
                'meta' => $result['meta'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Server error occurred',
                'details' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }



    public function search(SearchRequest $request)
    {
        try {


            $products = $this->repository->search($request->validated());

            return response()->json([
                'message' => 'Product List',
                'status' => 200,
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


            $item = $this->repository->update($id, $request->validated());

            return response()->json([
                'message' => 'Product Updated !!',
                'status' => 200,
                'data' => $item
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not Found'], 404);
        } catch (\Exception $e) {

            return response()->json(['error' => 'Update failed: ' . $e->getMessage()], 500);
        }

    }

    public function store(StoreRequest $request)
    {
        try {

   
            $item = $this->repository->create($request->validated());

            return response()->json([
                'message' => 'Product Created !!',
                'status' => 200,
                'data' => $item

            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {
           
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }



    public function show($id)
    {
        try {

            $product = $this->repository->show($id);

            return response()->json([
                'message' => 'Product Details !',
                'status' => 200,
                'data' => $product
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

    public function productFields()
    {
        try {

            $data = $this->repository->productFields();

            return response()->json([
                'message' => 'Product Fields !',
                'status' => 200,
                'data' => $data
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product not found!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database query error occurred!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error occurred!'], 500);
        }
    }



}








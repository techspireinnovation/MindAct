<?php

namespace App\Http\Controllers;
use App\Http\Resources\BrandCollection;
use App\Http\Resources\BrandResource;
use App\Interfaces\BrandRepositoryInterface;
use App\Http\Requests\BrandRequest\ListRequest;
use App\Http\Requests\BrandRequest\DetailRequest;
use App\Http\Requests\BrandRequest\StoreRequest;
use App\Http\Requests\BrandRequest\UpdateRequest;
use App\Models\Brand;
use App\Repositories\BrandRepository;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Sagautam5\LocalStateNepal\Entities\Province;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;



class BrandController extends Controller
{



    protected $repository;

    public function __construct(BrandRepositoryInterface $repository)
    {

        $this->repository = $repository;

    }
    public function index(ListRequest $request)
    {
        try {

            $brands = $this->repository->list($request->validated());

            return response()->json([
                'message' => 'Brand List!',
                'status' => 200,
                'data' => $brands['data'],
                'meta' => $brands['meta'],
            ]);


        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An unexpected error occurred !!'
            ], 500);
        }
    }



    public function brandDetails(DetailRequest $request)
    {
        try {


            $brandDetails = $this->repository->brandDetails($request->validated());
            return response()->json([
                'message' => 'Brand Details !',
                'status' => 200,
                'data' => $brandDetails
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Brand not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }

    public function getListProvice()
    {
        $province = new Province('np');
        $provincesData = $province->allProvinces();
        return response()->json($provincesData);

    }

    public function update(UpdateRequest $request, $id): JsonResponse
    {
        try {
            
            $item = $this->repository->update($id, $request->validated());

             return response()->json([
                'message' => 'Brand Updated !!',
                'status' => 200,
                'data' => $item
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);

        }
    }

    public function store(StoreRequest $request): JsonResponse
    {

        try {

            $item = $this->repository->create($request->validated());
            return response()->json([
                'message' => 'Brand Created !!',
                'status' => 201,
                'data' =>$item 
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item Not Found !!'], 404);
        } catch (QueryException) {
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }

    public function show($id)
    {
        try {
            $item = $this->repository->show($id);
            return response()->json([
                'message' => 'Brand Details !',
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
                'message' => 'Brand deleted successfully!'
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'true',
                'message' => 'Brand not found!'
            ], 404);
        } catch (QueryException $e) {
            \Log::error($e->getMessage());
            return response()->json([
                'error' => 'query_error',
                'message' => $e->getMessage()
            ], 500);

        } catch (\Exception $e) {

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the brand.'
            ], 500);
        }
    }

    public function activeBrandList(Request $request)
    {
        try {

            $brands = $this->repository->activeBrandList();
            return response()->json([
                'message' => 'Brand List !',
                'status' => 200,
                'data' => $brands
            ]);


        } catch (ModelNotFoundException $e) {

            return response()->json(["error" => "Brand not Found !"], 404);
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }




}

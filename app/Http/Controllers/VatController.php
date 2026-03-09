<?php

namespace App\Http\Controllers;

use App\Http\Resources\VatResource;
use App\Interfaces\VatRepositoryInterface;
use App\Http\Requests\VatRequest\ListRequest;
use App\Http\Requests\VatRequest\DetailRequest;
use App\Http\Requests\VatRequest\StoreRequest;
use App\Http\Requests\VatRequest\UpdateRequest;
use App\Models\Vat;
use App\Repositories\VatRepository;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Sagautam5\LocalStateNepal\Entities\Province;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;



class VatController extends Controller
{



    protected $repository;

    public function __construct(VatRepositoryInterface $repository)
    {

        $this->repository = $repository;

    }
    public function index(ListRequest $request)
    {
        try {

        

            $brands = $this->repository->list($request->validated());

            return response()->json([
                'message' => 'Vat List!',
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



    public function vatDetails(DetailRequest $request)
    {
        try {


            $brandDetails = $this->repository->vatDetails($request->validated());
            return response()->json([
                'message' => 'Vat Details !',
                'status' => 200,
                'data' => $brandDetails
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Vat not Found !!"], 404);
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
                'message' => 'Vat Updated !!',
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
                'message' => 'Vat Created !!',
                'status' => 201,
                'data' =>$item 
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item Not Found !!'], 404);
        } catch (QueryException $e) {
            dd($e->getMessage());
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
                'message' => 'Vat Details !',
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
                'message' => 'Vat deleted successfully!'
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'true',
                'message' => 'Vat not found!'
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
                'message' => 'Vat List !',
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

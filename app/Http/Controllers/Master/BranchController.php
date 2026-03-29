<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchCollection;
use App\Http\Resources\BranchResource;
use App\Interfaces\BranchRepositoryInterface;
use App\Http\Requests\BranchRequest\ListRequest;
use App\Http\Requests\BranchRequest\DetailRequest;
use App\Http\Requests\BranchRequest\StoreRequest;
use App\Http\Requests\BranchRequest\UpdateRequest;
use App\Models\Branch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;


class BranchController extends Controller
{

    protected $repository;


    public function __construct(BranchRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }


    public function index(ListRequest $request)
    {
        try {


            $branches = $this->repository->list($request->keywords);

            return response()->json([
                'message' => 'Branch List !',
                'status' => 200,
                'data' => $branches['data'],
                'meta' => $branches['meta'],
            ]);

        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }

    public function store(StoreRequest $request): JsonResponse
    {
        try {
            $item = $this->repository->create($request->validated());
            return response()->json([
                'message' => 'Branch Created !',
                'status' => 200,
                'data' => $item
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }



    public function branchDetails(DetailRequest $request)
    {
        try {

            $branchDetails = $this->repository->branchDetails($request->validated());

            return response()->json([
                'message' => 'Branch Details !',
                'status' => 200,
                'data' => $branchDetails
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
                'message' => 'Branch Updated !!',
                'status' => 200,
                'data' => $item
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    public function show($id)
    {
        try {
            $item = $this->repository->show($id);
            return response()->json([
                'message' => 'Branch Details !',
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
            // Fetch branch using tenant connection

            $data = $this->repository->delete($id);

            return response()->json([
                'success' => true,
                'message' => 'Branch deleted successfully.'
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Branch not found.'
            ], 404);

        } catch (QueryException $e) {

            return response()->json([
                'error' => 'query_error',
                'message' => $e->getMessage(),
            ], 422);

        } catch (\Exception $e) {

            return response()->json([
                'error' => 'unexpected_error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }











    public function activeBranchList(Request $request)
    {
        try {
            $branches = $this->repository->activeBranchList();
            return response()->json([
                'message' => 'Branch List !',
                'status' => 200,
                'data' => $branches
            ]);


        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'No Active Branch Found!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database Error Occurred!'], 500);
        } catch (\Exception $e) {


            return response()->json(['error' => 'Unexpected Error Occurred!'], 500);
        }
    }


}

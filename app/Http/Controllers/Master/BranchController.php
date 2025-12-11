<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Http\Resources\BranchCollection;
use App\Http\Resources\BranchResource;
use App\Interfaces\BranchRepositoryInterface;
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


    public function index(Request $request)
    {
        try {

            $filters = $request->only('keywords');
            $branches = $this->repository->list($filters);

            return new BranchCollection($branches);

        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }

    public function store(StoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        $item = $this->repository->create($data);
        return response()->json($item, 201);
    }



    public function branchDetails(Request $request)
    {
        try {
            $branch = $request->branch_name;

            $branchDetails = $this->repository->branchDetails($branch);

            return new BranchResource($branchDetails);


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
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



    public function show($id)
    {
        try {
            $item = $this->repository->show($id);
            return new BranchResource($item);
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
            return BranchResource::collection($branches)
                ->map(fn($branch) => [
                    'id' => $branch->id,
                    'name' => $branch->name,
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

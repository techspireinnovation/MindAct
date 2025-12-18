<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

use App\Http\Requests\BankRequest\ListRequest;
use App\Http\Requests\BankRequest\DetailRequest;
use App\Http\Requests\BankRequest\StoreRequest;
use App\Http\Requests\BankRequest\UpdateRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Interfaces\BankRepositoryInterface;
use Validator;

class BankController extends Controller
{

    protected $repository;

    public function __construct(BankRepositoryInterface $repository)
    {

        $this->repository = $repository;

    }
    public function index(ListRequest $request): JsonResponse
    {
        try {
            $items = $this->repository->list($request->validated());
            return response()->json([
                'message' => 'Banks List!',
                'status' => 200,
                'data' => $items['data'],
                'meta' => $items['meta'],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);

        }
    }

    public function bankList(Request $request): JsonResponse
    {
        try {
            $banks = Bank::where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->where('is_active', 1)
                ->get(['id', 'name'])
                ->map(fn($bank) => ['id' => $bank->id, 'name' => $bank->name])
                ->values()
                ->toArray();

            return response()->json([
                "message" => "Bank List Received !!",
                "data" => $banks
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json(["error" => "Bank not Found !!"], 404);
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }

    public function bankDetails(DetailRequest $request): JsonResponse
    {
        try {
            $bankDetails = $this->repository->bankDetails($request->validated());

            return response()->json([
                "message" => "Bank Details !!",
                'status' => 200,
                "data" => $bankDetails
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Bank not Found !!"], 404);
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }



    public function update(UpdateRequest $request, $id): JsonResponse
    {
        try {
            $bank = $this->repository->update($id, $request->validated());
            return response()->json([
                'message' => 'Bank Updated !!',
                'status' => 200,
                'data' => $bank
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

            $store = $this->repository->create($request->validated());
            return response()->json([
                'message' => 'Bank Created !!',
                'status' => 201,
                'data' => $store
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Bank not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database  error occurred!!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }


    public function show($id): JsonResponse
    {
        try {
            $item = $this->repository->show($id);
            return response()->json([
                'message' => 'Bank Details !!',
                'status' => 200,
                'data' => $item
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $this->repository->delete($id);


            return response()->json([
                'success' => true,
                'message' => 'Bank deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Item not found'], 404);

        } catch (QueryException $e) {

            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the bank.'
            ], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function activeBankList(Request $request): JsonResponse
    {
        try {
            $banks = $this->repository->activeBankList();

            return response()->json([
                "message" => "Bank List !!",
                'status' => 200,
                "data" => $banks
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Bank not Found !!"], 404);
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }

}

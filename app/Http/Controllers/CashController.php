<?php

namespace App\Http\Controllers;

use App\Interfaces\CashRepositoryInterface;

use App\Http\Requests\CashRequest\StoreRequest;
use App\Http\Requests\CashRequest\UpdateRequest;
use App\Models\Cash;
use Illuminate\Database\Eloquent\ModelNotFoundException;

use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;




class CashController extends Controller
{

    protected $repository;

    public function __construct(CashRepositoryInterface $repository)
    {

        $this->repository = $repository;
    }
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only('keywords');

        $cash = $this->repository->list($filters);

        return response()->json($cash, 200);
    }


    public function update(UpdateRequest $request, $id): JsonResponse
    {
        try {

            $validated = $request->validated();

            $cash = $this->repository->update($id, $validated);

            return response()->json($cash, 200);

        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Cash not found!'], 404);
        } catch (QueryException $e) {
             
            return response()->json(['error' => 'An unexpected error occurred!'], 500);
        } catch (\Exception $e) {
            
            return response()->json(['error' => 'An unexpected error occurred!'], 500);
        }
    }

    public function store(StoreRequest $request): JsonResponse
    {
        try {

            $validated = $request->validated();

            $cash = $this->repository->create($validated);

            return response()->json($cash, 201);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Cash not found!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred!'], 500);
        } catch (\Exception $e) {
           
            return response()->json(['error' => 'An unexpected error occurred!'], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $cash = $this->repository->show($id);
            return response()->json($cash);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Cash not found!!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {

            $this->repository->delete($id);
            return response()->json(['message' => 'Cash deleted!!']);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Cash not found!!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function activeCashList(Request $request): JsonResponse
    {
        try {
            $cashes = $this->repository->activeCashList();

            return response()->json([
                "message" => "Active Cash List Received !!",
                "data" => $cashes
            ]);
        } catch (ModelNotFoundException $e) {

            return response()->json(["error" => "Cash not Found !!"], 404);
        } catch (QueryException $e) {

            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }



}

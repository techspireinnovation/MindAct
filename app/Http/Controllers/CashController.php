<?php

namespace App\Http\Controllers;

use App\Interfaces\CashRepositoryInterface;
use App\Http\Resources\CashCollection;
use App\Http\Resources\CashResource;

use App\Http\Requests\CashRequest\ListRequest;
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
    public function index(ListRequest $request)
    {
        try {


            $cash = $this->repository->list($request->validated());

            return response()->json([
                'message' => 'Brand List!',
                'status' => 200,
                'data' => $cash['data'],
                'meta' => $cash['meta'],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }


    public function update(UpdateRequest $request, $id): JsonResponse
    {
        try {
            

            $cash = $this->repository->update($id, $request->validated());

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

           

            $cash = $this->repository->create($request->validated());

            return response()->json($cash, 201);
        } catch (ModelNotFoundException $e) {

            return response()->json(['error' => 'Cash not found!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'An unexpected error occurred!'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred!'], 500);
        }
    }

    public function show($id)
    {
        try {
            $cash = $this->repository->show($id);
            return response()->json([
                'message' => 'Cash Details !',
                'status' => 200,
                'data' => $cash
            ]);
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

    public function activeCashList(Request $request)
    {
        try {
            $cash = $this->repository->activeCashList();

            return response()->json([
                'message' => 'Cash List !',
                'status' => 200,
                'data' => $cash
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

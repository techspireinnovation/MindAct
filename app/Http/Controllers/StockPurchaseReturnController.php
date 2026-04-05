<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

use App\Interfaces\StockPurchaseReturnRepositoryInterface;
use App\Http\Requests\StockPurchaseReturnRequest\StoreRequest;
use App\Http\Requests\StockPurchaseReturnRequest\UpdateRequest;

use Illuminate\Http\Request;

class StockPurchaseReturnController extends Controller
{
    protected $repository;

    public function __construct(StockPurchaseReturnRepositoryInterface $repository)
    {
        $this->repository = $repository;

    }

    public function index(Request $request)
    {

        try {
            $data = $this->repository->list($request->all());
            return response()->json(['message' => 'Stock Purchase Return retrieved successfully !', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Purchase Return not found !!'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock purchase return!!', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock purchase return !!', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(StoreRequest $request)
    {

        try {

            $data = $this->repository->create($request->validated());
            return response()->json(['message' => 'Stock Purchase Return created successfully', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Purchase Return not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock return', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock return', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {

        try {

            $data = $this->repository->show($id);
            return response()->json(['message' => 'Stock Purchase Return retrieved successfully', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Purchase Return not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock return', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock return', 'error' => $e->getMessage()], 500);
        }
    }


    public function update(UpdateRequest $request, $id)
    {

        try {

            $data = $this->repository->update($id, $request->validated());
            return response()->json([
                'success' => true,
                'message' => 'Stock Purchase Return Updated Successfully !!',
                'data' => $data,
                200
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Purchase Return not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock return', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock return', 'error' => $e->getMessage()], 500);
        }
    }



    public function destroy($id)
    {

        try {

            $data = $this->repository->delete($id);
            return response()->json([
                'success' => true,
                'message' => 'Stock Purchase Return Deleted Successfully !!',
                'data' => $data,

            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Purchase Return not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock return', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock return', 'error' => $e->getMessage()], 500);
        }
    }
}

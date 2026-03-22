<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use App\Interfaces\StockRepositoryInterface;
use App\Http\Requests\StockRequest\StoreRequest;
use App\Http\Requests\StockRequest\UpdateRequest;
class StockController extends Controller
{
    protected $repository;

    public function __construct(StockRepositoryInterface $repository)
    {
        $this->repository = $repository;

    }

    public function index(Request $request)
    {

        try {
            $data = $this->repository->list($request->all());
            return response()->json(['message' => 'Stocks retrieved successfully', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock !!', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(StoreRequest $request)
    {

        try {
            $data = $this->repository->create($request->validated());
            return response()->json(['message' => 'Stock created successfully !', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock not found !'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock !', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock !', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $data = $this->repository->show($id);
            return response()->json(['message' => 'Stock retrieved successfully !!', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock !', 'error' => $e->getMessage()], 500);
        }
    }


    public function update(UpdateRequest $request, $id)
    {

        try {

            $data = $this->repository->update($id, $request->validated());
            return response()->json([
                'success' => true,
                'message' => 'Stock Updated Successfully !!',
                'data' => $data,
                200
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $data = $this->repository->delete($id);
            return response()->json(['message' => 'Stock deleted successfully !', 'data' => $data], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock not found !'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock', 'error' => $e->getMessage()], 500);
        }
    }
}

<?php

namespace App\Http\Controllers;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;


use App\Interfaces\StockSalesReturnItemWiseRepositoryInterface;
use App\Http\Requests\StockSalesReturnItemWiseRequest\StoreRequest;
use App\Http\Requests\StockSalesReturnItemWiseRequest\UpdateRequest;

use Illuminate\Http\Request;

class StockSalesReturnItemWiseController extends Controller
{
    protected $repository;

    public function __construct(StockSalesReturnItemWiseRepositoryInterface $repository)
    {
        $this->repository = $repository;

    }

    public function index(Request $request)
    {

        try {

            $data = $request->all();

            $data = $this->repository->list($data);

            return response()->json(['message' => 'Stock Sales Return Item Wise retrieved successfully', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Sales Return Item Wise not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock sales return item wise', 'error' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            dd($e->getMessage());
            return response()->json(['message' => 'An error occurred while listing the stock sales return item wise', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(StoreRequest $request)
    {

        try {

            $data = $this->repository->create($request->validated());
            return response()->json(['message' => 'Stock Sales Return created successfully', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Sales Return not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock sales return', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock sales return', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {

        try {

            $data = $this->repository->show($id);
            return response()->json(['message' => 'Stock Sales Return retrieved successfully', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Sales Return not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock sales return', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock sales return', 'error' => $e->getMessage()], 500);
        }
    }


    public function update(UpdateRequest $request, $id)
    {

        try {

            $data = $this->repository->update($id, $request->validated());
            return response()->json([
                'success' => true,
                'message' => 'Stock Sales Return Updated Successfully !!',
                'data' => $data,
                200
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Sales Return not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock sales return', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock sales return', 'error' => $e->getMessage()], 500);
        }
    }



    public function destroy($id)
    {

        try {

            $data = $this->repository->delete($id);
            return response()->json([
                'success' => true,
                'message' => 'Stock Sales Return Deleted Successfully !',
                'data' => $data,

            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Sales Return not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock sales return', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock sales return', 'error' => $e->getMessage()], 500);
        }
    }
}

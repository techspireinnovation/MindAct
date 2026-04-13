<?php

namespace App\Http\Controllers;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;


use App\Interfaces\StockSaleRepositoryInterface;
use App\Http\Requests\StockSaleRequest\StoreRequest;
use App\Http\Requests\StockSaleRequest\UpdateRequest;

use Illuminate\Http\Request;

class StockSaleController extends Controller
{
    protected $repository;

    public function __construct(StockSaleRepositoryInterface $repository)
    {
        $this->repository = $repository;

    }


    public function index(Request $request)
    {
        try {
            $validated = $request->all();
            $data = $this->repository->list($validated);
            return response()->json(['message' => 'Stock Sales retrieved successfully !!', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred !!'], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'An error occurred while listing the stock sale !!'], 500);
        }
    }

    public function store(StoreRequest $request)
    {

        try {

            $data = $this->repository->create($request->validated());
            return response()->json(['message' => 'Stock Sales created successfully', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            
            return response()->json(['message' => 'Stock Sales not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock sale', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock sale', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {

        try {

            $data = $this->repository->show($id);
            return response()->json(['message' => 'Stock Sales retrieved successfully', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Sales not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while retrieving the stock sales', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while retrieving the stock sales', 'error' => $e->getMessage()], 500);
        }
    }


    public function update(UpdateRequest $request, $id)
    {

    

        try {

            $data = $this->repository->update($id, $request->validated());
            return response()->json([
                'success' => true,
                'message' => 'Stock Sales Updated Successfully !!',
                'data' => $data,
                200
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Sales not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while updating the stock sales', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while updating the stock sales', 'error' => $e->getMessage()], 500);
        }
    }



    public function destroy($id)
    {

        try {

            $data = $this->repository->delete($id);
            return response()->json([
                'success' => true,
                'message' => 'Stock Sales Deleted Successfully !',
                'data' => $data,

            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Sales not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while deleting the stock sales', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while deleting the stock sales', 'error' => $e->getMessage()], 500);
        }
    }

}

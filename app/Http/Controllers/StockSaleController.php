<?php

namespace App\Http\Controllers;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

use App\Interfaces\StockSaleRepositoryInterface;
use App\Http\Requests\StockPurchaseReturnRequest\StoreRequest;
use App\Http\Requests\StockPurchaseReturnRequest\UpdateRequest;

use Illuminate\Http\Request;

class StockSaleController extends Controller
{
    protected $repository;

    public function __construct(StockSaleRepositoryInterface $repository)
    {
        $this->repository = $repository;

    }

    public function store(StoreRequest $request)
    {

        try {

            $data = $this->repository->create($request->validated());
            return response()->json(['message' => 'Stock Sale created successfully', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Sale not found'], 404);
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
            return response()->json(['message' => 'Stock Sale retrieved successfully', 'data' => $data], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Sale not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock sale', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock sale', 'error' => $e->getMessage()], 500);
        }
    }


    public function update(UpdateRequest $request, $id)
    {

        try {

            $data = $this->repository->update($id, $request->validated());
            return response()->json([
                'success' => true,
                'message' => 'Stock Sale Updated Successfully !!',
                'data' => $data,
                200
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Sale not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock sale', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock sale', 'error' => $e->getMessage()], 500);
        }
    }



    public function destroy($id)
    {

        try {

            $data = $this->repository->delete($id);
            return response()->json([
                'success' => true,
                'message' => 'Stock Sale Deleted Successfully .',
                'data' => $data,

            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Sale not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the stock sale', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the stock sale', 'error' => $e->getMessage()], 500);
        }
    }

}

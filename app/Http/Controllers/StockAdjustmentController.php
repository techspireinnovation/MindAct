<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use App\Interfaces\StockAdjustmentRepositoryInterface;
use App\Http\Requests\StockAdjustmentRequest\StoreRequest;
use App\Http\Requests\StockAdjustmentRequest\UpdateRequest;

class StockAdjustmentController extends Controller
{
    protected $repository;

    public function __construct(StockAdjustmentRepositoryInterface $repository)
    {
        $this->repository = $repository;

    }

    public function index(Request $request)
    {

        try {
            $data = $this->repository->list($request->all());
            return response()->json(['message' => 'Stock Adjsutment retrieved successfully !', 'data' => $data], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Adjsutment not found !!'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the Stock Adjsutment', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the Stock Adjsutment!!', 'error' => $e->getMessage()], 500);
        }
    }

    public function store(StoreRequest $request)
    {

        try {
            $data = $this->repository->create($request->validated());
            return response()->json(['message' => 'Stock Adjustment created successfully !', 'data' => $data], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Adjustment not found !!'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the Stock Adjustment!!', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the Stock Adjustment!!', 'error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, $id)
    {
        try {
            $data = $this->repository->show($id);
            return response()->json(['message' => 'Stock Adjustment retrieved successfully !', 'data' => $data], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Adjustment not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the Stock Adjustment', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the Stock Adjustment!', 'error' => $e->getMessage()], 500);
        }
    }


    public function update(UpdateRequest $request, $id)
    {

        try {
            $data = $this->repository->update($id, $request->validated());
            return response()->json([
                'success' => true,
                'message' => 'Stock Adjustment Updated Successfully !!',
                'data' => $data,
                200
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Adjustment not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the Stock Adjustment', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the Stock Adjustment!!', 'error' => $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $data = $this->repository->delete($id);
            return response()->json(['message' => 'Stock Adjustment deleted successfully !', 'data' => $data], 201);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Stock Adjustment not found !'], 404);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An error occurred while creating the Stock Adjustment', 'error' => $e->getMessage()], 500);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database error occurred while creating the Stock Adjustment', 'error' => $e->getMessage()], 500);
        }
    }
}

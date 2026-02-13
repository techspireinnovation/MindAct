<?php

namespace App\Http\Controllers;
use App\Interfaces\FiscalYearRepositoryInterface;

use App\Http\Requests\FiscalYearRequest\ListRequest;
use App\Http\Requests\FiscalYearRequest\DetailRequest;
use App\Http\Requests\FiscalYearRequest\StoreRequest;
use App\Http\Requests\FiscalYearRequest\UpdateRequest;
use App\Http\Requests\FiscalYearRequest\ListAssignFiscalYearRequest;
use App\Http\Requests\FiscalYearRequest\StoreAssignFiscalYearRequest;
use App\Http\Requests\FiscalYearRequest\UpdateAssignFiscalYearRequest;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

use Illuminate\Http\Request;
use PhpParser\Node\Expr\Assign;

class FiscalYearController extends Controller
{
    protected $repository;

    public function __construct(FiscalYearRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function index(ListRequest $request)
    {
        try {


            $fiscalYears = $this->repository->list($request->validated());

            return response()->json([
                'message' => 'Fiscal Year List!',
                'status' => 200,
                'data' => $fiscalYears['data'],
                'meta' => $fiscalYears['meta'],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Fiscal Year Not Found !!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred !'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }



    public function fiscalYearDetails(DetailRequest $request)
    {
        try {

            $fiscalYearDetail = $this->repository->fiscalYearDetails($request->validated());


            return response()->json([
                'message' => 'Fisacl Year Details !',
                'status' => 200,
                'data' => $fiscalYearDetail
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json(["error" => "Fiscal Year Not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {

            return response()->json(["error" => "An unexpected error occurred"], 500);
        }

    }






    public function store(StoreRequest $request): JsonResponse
    {
        try {

            $fiscalYear = $this->repository->create($request->validated());

            return response()->json([
                'success' => 'Created Successfully !!',
                'status' => 201,
                'data' => $fiscalYear
            ], 201);


        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Fiscal Year not found'], 404);
        } catch (QueryException $e) {
            dd($e->getMessage());
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {


            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }


    public function show($id)
    {
        try {
            $fiscalYear = $this->repository->show($id);
            return response()->json([
                'message' => 'Fiscal Year Details !',
                'status' => 200,
                'data' => $fiscalYear
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Fiscal Year not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }


    public function update(UpdateRequest $request, $id): JsonResponse
    {
        try {

            $fiscalYear = $this->repository->update($id, $request->validated());

            return response()->json([
                'success' => 'Fiscal Year Updated Sucessfully !!',
                'status' => 200,
                'data' => $fiscalYear
            ], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Product Category not found!!'], 404);
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
                'message' => 'Fiscal Year deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {

            return response()->json([
                'error' => 'not_found',
                'message' => 'Fiscal Year not found!'
            ], 404);

        } catch (QueryException $e) {

            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the Fiscal Year.'
            ], 500);

        } catch (\Exception $e) {

            if (str_starts_with($e->getMessage(), 'in_use:')) {
                $usedIn = explode(',', str_replace('in_use:', '', $e->getMessage()));
                return response()->json([
                    'error' => 'in_use',
                    'message' => 'Fiscal Year cannot be deleted because it is used in: ' . implode(', ', $usedIn),
                    'used_in' => $usedIn
                ], 400);

            }

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the Fiscal Year.'
            ], 500);


        }
    }


    public function activeFiscalYearList(Request $request)
    {
        try {
            $fiscalYears = $this->repository->activeFiscalYearList();

            return response()->json([
                'message' => 'Fiscal Year List !',
                'status' => 200,
                'data' => $fiscalYears
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(["error" => "Fiscal Year Not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["error" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["error" => "An unexpected error occurred !!"], 500);
        }
    }

    public function createAssignFiscalYear(StoreAssignFiscalYearRequest $request)
    {
        try {
            $data = $this->repository->createAssignFiscalYear($request->validated());
            return response()->json([
                'message' => 'Assigned Fiscal Year to Company !',
                'status' => 200,
                'data' => $data
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Data Not Found !!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occurred !!', 'message' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error', 'message' => $e->getMessage()], 500);
        }

    }

    public function getAssignedFiscalYearCompanyList(ListAssignFiscalYearRequest $request)
    {
        try {
            $data = $this->repository->getAssignFiscalYearList($request->validated());
            return response()->json([
                'message' => 'Assigned Fiscal Year to Companies !',
                'status' => 200,
                'data' => $data
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Data Not Found !!'], 404);
        } catch (QueryException $e) {
            dd($e->getMessage());
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }

    }


    public function getAssignFiscalYearDetails($companyId, Request $request)
    {
        try {
            $data = $this->repository->getAssignFiscalYearDetails($companyId);
            return response()->json([
                'message' => 'Assigned Fiscal Year List !',
                'status' => 200,
                'data' => $data
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Data Not Found !!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }

    }


    public function updateAssignFiscalYear($companyId, UpdateAssignFiscalYearRequest $request)
    {
        try {
            $data = $this->repository->updateAssignFiscalYear($companyId, $request->validated());
            return response()->json([
                'message' => 'Updated Assigned Fiscal Year to Company !',
                'status' => 200,
                'data' => $data
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Data Not Found !!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occurred !!', 'message' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error', 'message' => $e->getMessage()], 500);
        }

    }


    public function deleteFiscalYear($companyId)
    {
        try {
            $this->repository->deleteFiscalYear($companyId);
            return response()->json([
                'success' => true,
                'message' => 'Assigned Fiscal Year deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'not_found',
                'message' => 'Assigned Fiscal Year not found!'
            ], 404);

        } catch (QueryException $e) {
            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the Assigned Fiscal Year.'
            ], 500);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the Assigned Fiscal Year.'
            ], 500);
        }
    }
}

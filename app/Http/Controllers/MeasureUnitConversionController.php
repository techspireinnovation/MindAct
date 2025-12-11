<?php

namespace App\Http\Controllers;

use App\Http\Resources\MeasureUnitConversionCollection;
use App\Interfaces\MeasureUnitConversionRepositoryInterface;

use App\Http\Resources\MeasureUnitConversionResource;
use App\Http\Requests\MeasureUnitConversionRequest\StoreRequest;
use App\Http\Requests\MeasureUnitConversionRequest\UpdateRequest;
use App\Models\MeasureUnitConversion;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class MeasureUnitConversionController extends Controller
{
    /**
     * Display a listing of the resource.
     */


    protected $repository;


    public function __construct(MeasureUnitConversionRepositoryInterface $repository)
    {

        $this->repository = $repository;

    }
    public function index(Request $request)
    {

        try {

            $filters = $request->only('kewords');
            $conversions = $this->repository->list($filters);
            return new MeasureUnitConversionCollection($conversions);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreRequest $request)
    {
        try {

            $data = $request->validated();

            $conversion = $this->repository->create($data);

            return response()->json([
                'success' => 'Conversion created successfully !!',
                'data' => $conversion
            ], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred !!'], 500);

        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {

            $conversion = $this->repository->show($id);

           return new MeasureUnitConversionResource($conversion);


        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateRequest $request, string $id)
    {
        try {

            $data = $request->validated();

            $conversion = $this->repository->update($id, $data);

            return response()->json([
                'success' => 'Conversion retrieved successfully !!',
                'data' => $conversion
            ], 200);


        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */

    public function activeMeasureUnitConversionList()
    {
        try {
            $conversions = $this->repository->activeMeasureUnitConversionList();

            return MeasureUnitConversionResource::collection($conversions)
                ->map(fn($conversion) => [
                    'id' => $conversion->id,
                    'product_id' => $conversion->product_id,
                ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }

    }
    public function destroy(string $id)
    {
        try {
            $conversion = $this->repository->delete($id);

            return response()->json(['success' => 'Conversion Deleted Successfully !!'], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (\Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }
}

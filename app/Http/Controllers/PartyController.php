<?php

namespace App\Http\Controllers;

use App\Interfaces\PartyRepositoryInterface;

use App\Http\Resources\PartyCollection;
use App\Http\Resources\PartyResource;

use App\Http\Requests\PartyRequest\ListRequest;
use App\Http\Requests\PartyRequest\DetailRequest;
use App\Http\Requests\PartyRequest\StoreRequest;
use App\Http\Requests\PartyRequest\UpdateRequest;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;

class PartyController extends Controller
{
    protected $repository;

    public function __construct(PartyRepositoryInterface $repository)
    {

        $this->repository = $repository;

    }

    public function index(ListRequest $request)
    {

        try {

            $items = $this->repository->list($request->validated());


        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => 'Parties not found!'

            ], 404);
        } catch (QueryException $e) {
            return response()->json([
                'error' => 'Database error occurred !'

            ], 500);

        } catch (Exception $e) {

            return response()->json([
                'error' => 'An unexpected error occurred !'
            ], 500);

        }



    }


    public function store(StoreRequest $request)
    {

        try {




            $item = $this->repository->create($request->validated());

            return response()->json([
                'success' => 'Created Successfully !!',
                'data' => $item
            ], 201);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item Not Found !'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occurred !'], 500);
        } catch (Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !'], 500);
        }


    }

    public function update(UpdateRequest $request, $id)
    {
        try {



            $item = $this->repository->update($id, $request->validated());

            return response()->json([
                'success' => 'Party Updated Sucessfully !',
                'data' => $item
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred !'], 500);
        } catch (Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !'], 500);
        }
    }


    public function show($id)
    {
        try {
            $item = $this->repository->show($id);

            return response()->json([
                'message' => 'Party Details Received !!',
                'status' => 200,
                'data' => $item
            ]);


        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item Not Found !'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred !'], 500);
        } catch (Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !'], 500);

        }
    }


    public function activePartyList()
    {
        try {
            $item = $this->repository->activePartyList();

            return response()->json([
                'message' => 'Party List',
                'status' => 200,
                'data' => $item
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item Not Found !'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occurred !'], 500);
        } catch (Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred !'], 500);

        }
    }


    public function partyDetails(Request $request)
    {
        try {

            $partyId = $request->party_id;
            $partyName = $request->party_name;

            $item = $this->repository->partyDetails($partyId, $partyName);

            return new PartyResource($item);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item Not Found !'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred !'], 500);
        } catch (Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred !'], 500);

        }
    }

    public function searchPartyList(Request $request)
    {
        try {

            $partyName = $request->party_name;

            $party = $this->repository->search($partyName);

            return response()->json([
                'success' => 'Party Name Retrieved !',
                'data' => $party
            ], 200);



        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }


    public function destroy($id)
    {
        try {

            $this->repository->delete($id);

            return response()->json(['success' => 'Deleted Successfully !!'], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {

            return response()->json(['error' => 'Database error occurred !!'], 500);
        } catch (Exception $e) {

            return response()->json(['error' => 'An unexpected error occurred !!'], 500);
        }
    }
}

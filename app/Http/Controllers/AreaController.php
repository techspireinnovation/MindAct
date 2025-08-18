<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use App\Models\Area;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AreaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Area::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }


    public function areaList(Request $request): JsonResponse
    {
        try {

            $area = Area::where('company_id', $request->company_id)
                ->where('is_active', 1)->get();

            return response()->json([
                'message' => 'List Received Sucessfully !!',
                'data' => $area
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Item not Found !!'], 404);
        } catch (QueryException $e) {
            return response()->json(['message' => 'Database Error Ocurred!!'], 500);
        } catch (\Exception $e) {
            return response()->json(['message' => 'An unexpected error Ocurred!!'], 500);
        }

    }
    public function getAreaList(Request $request){
        try{

            $accountHeads = Area::where('company_id',$request->company_id)
            ->whereNull('deleted_at')
            ->where('is_active', 1)
            ->get(['id', 'name'])
            ->map(fn($accountHead) => ['id' => $accountHead->id, 'name' => $accountHead->name])
            ->values()
            ->toArray();
            return response()->json(["message"=>"Area List Received !!",
                                       "data"=>$accountHeads
                                    ]);

        }catch(ModelNotFoundException $e){
            \Log::error($e);
            return response()->json(["error"=>"Area not Found !!"],404);
        }catch(QueryException $e){
            \Log::error($e);
            return response()->json(["error"=>"Database error occurred !!"],500);
        }catch(\Exception $e){
            \Log::error($e);
            return response()->json(["error"=>"An unexpected error occurred !!"],500);
        }
    }
    public function getAreaDetails(Request $request){
        try{

           $companyId  = $request->company_id;
           if(!$companyId){
            return response()->json(["error"=>"No Company Logged In !!"],404);
           }

           $accountHead = $request->account_head_name;
           $accountHeadDetails = Area::where('company_id',$request->company_id)
                                         ->where('name',$accountHead)
                                       ->whereNull('deleted_at')
                                       ->firstorFail();   
           return response()->json(["message"=>"Area Details Received !!",
                                    "data"=>$accountHeadDetails
                                ],200);


        }catch(ModelNotFoundException $e){
            return response()->json(["error"=>"Area not Found !!"],404);
        }catch(QueryException $e){
            return response()->json(["error"=>"Database error occurred !!"],500);
        }catch(\Exception $e){
            return response()->json(["error"=>"An unexpected error occurred !!"],500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $area = Area::findOrFail($id);
            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('areas')
                        ->ignore($id)
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');

                        }),
                ],

                'company_id' => 'integer|exists:companies,id',
                'is_primary' => 'boolean',
                'is_active' => 'boolean|nullable',
            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }
            $validated = $validator->validated();

            if (isset($validated['is_primary']) && $validated['is_primary'] == true) {
                Area::where('company_id', $area->company_id)
                    ->where('id', '!=', $id)

                    ->update(['is_primary' => false]);
            }

            $area->update($validated);
            return response()->json($area);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Area not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {

            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('areas')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                            ->whereNull('deleted_at');

                    }),

                ],

                'company_id' => 'integer|exists:companies,id',
                'is_primary' => 'boolean',
                'is_active' => 'boolean|nullable',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }


            $validated = $validator->validated();

            if (!empty($validated['is_primary']) && $validated['is_primary'] == 1) {
                Area::where('company_id', $validated['company_id'])
                    ->update(['is_primary' => 0]);
            }

            $area = Area::create($validated);
            return response()->json($area, 201);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Area not found!!'], 404);
        } catch (QueryException $e) {
            dd($e->getMessage());
            \Log::error($e);
            return response()->json(['error' => 'Database  error occurred!!'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $area = Area::findOrFail($id);
            return response()->json($area);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Area not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {

            $area = Area::findOrFail($id);
            $area->delete();
            return response()->json(['message' => 'Area deleted!!']);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Area not found!!'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}

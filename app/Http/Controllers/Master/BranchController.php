<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{


    public function index(Request $request): JsonResponse
    {
        $query = Branch::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(50));
    }

 public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required',
            'string',
            'max:255',
            Rule::unique('branches')->where(function ($query) use ($request){
                return $query->where('company_id',$request->company_id)
                ->whereNull('deleted_at');

            }),
        ],
            'is_primary' => 'boolean',
           
            'is_active' => 'boolean|required',
            'company_id' => 'integer|exists:companies,id'
        ]);
        if (!empty($validated['is_primary'])) {
            Branch::where('company_id', $validated['company_id'])
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
        }
            
        $validated['is_primary'] = $validated['is_primary'] ?? false;

        $item = Branch::create($validated);
        return response()->json($item, 201);
    }

    public function branchList(Request $request){
        try{

            $branches = Branch::where('company_id',$request->company_id)
                                        ->whereNull('deleted_at')
                                        ->where('is_active', 1)
                                        ->get(['id', 'name'])
                                        ->map(fn($branch) => ['id' => $branch->id, 'name' => $branch->name])
                                        ->values()
                                        ->toArray();
            return response()->json(["message"=>"Branch List Received !!",
                                       "data"=>$branches
                                    ]);

        }catch(ModelNotFoundException $e){
            \Log::error($e);
            return response()->json(["error"=>"Branch not Found !!"],404);
        }catch(QueryException $e){
            \Log::error($e);
            return response()->json(["error"=>"Database error occurred !!"],500);
        }catch(\Exception $e){
            \Log::error($e);
            return response()->json(["error"=>"An unexpected error occurred !!"],500);
        }
    }


    public function branchDetails(Request $request){
        try{

           $companyId  = $request->company_id;
           if(!$companyId){
            return response()->json(["error"=>"No Company Logged In !!"],404);
           }

           $branch = $request->branch_name;
           $branchDetails = Branch::where('company_id',$request->company_id)
                                         ->where('name',$branch)
                                       ->whereNull('deleted_at')
                                       ->firstorFail();   
           return response()->json(["message"=>"Branch Details Received !!",
                                    "data"=>$branchDetails
                                ],200);


        }catch(ModelNotFoundException $e){
            return response()->json(["error"=>"Not Item Found !!"],404);
        }catch(QueryException $e){
            return response()->json(["error"=>"Database error occurred !!"],500);
        }catch(\Exception $e){
            return response()->json(["error"=>"An unexpected error occurred !!"],500);
        }
    } 

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $item = Branch::findOrFail($id);
            $validated = $request->validate([
                'name' => ['required',
                            'string',
                            'max:255',
                            Rule::unique('branches')
                                ->ignore($id)
                                ->where(function ($query) use ($request, $item){
                                    return $query->where('company_id',$request->input('company_id',$item->company_id))
                                    ->whereNull('deleted_at');

                                }),
                        ],
                'is_primary' => 'sometimes|boolean',
                'is_active' => 'boolean|required',
                'company_id' => 'integer|exists:companies,id'
            ]);
            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
            Branch::where('company_id', $item->company_id)
                ->where('id', '!=', $id) 
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }
        if ($request->has('is_primary')) {
            $validated['is_primary'] = (bool) $request->input('is_primary');
        }
            $item->update($validated);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

   

    public function show($id): JsonResponse
    {
        try {
            $item = Branch::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = Branch::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Branch deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }
}

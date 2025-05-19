<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use App\Models\AccountHead;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class AccountHeadController extends Controller
{

    public function index(Request $request): JsonResponse
    {
        $query = AccountHead::query();
    
        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }
    
        return response()->json($query->paginate(50));
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $account_head = AccountHead::findOrFail($id);
            $validator = Validator::make($request->all(),[
                'name' => ['required',
                            'string',
                            'max:255',
                        Rule::unique('account_heads')
                        ->ignore($id)
                        ->where(function ($query) use ($request, $account_head){
                            return $query->where('company_id',$request->input('company_id',$account_head->company_id))
                            ->whereNull('deleted_at');

                        }),
                    ],
                'is_active' => 'boolean|required',
                'is_primary' =>'boolean',
                'company_id' => 'integer|exists:companies,id',
                'account_group_id' => 'integer|exists:account_groups,id',
                'code' => 'string|max:255',

            ]);
            if($validator->fails()){
                return response()->json($validator->errors(),422);
            }

            $validated = $validator->validated();

            if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
                AccountHead::where('company_id', $account_head->company_id)
                    ->where('id', '!=', $id) 
                    ->where('is_primary', true)
                    ->update(['is_primary' => false]);
            }
    
            
            if ($request->has('is_primary')) {
                $validated['is_primary'] = (bool) $request->input('is_primary');
            }
    
            $account_head->update($validated);
            return response()->json($account_head);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Account Head not found!!'], 404);
        } catch (QueryException $e) {
            
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }catch(\Exception $e){
            
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);

        }
    }

    public function store(Request $request): JsonResponse
    {
        try{
        $validator = Validator::make($request->all(),[
            'name' => ['required',
                       'string',
                       'max:255',
                       Rule::unique('account_heads')->where(function ($query) use ($request){
                        return $query->where('company_id',$request->company_id)
                        ->whereNull('deleted_at');

                       }),

                   ],
            'is_active' => 'boolean|required',
            'is_primary' =>'boolean',
            'company_id' => 'integer|exists:companies,id',
            'account_group_id' => 'integer|exists:account_groups,id',
            'code' => 'string|max:255'
        ]);

        if($validator->fails()){
            return response()->json($validator->errors(),422);
        }

        $validated = $validator->validated();

        if (!empty($validated['is_primary'])) {
            AccountHead::where('company_id', $validated['company_id'])
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
        }
            
        $validated['is_primary'] = $validated['is_primary'] ?? false;
       

        $account_head = AccountHead::create($validated);
        return response()->json($account_head, 201);
    }catch (ModelNotFoundException $e) {
        return response()->json(['error' => 'Account Head  not found!!'], 404);
    } catch (QueryException $e) {
        return response()->json(['error' => 'An unexpected error occurred!!'], 500);
    }catch(\Exception $e){
        return response()->json(['error' => 'An unexpected error occurred!!'], 500);
    }
}

    public function show($id): JsonResponse
    {
        try {
            $account_head = AccountHead::findOrFail($id);
            return response()->json($account_head);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Account Head not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $account_head = AccountHead::findOrFail($id);
            $account_head->delete();
            return response()->json(['message' => 'Account Head deleted!!']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Account Head not found!!'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred!!'], 500);
        }
    }
}

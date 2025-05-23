<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Response;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function companyCount(){

        try{

            $company = Company::count();

            return response()->json([
            'company_count' => $company
        ]);
        }catch(QueryException $e){
            return response()->json([
                'message' => 'Error fetching company count',
                'error' => $e->getMessage()
            ], 500);
        }catch(ModelNotFoundException $e){
            return response()->json([
                'message' => 'Company not found',
                'error' => $e->getMessage()
            ], 404);
        }catch(Exception $e){   
            return response()->json([
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

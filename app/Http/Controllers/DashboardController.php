<?php

namespace App\Http\Controllers;

use App\Models\Company;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Log;

class DashboardController extends Controller
{
    public function dashboardStat()
    {
        try {
            $company = Company::count();
            return response()->json([
                'company_count' => $company
            ]);
        } catch (QueryException $e) {
            return response()->json([
                'message' => 'Error fetching company count',
                'error' => $e->getMessage()
            ], 500);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Company not found',
                'error' => $e->getMessage()
            ], 404);
        } catch (Exception $e) {
            Log::error('dashboard exception ' . $e->getMessage());
            return response()->json([
                'message' => 'An unexpected error occurred',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}

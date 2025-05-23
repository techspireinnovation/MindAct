<?php

namespace App\Http\Controllers;

use App\Models\FixedAssetAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = FixedAssetAccount::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }
}

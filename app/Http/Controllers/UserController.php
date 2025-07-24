<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            // Log request for debugging
            Log::info('UserController::store Request', [
                'headers' => $request->headers->all(),
                'payload' => $request->all(),
                'company_id' => $request->company_id,
            ]);

            // Validate request
            $validator = Validator::make($request->all(), [
                'name' => ['required', 'string', 'max:255'],
                'email' => [
                    'required',
                    'email',
                    'max:255',
                    Rule::unique('users')->whereNull('deleted_at'),
                ],
                'password' => ['required', 'string', 'min:8'],
                'branch_ids' => [
                    'required',
                    'array',
                    Rule::exists('branches', 'id')->where(function ($query) use ($request) {
                        return $query->where('company_id', $request->company_id)
                                    ->whereNull('deleted_at');
                    }),
                ],
                'role' => ['required', 'string', 'in:company_user'], // Restrict to specific roles
            ]);

            if ($validator->fails()) {
                Log::error('UserController::store Validation Failed', $validator->errors()->toArray());
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors(),
                ], 422);
            }

            // Create user
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'company_id' => $request->company_id, // Optional, if users are tied to a single company
            ]);

            // Assign company_admin role (if using Spatie Laravel Permission)
            $user->assignRole('company_user', 'api');

            // Assign branches
            $user->branches()->sync($request->branch_ids);

            // Log success
            Log::info('UserController::store User Created', [
                'user_id' => $user->id,
                'email' => $user->email,
                'branch_ids' => $request->branch_ids,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'User created successfully.',
                'data' => [
                    'user' => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'branches' => $user->branches->pluck('name'),
                    ],
                ],
            ], 201);

        } catch (\Exception $e) {
            Log::error('UserController::store Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred.',
            ], 500);
        }
    }
}
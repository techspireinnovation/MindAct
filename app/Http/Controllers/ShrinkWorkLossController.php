<?php

namespace App\Http\Controllers;

use App\Models\ShrinkWorkLoss;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Exception;

class ShrinkWorkLossController extends Controller
{
    /**
     * Display the specified resource by company_id and branch_id.
     */
    public function show(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'branch_id'  => 'required|exists:branches,id',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $loss = ShrinkWorkLoss::withoutGlobalScopes()
                ->where('company_id', $request->company_id)
                ->where('branch_id', $request->branch_id)
                ->first();

            if (!$loss) {
                // Return default values instead of 404
                return response()->json([
                    'data' => [
                        'company_id' => $request->company_id,
                        'branch_id' => $request->branch_id,
                        'shrinking_loss_percent' => 0,
                        'working_loss_percent' => 0,
                        'internal_loss_percent' => 0,
                        'created_at' => null,
                        'updated_at' => null,
                    ]
                ], 200);
            }

            return response()->json(['data' => $loss], 200);

        } catch (Exception $e) {
            Log::error('Error in ShrinkWorkLossController@show', [
                'message'    => $e->getMessage(),
                'company_id' => $request->company_id,
                'branch_id'  => $request->branch_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving the record.',
            ], 500);
        }
    }

    /**
     * Update or create the specified resource in storage.
     */
    public function update(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id'              => 'required|exists:companies,id',
                'branch_id'               => 'required|exists:branches,id',
                'shrinking_loss_percent'  => 'nullable|numeric|min:0',
                'working_loss_percent'    => 'nullable|numeric|min:0',
                'internal_loss_percent'   => 'nullable|numeric|min:0',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $data = $request->only([
                'company_id',
                'branch_id',
                'shrinking_loss_percent',
                'working_loss_percent',
                'internal_loss_percent',
            ]);

            $loss = ShrinkWorkLoss::withoutGlobalScopes()
                ->updateOrCreate(
                    [
                        'company_id' => $data['company_id'],
                        'branch_id'  => $data['branch_id'],
                    ],
                    [
                        'shrinking_loss_percent' => $data['shrinking_loss_percent'] ?? 0,
                        'working_loss_percent'   => $data['working_loss_percent']   ?? 0,
                        'internal_loss_percent'  => $data['internal_loss_percent']  ?? 0,
                    ]
                );

            return response()->json(['data' => $loss], 200);

        } catch (Exception $e) {
            Log::error('Error in ShrinkWorkLossController@update', [
                'message'    => $e->getMessage(),
                'company_id' => $request->company_id,
                'branch_id'  => $request->branch_id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while updating or creating the record.',
            ], 500);
        }
    }
}
<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Validator;

class BankController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Bank::query();

        if ($request->has('keywords')) {
            $query->where('name', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }

        public function bankList(Request $request): JsonResponse
        {
            try {
                $banks = Bank::where('company_id', $request->company_id)
                    ->whereNull('deleted_at')
                    ->where('is_active', 1)
                    ->get(['id', 'name'])
                    ->map(fn($bank) => ['id' => $bank->id, 'name' => $bank->name])
                    ->values()
                    ->toArray();

                return response()->json([
                    "message" => "Bank List Received !!",
                    "data" => $banks
                ]);
            } catch (ModelNotFoundException $e) {
                \Log::error($e);
                return response()->json(["error" => "Bank not Found !!"], 404);
            } catch (QueryException $e) {
                \Log::error($e);
                return response()->json(["error" => "Database error occurred !!"], 500);
            } catch (\Exception $e) {
                \Log::error($e);
                return response()->json(["error" => "An unexpected error occurred !!"], 500);
            }
        }

        public function bankDetails(Request $request): JsonResponse
        {
            try {
                $companyId = $request->company_id;
                if (!$companyId) {
                    return response()->json(["error" => "No Company Logged In !!"], 404);
                }

                $bankName = $request->bank_name;
                $bankDetails = Bank::where('company_id', $request->company_id)
                    ->where('name', $bankName)
                    ->whereNull('deleted_at')
                    ->firstOrFail();

                return response()->json([
                    "message" => "Bank Details Received !!",
                    "data" => $bankDetails
                ], 200);
            } catch (ModelNotFoundException $e) {
                return response()->json(["error" => "Bank not Found !!"], 404);
            } catch (QueryException $e) {
                \Log::error($e);
                return response()->json(["error" => "Database error occurred !!"], 500);
            } catch (\Exception $e) {
                \Log::error($e);
                return response()->json(["error" => "An unexpected error occurred !!"], 500);
            }
        }

    // public function update(Request $request, $id): JsonResponse
    // {
    //     try {
    //         $item = Bank::findOrFail($id);


    //         $validator = Validator::make($request->all(), [
    //             'name' => [
    //                 'required',
    //                 'string',
    //                 'max:255',
    //                 Rule::unique('banks')
    //                     ->ignore($id)
    //                     ->where(function ($query) use ($request, $item) {
    //                         return $query->where('company_id', $request->input('company_id', $request->company_id))
    //                             ->whereNull('deleted_at');
    //                     }),
    //             ],
    //             'is_active' => 'boolean|required',
    //             'is_primary' => 'boolean',
    //             'address' => 'nullable|string|max:255',
    //             'class' => 'nullable|string|max:255',
    //             'number' => 'nullable|string|max:255',
    //             'swift' => 'nullable|string|max:255',
    //             'company_id' => 'required|integer|exists:companies,id'
    //         ]);


    //         if ($validator->fails()) {
    //             return response()->json($validator->errors(), 422);
    //         }


    //         $validated = $validator->validated();

    //         // Handle is_primary logic: Set other banks' is_primary to false if this one is true
    //         // Handle is_primary logic: Set other banks' is_primary to false if this one is true
    //         if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
    //             Bank::where('company_id', $item->company_id)
    //                 ->where('id', '!=', $id)
    //                 ->where('is_primary', true)
    //                 ->update(['is_primary' => false]);
    //         }

    //         // Explicitly set nullable fields to null if not provided in the request
    //         $updateData = [
    //             'name' => $validated['name'],
    //             'is_active' => (bool) $validated['is_active'],
    //             'is_primary' => isset($validated['is_primary']) ? (bool) $validated['is_primary'] : $item->is_primary,
    //             'company_id' => $validated['company_id'],
    //             'address' => $request->has('address') ? ($validated['address'] ?? null) : null,
    //             'class' => $request->has('class') ? ($validated['class'] ?? null) : null,
    //             'number' => $request->has('number') ? ($validated['number'] ?? null) : null,
    //             'swift' => $request->has('swift') ? ($validated['swift'] ?? null) : null,
    //         ];

    //         $item->update($updateData);
    //         // Explicitly set nullable fields to null if not provided in the request
    //         $updateData = [
    //             'name' => $validated['name'],
    //             'is_active' => (bool) $validated['is_active'],
    //             'is_primary' => isset($validated['is_primary']) ? (bool) $validated['is_primary'] : $item->is_primary,
    //             'company_id' => $validated['company_id'],
    //             'address' => $request->has('address') ? ($validated['address'] ?? null) : null,
    //             'class' => $request->has('class') ? ($validated['class'] ?? null) : null,
    //             'number' => $request->has('number') ? ($validated['number'] ?? null) : null,
    //             'swift' => $request->has('swift') ? ($validated['swift'] ?? null) : null,
    //         ];

    //         $item->update($updateData);
    //         $item->refresh();

    //         return response()->json($item);
    //     } catch (ModelNotFoundException $e) {
    //         \Log::error($e);
    //         return response()->json(['error' => 'Item not found'], 404);
    //     } catch (QueryException $e) {
    //         \Log::error($e);
    //         return response()->json(['error' => 'An unexpected error occurred'], 500);
    //     } catch (\Exception $e) {
    //         \Log::error($e);
    //         return response()->json(['error' => 'An unexpected error occurred'], 500);
    //     }
    // }


    public function update(Request $request, $id): JsonResponse
{
    try {
        $item = Bank::findOrFail($id);

        $messages = [
            'swift.unique' => 'Swift code already taken.',
        ];

        $validator = Validator::make($request->all(), [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('banks')
                    ->ignore($id)
                    ->where(function ($query) use ($request, $item) {
                        return $query->where('company_id', $request->input('company_id', $request->company_id))
                                     ->whereNull('deleted_at');
                    }),
            ],
            'is_active' => 'boolean|required',
            'is_primary' => 'boolean',
            'address' => 'nullable|string|max:255',
            'class' => 'nullable|string|max:255',
            'number' => 'nullable|string|max:255',
            'swift' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('banks')
                    ->ignore($id)
                    ->where(function ($query) use ($request, $item) {
                        return $query->where('company_id', $request->input('company_id', $request->company_id))
                                     ->whereNull('deleted_at');
                    }),
            ],
            'company_id' => 'required|integer'
        ], $messages);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $validated = $validator->validated();

        // Handle is_primary logic: reset other banks if this one is set to primary
        if (isset($validated['is_primary']) && $validated['is_primary'] === true) {
            Bank::where('company_id', $item->company_id)
                ->where('id', '!=', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        // Update data
        $updateData = [
            'name' => $validated['name'],
            'is_active' => (bool) $validated['is_active'],
            'is_primary' => isset($validated['is_primary']) ? (bool) $validated['is_primary'] : $item->is_primary,
            'company_id' => $validated['company_id'],
            'address' => $request->has('address') ? ($validated['address'] ?? null) : null,
            'class' => $request->has('class') ? ($validated['class'] ?? null) : null,
            'number' => $request->has('number') ? ($validated['number'] ?? null) : null,
            'swift' => $request->has('swift') ? ($validated['swift'] ?? null) : null,
        ];

        $item->update($updateData);
        $item->refresh();

        return response()->json($item);
    } catch (ModelNotFoundException $e) {
        \Log::error($e);
        return response()->json(['error' => 'Item not found'], 404);
    } catch (QueryException $e) {
        \Log::error($e);
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    } catch (\Exception $e) {
        \Log::error($e);
        return response()->json(['error' => 'An unexpected error occurred'], 500);
    }
}


    // public function store(Request $request): JsonResponse
    // {
    //     $validated = $request->validate([
    //         'name' => [
    //             'required',
    //             'string',
    //             'max:255',
    //             Rule::unique('banks')->where(function ($query) use ($request) {
    //                 return $query->where('company_id', $request->input('company_id', $request->company_id))
    //                     ->whereNull('deleted_at');

    //             }),
    //         ],
    //         'is_active' => 'boolean|required',
    //         'is_primary' => 'boolean',
    //         'address' => 'nullable|string|max:255',
    //         'class' => 'nullable|string|max:255',
    //         'number' => 'nullable|string|max:255',
    //         'swift' => 'nullable|string|max:255',

    //         'company_id' => 'required|integer|exists:companies,id'
    //     ]);

    //     if (!empty($validated['is_primary']) && $validated['is_primary'] == 1) {
    //         Bank::where('company_id', $validated['company_id'])
    //             ->update(['is_primary' => 0]);
    //     }

    //     $validated['is_primary'] = $validated['is_primary'] ?? false;
    //     $validated['is_active'] = $validated['is_active'] ?? true;

    //     $item = Bank::create($validated);
    //     return response()->json($item, 201);
    // }


    public function store(Request $request): JsonResponse
{
    $messages = [
        'swift.unique' => 'Swift code already taken.',
    ];

    $validated = $request->validate([
        'name' => [
            'required',
            'string',
            'max:255',
            Rule::unique('banks')->where(function ($query) use ($request) {
                return $query->where('company_id', $request->input('company_id', $request->company_id))
                             ->whereNull('deleted_at');
            }),
        ],
        'is_active' => 'boolean|required',
        'is_primary' => 'boolean',
        'address' => 'nullable|string|max:255',
        'class' => 'nullable|string|max:255',
        'number' => 'nullable|string|max:255',
        'swift' => [
            'nullable',
            'string',
            'max:255',
            Rule::unique('banks')->where(function ($query) use ($request) {
                return $query->where('company_id', $request->input('company_id'))
                             ->whereNull('deleted_at');
            }),
        ],
        'company_id' => 'required'
    ], $messages);

    // If is_primary = 1, reset others
    if (!empty($validated['is_primary']) && $validated['is_primary'] == 1) {
        Bank::where('company_id', $validated['company_id'])
            ->update(['is_primary' => 0]);
    }

    $validated['is_primary'] = $validated['is_primary'] ?? false;
    $validated['is_active'] = $validated['is_active'] ?? true;

    $item = Bank::create($validated);

    return response()->json($item, 201);
}


    public function show($id): JsonResponse
    {
        try {
            $item = Bank::findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $bank = Bank::findOrFail($id);

            $usedIn = [];

            if ($bank->purchases()->exists()) {
                $usedIn[] = 'purchases';
            }
            if ($bank->sales()->exists()) {
                $usedIn[] = 'sales';
            }
            if ($bank->bankVouchers()->exists()) {
                $usedIn[] = 'bank vouchers';
            }
            if ($bank->customers()->exists()) {
                $usedIn[] = 'customers';
            }

            if (!empty($usedIn)) {
                return response()->json([
                    'error' => 'cannot delete in use',
                    'message' => 'Bank cannot be deleted because it is used in: ' . implode(', ', $usedIn)
                ], 400);
            }

            $bank->delete();

            return response()->json(['message' => 'Bank deleted']);

        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Item not found'], 404);

        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the bank.'
            ], 500);
        }
    }

    public function activeBanks(Request $request): JsonResponse
{
    try {
        $banks = Bank::where('company_id', $request->company_id)
            ->where('is_active', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'name']);

        if ($banks->isEmpty()) {
            return response()->json([
                "message" => "No active banks found",
                "data" => []
            ], 200);
        }

        return response()->json([
            "message" => "Active banks retrieved successfully!",
            "data" => $banks
        ], 200);

    } catch (\Exception $e) {
        \Log::error($e);
        return response()->json(["error" => "An unexpected error occurred !!"], 500);
    }
}

}

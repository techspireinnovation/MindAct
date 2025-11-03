<?php

namespace App\Http\Controllers;

use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

use App\Models\MeterReading;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;

class MeterReadingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MeterReading::where('company_id', $request->company_id);


        return response()->json([
            'message' => 'Data Retrived Successfully!!',
            'data' => $query->paginate(50)
        ]);

    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'nozzle_id' => 'nullable|numeric|max:255',
                'type_of_fuel' => 'nullable|string|max:255',
                'opening_reading' => 'nullable|string|max:255',
                'closing_reading' => 'nullable|string|max:255',
                'sale_litres' => 'nullable|string|max:255',
                'due_sale_litre' => 'nullable|string|max:255',
                'is_active' => 'boolean|nullable'

            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'error' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();

            $validated['company_id'] = $request->company_id;


            $MeterReading = MeterReading::create($validated);
            return response()->json([
                'message' => 'Data created Successfully!!',
                'data' => $MeterReading
            ]);


        } catch (ModelNotFoundException $e) {
            \Log::error('Item Not Found', ['Item not Found' => $e]);
            return response()->json(['error' => 'Item Not Found'], 404);
        } catch (QueryException $e) {

            \Log::error('Database error occured', ['Query Exception' => $e]);
            return response()->json(['error' => 'Database error occured'], 500);
        } catch (\Exception $e) {
          
            \Log::error('Unexpected error', ['exception' => $e]);
            return response()->json(['error' => 'An unexpected error occured'], 500);


        }

    }



    public function update(Request $request, $id): JsonResponse
    {
        try {
            $MeterReading = MeterReading::find($id);
            $validator = Validator::make($request->all(), [
                'nozzle_id' => 'nullable|numeric|max:255',
                'type_of_fuel' => 'nullable|string|max:255',
                'opening_reading' => 'nullable|string|max:255',
                'closing_reading' => 'nullable|string|max:255',
                'sale_litres' => 'nullable|string|max:255',
                'due_sale_litre' => 'nullable|string|max:255',
                'is_active' => 'boolean|nullable'


            ]);
            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors(),

                ]);

            }
            $validated = $validator->validated();

            $validated['company_id'] = $request->company_id;

            $MeterReading->update($validated);

            return response()->json([
                'message' => 'Data updated Successfully!!',
                'data' => $MeterReading
            ]);


        } catch (ModelNotFoundException $e) {
            \Log::error('Item Not Found', ['Item not Found' => $e]);
            return response()->json(['error' => 'Item Not Found'], 404);
        } catch (QueryException $e) {
            \Log::error('Database error occured', ['Query Exception' => $e]);
            return response()->json(['error' => 'Database error occured'], 500);
        } catch (\Exception $e) {

            \Log::error('Unexpected error', ['exception' => $e]);
            return response()->json(['error' => 'An unexpected error occured'], 500);


        }

    }


    public function show(Request $request, $id): JsonResponse
    {
        try {
            $MeterReading = MeterReading::where('id', $id)
                ->where('company_id', $request->company_id)
                ->whereNull('deleted_at')
                ->first();
            if (!$MeterReading) {
                return response()->json(['error' => 'Item Not Found'], 404);
            }
            return response()->json([
                'message' => 'MeterReading Retrieved successfully!',
                'data' => $MeterReading
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item Not Found', 'exception' => $e->getMessage()], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred', 'exception' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred', 'exception' => $e->getMessage()], 500);
        }


    }


    public function destroy($id)
    {
        try {
            $MeterReading = MeterReading::find($id);

            if (!$MeterReading) {
                return response()->json(['error' => 'Item Not Found'], 404);
            }

            $MeterReading->delete();

            return response()->json([
                'message' => 'Work shift deleted successfully!'
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item Not Found', 'exception' => $e->getMessage()], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error occurred', 'exception' => $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An unexpected error occurred', 'exception' => $e->getMessage()], 500);
        }
    }

    // public function getLastClosingReading(Request $request): JsonResponse
// {
//     try {
//         $request->validate([
//             'nozzle_id' => 'required|numeric',
//         ]);

    //         // Get the last closing reading for the given nozzle_id
//         $lastClosingReading = MeterReading::where('nozzle_id', $request->nozzle_id)
//             ->orderByDesc('id')
//             ->value('closing_reading');

    //         if (is_null($lastClosingReading)) {
//             return response()->json([
//                 'message' => 'No closing reading found for this nozzle',
//                 'closing_reading' => null
//             ], 404);
//         }

    //         return response()->json([
//             'message' => 'Last closing reading retrieved successfully!',
//             'closing_reading' => $lastClosingReading
//         ]);

    //     } catch (\Exception $e) {
//         \Log::error('Error fetching last closing reading', ['error' => $e->getMessage()]);
//         return response()->json(['error' => 'An unexpected error occurred'], 500);
//     }
// }

    public function getLastClosingReading(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'nozzle_id' => 'required|numeric',
            ]);

            // Get the last closing reading for the given nozzle_id
            $lastClosingReading = MeterReading::where('nozzle_id', $request->query('nozzle_id'))
                ->orderByDesc('id')
                ->value('closing_reading');

            if (is_null($lastClosingReading)) {
                return response()->json([
                    'message' => 'No closing reading found for this nozzle',
                    'closing_reading' => null
                ], 404);
            }

            return response()->json([
                'message' => 'Last closing reading retrieved successfully!',
                'closing_reading' => $lastClosingReading
            ]);

        } catch (\Exception $e) {
            \Log::error('Error fetching last closing reading', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }



}

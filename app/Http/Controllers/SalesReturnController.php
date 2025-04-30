<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\SalesReturn;
use App\Models\SalesReturnProduct;
use DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesReturnController extends Controller
{
    private function generateUniqueInvoiceNumber(string $fiscalYear): string
    {
        $lastInvoice = SalesReturn::where('invoice_number', 'like', "INVSR-$fiscalYear-%")
            ->orderBy('id', 'desc')
            ->first();

        $lastNumber = 0;
        if ($lastInvoice) {
            $lastParts = explode('-', $lastInvoice->invoice_number);
            $lastNumber = isset($lastParts[3]) ? (int)$lastParts[3] : 0;
        }

        $newNumber = $lastNumber + 1;
        $formattedNumber = str_pad($newNumber, 4, '0', STR_PAD_LEFT);
        $invoiceNumber = "INVSR-$fiscalYear-$formattedNumber";

        while (SalesReturn::where('invoice_number', $invoiceNumber)->exists()) {
            $newNumber++;
            $formattedNumber = str_pad($newNumber, 4, '0', STR_PAD_LEFT);
            $invoiceNumber = "INVSR-$fiscalYear-$formattedNumber";
        }

        return $invoiceNumber;
    }

    private function generateUniqueBatchNumber(string $fiscalYear): string
    {
        $lastBatch = SalesReturn::where('batch_no', 'like', "BATCHSR-$fiscalYear-%")
            ->orderBy('id', 'desc')
            ->first();

        $lastNumber = 0;
        if ($lastBatch) {
            $lastParts = explode('-', $lastBatch->batch_no);
            $lastNumber = isset($lastParts[3]) ? (int)$lastParts[3] : 0;
        }

        $newNumber = $lastNumber + 1;
        $formattedNumber = str_pad($newNumber, 4, '0', STR_PAD_LEFT);
        $batchNumber = "BATCHSR-$fiscalYear-$formattedNumber";

        while (SalesReturn::where('batch_no', $batchNumber)->exists()) {
            $newNumber++;
            $formattedNumber = str_pad($newNumber, 4, '0', STR_PAD_LEFT);
            $batchNumber = "BATCHSR-$fiscalYear-$formattedNumber";
        }

        return $batchNumber;
    }

    public function index(Request $request): JsonResponse
    {
        $query = SalesReturn::query();

        if ($request->has('keywords')) {
            $query->where('invoice_number', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }


    public function store(Request $request): JsonResponse
    {

    try{
        $validator = Validator::make($request->all(),[
            'company_id' => 'required|exists:companies,id',
            'customer_id' => 'required|exists:customers,id',
            'salesman_id' => 'required|integer',
            'invoice_number' => 'nullable|string|max:255|unique:sales_returns,invoice_number',
            'document_number' => 'nullable|string|max:255',
            'batch_no' => 'nullable|string|max:255|unique:sales_returns,batch_no',
            'balance' => 'nullable|string|max:255',
            'invoice_date' => 'nullable|date',
            'remarks' => 'nullable|string|max:255',
            'store_id' => 'required|exists:stores,id',
            'location_id' => 'required|exists:locations,id',
            'discount_amount' => 'nullable|numeric',
            'excise_duty' => 'nullable|numeric',
            'health_insurance' => 'nullable|numeric',
            'freight_amount' => 'nullable|numeric',
            'discount_vat' => 'nullable|numeric',
            'discount_after_vat' => 'nullable|numeric',
            'paid_amount' => 'nullable|numeric',
            'round_of_amount' => 'nullable|numeric',
            'payment_type' => 'required|in:cash,bank,credit',
            'sales_return_products' => 'nullable|array',
            'sales_return_products.*.company_id' => 'required|exists:companies,id',
            'sales_return_products.*.product_id' => 'required|exists:products,id',
            'sales_return_products.*.expiry_date' => 'nullable|date',
            'sales_return_products.*.quantity' => 'nullable|numeric',
            'sales_return_products.*.free_quantity' => 'nullable|numeric',
            'sales_return_products.*.price' => 'nullable|numeric',
            'sales_return_products.*.discount_percent' => 'nullable|numeric',
            'sales_return_products.*.discount_amount' => 'nullable|numeric',
            'sales_return_products.*.is_vatable' => 'nullable|boolean',
            'sales_return_products.*.measure_unit_id' => 'required|exists:measure_units,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $validated = $validator->validated();
        

            // Calculate fiscal year
            $date = $request->invoice_date ? Carbon::parse($request->invoice_date) : now();
            $fiscal_year_start = Carbon::create($date->year, 7, 16);
            $fiscalYear = $date->lessThan($fiscal_year_start)
                ? ($date->year - 1) . '-' . substr($date->year, 2, 2)
                : $date->year . '-' . substr($date->year + 1, 2, 2);

           
            $validated['invoice_number'] = $request->input('invoice_number') ?? $this->generateUniqueInvoiceNumber($fiscalYear);
            $validated['batch_no'] = $request->input('batch_no') ?? $this->generateUniqueBatchNumber($fiscalYear);

            $salesReturn = DB::transaction(function () use ($validated) {
                $salesReturn = SalesReturn::create($validated);

                if (isset($validated['sales_return_products'])) {
                    foreach ($validated['sales_return_products'] as &$product) {
                        $product['company_id'] = $validated['company_id']; 
                    }
                    $salesReturn->salesReturnProducts()->createMany($validated['sales_return_products']);
                }

                return $salesReturn;
            });

            return response()->json([
                'message' => 'Sales Return created successfully',
                'data' => $salesReturn->load('salesReturnProducts')
            ], 201);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Sales Return not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred'], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $salesReturn = SalesReturn::with('salesReturnProducts')->findOrFail($id);
            return response()->json($salesReturn);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Sales Return not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error'], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {

     
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'customer_id' => 'required|exists:customers,id',
                'salesman_id' => 'required|integer',
                'invoice_number' => 'required|string|max:255|unique:sales_returns,invoice_number,' . $id,
                'document_number' => 'nullable|string|max:255',
                'batch_no' => 'nullable|string|max:255|unique:sales_returns,batch_no,' . $id,
                'balance' => 'nullable|string|max:255',
                'invoice_date' => 'nullable|date',
                'remarks' => 'nullable|string|max:255',
                'store_id' => 'required|exists:stores,id',
                'location_id' => 'required|exists:locations,id',
                'discount_amount' => 'nullable|numeric',
                'excise_duty' => 'nullable|numeric',
                'health_insurance' => 'nullable|numeric',
                'freight_amount' => 'nullable|numeric',
                'discount_vat' => 'nullable|numeric',
                'discount_after_vat' => 'nullable|numeric',
                'paid_amount' => 'nullable|numeric',
                'round_of_amount' => 'nullable|numeric',
                'payment_type' => 'required|in:cash,bank,credit',
                'sales_return_products' => 'nullable|array',
                'sales_return_products.*.company_id' => 'required|exists:companies,id',
                'sales_return_products.*.product_id' => 'required|exists:products,id',
                'sales_return_products.*.expiry_date' => 'nullable|date',
                'sales_return_products.*.quantity' => 'nullable|numeric',
                'sales_return_products.*.free_quantity' => 'nullable|numeric',
                'sales_return_products.*.price' => 'nullable|numeric',
                'sales_return_products.*.discount_percent' => 'nullable|numeric',
                'sales_return_products.*.discount_amount' => 'nullable|numeric',
                'sales_return_products.*.is_vatable' => 'nullable|boolean',
                'sales_return_products.*.measure_unit_id' => 'required|exists:measure_units,id',
            ]);
            
    
            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }
    
            $validated = $validator->validated();
            $salesReturn = DB::transaction(function () use ($validated, $id) {
                $salesReturn = SalesReturn::findOrFail($id);

                // Calculate fiscal years
                $newInvoiceDate = isset($validated['invoice_date']) ? Carbon::parse($validated['invoice_date']) : now();
                $currentInvoiceDate = $salesReturn->invoice_date ? Carbon::parse($salesReturn->invoice_date) : now();

                $fiscal_year_start_new = Carbon::create($newInvoiceDate->year, 7, 16);
                $fiscalYearNew = $newInvoiceDate->lessThan($fiscal_year_start_new)
                    ? ($newInvoiceDate->year - 1) . '-' . substr($newInvoiceDate->year, 2, 2)
                    : $newInvoiceDate->year . '-' . substr($newInvoiceDate->year + 1, 2, 2);

                $fiscal_year_start_current = Carbon::create($currentInvoiceDate->year, 7, 16);
                $fiscalYearCurrent = $currentInvoiceDate->lessThan($fiscal_year_start_current)
                    ? ($currentInvoiceDate->year - 1) . '-' . substr($currentInvoiceDate->year, 2, 2)
                    : $currentInvoiceDate->year . '-' . substr($currentInvoiceDate->year + 1, 2, 2);

                // Handle invoice_number
                if ($fiscalYearNew !== $fiscalYearCurrent || !isset($validated['invoice_number'])) {
                    $validated['invoice_number'] = $this->generateUniqueInvoiceNumber($fiscalYearNew);
                } else {
                    $existingInvoiceNumber = $salesReturn->invoice_number;
                    if (SalesReturn::where('invoice_number', $existingInvoiceNumber)->where('id', '!=', $id)->exists()) {
                        return response()->json(['error' => 'Invoice number must be unique.'], 422);
                    }
                    $validated['invoice_number'] = $existingInvoiceNumber;
                }

                // Handle batch_no
                if ($fiscalYearNew !== $fiscalYearCurrent && !isset($validated['batch_no'])) {
                    $validated['batch_no'] = $this->generateUniqueBatchNumber($fiscalYearNew);
                } else {
                    $existingBatchNo = $salesReturn->batch_no;
                    if (isset($validated['batch_no']) && $validated['batch_no'] !== $existingBatchNo) {
                        if (SalesReturn::where('batch_no', $validated['batch_no'])->where('id', '!=', $id)->exists()) {
                            return response()->json(['error' => 'Batch number must be unique.'], 422);
                        }
                    }
                    $validated['batch_no'] = $validated['batch_no'] ?? $existingBatchNo;
                }

                $salesReturn->update($validated);

                if (isset($validated['sales_return_products'])) {
                    $existingProductIds = $salesReturn->salesReturnProducts()->pluck('id')->toArray();
                    $incomingProductIds = collect($validated['sales_return_products'])->pluck('id')->filter()->toArray();

                    // Delete products no longer in the request
                    $productsToDelete = array_diff($existingProductIds, $incomingProductIds);
                    SalesReturnProduct::whereIn('id', $productsToDelete)->delete();

                    foreach ($validated['sales_return_products'] as $productData) {
                        $productData['company_id'] = $validated['company_id']; // Ensure company_id consistency
                        if (isset($productData['id'])) {
                            $saleProduct = SalesReturnProduct::find($productData['id']);
                            if ($saleProduct) {
                                $saleProduct->update($productData);
                            }
                        } else {
                            $salesReturn->salesReturnProducts()->create($productData);
                        }
                    }
                }

                return $salesReturn;
            });

            return response()->json([
                'message' => 'Sales Return updated successfully',
                'data' => $salesReturn->load('salesReturnProducts')
            ], 200);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Sales Return not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred'], 500);
        }
    }

    
    public function destroy($id): JsonResponse
    {
        try {
            $salesReturn = SalesReturn::findOrFail($id);
            $salesReturn->delete();
            return response()->json(['message' => 'Sales Return deleted']);
        } catch (ModelNotFoundException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Sales Return not found'], 404);
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error'], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error'], 500);
        }

        
    }

    public function getSalesReturnByProduct(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'product_id' => 'required|integer|exists:products,id',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);
    
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
    
            $productId = $request->input('product_id');
            $companyId = $request->input('company_id');
    
            $sales = Helper::getSalesReturnByProductId($productId, $companyId);
    
            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales return found for the specified product'], 404);
            }
    
            return response()->json([
                'message' => 'Sales Return retrieved successfully',
                'data' => $sales
            ], 200);
    
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }
    
    
    public function getSalesReturnByCustomer(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'customer_id' => 'required|exists:customers,id',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);
    
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
    
            $customerID = $request->input('customer_id');
            $companyId = $request->input('company_id');
    
            $sales = Helper::getSalesReturnByCustomer($customerID, $companyId);
    
            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales returns found for the specified customer'], 404);
            }
    
            return response()->json([
                'message' => 'Sales Return retrieved successfully',
                'data' => $sales
            ], 200);
    
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }
    
    
    public function getSalesReturnByBatch(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'batch_no' => 'required|exists:sales_returns,batch_no',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);
    
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
    
            $batchNo = $request->input('batch_no');
            $companyId = $request->input('company_id');
    
            $sales = Helper::getSalesReturnByBatch($batchNo, $companyId);
    
            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales return found for the specified batch'], 404);
            }
    
            return response()->json([
                'message' => 'Sales retrieved successfully',
                'data' => $sales
            ], 200);
    
        } catch (QueryException $e) {
            \Log::error($e);
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            \Log::error($e);
            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }
    
    public function getAllExpiryDates(): JsonResponse
    {
        $expiryDates = SalesReturnProduct::select('expiry_date')
            ->distinct()
            ->orderBy('expiry_date', 'asc')
            ->pluck('expiry_date');
    
        return response()->json([
            'message' => 'Expiry dates retrieved successfully',
            'data' => $expiryDates
        ], 200);
    }
    
    
    public function getSalesReturnByExpiryDate(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'expiry_date' => 'required|exists:sales_return_products,expiry_date',
                'company_id' => 'nullable|integer|exists:companies,id',
            ]);
    
            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }
    
            $expiryDate = $request->input('expiry_date');
            $companyId = $request->input('company_id');
    
            $sales = Helper::getSalesReturnByExpiryDate($expiryDate, $companyId);
    
            if ($sales->isEmpty()) {
                return response()->json(['message' => 'No sales returns found for the specified Expiry Date'], 404);
            }
    
            return response()->json([
                'message' => 'Sales Return retrieved successfully',
                'data' => $sales
            ], 200);
    
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
        
            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }
    
}

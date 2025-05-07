<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Helpers\Helper;
use App\Models\SaleProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    private function generateUniqueInvoiceNumber(string $fiscalYear): string
    {
        $lastInvoice = Sale::where('invoice_number', 'like', "INV-$fiscalYear-%")
            ->orderBy('id', 'desc')
            ->first();

        $lastNumber = 0;
        if ($lastInvoice) {
            $lastParts = explode('-', $lastInvoice->invoice_number);
            $lastNumber = isset($lastParts[3]) ? (int)$lastParts[3] : 0;
        }

        $newNumber = $lastNumber + 1;
        $formattedNumber = str_pad($newNumber, 4, '0', STR_PAD_LEFT);
        $invoiceNumber = "INV-$fiscalYear-$formattedNumber";

        while (Sale::where('invoice_number', $invoiceNumber)->exists()) {
            $newNumber++;
            $formattedNumber = str_pad($newNumber, 4, '0', STR_PAD_LEFT);
            $invoiceNumber = "INV-$fiscalYear-$formattedNumber";
        }

        return $invoiceNumber;
    }

    public function index(Request $request): JsonResponse
    {
        $query = Sale::query();
    
        if ($request->has('keywords')) {
            $query->where('invoice_number', 'LIKE', '%' . $request->input('keywords') . '%');
        }
        return response()->json($query->paginate(10));
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'customer_id' => 'required|exists:customers,id',
                'balance' => 'nullable|numeric',
                'invoice_date' => 'nullable|date',
                'batch_no' => 'string|max:255|unique:sales,batch_no',
             
                'document_number' => 'nullable|string|max:255',
                'store_id' => 'required|integer',
                'location_id' => 'nullable|exists:locations,id',
                'salesman_id' => 'required|integer',
                'discount' => 'nullable|numeric',
                'excise_duty' => 'nullable|numeric',
                'health_insurance' => 'nullable|numeric',
                'freight_charge' => 'nullable|numeric',
                'discount_after_vat' => 'nullable|numeric',
                'round_off_amount' => 'nullable|numeric',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'is_mail_notify' => 'boolean',
                'is_whatsapp_notify' => 'boolean',
                'sale_products' => 'nullable|array',
                'sale_products.*.company_id' => 'required|integer|exists:companies,id',
                'sale_products.*.product_id' => 'required|integer|exists:products,id',
                'sale_products.*.code' => 'nullable|string|max:255',
                'sale_products.*.name' => 'required|string|max:255',
                'sale_products.*.expiry_date' => 'nullable|date',
                'sale_products.*.measure_unit_id' => 'nullable|integer|exists:measure_units,id',
                'sale_products.*.quantity' => 'nullable|numeric',
                'sale_products.*.free_quantity' => 'nullable|numeric',
                'sale_products.*.price' => 'nullable|numeric',
                'sale_products.*.discount_percent' => 'nullable|numeric',
                'sale_products.*.discount_amount' => 'nullable|numeric',
                'sale_products.*.is_vatable' => 'required|boolean',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $date = $request->invoice_date ? Carbon::parse($request->invoice_date) : now();
            $fiscal_year_start = Carbon::create($date->year, 7, 16);
            $fiscalYear = $date->lessThan($fiscal_year_start)
                ? ($date->year - 1) . '-' . substr($date->year, 2, 2)
                : $date->year . '-' . substr($date->year + 1, 2, 2);

            $invoiceNumber = $this->generateUniqueInvoiceNumber($fiscalYear);

            $validated = $validator->validated();
            $validated['invoice_number'] = $invoiceNumber;

            $sale = DB::transaction(function () use ($validated) {
                $sale = Sale::create($validated);

                if (isset($validated['sale_products'])) {
                    foreach ($validated['sale_products'] as &$product) {
                        $product['company_id'] = $validated['company_id']; // Ensure company_id consistency
                    }
                    $sale->saleProducts()->createMany($validated['sale_products']);
                }

                return $sale;
            });

            return response()->json([
                'message' => 'Sale created successfully',
                'data' => $sale->load('saleProducts')
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['error' => 'Validation failed: ' . $e->getMessage()], 422);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }

    public function show($id): JsonResponse
    {
        try {
            $item = Sale::with('saleProducts')->findOrFail($id);
            return response()->json($item);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'customer_id' => 'required|exists:customers,id',
                'balance' => 'nullable|numeric',
                'invoice_date' => 'nullable|date',
                'batch_no' => 'string|max:255|unique:sales,batch_no,' . $id,
                'document_number' => 'nullable|string|max:255',
                'store_id' => 'required|integer',
                'location_id' => 'nullable|exists:locations,id',
                'salesman_id' => 'required|integer',
                'discount' => 'nullable|numeric',
                'excise_duty' => 'nullable|numeric',
                'health_insurance' => 'nullable|numeric',
                'freight_charge' => 'nullable|numeric',
                'discount_after_vat' => 'nullable|numeric',
                'round_off_amount' => 'nullable|numeric',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'is_mail_notify' => 'boolean',
                'is_whatsapp_notify' => 'boolean',
                'sale_products' => 'nullable|array',
                'sale_products.*.company_id' => 'required|integer|exists:companies,id',
                'sale_products.*.product_id' => 'required|integer|exists:products,id',
                'sale_products.*.code' => 'nullable|string|max:255',
                'sale_products.*.name' => 'required|string|max:255',
                'sale_products.*.expiry_date' => 'nullable|date',
                'sale_products.*.measure_unit_id' => 'nullable|integer|exists:measure_units,id',
                'sale_products.*.quantity' => 'nullable|numeric',
                'sale_products.*.free_quantity' => 'nullable|numeric',
                'sale_products.*.price' => 'nullable|numeric',
                'sale_products.*.discount_percent' => 'nullable|numeric',
                'sale_products.*.discount_amount' => 'nullable|numeric',
                'sale_products.*.is_vatable' => 'required|boolean',
                'sale_products.*.id' => 'nullable|integer|exists:sale_products,id',
            ]);

            if ($validator->fails()) {
                return response()->json($validator->errors(), 422);
            }

            $validated = $validator->validated();

            $sale = DB::transaction(function () use ($validated, $id) {
                $sale = Sale::findOrFail($id);

                $newInvoiceDate = isset($validated['invoice_date']) ? Carbon::parse($validated['invoice_date']) : now();
                $currentInvoiceDate = $sale->invoice_date ? Carbon::parse($sale->invoice_date) : now();

                $fiscal_year_start_new = Carbon::create($newInvoiceDate->year, 7, 16);
                $fiscalYearNew = $newInvoiceDate->lessThan($fiscal_year_start_new)
                    ? ($newInvoiceDate->year - 1) . '-' . substr($newInvoiceDate->year, 2, 2)
                    : $newInvoiceDate->year . '-' . substr($newInvoiceDate->year + 1, 2, 2);

                $fiscal_year_start_current = Carbon::create($currentInvoiceDate->year, 7, 16);
                $fiscalYearCurrent = $currentInvoiceDate->lessThan($fiscal_year_start_current)
                    ? ($currentInvoiceDate->year - 1) . '-' . substr($currentInvoiceDate->year, 2, 2)
                    : $currentInvoiceDate->year . '-' . substr($currentInvoiceDate->year + 1, 2, 2);

                if ($fiscalYearNew !== $fiscalYearCurrent) {
                    $validated['invoice_number'] = $this->generateUniqueInvoiceNumber($fiscalYearNew);
                } else {
                    $existingInvoiceNumber = $sale->invoice_number;
                    if (Sale::where('invoice_number', $existingInvoiceNumber)->where('id', '!=', $id)->exists()) {
                        return response()->json(['error' => 'Invoice number must be unique.'], 422);
                    }
                    $validated['invoice_number'] = $existingInvoiceNumber;
                }

                $sale->update($validated);

                if (isset($validated['sale_products'])) {
                    $existingProductIds = $sale->saleProducts()->pluck('id')->toArray();
                    $incomingProductIds = collect($validated['sale_products'])->pluck('id')->filter()->toArray();

                    $productsToDelete = array_diff($existingProductIds, $incomingProductIds);
                    SaleProduct::whereIn('id', $productsToDelete)->delete();

                    foreach ($validated['sale_products'] as $productData) {
                        $productData['company_id'] = $validated['company_id']; // Ensure company_id consistency
                        if (isset($productData['id'])) {
                            $saleProduct = SaleProduct::find($productData['id']);
                            if ($saleProduct) {
                                $saleProduct->update($productData);
                            }
                        } else {
                            $sale->saleProducts()->create($productData);
                        }
                    }
                }

                return $sale;
            });

            return response()->json([
                'message' => 'Sale updated successfully',
                'data' => $sale->load('saleProducts')
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Sale not found.'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $item = Sale::findOrFail($id);
            $item->delete();
            return response()->json(['message' => 'Sale deleted']);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Item not found'], 404);
        } catch (QueryException $e) {
            return response()->json(['error' => 'An unexpected error occurred'], 500);
        }
    }

    public function getSalesByProduct(Request $request): JsonResponse
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

        $sales = Helper::getSalesByProductId($productId, $companyId);

        if ($sales->isEmpty()) {
            return response()->json(['message' => 'No sales found for the specified product'], 404);
        }

        return response()->json([
            'message' => 'Sales retrieved successfully',
            'data' => $sales
        ], 200);

    } catch (QueryException $e) {
        return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
    } catch (\Exception $e) {
        return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
    }
}


public function getSalesByCustomer(Request $request): JsonResponse
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

        $sales = Helper::getSalesByCustomer($customerID, $companyId);

        if ($sales->isEmpty()) {
            return response()->json(['message' => 'No sales found for the specified customer'], 404);
        }

        return response()->json([
            'message' => 'Sales retrieved successfully',
            'data' => $sales
        ], 200);

    } catch (QueryException $e) {
        return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
    } catch (\Exception $e) {
        dd($e->getMessage());
        return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
    }
}


public function getSalesByBatch(Request $request): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'batch_no' => 'required|exists:sales,batch_no',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $batchNo = $request->input('batch_no');
        $companyId = $request->input('company_id');

        $sales = Helper::getSalesByBatch($batchNo, $companyId);

        if ($sales->isEmpty()) {
            return response()->json(['message' => 'No sales found for the specified batch'], 404);
        }

        return response()->json([
            'message' => 'Sales retrieved successfully',
            'data' => $sales
        ], 200);

    } catch (QueryException $e) {
        return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
    } catch (\Exception $e) {
        dd($e->getMessage());
        return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
    }
}

public function getAllExpiryDates(): JsonResponse
{
    $expiryDates = SaleProduct::select('expiry_date')
        ->distinct()
        ->orderBy('expiry_date', 'asc')
        ->pluck('expiry_date');

    return response()->json([
        'message' => 'Expiry dates retrieved successfully',
        'data' => $expiryDates
    ], 200);
}


public function getSalesByExpiryDate(Request $request): JsonResponse
{
    try {
        $validator = Validator::make($request->all(), [
            'expiry_date' => 'required|exists:sale_products,expiry_date',
            'company_id' => 'nullable|integer|exists:companies,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $expiryDate = $request->input('expiry_date');
        $companyId = $request->input('company_id');

        $sales = Helper::getSalesByExpiryDate($expiryDate, $companyId);

        if ($sales->isEmpty()) {
            return response()->json(['message' => 'No sales found for the specified Expiry Date'], 404);
        }

        return response()->json([
            'message' => 'Sales retrieved successfully',
            'data' => $sales
        ], 200);

    } catch (QueryException $e) {
        return response()->json(['error' => 'Database error: ' . $e->getMessage()], 500);
    } catch (\Exception $e) {
        dd($e->getMessage());
        return response()->json(['error' => 'Unexpected error: ' . $e->getMessage()], 500);
    }
}


}
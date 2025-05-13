<?php

namespace App\Http\Controllers;

use App\Helpers\Helper;
use App\Models\SalesReturn;
use App\Models\Sale;
use App\Models\SalesReturnProduct;
use App\Models\SaleReturnAdditional;
use App\Models\SaleProduct;
use DB;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesReturnController extends Controller
{
 
    public function index(Request $request): JsonResponse
    {
        $query = SalesReturn::query();

        if ($request->has('keywords')) {
            $query->where('invoice_number', 'LIKE', '%' . $request->input('keywords') . '%');
        }

        return response()->json($query->paginate(50));
    }


    protected function generateUniqueInvoiceNumber(string $fiscalYear): string
    {
        $prefix = 'SRET-INV';
        $year = substr($fiscalYear, 0, 4);

        return DB::transaction(function () use ($prefix, $year) {
            $latestInvoice = SalesReturn::where('invoice_number', 'like', "{$prefix}-{$year}-%")
                ->orderBy('invoice_number', 'desc')
                ->first();

            $sequence = 0;
            if ($latestInvoice && preg_match("/{$prefix}-{$year}-(\d+)/", $latestInvoice->invoice_number, $matches)) {
                $sequence = (int)$matches[1];
            }

            $newSequence = $sequence + 1;
            $formattedSequence = str_pad($newSequence, 6, '0', STR_PAD_LEFT);
            $newInvoiceNumber = "{$prefix}-{$year}-{$formattedSequence}";

            while (SalesReturn::where('invoice_number', $newInvoiceNumber)->exists()) {
                $newSequence++;
                $formattedSequence = str_pad($newSequence, 6, '0', STR_PAD_LEFT);
                $newInvoiceNumber = "{$prefix}-{$year}-{$formattedSequence}";
            }

            return $newInvoiceNumber;
        });
    }

    /**
     * Generate a unique batch number based on fiscal year.
     */

    /**
     * Store a new sales return.
     */
public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'customer_id' => 'required|exists:customers,id',
                'salesman_id' => 'nullable|exists:salesmen,id',
                'invoice_number' => 'nullable|string|max:255|unique:sales_returns,invoice_number',
                'document_number' => 'nullable|string|max:255',
                'batch_no' => 'nullable|string|max:255|unique:sales_returns,batch_no',
                'balance' => 'nullable|numeric',
                'invoice_date' => 'nullable|date',
                'remarks' => 'nullable|string|max:255',
                'store_id' => 'required|exists:stores,id',
                'location_id' => 'required|exists:locations,id',
                'excise_duty' => 'nullable|numeric|min:0',
                'health_insurance' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'discount_after_vat' => 'nullable|numeric|min:0',
                'total_amount' => 'nullable|numeric|min:0',
                'round_of_amount' => 'nullable|numeric',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'payment_type' => 'nullable|string|in:cash,credit,bank',
                'return_entire_sale' => 'nullable|boolean',
                'sale_id' => 'required|integer|exists:sales,id',
                'sale_product_ids' => 'nullable|array',
                'sale_product_ids.*' => 'integer|exists:sale_products,id',
                'sales_return_products' => 'required_without:return_entire_sale,sale_product_ids|array|min:1',
                'sales_return_products.*.product_id' => 'required|exists:products,id',
                'sales_return_products.*.sale_product_id' => 'required|integer|exists:sale_products,id',
                'sales_return_products.*.expiry_date' => 'nullable|date',
                'sales_return_products.*.quantity' => 'required|numeric|min:0',
                'sales_return_products.*.free_quantity' => 'nullable|numeric|min:0',
                'sales_return_products.*.price' => 'required|numeric|min:0',
                'sales_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sales_return_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sales_return_products.*.is_vatable' => 'nullable|boolean',
                'sales_return_products.*.measure_unit_id' => 'required|exists:measure_units,id',
                'sales_return_additionals' => 'nullable|array',
                'sales_return_additionals.place' => 'nullable|string|max:255',
                'sales_return_additionals.transport' => 'nullable|string|max:255',
                'sales_return_additionals.vehicle_number' => 'nullable|string|max:255',
                'sales_return_additionals.vehicle_name' => 'nullable|string|max:255',
                'sales_return_additionals.driver_name' => 'nullable|string|max:255',
                'sales_return_additionals.return_code' => 'required_with:sales_return_additionals|string|max:255',
                'sales_return_additionals.driver_contact_number' => 'nullable|string|max:255',
                'sales_return_additionals.return_date' => 'nullable|date',
                'sales_return_additionals.return_time' => 'nullable|date_format:H:i:s',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $validated = $validator->validated();

            // Fetch sale with saleAdditional
            $sale = Sale::with(['saleProducts', 'saleAdditionals'])->findOrFail($validated['sale_id']);
            if ($sale->company_id != $validated['company_id']) {
                return response()->json(['error' => 'Sale does not belong to the provided company'], 422);
            }

            // Handle return_entire_sale
            if ($validated['return_entire_sale'] ?? false) {
                $validated['sales_return_products'] = $sale->saleProducts->map(function ($product) {
                    return [
                        'sale_product_id' => $product->id,
                        'product_id' => $product->product_id,
                        'quantity' => $product->quantity,
                        'free_quantity' => $product->free_quantity ?? 0,
                        'price' => $product->price,
                        'discount_percent' => $product->discount_percent ?? 0,
                        'discount_amount' => $product->discount_amount ?? 0,
                        'is_vatable' => $product->is_vatable,
                        'measure_unit_id' => $product->measure_unit_id,
                        'expiry_date' => $product->expiry_date,
                    ];
                })->toArray();
            }
            // Handle specific sale_product_ids
            elseif (isset($validated['sale_product_ids']) && !empty($validated['sale_product_ids'])) {
                $saleProducts = SaleProduct::whereIn('id', $validated['sale_product_ids'])
                    ->with(['sale' => function ($query) {
                        $query->select('id', 'batch_no', 'company_id', 'customer_id', 'salesman_id', 'store_id', 'location_id');
                    }])
                    ->get();

                if ($saleProducts->isEmpty()) {
                    return response()->json(['error' => 'No valid sale products found for provided IDs'], 422);
                }

                $saleId = $saleProducts->first()->sale_id;
                if ($saleProducts->pluck('sale_id')->unique()->count() > 1) {
                    return response()->json(['error' => 'All sale products must belong to the same sale'], 422);
                }
                if ($saleProducts->pluck('company_id')->unique()->count() > 1 || !$saleProducts->every(fn($p) => $p->company_id == $validated['company_id'])) {
                    return response()->json(['error' => 'All sale products must belong to the same company'], 422);
                }

                $validated['sales_return_products'] = $saleProducts->map(function ($product) {
                    return [
                        'sale_product_id' => $product->id,
                        'product_id' => $product->product_id,
                        'quantity' => $product->quantity,
                        'free_quantity' => $product->free_quantity ?? 0,
                        'price' => $product->price,
                        'discount_percent' => $product->discount_percent ?? 0,
                        'discount_amount' => $product->discount_amount ?? 0,
                        'is_vatable' => $product->is_vatable,
                        'measure_unit_id' => $product->measure_unit_id,
                        'expiry_date' => $product->expiry_date,
                    ];
                })->toArray();
            }

            // Set sale-related fields
            $validated['batch_no'] = $validated['batch_no'] ?? $sale->batch_no;
            $validated['customer_id'] = $validated['customer_id'] ?? $sale->customer_id;
            $validated['salesman_id'] = $validated['salesman_id'] ?? $sale->salesman_id;
            $validated['store_id'] = $validated['store_id'] ?? $sale->store_id;
            $validated['location_id'] = $validated['location_id'] ?? $sale->location_id;

            // Calculate fiscal year
            $date = $request->invoice_date ? Carbon::parse($request->invoice_date) : now();
            $fiscal_year_start = Carbon::create($date->year, 7, 16);
            $fiscalYear = $date->lessThan($fiscal_year_start)
                ? ($date->year - 1) . '-' . substr($date->year, 2, 2)
                : $date->year . '-' . substr($date->year + 1, 2, 2);

            // Generate unique invoice number if not provided
            $validated['invoice_number'] = $request->input('invoice_number') ?? $this->generateUniqueInvoiceNumber($fiscalYear);

            // Validate return quantities
            foreach ($validated['sales_return_products'] as $index => $product) {
                $saleProductId = $product['sale_product_id'];
                $productId = $product['product_id'];
                $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);

                $saleProduct = SaleProduct::where('id', $saleProductId)
                    ->where('sale_id', $validated['sale_id'])
                    ->firstOrFail();
                $availableToReturn = $saleProduct->quantity + ($saleProduct->free_quantity ?? 0);

                $returnedQuantity = SalesReturnProduct::where('sale_product_id', $saleProductId)
                    ->where('company_id', $validated['company_id'])
                    ->whereNull('deleted_at')
                    ->sum('quantity');

                $availableToReturn -= $returnedQuantity;

                if ($requestedQuantity > $availableToReturn) {
                    return response()->json([
                        'error' => "Cannot return more than sold for sale product ID {$saleProductId} at index {$index}. Available: {$availableToReturn}, Requested: {$requestedQuantity}"
                    ], 422);
                }
            }

            // Prepare sales return additionals data
            $salesReturnAdditionalsData = $validated['sales_return_additionals'] ?? null;
            if (!$salesReturnAdditionalsData && $sale->saleAdditional) {
                $salesReturnAdditionalsData = [
                    'place' => $sale->saleAdditional->place,
                    'transport' => $sale->saleAdditional->transport,
                    'vehicle_number' => $sale->saleAdditional->vehicle_number,
                    'vehicle_name' => $sale->saleAdditional->vehicle_name,
                    'driver_name' => $sale->saleAdditional->driver_name,
                    'return_code' => 'RETURN' . now()->format('YmdHis'),
                    'driver_contact_number' => $sale->saleAdditional->driver_contact_number,
                    'return_date' => now()->toDateString(),
                    'return_time' => now()->toTimeString(),
                ];
            } elseif (!$salesReturnAdditionalsData) {
                // If no sale additionals exist, set minimal defaults
                $salesReturnAdditionalsData = [
                    'return_code' => 'RETURN' . now()->format('YmdHis'),
                    'return_date' => now()->toDateString(),
                    'return_time' => now()->toTimeString(),
                ];
            }

            // Filter validated data to match sales_returns table columns
            $salesReturnData = array_intersect_key($validated, array_flip([
                'company_id', 'customer_id', 'salesman_id', 'invoice_number', 'document_number', 'batch_no',
                'balance', 'invoice_date', 'remarks', 'store_id', 'location_id', 'excise_duty',
                'health_insurance', 'freight_amount', 'discount', 'discount_after_vat', 'total_amount',
                'round_of_amount', 'payment'
            ]));

            $salesReturn = DB::transaction(function () use ($salesReturnData, $validated, $salesReturnAdditionalsData) {
                $salesReturn = SalesReturn::create($salesReturnData);

                if (isset($validated['sales_return_products'])) {
                    foreach ($validated['sales_return_products'] as $product) {
                        $product['company_id'] = $validated['company_id'];
                        $product['sale_id'] = $validated['sale_id'];
                        $salesReturn->salesReturnProducts()->create($product);
                    }
                }

                // Create sales return additionals
                SaleReturnAdditional::create([
                    'company_id' => $validated['company_id'],
                    'sales_return_id' => $salesReturn->id,
                    'place' => $salesReturnAdditionalsData['place'] ?? null,
                    'transport' => $salesReturnAdditionalsData['transport'] ?? null,
                    'vehicle_number' => $salesReturnAdditionalsData['vehicle_number'] ?? null,
                    'vehicle_name' => $salesReturnAdditionalsData['vehicle_name'] ?? null,
                    'driver_name' => $salesReturnAdditionalsData['driver_name'] ?? null,
                    'return_code' => $salesReturnAdditionalsData['return_code'],
                    'driver_contact_number' => $salesReturnAdditionalsData['driver_contact_number'] ?? null,
                    'return_date' => $salesReturnAdditionalsData['return_date'],
                    'return_time' => $salesReturnAdditionalsData['return_time'],
                ]);

                return $salesReturn;
            });

            return response()->json([
                'message' => 'Sales Return created successfully',
                'data' => $salesReturn->load(['salesReturnProducts', 'salesReturnAdditional'])
            ], 201);
        } catch (ModelNotFoundException $e) {
            Log::error($e);
            return response()->json(['error' => 'Resource not found'], 404);
        } catch (QueryException $e) {
            Log::error($e);
            return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Update an existing sales return.
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|exists:companies,id',
                'customer_id' => 'required|exists:customers,id',
                'salesman_id' => 'nullable|exists:salesmen,id',
                'invoice_number' => ['nullable', 'string', 'max:255', Rule::unique('sales_returns')->ignore($id)],
                'document_number' => 'nullable|string|max:255',
                'batch_no' => ['nullable', 'string', 'max:255', Rule::unique('sales_returns')->ignore($id)],
                'balance' => 'nullable|numeric',
                'invoice_date' => 'nullable|date',
                'remarks' => 'nullable|string|max:255',
                'store_id' => 'required|exists:stores,id',
                'location_id' => 'required|exists:locations,id',
                'excise_duty' => 'nullable|numeric|min:0',
                'health_insurance' => 'nullable|numeric|min:0',
                'freight_amount' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'discount_after_vat' => 'nullable|numeric|min:0',
                'total_amount' => 'nullable|numeric|min:0',
                'round_of_amount' => 'nullable|numeric',
                'payment' => 'nullable|array',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'payment_type' => 'nullable|string|in:cash,credit,bank',
                'return_entire_sale' => 'nullable|boolean',
                'sale_id' => 'required|integer|exists:sales,id',
                'sale_product_ids' => 'nullable|array',
                'sale_product_ids.*' => 'integer|exists:sale_products,id',
                'sales_return_products' => 'required_without:return_entire_sale,sale_product_ids|array|min:1',
                'sales_return_products.*.id' => 'nullable|integer|exists:sales_return_products,id',
                'sales_return_products.*.sale_product_id' => 'required|integer|exists:sale_products,id',
                'sales_return_products.*.product_id' => 'required|exists:products,id',
                'sales_return_products.*.expiry_date' => 'nullable|date',
                'sales_return_products.*.quantity' => 'required|numeric|min:0',
                'sales_return_products.*.free_quantity' => 'nullable|numeric|min:0',
                'sales_return_products.*.price' => 'required|numeric|min:0',
                'sales_return_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sales_return_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sales_return_products.*.is_vatable' => 'nullable|boolean',
                'sales_return_products.*.measure_unit_id' => 'required|exists:measure_units,id',
                'sales_return_additionals' => 'nullable|array',
                'sales_return_additionals.place' => 'nullable|string|max:255',
                'sales_return_additionals.transport' => 'nullable|string|max:255',
                'sales_return_additionals.vehicle_number' => 'nullable|string|max:255',
                'sales_return_additionals.vehicle_name' => 'nullable|string|max:255',
                'sales_return_additionals.driver_name' => 'nullable|string|max:255',
                'sales_return_additionals.return_code' => 'required_with:sales_return_additionals|string|max:255',
                'sales_return_additionals.driver_contact_number' => 'nullable|string|max:255',
                'sales_return_additionals.return_date' => 'nullable|date',
                'sales_return_additionals.return_time' => 'nullable|date_format:H:i:s',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $validated = $validator->validated();

            // Fetch sale and sales return
            $sale = Sale::with(['saleProducts', 'saleAdditional'])->findOrFail($validated['sale_id']);
            $salesReturn = SalesReturn::findOrFail($id);
            if ($sale->company_id != $validated['company_id'] || $salesReturn->company_id != $validated['company_id']) {
                return response()->json(['error' => 'Sale or Sales Return does not belong to the provided company'], 422);
            }

            // Handle return_entire_sale
            if ($validated['return_entire_sale'] ?? false) {
                $validated['sales_return_products'] = $sale->saleProducts->map(function ($product) {
                    return [
                        'sale_product_id' => $product->id,
                        'product_id' => $product->product_id,
                        'quantity' => $product->quantity,
                        'free_quantity' => $product->free_quantity ?? 0,
                        'price' => $product->price,
                        'discount_percent' => $product->discount_percent ?? 0,
                        'discount_amount' => $product->discount_amount ?? 0,
                        'is_vatable' => $product->is_vatable,
                        'measure_unit_id' => $product->measure_unit_id,
                        'expiry_date' => $product->expiry_date,
                    ];
                })->toArray();
            }
            // Handle specific sale_product_ids
            elseif (isset($validated['sale_product_ids']) && !empty($validated['sale_product_ids'])) {
                $saleProducts = SaleProduct::whereIn('id', $validated['sale_product_ids'])
                    ->with(['sale' => function ($query) {
                        $query->select('id', 'batch_no', 'company_id', 'customer_id', 'salesman_id', 'store_id', 'location_id');
                    }])
                    ->get();

                if ($saleProducts->isEmpty()) {
                    return response()->json(['error' => 'No valid sale products found for provided IDs'], 422);
                }

                $saleId = $saleProducts->first()->sale_id;
                if ($saleProducts->pluck('sale_id')->unique()->count() > 1) {
                    return response()->json(['error' => 'All sale products must belong to the same sale'], 422);
                }
                if ($saleProducts->pluck('company_id')->unique()->count() > 1 || !$saleProducts->every(fn($p) => $p->company_id == $validated['company_id'])) {
                    return response()->json(['error' => 'All sale products must belong to the same company'], 422);
                }

                $validated['sales_return_products'] = $saleProducts->map(function ($product) {
                    return [
                        'sale_product_id' => $product->id,
                        'product_id' => $product->product_id,
                        'quantity' => $product->quantity,
                        'free_quantity' => $product->free_quantity ?? 0,
                        'price' => $product->price,
                        'discount_percent' => $product->discount_percent ?? 0,
                        'discount_amount' => $product->discount_amount ?? 0,
                        'is_vatable' => $product->is_vatable,
                        'measure_unit_id' => $product->measure_unit_id,
                        'expiry_date' => $product->expiry_date,
                    ];
                })->toArray();
            }

            // Set sale-related fields
            $validated['batch_no'] = $validated['batch_no'] ?? $sale->batch_no;
            $validated['customer_id'] = $validated['customer_id'] ?? $sale->customer_id;
            $validated['salesman_id'] = $validated['salesman_id'] ?? $sale->salesman_id;
            $validated['store_id'] = $validated['store_id'] ?? $sale->store_id;
            $validated['location_id'] = $validated['location_id'] ?? $sale->location_id;

            // Calculate fiscal year
            $newInvoiceDate = $validated['invoice_date'] ? Carbon::parse($validated['invoice_date']) : now();
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
            }

            // Validate return quantities
            foreach ($validated['sales_return_products'] as $index => $product) {
                $saleProductId = $product['sale_product_id'] ?? null;
                $productId = $product['product_id'];
                $requestedQuantity = $product['quantity'] + ($product['free_quantity'] ?? 0);

                if ($saleProductId) {
                    $saleProduct = SaleProduct::where('id', $saleProductId)
                        ->where('sale_id', $validated['sale_id'])
                        ->firstOrFail();
                    $availableToReturn = $saleProduct->quantity + ($saleProduct->free_quantity ?? 0);

                    $returnedQuantity = SalesReturnProduct::where('sale_product_id', $saleProductId)
                        ->where('company_id', $validated['company_id'])
                        ->where('sales_return_id', '!=', $id)
                        ->whereNull('deleted_at')
                        ->sum('quantity');

                    $availableToReturn -= $returnedQuantity;

                    if ($requestedQuantity > $availableToReturn) {
                        return response()->json([
                            'error' => "Cannot return more than sold for sale product ID {$saleProductId} at index {$index}. Available: {$availableToReturn}, Requested: {$requestedQuantity}"
                        ], 422);
                    }
                } else {
                    return response()->json([
                        'error' => "sale_product_id is required for product ID {$productId} at index {$index}"
                    ], 422);
                }
            }

            // Prepare sales return additionals data
            $salesReturnAdditionalsData = $validated['sales_return_additionals'] ?? null;
            if (!$salesReturnAdditionalsData && $sale->saleAdditional) {
                $salesReturnAdditionalsData = [
                    'place' => $sale->saleAdditional->place,
                    'transport' => $sale->saleAdditional->transport,
                    'vehicle_number' => $sale->saleAdditional->vehicle_number,
                    'vehicle_name' => $sale->saleAdditional->vehicle_name,
                    'driver_name' => $sale->saleAdditional->driver_name,
                    'return_code' => 'RETURN' . now()->format('YmdHis'),
                    'driver_contact_number' => $sale->saleAdditional->driver_contact_number,
                    'return_date' => now()->toDateString(),
                    'return_time' => now()->toTimeString(),
                ];
            } elseif (!$salesReturnAdditionalsData) {
                // If no sale additionals exist, set minimal defaults
                $salesReturnAdditionalsData = [
                    'return_code' => 'RETURN' . now()->format('YmdHis'),
                    'return_date' => now()->toDateString(),
                    'return_time' => now()->toTimeString(),
                ];
            }

            // Filter validated data to match sales_returns table columns
            $salesReturnData = array_intersect_key($validated, array_flip([
                'company_id', 'customer_id', 'salesman_id', 'invoice_number', 'document_number', 'batch_no',
                'balance', 'invoice_date', 'remarks', 'store_id', 'location_id', 'excise_duty',
                'health_insurance', 'freight_amount', 'discount', 'discount_after_vat', 'total_amount',
                'round_of_amount', 'payment'
            ]));

            $salesReturn = DB::transaction(function () use ($salesReturn, $salesReturnData, $validated, $salesReturnAdditionalsData) {
                $salesReturn->update($salesReturnData);

                if (isset($validated['sales_return_products'])) {
                    $existingProductIds = [];
                    foreach ($validated['sales_return_products'] as $product) {
                        $product['company_id'] = $validated['company_id'];
                        $product['sale_id'] = $validated['sale_id'];
                        if (isset($product['id']) && SalesReturnProduct::where('id', $product['id'])->exists()) {
                            SalesReturnProduct::find($product['id'])->update($product);
                            $existingProductIds[] = $product['id'];
                        } else {
                            $newProduct = $salesReturn->salesReturnProducts()->create($product);
                            $existingProductIds[] = $newProduct->id;
                        }
                    }

                    $salesReturn->salesReturnProducts()
                        ->whereNotIn('id', $existingProductIds)
                        ->delete();
                } else {
                    $salesReturn->salesReturnProducts()->delete();
                }

                // Update or create sales return additionals
                $salesReturn->salesReturnAdditional()->updateOrCreate(
                    ['sales_return_id' => $salesReturn->id],
                    [
                        'company_id' => $validated['company_id'],
                        'place' => $salesReturnAdditionalsData['place'] ?? null,
                        'transport' => $salesReturnAdditionalsData['transport'] ?? null,
                        'vehicle_number' => $salesReturnAdditionalsData['vehicle_number'] ?? null,
                        'vehicle_name' => $salesReturnAdditionalsData['vehicle_name'] ?? null,
                        'driver_name' => $salesReturnAdditionalsData['driver_name'] ?? null,
                        'return_code' => $salesReturnAdditionalsData['return_code'],
                        'driver_contact_number' => $salesReturnAdditionalsData['driver_contact_number'] ?? null,
                        'return_date' => $salesReturnAdditionalsData['return_date'],
                        'return_time' => $salesReturnAdditionalsData['return_time'],
                    ]
                );

                return $salesReturn;
            });

            return response()->json([
                'message' => 'Sales Return updated successfully',
                'data' => $salesReturn->load(['salesReturnProducts', 'salesReturnAdditional'])
            ], 200);
        } catch (ModelNotFoundException $e) {
            Log::error($e);
            return response()->json(['error' => 'Sales Return not found'], 404);
        } catch (QueryException $e) {
            Log::error($e);
            return response()->json(['error' => 'Database error occurred: ' . $e->getMessage()], 500);
        } catch (\Exception $e) {
            Log::error($e);
            return response()->json(['error' => 'Unexpected error occurred: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Generate a unique invoice number for the fiscal year.
     */


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

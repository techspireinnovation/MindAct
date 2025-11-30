<?php

namespace App\Http\Controllers;
use Pratiksh\Nepalidate\Services\NepaliDate;

use Anuzpandey\LaravelNepaliDate\LaravelNepaliDate;
use Pratiksh\Nepalidate\Services\EnglishDate;

use NepaliCalendar;
use App\Helpers\Helper;
use App\Models\MeasureUnit;
use App\Models\Product;
use App\Models\ProductList;
use App\Models\StockTransferFieldValue;
use App\Models\StockTransfer;
use App\Models\StockTransferDetails;
use App\Models\PurchaseStockProductReturn;
use App\Models\PurchaseStockProductReturnFieldValue;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseStockProduct;
use App\Models\PurchaseProductFieldValue;
use App\Models\PurchaseProductReturn;
use App\Models\PurchaseReturnProductFieldValue;
use App\Models\HoldSale;
use App\Models\SaleAdditional;
use App\Models\HoldSaleProduct;
use App\Models\SalesReturnProduct;
use App\Models\SaleReturnProductFieldValue;
use App\Models\SalesProductFieldValue;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;

class HoldSaleController extends Controller
{


    private function calculatePieces(float $quantity, float $measureUnitQuantity): float
    {
        if ($measureUnitQuantity <= 0) {
          
            return 0;
        }


        $integerPart = floor($quantity);

        $decimalPart = $quantity - $integerPart;

        $decimalStr = (string) $decimalPart;
        $decimalPieces = $decimalStr > 0 ? (int) str_replace('.', '', (string) $decimalStr) : 0;

        return ($integerPart * $measureUnitQuantity) + $decimalPieces;
    }

    private function calculateAvailablePieces($purchaseProduct, int $companyId, int $branchId, $measureUnitsCalc): int
    {
        $purchaseMeasureUnitQuantity = isset($measureUnitsCalc[$purchaseProduct->measure_unit_id]) ? $measureUnitsCalc[$purchaseProduct->measure_unit_id]->quantity : 1;

       

        if ($purchaseMeasureUnitQuantity <= 0) {
           
            return 0;
        }

      

        // Prioritize field values if they exist
        $fieldValues = $purchaseProduct->fieldValues->whereNull('deleted_at')->groupBy('quantity_index');
        if ($fieldValues->isNotEmpty()) {
            $unavailableIndices = $this->getUnavailableQuantityIndices($purchaseProduct, $companyId, $branchId);
            $availablePieces = $fieldValues->filter(function ($fv, $index) use ($unavailableIndices) {
                return !in_array($index, $unavailableIndices);
            })->count();

           

            return max(0, $availablePieces);
        }

        // Fallback to quantity-based calculation
        $regularPieces = $this->calculatePieces($purchaseProduct->quantity ?? 0, $purchaseMeasureUnitQuantity);
        $freePieces = $this->calculatePieces($purchaseProduct->free_quantity ?? 0, $purchaseMeasureUnitQuantity);
        $totalPurchasedPieces = $regularPieces + $freePieces;

        $purchaseReturnedPieces = $purchaseProduct->purchaseStockProductReturns->reduce(
            function ($carry, $return) use ($measureUnitsCalc) {
                $returnMeasureUnitQuantity = isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1;
                return $carry + $this->calculatePieces(
                    ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                    $returnMeasureUnitQuantity
                );
            },
            0
        );

        $soldPieces = $purchaseProduct->saleProducts->reduce(
            function ($carry, $sale) use ($measureUnitsCalc) {
                $saleMeasureUnitQuantity = isset($measureUnitsCalc[$sale->measure_unit_id]) ? $measureUnitsCalc[$sale->measure_unit_id]->quantity : 1;
                return $carry + $this->calculatePieces(
                    ($sale->quantity ?? 0) + ($sale->free_quantity ?? 0),
                    $saleMeasureUnitQuantity
                );
            },
            0
        );

        $salesReturnedPieces = $purchaseProduct->saleProducts->flatMap(function ($sale) use ($companyId, $measureUnitsCalc) {
            return $sale->saleProductReturns->where('company_id', $companyId)->whereNull('deleted_at');
        })->reduce(
                function ($carry, $return) use ($measureUnitsCalc) {
                    $returnMeasureUnitQuantity = isset($measureUnitsCalc[$return->measure_unit_id]) ? $measureUnitsCalc[$return->measure_unit_id]->quantity : 1;
                    return $carry + $this->calculatePieces(
                        ($return->quantity ?? 0) + ($return->free_quantity ?? 0),
                        $returnMeasureUnitQuantity
                    );
                },
                0
            );

        $availablePieces = $totalPurchasedPieces - $purchaseReturnedPieces - $soldPieces + $salesReturnedPieces;

        if ($availablePieces < 0) {
          
        }

       

        return max(0, (int) $availablePieces); // Remove floor, cast to int
    }



    private function availablePiecesForSaleUpdate(
        $purchaseProduct,
        float $measureUnitQty,
        int $companyId,
        int $branchId,
        ?int $ignoreSaleId = null
    ): float {
        // 1) pieces that entered via purchase
        $regularPieces = $this->calculatePieces($purchaseProduct->quantity ?? 0, $measureUnitQty);
        $freePieces = $this->calculatePieces($purchaseProduct->free_quantity ?? 0, $measureUnitQty);
        $purchasedPieces = $regularPieces + $freePieces;

        // 2) pieces already returned to supplier
        $purchaseReturnedPieces = $purchaseProduct->purchaseStockProductReturns()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->with('measureUnit')
            ->get()
            ->reduce(
                fn($carry, $ret) =>
                $carry
                + $this->calculatePieces($ret->quantity ?? 0, $ret->measureUnit->quantity ?? 1)
                + $this->calculatePieces($ret->free_quantity ?? 0, $ret->measureUnit->quantity ?? 1)
                ,
                0
            );

        // 3) pieces already sold (ignore the sale we are editing)
        $soldPieces = $purchaseProduct->saleProducts()
            ->where('company_id', $companyId)
            ->when($ignoreSaleId, fn($q, $id) => $q->where('sale_id', '!=', $id))
            ->whereNull('deleted_at')
            ->with('measureUnit')
            ->get()
            ->reduce(
                fn($carry, $sale) =>
                $carry
                + $this->calculatePieces($sale->quantity ?? 0, $sale->measureUnit->quantity ?? 1)
                + $this->calculatePieces($sale->free_quantity ?? 0, $sale->measureUnit->quantity ?? 1)
                ,
                0
            );

        // 4) pieces returned by customers (adds back to stock)
        $customerReturnedPieces = SalesReturnProduct::where('product_id', $purchaseProduct->product_id)
            ->whereHas(
                'saleProduct',
                fn($q) =>
                $q->where('purchase_product_id', $purchaseProduct->id)
                    ->where('company_id', $companyId)
            )
            ->when(
                $ignoreSaleId,
                fn($q, $id) =>
                // ignore returns that belong to the sale we are editing
                $q->whereHas('saleProduct.sale', fn($sq) => $sq->where('id', '!=', $id))
            )
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->with('measureUnit')
            ->get()
            ->reduce(
                fn($carry, $ret) =>
                $carry
                + $this->calculatePieces($ret->quantity ?? 0, $ret->measureUnit->quantity ?? 1)
                + $this->calculatePieces($ret->free_quantity ?? 0, $ret->measureUnit->quantity ?? 1)
                ,
                0
            );

        $available = max(0, $purchasedPieces - $purchaseReturnedPieces - $soldPieces + $customerReturnedPieces);

       

        return $available;
    }


    private function flattenFieldValues($fieldValues, $index): array
    {
        $flat = [];
        foreach ($fieldValues as $fvSet) {
            foreach ($fvSet as $fv) {
                $flat[] = [
                    'purchase_stock_product_id' => $fv['purchase_stock_product_id'] ?? throw new \Exception("Missing purchase_stock_product_id in field values at index {$index}."),
                    'purchase_product_id' => $fv['purchase_product_id'] ?? null,
                    'stock_product_id' => $fv['stock_product_id'] ?? null,
                    'stock_adjustment_id' => $fv['stock_adjustment_id'] ?? null,
                    'stock_reconciliation_id' => $fv['stock_reconciliation_id'] ?? null,
                    'stock_transfer_id' => $fv['stock_transfer_id'] ?? null,
                    'product_field_id' => $fv['product_field_id'] ?? null,
                    'value' => $fv['value'] ?? throw new \Exception("Missing value in field values at index {$index}."),
                    'quantity_index' => $fv['quantity_index'] ?? throw new \Exception("Missing quantity_index in field values at index {$index}."),
                    'quantity_type' => $fv['quantity_type'] ?? 'regular',
                ];
            }
        }
        return $flat;
    }


    private function convertToTargetMeasureUnit(float $regularPieces, float $freePieces, float $targetMeasureUnitQuantity): array
    {
        if ($targetMeasureUnitQuantity <= 0) {
           
            return [0, 0];
        }


        //For Regular 
        $regularPiecesInt = floor($regularPieces / $targetMeasureUnitQuantity);
        $regularRemainingPieces = $regularPieces - ($regularPiecesInt * $targetMeasureUnitQuantity);
        $regularDecimal = $regularRemainingPieces > 0 ? (float) ('0.' . (int) $regularRemainingPieces) : 0;
        $regularQuantity = $regularPiecesInt + $regularDecimal;

        //For Free Pieces

        $freePiecesInt = floor($freePieces / $targetMeasureUnitQuantity);
        $freeRemainingPieces = $freePieces - ($freePiecesInt * $targetMeasureUnitQuantity);
        $freeDecimal = $freeRemainingPieces > 0 ? (float) ('0.' . (int) $freeRemainingPieces) : 0;
        $freeQuantity = $freePiecesInt + $freeDecimal;


      

        return [$regularQuantity, $freeQuantity];
    }

    private function getUnavailableQuantityIndices($purchaseProduct, int $companyId, int $branchId): array
    {
        $soldIndices = SalesProductFieldValue::whereIn('sale_product_id', $purchaseProduct->saleProducts->pluck('id'))
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->pluck('quantity_index')
            ->unique()
            ->values()
            ->toArray();

        $returnedIndices = PurchaseStockProductReturnFieldValue::whereIn('purchase_stock_product_return_id', $purchaseProduct->purchaseStockProductReturns->pluck('id'))
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->whereNull('deleted_at')
            ->pluck('quantity_index')
            ->unique()
            ->values()
            ->toArray();

        $unavailableIndices = array_unique(array_merge($soldIndices, $returnedIndices));

       

        return $unavailableIndices;
    }



    public function index()
    {
        try {

            $sale = HoldSale::with(['holdSaleProducts'])->get();
            return response()->json([
                'message' => 'Hold Sales retrieved successfully',
                'data' => $sale
            ], 200);

        } catch (ModelNotFoundException $e) {
           
            return response()->json([
                'message' => 'Hold Sales not found'
            ], 404);

        } catch (QueryException $e) {
          
            return response()->json([
                'message' => 'Database query error'
            ], 500);
        } catch (\Exception $e) {
          
            return response()->json([
                'message' => 'An error occurred while retrieving Hold Sales'
            ], 500);
        }
    }

    public function store(Request $request): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer',
                'customer_id' => 'nullable|integer|exists:customers,id',

                'customer_name' => 'required|string|max:255',
                'customer_address' => 'nullable|string|max:255',
                'contact_number' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                'credit_days' => 'nullable|string|max:255',
                'payment' => 'nullable|array',
                'payment.bank_name' => 'nullable|string',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'invoice_number' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('hold_sales')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'store_id' => 'nullable|integer|exists:stores,id',
                'location_id' => 'nullable|integer|exists:locations,id',
                'sub_total_before_discount' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'discount_after_vat' => 'nullable|numeric|min:0',
                'freight_charge' => 'nullable|numeric|min:0',
                'excise_duty' => 'nullable|numeric|min:0',
                'health_insurance' => 'nullable|numeric|min:0',
                'balance' => 'nullable|numeric|min:0',
                'taxable_amount' => 'nullable|numeric|min:0',
                'non_taxable_amount' => 'nullable|numeric|min:0',
                'ref_bill_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('sales', 'ref_number')
                        ->where(function ($query) use ($request) {
                            return $query->where('company_id', $request->input('company_id', $request->company_id))
                                ->whereNull('deleted_at');
                        }),
                ],
                'round_off_amount' => 'nullable|numeric|max:255',
                'roundoff_type' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'vat_amount' => 'nullable|numeric',
                'abvt' => 'nullable|boolean',
                'is_vatable' => 'nullable|boolean',
                'total_amount' => 'nullable|numeric|min:0',
                'promo_disc' => 'nullable',
                'bill_amount' => 'nullable|numeric',
                'hold_discount' => 'nullable|numeric',
                'final_amount' => 'nullable|numeric',
                'ic_amount' => 'nullable|numeric',
                'tender' => 'nullable|numeric',
                'return' => 'nullable|numeric',
                'sell_entire_batch' => 'nullable|boolean',
                'purchase_id' => 'nullable|integer|exists:purchases,id',
                'purchase_bill_number' => 'nullable|string|max:255',
                'batch_no_sale' => 'nullable|string|max:255',
                'sale_products' => [
                    'required_unless:sell_entire_batch,true',
                    'array',
                    'min:1',
                ],
                'sale_products.*.product_name' => 'required_without:sale_products.*.product_id|string|max:255',
                'sale_products.*.product_id' => 'nullable|integer|exists:products,id',
                'sale_products.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',

                'sale_products.*.quantity' => 'nullable|string',
                'sale_products.*.free_quantity' => 'nullable|string',
                'sale_products.*.price' => 'required|numeric|min:0',
                'sale_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sale_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sale_products.*.is_vatable' => 'nullable|boolean',
                'sale_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'sale_products.*.batch_no' => 'nullable|string|max:255',
                'sale_products.*.amount' => 'nullable|numeric|min:0',
                'sale_products.*.mfd' => 'nullable|string|max:255',
                'sale_products.*.expiry_date' => 'nullable|string|max:255',
                'sale_products.*.field_values' => 'present|array',
                'sale_products.*.field_values.*' => 'array|min:1',
                'sale_products.*.field_values.*.*.product_id' => 'nullable',
                'sale_products.*.field_values.*.*.product_field_id' => 'required_if:hold_sale_products.*.field_values,array|integer|exists:product_fields,id',
                'sale_products.*.field_values.*.*.value' => 'required_if:sale_products.*.field_values,array|string|max:255',
                'sale_products.*.field_values.*.*.quantity_index' => 'required_if:sale_products.*.field_values,array|integer|min:0',
                'sale_products.*.field_values.*.*.quantity_type' => 'nullable|string|in:regular,free',
                'sale_products.*.field_values.*.*.purchase_stock_product_id' => 'required_if:sale_products.*.field_values,array|integer|exists:purchase_stock_products,id',
                'sale_products.*.field_values.*.*.purchase_product_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_product_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_adjustment_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_reconciliation_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_transfer_id' => 'nullable',
                'sale_additionals.company_id' => 'nullable|integer|exists:companies,id',
                'sale_additionals.sale_id' => 'nullable|string|max:255',
                'sale_additionals.place' => 'nullable|string|max:255',
                'sale_additionals.transport' => 'nullable|string|max:255',
                'sale_additionals.vehicle_number' => 'nullable|string|max:255',
                'sale_additionals.vehicle_name' => 'nullable|string|max:255',
                'sale_additionals.driver_name' => 'nullable|string|max:255',
                'sale_additionals.dispatch_code' => 'required_if:sale_additionals,exists|string|max:255',
                'sale_additionals.driver_contact_number' => 'nullable|string|max:255',
                'sale_additionals.delivery_date' => 'nullable|string|max:255',
                'sale_additionals.delivery_time' => 'nullable|string|max:255',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $validated['branch_id'] = $request->branch_id;
            $validated['company_id'] = $request->company_id;

          

            $sale = DB::transaction(function () use ($validated) {
                // Create HoldSale
                $sale = HoldSale::create([
                    'company_id' => $validated['company_id'],
                    'branch_id' => $validated['branch_id'],
                    'customer_id' => $validated['customer_id'] ?? null,

                    'customer_name' => $validated['customer_name'],
                    'customer_address' => $validated['customer_address'] ?? null,
                    'contact_number' => $validated['contact_number'] ?? null,
                    'pan_number' => $validated['pan_number'] ?? null,
                    'credit_days' => $validated['credit_days'] ?? null,
                    'ref_number' => $validated['ref_bill_number'] ?? null,
                    'invoice_number' => $validated['invoice_number'],
                    'invoice_date' => $validated['invoice_date'] ?? now(),
                    'invoice_date_bs' => $validated['invoice_date_bs'] ?? null,
                    'store_id' => $validated['store_id'] ?? null,
                    'location_id' => $validated['location_id'] ?? null,
                    'sub_total_before_discount' => $validated['sub_total_before_discount'] ?? 0,
                    'discount' => $validated['discount'] ?? 0,
                    'discount_after_vat' => $validated['discount_after_vat'] ?? 0,
                    'freight_charge' => $validated['freight_charge'] ?? 0,
                    'excise_duty' => $validated['excise_duty'] ?? 0,
                    'health_insurance' => $validated['health_insurance'] ?? 0,
                    'balance' => $validated['balance'] ?? 0,
                    'payment' => $validated['payment'] ?? null,
                    'promo_disc' => $validated['promo_disc'] ?? null,
                    'bill_amount' => $validated['bill_amount'] ?? null,
                    'final_amount' => $validated['ic_amount'] ?? null,
                    'ic_amount' => $validated['bill_amount'] ?? null,
                    'hold_discount' => $validated['hold_discount'] ?? null,
                    'tender' => $validated['tender'] ?? null,
                    'return' => $validated['return'] ?? null,
                    'taxable_amount' => $validated['taxable_amount'] ?? 0,
                    'non_taxable_amount' => $validated['non_taxable_amount'] ?? 0,
                    'ref_bill_number' => $validated['ref_bill_number'] ?? null,
                    'round_off_amount' => $validated['round_off_amount'] ?? 0,
                    'roundoff_type' => $validated['roundoff_type'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'abvt' => $validated['abvt'] ?? false,
                    'cash' => $validated['payment']['cash'] ?? 0,
                    'credit' => $validated['payment']['credit'] ?? 0,
                    'bank' => $validated['payment']['bank'] ?? 0,
                    'is_vatable' => $validated['is_vatable'] ?? false,
                    'total_amount' => $validated['total_amount'] ?? 0,
                    'purchase_id' => $validated['purchase_id'] ?? null,
                    'vat_amount' => $validated['vat_amount'] ?? null,
                    'purchase_bill_number' => $validated['purchase_bill_number'] ?? null,
                    'batch_no_sale' => $validated['batch_no_sale'] ?? null,
                ]);

             

                // Save products
                foreach ($validated['sale_products'] as $productData) {
                    $holdSaleProduct = $sale->holdSaleProducts()->create([
                        'product_id' => $productData['product_id'] ?? null,
                        'product_name' => $productData['product_name'] ?? null,
                        'purchase_stock_product_id' => $productData['purchase_stock_product_id'] ?? null,
                        'company_id' => $validated['company_id'] ?? null,
                        'branch_id' => $validated['branch_id'] ?? null,
                        'quantity' => $productData['quantity'] ?? null,
                        'free_quantity' => $productData['free_quantity'] ?? null,
                        'price' => $productData['price'],
                        'discount_percent' => $productData['discount_percent'] ?? 0,
                        'discount_amount' => $productData['discount_amount'] ?? 0,
                        'is_vatable' => $productData['is_vatable'] ?? false,
                        'measure_unit_id' => $productData['measure_unit_id'],
                        'batch_no' => $productData['batch_no'] ?? null,
                        'amount' => $productData['amount'] ?? 0,
                        'mfd' => $productData['mfd'] ?? null,
                        'expiry_date' => $productData['expiry_date'] ?? null,
                    ]);

                    // Save field values
                    if (!empty($productData['field_values'])) {
                        foreach ($productData['field_values'] as $fieldGroup) {
                            foreach ($fieldGroup as $field) {
                                $holdSaleProduct->fieldValues()->create([
                                    'company_id' => $validated['company_id'] ?? null,
                                    'branch_id' => $validated['branch_id'] ?? null,
                                    'product_id' => $field['product_id'],
                                    'product_field_id' => $field['product_field_id'],
                                    'value' => $field['value'],
                                    'quantity_index' => $field['quantity_index'],
                                    'quantity_type' => $field['quantity_type'] ?? null,
                                    'purchase_stock_product_id' => $field['purchase_stock_product_id'],

                                ]);
                            }
                        }
                    }
                }

                return $sale;
            });

            return response()->json([
                'message' => 'Hold sale created successfully',
                'sale' => $sale
            ], 201);

        } catch (ModelNotFoundException $e) {
          
            return response()->json(['error' => 'Resource not found'], 404);
        } catch (QueryException $e) {
           
            return response()->json(['error' => 'Database error occurred'], 500);
        } catch (\Exception $e) {
          
            return response()->json(['error' => 'Unexpected error occurred'], 500);
        }
    }


    public function show($id)
    {
        try {
            $holdSale = HoldSale::with([
                'holdSaleProducts.fieldValues'  // Nested relationship
            ])->findOrFail($id);

            return response()->json([
                'message' => 'Hold Sale retrieved successfully',
                'data' => $holdSale
            ], 200);

        } catch (ModelNotFoundException $e) {
           
            return response()->json([
                'message' => 'Hold Sale not found'
            ], 404);

        } catch (QueryException $e) {
          
            return response()->json([
                'message' => 'Database query error'
            ], 500);

        } catch (\Exception $e) {
           
            return response()->json([
                'message' => 'An error occurred while retrieving Hold Sale'
            ], 500);
        }
    }


    public function update(Request $request, $id): JsonResponse
    {
        try {
            $holdSale = HoldSale::where('company_id', $request->company_id)
                ->where('branch_id', $request->branch_id)
                ->findOrFail($id);

            $validator = Validator::make($request->all(), [
                'company_id' => 'required|integer',
                'customer_id' => 'nullable|integer|exists:customers,id',
                
                'customer_name' => 'required|string|max:255',
                'customer_address' => 'nullable|string|max:255',
                'contact_number' => 'nullable|string|max:255',
                'pan_number' => 'nullable|string|max:255',
                'credit_days' => 'nullable|string|max:255',
                'payment' => 'nullable|array',
                'payment.bank_name' => 'nullable|string',
                'payment.cash' => 'nullable|numeric|min:0',
                'payment.credit' => 'nullable|numeric|min:0',
                'payment.bank' => 'nullable|numeric|min:0',
                'invoice_number' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('hold_sales')
                        ->where(fn($query) => $query->where('company_id', $request->company_id)->whereNull('deleted_at'))
                        ->ignore($holdSale->id),
                ],
                'invoice_date' => 'nullable|date',
                'invoice_date_bs' => 'nullable|string|max:255',
                'store_id' => 'nullable|integer|exists:stores,id',
                'location_id' => 'nullable|integer|exists:locations,id',
                'sub_total_before_discount' => 'nullable|numeric|min:0',
                'discount' => 'nullable|numeric|min:0',
                'discount_after_vat' => 'nullable|numeric|min:0',
                'freight_charge' => 'nullable|numeric|min:0',
                'excise_duty' => 'nullable|numeric|min:0',
                'health_insurance' => 'nullable|numeric|min:0',
                'balance' => 'nullable|numeric|min:0',
                'taxable_amount' => 'nullable|numeric|min:0',
                'non_taxable_amount' => 'nullable|numeric|min:0',
                'ref_bill_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('sales', 'ref_number')
                        ->where(fn($query) => $query->where('company_id', $request->company_id)->whereNull('deleted_at')),
                ],
                'round_off_amount' => 'nullable|numeric|max:255',
                'roundoff_type' => 'nullable|string|max:255',
                'remarks' => 'nullable|string|max:255',
                'vat_amount' => 'nullable|numeric',
                'abvt' => 'nullable|boolean',
                'promo_disc' => 'nullable',
                'bill_amount' => 'nullable|numeric',
                'hold_discount' => 'nullable|numeric',
                'final_amount' => 'nullable|numeric',
                'ic_amount' => 'nullable|numeric',
                'tender' => 'nullable|numeric',
                'return' => 'nullable|numeric',
                'is_vatable' => 'nullable|boolean',
                'total_amount' => 'nullable|numeric|min:0',
                'sell_entire_batch' => 'nullable|boolean',
                'purchase_id' => 'nullable|integer|exists:purchases,id',
                'purchase_bill_number' => 'nullable|string|max:255',
                'batch_no_sale' => 'nullable|string|max:255',
                'sale_products' => ['required_unless:sell_entire_batch,true', 'array', 'min:1'],
                'sale_products.*.product_name' => 'required_without:sale_products.*.product_id|string|max:255',
                'sale_products.*.product_id' => 'nullable|integer|exists:products,id',
                'sale_products.*.purchase_stock_product_id' => 'nullable|integer|exists:purchase_stock_products,id',
             
                'sale_products.*.quantity' => 'nullable|string',
                'sale_products.*.free_quantity' => 'nullable|string',
                'sale_products.*.price' => 'required|numeric|min:0',
                'sale_products.*.discount_percent' => 'nullable|numeric|min:0|max:100',
                'sale_products.*.discount_amount' => 'nullable|numeric|min:0',
                'sale_products.*.is_vatable' => 'nullable|boolean',
                'sale_products.*.measure_unit_id' => 'required|integer|exists:measure_units,id',
                'sale_products.*.batch_no' => 'nullable|string|max:255',
                'sale_products.*.amount' => 'nullable|numeric|min:0',
                'sale_products.*.mfd' => 'nullable|string|max:255',
                'sale_products.*.expiry_date' => 'nullable|string|max:255',
                'sale_products.*.field_values' => 'present|array',
                'sale_products.*.field_values.*' => 'array|min:1',
                'sale_products.*.field_values.*.*.product_field_id' => 'required_if:sale_products.*.field_values,array|integer|exists:product_fields,id',
                'sale_products.*.field_values.*.*.product_id' => 'nullable',
                'sale_products.*.field_values.*.*.value' => 'required_if:sale_products.*.field_values,array|string|max:255',
                'sale_products.*.field_values.*.*.quantity_index' => 'required_if:sale_products.*.field_values,array|integer|min:0',
                'sale_products.*.field_values.*.*.quantity_type' => 'nullable|string|in:regular,free',
                'sale_products.*.field_values.*.*.purchase_stock_product_id' => 'required_if:sale_products.*.field_values,array|integer|exists:purchase_stock_products,id',
                'sale_products.*.field_values.*.*.purchase_product_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_product_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_adjustment_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_reconciliation_id' => 'nullable',
                'sale_products.*.field_values.*.*.stock_transfer_id' => 'nullable',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'message' => $validator->errors()->first(),
                    'errors' => $validator->errors()
                ], 422);
            }

            $validated = $validator->validated();
            $validated['branch_id'] = $request->branch_id;

            DB::transaction(function () use ($holdSale, $validated) {
                // Update main hold sale
                $holdSale->update([
                    'customer_id' => $validated['customer_id'] ?? null,
                   
                    'customer_name' => $validated['customer_name'],
                    'customer_address' => $validated['customer_address'] ?? null,
                    'contact_number' => $validated['contact_number'] ?? null,
                    'pan_number' => $validated['pan_number'] ?? null,
                    'credit_days' => $validated['credit_days'] ?? null,
                    'ref_number' => $validated['ref_bill_number'] ?? null,
                    'invoice_number' => $validated['invoice_number'],
                    'invoice_date' => $validated['invoice_date'] ?? now(),
                    'invoice_date_bs' => $validated['invoice_date_bs'] ?? null,
                    'store_id' => $validated['store_id'] ?? null,
                    'promo_disc' => $validated['promo_disc'] ?? null,
                    'bill_amount' => $validated['bill_amount'] ?? null,
                    'final_amount' => $validated['ic_amount'] ?? null,
                    'ic_amount' => $validated['bill_amount'] ?? null,
                    'hold_discount' => $validated['hold_discount'] ?? null,
                    'tender' => $validated['tender'] ?? null,
                    'return' => $validated['return'] ?? null,
                    'location_id' => $validated['location_id'] ?? null,
                    'sub_total_before_discount' => $validated['sub_total_before_discount'] ?? 0,
                    'discount' => $validated['discount'] ?? 0,
                    'discount_after_vat' => $validated['discount_after_vat'] ?? 0,
                    'freight_charge' => $validated['freight_charge'] ?? 0,
                    'excise_duty' => $validated['excise_duty'] ?? 0,
                    'health_insurance' => $validated['health_insurance'] ?? 0,
                    'balance' => $validated['balance'] ?? 0,
                    'payment' => $validated['payment'] ?? null,
                    'taxable_amount' => $validated['taxable_amount'] ?? 0,
                    'non_taxable_amount' => $validated['non_taxable_amount'] ?? 0,
                    'round_off_amount' => $validated['round_off_amount'] ?? 0,
                    'roundoff_type' => $validated['roundoff_type'] ?? null,
                    'remarks' => $validated['remarks'] ?? null,
                    'abvt' => $validated['abvt'] ?? false,
                    'cash' => $validated['payment']['cash'] ?? 0,
                    'credit' => $validated['payment']['credit'] ?? 0,
                    'bank' => $validated['payment']['bank'] ?? 0,
                    'is_vatable' => $validated['is_vatable'] ?? false,
                    'total_amount' => $validated['total_amount'] ?? 0,
                    'purchase_id' => $validated['purchase_id'] ?? null,
                    'vat_amount' => $validated['vat_amount'] ?? null,
                    'purchase_bill_number' => $validated['purchase_bill_number'] ?? null,
                    'batch_no_sale' => $validated['batch_no_sale'] ?? null,
                    'sell_entire_batch' => $validated['sell_entire_batch'] ?? false,
                ]);

                // Delete old products and field values
                foreach ($holdSale->holdSaleProducts as $product) {
                    $product->fieldValues()->delete();
                }
                $holdSale->holdSaleProducts()->delete();

                // Create updated products and field values
                foreach ($validated['sale_products'] as $productData) {
                    $holdSaleProduct = $holdSale->holdSaleProducts()->create([
                        'company_id' => $validated['company_id'],
                        'branch_id' => $validated['branch_id'],
                        'product_id' => $productData['product_id'] ?? null,
                        'product_name' => $productData['product_name'] ?? null,
                        'purchase_stock_product_id' => $productData['purchase_stock_product_id'] ?? null,
                        
                        'quantity' => $productData['quantity'] ?? null,
                        'free_quantity' => $productData['free_quantity'] ?? null,
                        'price' => $productData['price'],
                        'discount_percent' => $productData['discount_percent'] ?? 0,
                        'discount_amount' => $productData['discount_amount'] ?? 0,
                        'is_vatable' => $productData['is_vatable'] ?? false,
                        'measure_unit_id' => $productData['measure_unit_id'],
                        'batch_no' => $productData['batch_no'] ?? null,
                        'amount' => $productData['amount'] ?? 0,
                        'mfd' => $productData['mfd'] ?? null,
                        'expiry_date' => $productData['expiry_date'] ?? null,
                    ]);

                    if (!empty($productData['field_values'])) {
                        foreach ($productData['field_values'] as $fieldGroup) {
                            foreach ($fieldGroup as $field) {
                                $holdSaleProduct->fieldValues()->create([
                                    'company_id' => $validated['company_id'],
                                    'branch_id' => $validated['branch_id'],
                                    'product_id' => $field['product_id'] ?? null,
                                    'product_field_id' => $field['product_field_id'],
                                    'value' => $field['value'],
                                    'quantity_index' => $field['quantity_index'],
                                    'quantity_type' => $field['quantity_type'] ?? null,
                                    'purchase_stock_product_id' => $field['purchase_stock_product_id'],
                                  
                                ]);
                            }
                        }
                    }
                }
            });

            $holdSale->load('holdSaleProducts.fieldValues');

            return response()->json([
                'message' => 'Hold sale updated successfully',
                'data' => $holdSale
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Delete a HoldSale + all its products + all field values.
     */
    public function destroy($id): JsonResponse
    {
        try {

            $holdSale = HoldSale::with(['holdSaleProducts.fieldValues'])
                ->findOrFail($id);

            DB::transaction(function () use ($holdSale) {

                $holdSale->holdSaleProducts->each(function ($product) {
                    $product->fieldValues()->delete();   // soft-delete or hard-delete
                });


                $holdSale->holdSaleProducts()->delete();


                $holdSale->delete();
            });

            return response()->json([
                'success' => true,
                'message' => 'Hold sale and all related products & field values deleted successfully!'
            ], 200);

        } catch (ModelNotFoundException $e) {
          

            return response()->json([
                'error' => 'not_found',
                'message' => 'Hold sale not found!',
            ], 404);

        } catch (QueryException $e) {
          

            return response()->json([
                'error' => 'query_error',
                'message' => 'A database error occurred while deleting the hold sale.',
            ], 500);

        } catch (\Exception $e) {
           

            

            return response()->json([
                'error' => 'unexpected_error',
                'message' => 'An unexpected error occurred while deleting the hold sale.',
            ], 500);
        }
    }

}

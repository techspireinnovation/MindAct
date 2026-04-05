<?php

namespace App\Repositories;
use App\Services\CurrencyFormatService;
use Illuminate\Support\Facades\DB;
use App\Models\Stock;
use App\Models\StockProduct;
use App\Services\TransactionImplementService;
use App\Models\Vat;
use App\Models\StockTransaction;
use App\Models\TransactionPivot;
use App\Models\StockMovement;
use App\Models\FiscalYear;
use Illuminate\Support\Facades\Log;
use App\Services\UnitConversionService;
use App\Services\QuantityAllocationService;
use App\Models\StockProductFieldValue;
use Illuminate\Support\Facades\Cache;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

use App\Interfaces\StockPurchaseReturnItemWiseRepositoryInterface;

class StockPurchaseReturnItemWiseRepository implements StockPurchaseReturnItemWiseRepositoryInterface
{

    protected $unitConversionService;
    protected $quantityAllocationService;

    protected $currencyFormatService;
    protected $taxImplementService;



    public function __construct(UnitConversionService $unitConversionService, QuantityAllocationService $quantityAllocationService, CurrencyFormatService $currencyFormatService, TransactionImplementService $taxImplementService)
    {
        $this->unitConversionService = $unitConversionService;
        $this->quantityAllocationService = $quantityAllocationService;
        $this->currencyFormatService = $currencyFormatService;
        $this->taxImplementService = $taxImplementService;

    }






    public function create(array $data)
    {
        DB::beginTransaction();


        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        //@todo: validate the requests and make sure the return quantities are not greater than the purchased quantities for each product

        //@todo: use the currency formatters to handle the amount fields in the payload

        //@todo: generate bill numbers for this and it should be unique for the company and bill type

        $appliedVat = Vat::where('is_active', 1)->pluck('vat_percent')->first() ?? 0;

        $totalAmount = $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0;

        $taxableAmount = $this->currencyFormatService->cleanCurrency($data['taxable_amount'] ?? 0) ?? 0;

        $vatAmount = $this->taxImplementService->transactionImplement($appliedVat ?? 0, $taxableAmount) ?? 0;


        $stockData = [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'store_id' => $data['store_id'] ?? null,
            'type' => 'purchase_return',

            'bill_number' => $data['bill_number'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'party_id' => $data['party_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'batch_no' => $data['batch_no'] ?? null,
            'credit_days' => $data['credit_days'] ?? null,
            'balance' => $this->currencyFormatService->cleanCurrency($data['balance'] ?? 0) ?? 0,
            'ref_bill_number' => $data['ref_bill_number'] ?? null,
            'return_bill_no' => $data['return_bill_no'] ?? null,
            'reasons' => $data['reasons'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $this->currencyFormatService->cleanCurrency($data['discount_value'] ?? 0) ?? 0,
            'discount_after_vat' => $this->currencyFormatService->cleanCurrency($data['discount_after_vat'] ?? 0) ?? 0,
            'sub_total_before_discount' => $this->currencyFormatService->cleanCurrency($data['sub_total_before_discount'] ?? 0) ?? 0,
            'taxable_amount' => $this->currencyFormatService->cleanCurrency($data['taxable_amount'] ?? 0) ?? 0,
            'non_taxable_amount' => $this->currencyFormatService->cleanCurrency($data['non_taxable_amount'] ?? 0) ?? 0,
            'excise_duty' => $this->currencyFormatService->cleanCurrency($data['excise_duty'] ?? 0) ?? 0,
            'vat_percent' => $this->currencyFormatService->cleanCurrency($data['vat_percent'] ?? 0) ?? 0,
            'health_insurance' => $this->currencyFormatService->cleanCurrency($data['health_insurance'] ?? 0) ?? 0,
            'freight_amount' => $this->currencyFormatService->cleanCurrency($data['freight_amount'] ?? 0) ?? 0,
            'roundoff_type' => $data['roundoff_type'] ?? null,
            'roundoff_amount' => $this->currencyFormatService->cleanCurrency($data['roundoff_amount'] ?? 0) ?? 0,

            'total_amount' => $totalAmount + $vatAmount,
            'payment' => isset($data['payment']) ? json_encode($data['payment']) : null,
            'remarks' => $data['remarks'] ?? null,

        ];


        $stock = Stock::create($stockData);


        foreach ($data['stock_transactions'] as $product) {

            $fieldValues = $product['field_values'] ?? [];
            //@todo: handle case when field values are not present


            if (empty($fieldValues)) {

                $baseQuantity = $this->unitConversionService->convertToBaseUnit(
                    $product['measure_unit_id'],
                    $product['quantity']
                );

                //@todo: allocate the quantity correctly based on the avaialble purhcased products

                //@todo: also keep track of alreay used quanitity inside the payload to avoid reusing same quantity for multiple return products

                $allocatedQtys = $this->quantityAllocationService->allocateItemWiseWiseQuantity(
                    $product['product_id'],
                    $baseQuantity
                );

                $totalAllocated = collect($allocatedQtys)->sum('quantity');

                if ($totalAllocated < $baseQuantity) {
                    DB::rollBack();
                    throw new Exception('Return quantity cannot be greater than purchased quantity for product ID: ' . $product['product_id']);
                }

                $transactionMap = [];

                foreach ($allocatedQtys as $alloc) {
                    $transactionData = [
                        'stock_id' => $stock->id,
                        'fiscal_year_id' => $fiscalYearId,
                        'source_type' => $alloc['source_type'] ?? null,
                        'source_id' => $alloc['source_id'] ?? null,
                        'stock_product_id' => $alloc['stock_product_id'] ?? null,
                        'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,
                        'product_id' => $product['product_id'],
                        'measure_unit_id' => $product['measure_unit_id'],
                        'type' => 'purchase_return',
                        'quantity' => $alloc['quantity'],
                        'is_vatable' => $product['is_vatable'],
                        'stock_type' => 'regular',
                        'company_id' => $data['company_id'],
                        'branch_id' => $data['branch_id'],
                        'direction' => 'out',
                        'party_id' => $data['party_id'] ?? null,
                        'expiry_date' => $product['expiry_date'] ?? null,
                        'mfd' => $product['mfd'] ?? null,
                        'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                        'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                        'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                        'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                        'batch_no' => $product['batch_no'] ?? null,

                    ];
                    $transaction = StockTransaction::create($transactionData);

                    $transactionMap[$alloc['stock_product_id']] = $transaction;
                }


                if (!empty($product['free_quantity']) && $product['free_quantity'] > 0) {
                    $freeQuantity = $this->unitConversionService->convertToBaseUnit(
                        $product['measure_unit_id'],
                        $product['free_quantity']
                    );

                    $freeAllocated = $this->quantityAllocationService->allocateItemWiseWiseQuantity(
                        $product['product_id'],
                        $freeQuantity
                    );

                    foreach ($freeAllocated as $alloc) {
                        $relatedTransaction = $transactionMap[$alloc['stock_product_id']] ?? null;

                        $movementData = [
                            'stock_id' => $stock->id,
                            'source_type' => $alloc['source_type'] ?? null,
                            'source_id' => $alloc['source_id'] ?? null,
                            'stock_transaction_id' => $transaction?->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $alloc['quantity'],
                            'stock_type' => 'free',
                            'is_vatable' => $product['is_vatable'],
                            'direction' => 'out',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => null,
                            'discount_percent' => null,
                            'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                            'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $alloc['stock_product_id'] ?? null,

                        ];

                        StockMovement::create($movementData);
                    }
                }

            } else {
                //@todo: handle the case when field values are present and make sure the quantity allocation is done based on the field values and also the transaction pivots are created correctly based on the field values

                $grouped = [];

                foreach ($product['field_values'] as $group) {
                    foreach ($group as $field) {
                        $stockProductId = $field['stock_product_id'];
                        $stockMovementId = $field['stock_movement_id'] ?? null;
                        $quantityType = $field['quantity_type'] ?? 'regular';

                        if (!isset($grouped[$stockProductId])) {
                            $grouped[$stockProductId] = [
                                'stock_movement_id' => $stockMovementId,
                                'regular' => ['quantity' => 0, 'fields' => []],
                                'free' => ['quantity' => 0, 'fields' => []],
                            ];
                        }

                        $grouped[$stockProductId][$quantityType]['quantity']++;
                        $grouped[$stockProductId][$quantityType]['fields'][] = $field;
                    }
                }

                foreach ($grouped as $stockProductId => $types) {
                    $stockTransaction = null;
                    $stockMovement = null;

                    $stockMovementId = $types['stock_movement_id'] ?? null;

                    $sourceType = $stockMovementId ? 'stock_movement' : 'stock_product';
                    $sourceId = $stockMovementId ? $stockMovementId : $stockProductId;


                    if ($types['regular']['quantity'] > 0) {

                        $stockTransactionData = [
                            'stock_id' => $stock->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'source_type' => $sourceType,
                            'source_id' => $sourceId,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $types['regular']['quantity'],
                            'stock_type' => 'regular',
                            'direction' => 'out',
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'stock_product_id' => $stockProductId,

                            // 'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,


                            'is_vatable' => $product['is_vatable'],

                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                            'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                            'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                            'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                        ];
                        $stockTransaction = StockTransaction::create($stockTransactionData);
                    }


                    if ($types['free']['quantity'] > 0) {
                        // if (!$stockTransaction) {
                        //     throw new Exception("Free exists without regular for stock_product_id: " . $stockProductId);
                        // }

                        $movementData = [
                            'stock_id' => $stock->id,
                            'stock_transaction_id' => $stockTransaction->id ?? null,
                            'fiscal_year_id' => $fiscalYearId,
                            'source_type' => $sourceType,
                            'source_id' => $sourceId,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $types['free']['quantity'],
                            'stock_type' => 'free',
                            'direction' => 'out',
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'stock_product_id' => $stockProductId,


                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                            'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                            'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                            'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                        ];

                        $stockMovement = StockMovement::create($movementData);
                    }


                    foreach ($types['regular']['fields'] as $field) {

                        $transactionPivotData = [
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'type' => 'purchase_return',
                            'direction' => 'out',
                            'stock_product_id' => $stockProductId,
                            'stock_transaction_id' => $stockTransaction?->id,
                            'stock_movement_id' => null,
                            'product_id' => $product['product_id'],
                            'quantity_index' => $field['quantity_index'],
                            'quantity_type' => 'regular',
                        ];
                        TransactionPivot::create($transactionPivotData);
                    }


                    foreach ($types['free']['fields'] as $field) {
                        $transactionsPivotData = [
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'type' => 'purchase_return',
                            'direction' => 'out',
                            'stock_product_id' => $stockProductId,
                            'stock_transaction_id' => $stockTransaction?->id,
                            'stock_movement_id' => $stockMovement?->id,
                            'product_id' => $product['product_id'],
                            'quantity_index' => $field['quantity_index'],
                            'quantity_type' => 'free',
                        ];
                        TransactionPivot::create($transactionsPivotData);
                    }
                }
            }
        }

        DB::commit();

        return $stock->load('stockTransactions');

    }




    public function update($id, array $data)
    {
        DB::beginTransaction();


        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        $totalAmount = $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0;

        $taxableAmount = $this->currencyFormatService->cleanCurrency($data['taxable_amount'] ?? 0) ?? 0;

        $vatAmount = $this->taxImplementService->transactionImplement($appliedVat ?? 0, $taxableAmount) ?? 0;

        $stockValidated = [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'store_id' => $data['store_id'] ?? null,
            'type' => 'purchase_return',

            'bill_number' => $data['bill_number'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'party_id' => $data['party_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'batch_no' => $data['batch_no'] ?? null,
            'credit_days' => $data['credit_days'] ?? null,
            'balance' => $this->currencyFormatService->cleanCurrency($data['balance'] ?? 0) ?? 0,
            'ref_bill_number' => $data['ref_bill_number'] ?? null,
            'return_bill_no' => $data['return_bill_no'] ?? null,
            'reasons' => $data['reasons'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $this->currencyFormatService->cleanCurrency($data['discount_value'] ?? 0) ?? 0,
            'discount_after_vat' => $this->currencyFormatService->cleanCurrency($data['discount_after_vat'] ?? 0) ?? 0,
            'sub_total_before_discount' => $this->currencyFormatService->cleanCurrency($data['sub_total_before_discount'] ?? 0) ?? 0,
            'taxable_amount' => $this->currencyFormatService->cleanCurrency($data['taxable_amount'] ?? 0) ?? 0,
            'non_taxable_amount' => $this->currencyFormatService->cleanCurrency($data['non_taxable_amount'] ?? 0) ?? 0,
            'excise_duty' => $this->currencyFormatService->cleanCurrency($data['excise_duty'] ?? 0) ?? 0,
            'vat_percent' => $this->currencyFormatService->cleanCurrency($data['amount'] ?? 0) ?? 0,
            'health_insurance' => $this->currencyFormatService->cleanCurrency($data['health_insurance'] ?? 0) ?? 0,
            'freight_amount' => $this->currencyFormatService->cleanCurrency($data['freight_amount'] ?? 0) ?? 0,
            'roundoff_type' => $data['roundoff_type'] ?? null,
            'roundoff_amount' => $this->currencyFormatService->cleanCurrency($data['roundoff_amount'] ?? 0) ?? 0,
            'total_amount' => $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0,
            'payment' => isset($data['payment']) ? json_encode($data['payment']) : null,
            'remarks' => $data['remarks'] ?? null,
        ];

        $stock = Stock::where('type', 'purchase_return')
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $stock->update($stockValidated);

        $oldTransactionIds = StockTransaction::where('stock_id', $stock->id)->pluck('id');
        $oldMovementIds = StockMovement::where('stock_id', $stock->id)->pluck('id');


        TransactionPivot::whereIn('stock_transaction_id', $oldTransactionIds)
            ->orWhereIn('stock_movement_id', $oldMovementIds)
            ->delete();


        StockTransaction::where('stock_id', $stock->id)->delete();
        StockMovement::where('stock_id', $stock->id)->delete();
        foreach ($data['stock_transactions'] as $product) {

            $fieldValues = $product['field_values'] ?? [];


            if (empty($fieldValues)) {

                $baseQuantity = $this->unitConversionService->convertToBaseUnit(
                    $product['measure_unit_id'],
                    $product['quantity']
                );

                $allocatedQtys = $this->quantityAllocationService->allocateItemWiseWiseQuantity(
                    $product['product_id'],
                    $baseQuantity
                );

                $totalAllocated = collect($allocatedQtys)->sum('quantity');

                if ($totalAllocated < $baseQuantity) {
                    DB::rollBack();
                    throw new Exception('Return quantity cannot be greater than purchased quantity for product ID: ' . $product['product_id']);
                }

                $transactionMap = [];

                foreach ($allocatedQtys as $alloc) {

                    $stockTransactionData = [
                        'stock_id' => $stock->id,
                        'fiscal_year_id' => $fiscalYearId,
                        'stock_product_id' => $alloc['stock_product_id'] ?? null,
                        'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,
                        'product_id' => $product['product_id'],
                        'measure_unit_id' => $product['measure_unit_id'],
                        'type' => 'purchase_return',
                        'quantity' => $alloc['quantity'],
                        'is_vatable' => $product['is_vatable'],
                        'stock_type' => 'regular',
                        'company_id' => $data['company_id'],
                        'branch_id' => $data['branch_id'],
                        'direction' => 'out',
                        'party_id' => $data['party_id'] ?? null,
                        'expiry_date' => $product['expiry_date'] ?? null,
                        'mfd' => $product['mfd'] ?? null,
                        'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                        'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                        'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                        'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                        'batch_no' => $product['batch_no'] ?? null,
                    ];
                    $transaction = StockTransaction::create($stockTransactionData);

                    $transactionMap[$alloc['stock_product_id']] = $transaction;
                }


                if (!empty($product['free_quantity']) && $product['free_quantity'] > 0) {
                    $freeQuantity = $this->unitConversionService->convertToBaseUnit(
                        $product['measure_unit_id'],
                        $product['free_quantity']
                    );

                    $freeAllocated = $this->quantityAllocationService->allocateItemWiseWiseQuantity(
                        $product['product_id'],
                        $freeQuantity
                    );

                    foreach ($freeAllocated as $alloc) {
                        $relatedTransaction = $transactionMap[$alloc['stock_product_id']] ?? null;

                        $movementValidatedData = [
                            'stock_id' => $stock->id,
                            'stock_transaction_id' => $transaction?->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $alloc['quantity'],
                            'stock_type' => 'free',
                            'is_vatable' => $product['is_vatable'],
                            'direction' => 'out',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                            'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                            'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                            'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $alloc['stock_product_id'] ?? null,

                        ];
                        StockMovement::create($movementValidatedData);
                    }
                }

            } else {

                $grouped = [];

                foreach ($product['field_values'] as $group) {
                    foreach ($group as $field) {
                        $stockProductId = $field['stock_product_id'];
                        $quantityType = $field['quantity_type'] ?? 'regular';

                        if (!isset($grouped[$stockProductId])) {
                            $grouped[$stockProductId] = [
                                'regular' => ['quantity' => 0, 'fields' => []],
                                'free' => ['quantity' => 0, 'fields' => []],
                            ];
                        }

                        $grouped[$stockProductId][$quantityType]['quantity']++;
                        $grouped[$stockProductId][$quantityType]['fields'][] = $field;
                    }
                }

                foreach ($grouped as $stockProductId => $types) {
                    $stockTransaction = null;
                    $stockMovement = null;


                    if ($types['regular']['quantity'] > 0) {

                        $transactionStockData = [
                            'stock_id' => $stock->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $types['regular']['quantity'],
                            'stock_type' => 'regular',
                            'direction' => 'out',
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'stock_product_id' => $stockProductId,

                            'stock_movement_id' => $alloc['source_type'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,


                            'is_vatable' => $product['is_vatable'],

                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                            'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                            'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                            'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,

                        ];
                        $stockTransaction = StockTransaction::create($transactionStockData);
                    }


                    if ($types['free']['quantity'] > 0) {
                        // if (!$stockTransaction) {
                        //     throw new Exception("Free exists without regular for stock_product_id: " . $stockProductId);
                        // }

                        $movementTransactionData = [
                            'stock_id' => $stock->id,
                            'stock_transaction_id' => $stockTransaction->id ?? null,
                            'fiscal_year_id' => $fiscalYearId,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $types['free']['quantity'],
                            'stock_type' => 'free',
                            'direction' => 'out',
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'stock_product_id' => $stockProductId,


                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                            'discount_percent' => $this->currencyFormatService->cleanCurrency($product['discount_percent'] ?? 0) ?? 0,
                            'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                            'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                        ];
                        $stockMovement = StockMovement::create($movementTransactionData);
                    }


                    foreach ($types['regular']['fields'] as $field) {

                        $transactionPivotData = [
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'type' => 'purchase_return',
                            'direction' => 'out',
                            'stock_product_id' => $stockProductId,
                            'stock_transaction_id' => $stockTransaction?->id,
                            'stock_movement_id' => null,
                            'product_id' => $product['product_id'],
                            'quantity_index' => $field['quantity_index'],
                            'quantity_type' => 'regular',
                        ];
                        TransactionPivot::create($transactionPivotData);
                    }


                    foreach ($types['free']['fields'] as $field) {

                        $transactionsPivotData = [
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'type' => 'purchase_return',
                            'direction' => 'out',
                            'stock_product_id' => $stockProductId,
                            'stock_transaction_id' => $stockTransaction?->id ?? null,
                            'stock_movement_id' => $stockMovement?->id,
                            'product_id' => $product['product_id'],
                            'quantity_index' => $field['quantity_index'],
                            'quantity_type' => 'free',
                        ];
                        TransactionPivot::create($transactionsPivotData);
                    }
                }
            }
        }

        DB::commit();

        return $stock->load('stockTransactions');



    }




    public function list(array $filters)
    {
        return Stock::where('type', 'purchase_return')
            ->whereNull('deleted_at')
            ->get();

    }

    public function show($id)
    {
        $stock = Stock::with([
            'stockTransactions' => function ($query) {
                $query->whereNull('deleted_at')
                    ->with([
                        'transactionPivots' => function ($q) {
                            $q->whereNull('deleted_at');
                        }
                    ]);
            },
            'stockMovements' => function ($query) {
                $query->where('type', 'purchase_return')
                    ->where('stock_type', 'free')
                    ->whereNull('deleted_at');
            }
        ])
            ->whereNull('deleted_at')
            ->where('type', 'purchase_return')
            ->findOrFail($id);

        $mergedProducts = [];

        foreach ($stock->stockTransactions as $transaction) {

            $relatedMovements = $stock->stockMovements->filter(function ($movement) use ($transaction) {
                return $movement->product_id === $transaction->product_id
                    && $movement->measure_unit_id === $transaction->measure_unit_id;
            });

            $freeQty = $relatedMovements->sum('quantity');

            $fieldValues = $transaction->transactionPivots->toArray();


            $key = $transaction->product_id . '_' . $transaction->measure_unit_id;

            if (!isset($mergedProducts[$key])) {
                $mergedProducts[$key] = [
                    'product_id' => $transaction->product_id,
                    'measure_unit_id' => $transaction->measure_unit_id,
                    'quantity' => $transaction->quantity,
                    'free_quantity' => $freeQty,
                    'price' => $transaction->price,
                    'discount_percent' => $transaction->discount_percent,
                    'discount_amount' => $transaction->discount_amount,
                    'amount' => $transaction->amount,
                    'batch_no' => $transaction->batch_no,
                    'expiry_date' => $transaction->expiry_date,
                    'mfd' => $transaction->mfd,
                    'field_values' => $fieldValues,
                ];
            } else {
                $mergedProducts[$key]['quantity'] += $transaction->quantity;
                $mergedProducts[$key]['free_quantity'] += $freeQty;
                $mergedProducts[$key]['field_values'] = array_merge(
                    $mergedProducts[$key]['field_values'],
                    $fieldValues
                );
            }
        }


        $stock->stock_transactions = array_values($mergedProducts);
        unset($stock->stockTransactions);
        unset($stock->stockMovements);

        return $stock;
    }


    public function delete($id)
    {

        Db::beginTransaction();

        $stock = Stock::where('type', 'purchase_return')
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $stockTransactionIds = StockTransaction::where('stock_id', $stock->id)
            ->whereNull('deleted_at')
            ->pluck('id');
        $stockMovementIds = StockMovement::where('stock_id', $stock->id)
            ->whereNull('deleted_at')
            ->pluck('id');

        TransactionPivot::whereIn('stock_transaction_id', $stockTransactionIds)
            ->whereNull('deleted_at')
            ->delete();

        TransactionPivot::whereIn('stock_movement_id', $stockMovementIds)
            ->whereNull('deleted_at')
            ->delete();

        StockMovement::where('stock_id', $stock->id)
            ->where('type', 'purchase_return')
            ->where('stock_type', 'free')
            ->whereNull('deleted_at')
            ->delete();

        StockTransaction::where('stock_id', $stock->id)
            ->delete();

        $stock->delete();

        DB::commit();
        return true;



    }
}

?>
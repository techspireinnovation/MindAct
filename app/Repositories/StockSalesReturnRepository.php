<?php

namespace App\Repositories;
use Illuminate\Support\Facades\DB;
use App\Models\Stock;
use App\Models\StockProduct;
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

use App\Interfaces\StockSalesReturnRepositoryInterface;

class StockSalesReturnRepository implements StockSalesReturnRepositoryInterface
{

    protected $unitConversionService;
    protected $quantityAllocationService;

    public function __construct(UnitConversionService $unitConversionService, QuantityAllocationService $quantityAllocationService)
    {
        $this->unitConversionService = $unitConversionService;
        $this->quantityAllocationService = $quantityAllocationService;

    }








    public function create(array $data)
    {
        DB::beginTransaction();

        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        $salesBillNumber = $data['sales_bill_number'] ?? null;



        $salesStock = Stock::where('bill_number', $salesBillNumber)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->first();

        if (!$salesStock) {
            DB::rollBack();
            throw new Exception('Sales bill number is required for creating a sales return.');
        }

        $stockData = [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'store_id' => $data['store_id'] ?? null,
            'type' => 'sales_return',

            'bill_number' => $data['bill_number'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'party_id' => $data['party_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'batch_no' => $data['batch_no'] ?? null,
            'credit_days' => $data['credit_days'] ?? null,
            'balance' => $data['balance'] ?? 0,
            'ref_bill_number' => $data['ref_bill_number'] ?? null,
            'return_bill_no' => $salesBillNumber,
            'reasons' => $data['reasons'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $data['discount_value'] ?? 0,
            'discount_after_vat' => $data['discount_after_vat'] ?? 0,
            'sub_total_before_discount' => $data['sub_total_before_discount'] ?? 0,
            'taxable_amount' => $data['taxable_amount'] ?? 0,
            'non_taxable_amount' => $data['non_taxable_amount'] ?? 0,
            'excise_duty' => $data['excise_duty'] ?? 0,
            'vat_percent' => $data['vat_percent'] ?? 0,
            'health_insurance' => $data['health_insurance'] ?? 0,
            'freight_amount' => $data['freight_amount'] ?? 0,
            'roundoff_type' => $data['roundoff_type'] ?? null,
            'roundoff_amount' => $data['roundoff_amount'] ?? 0,
            'total_amount' => $data['total_amount'] ?? 0,
            'payment' => $data['payment'] ?? null,
            'remarks' => $data['remarks'] ?? null,

        ];


        $stock = Stock::create($stockData);


        foreach ($data['stock_transactions'] as $product) {

            $fieldValues = $product['field_values'] ?? [];


            if (empty($fieldValues)) {

                $regularBaseQty = $this->unitConversionService
                    ->convertToBaseUnit(
                        $product['measure_unit_id'],
                        $product['quantity'] ?? 0
                    );

                $freeBaseQty = $this->unitConversionService
                    ->convertToBaseUnit(
                        $product['measure_unit_id'],
                        $product['free_quantity'] ?? 0
                    );

                $totalReturnQty = $regularBaseQty + $freeBaseQty;

                if ($totalReturnQty <= 0) {
                    continue;
                }


                $allocatedRows = $this->quantityAllocationService
                    ->allocateSalesReturnQuantity(
                        $salesStock->id,
                        $product['product_id'],
                        $totalReturnQty
                    );

                $allocatedTotal = collect($allocatedRows)->sum('quantity');

                if ($allocatedTotal < $totalReturnQty) {
                    throw new Exception(
                        'Return quantity exceeds sold quantity for product ID: '
                        . $product['product_id']
                    );
                }

                $regularRemaining = $regularBaseQty;
                $freeRemaining = $freeBaseQty;

                foreach ($allocatedRows as $alloc) {

                    $available = $alloc['quantity'];


                    if ($regularRemaining > 0) {

                        $consume = min($regularRemaining, $available);

                        $validatedTransactionData = [
                            'stock_id' => $stock->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'source_id' => $alloc['source_id'],
                            'source_type' => $alloc['source_type'],
                            'stock_product_id' => $alloc['stock_product_id'],
                            'stock_movement_id' =>
                                $alloc['source_type'] === 'stock_movement'
                                ? $alloc['source_id']
                                : null,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'sales_return',
                            'direction' => 'in',
                            'quantity' => $consume,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'party_id' => $data['party_id'] ?? null,
                            'sales_bill_number' => $data['bill_number'] ?? null,
                            'is_vatable' => $product['is_vatable'],
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'batch_no' => $product['batch_no'] ?? null,

                        ];

                        $transaction = StockTransaction::create($validatedTransactionData);

                        $regularRemaining -= $consume;
                        $available -= $consume;
                    }


                    if ($available > 0 && $freeRemaining > 0) {

                        $consume = min($freeRemaining, $available);

                        $movementValidated = [
                            'stock_id' => $stock->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'stock_transaction_id' => $transaction->id ?? null,
                            'source_id' => $alloc['source_id'],
                            'source_type' => $alloc['source_type'],
                            'stock_product_id' => $alloc['stock_product_id'],
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'sales_return',
                            'stock_type' => 'free',
                            'direction' => 'in',
                            'quantity' => $consume,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'party_id' => $data['party_id'] ?? null,
                            'sales_bill_number' => $data['bill_number'] ?? null,
                            'is_vatable' => $product['is_vatable'],
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'batch_no' => $product['batch_no'] ?? null,
                        ];

                        StockMovement::create($movementValidated);

                        $freeRemaining -= $consume;
                    }
                }


            } else {

                $grouped = [];

                foreach ($product['field_values'] as $group) {
                    foreach ($group as $field) {
                        $stockProductId = $field['stock_product_id'];
                        $stockTransactionId = $field['stock_transaction_id'] ?? null;
                        $stockMovementId = $field['stock_movement_id'] ?? null;
                        $quantityType = $field['quantity_type'] ?? 'regular';


                        if ($stockTransactionId && $stockMovementId) {
                            $sourceId = $stockMovementId;
                            $sourceType = 'stock_movement';
                        } elseif ($stockTransactionId) {
                            $sourceId = $stockTransactionId;
                            $sourceType = 'stock_transaction';
                        } elseif ($stockMovementId) {
                            $sourceId = $stockMovementId;
                            $sourceType = 'stock_movement';
                        } else {
                            $sourceId = null;
                            $sourceType = null;
                        }


                        $groupKey = $stockProductId . '_' . ($sourceId ?? 'null') . '_' . ($sourceType ?? 'null');

                        if (!isset($grouped[$groupKey])) {
                            $grouped[$groupKey] = [
                                'stock_product_id' => $stockProductId,
                                'source_id' => $sourceId,
                                'source_type' => $sourceType,
                                'regular' => ['quantity' => 0, 'fields' => []],
                                'free' => ['quantity' => 0, 'fields' => []],
                            ];
                        }

                        $grouped[$groupKey][$quantityType]['quantity']++;
                        $grouped[$groupKey][$quantityType]['fields'][] = $field;
                    }
                }

                foreach ($grouped as $types) {
                    $stockProductId = $types['stock_product_id'];
                    $sourceId = $types['source_id'];
                    $sourceType = $types['source_type'];

                    $stockTransaction = null;
                    $stockMovement = null;


                    if ($types['regular']['quantity'] > 0) {

                        $fieldValueValidated = [
                            'stock_id' => $stock->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'source_id' => $sourceId,
                            'source_type' => $sourceType,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'sales_return',
                            'quantity' => $types['regular']['quantity'],
                            'direction' => 'in',
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'stock_product_id' => $stockProductId,
                            'sales_bill_number' => $data['bill_number'] ?? null,
                            'stock_movement_id' => null,
                            'is_vatable' => $product['is_vatable'],
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                        ];
                        $stockTransaction = StockTransaction::create($fieldValueValidated);
                    }


                    if ($types['free']['quantity'] > 0) {
                        $stockMovementValidated = [
                            'stock_id' => $stock->id,
                            'stock_transaction_id' => $stockTransaction->id ?? null,
                            'fiscal_year_id' => $fiscalYearId,
                            'source_id' => $sourceId,
                            'source_type' => $sourceType,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'sales_return',
                            'quantity' => $types['free']['quantity'],
                            'stock_type' => 'free',
                            'direction' => 'in',
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'stock_product_id' => $stockProductId,
                            'sales_bill_number' => $data['bill_number'] ?? null,
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,

                        ];
                        $stockMovement = StockMovement::create($stockMovementValidated);
                    }


                    foreach ($types['regular']['fields'] as $field) {

                        TransactionPivot::create([
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'type' => 'sales_return',
                            'direction' => 'in',
                            'stock_product_id' => $stockProductId,
                            'stock_transaction_id' => $stockTransaction?->id,
                            'stock_movement_id' => null,
                            'product_id' => $product['product_id'],
                            'quantity_index' => $field['quantity_index'],
                            'quantity_type' => 'regular',
                        ]);
                    }


                    foreach ($types['free']['fields'] as $field) {
                        TransactionPivot::create([
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'type' => 'sales_return',
                            'direction' => 'in',
                            'stock_product_id' => $stockProductId,
                            'stock_transaction_id' => $stockTransaction?->id,
                            'stock_movement_id' => $stockMovement?->id,
                            'product_id' => $product['product_id'],
                            'quantity_index' => $field['quantity_index'],
                            'quantity_type' => 'free',
                        ]);
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

        $salesBillNumber = $data['return_bill_no'] ?? null;



        $salesStock = Stock::where('bill_number', $salesBillNumber)
            ->where('type', 'sale')
            ->whereNull('deleted_at')
            ->first();

        if (!$salesStock) {
            DB::rollBack();
            throw new Exception('Sales bill number is required for updating bill wise a sales return.');
        }

        $stockData = [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'store_id' => $data['store_id'] ?? null,
            'type' => 'sales_return',
            'bill_number' => $data['bill_number'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'party_id' => $data['party_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'batch_no' => $data['batch_no'] ?? null,
            'credit_days' => $data['credit_days'] ?? null,
            'balance' => $data['balance'] ?? 0,
            'ref_bill_number' => $data['ref_bill_number'] ?? null,
            'return_bill_no' => $salesBillNumber,
            'reasons' => $data['reasons'] ?? null,
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $data['discount_value'] ?? 0,
            'discount_after_vat' => $data['discount_after_vat'] ?? 0,
            'sub_total_before_discount' => $data['sub_total_before_discount'] ?? 0,
            'taxable_amount' => $data['taxable_amount'] ?? 0,
            'non_taxable_amount' => $data['non_taxable_amount'] ?? 0,
            'excise_duty' => $data['excise_duty'] ?? 0,
            'vat_percent' => $data['vat_percent'] ?? 0,
            'health_insurance' => $data['health_insurance'] ?? 0,
            'freight_amount' => $data['freight_amount'] ?? 0,
            'roundoff_type' => $data['roundoff_type'] ?? null,
            'roundoff_amount' => $data['roundoff_amount'] ?? 0,
            'total_amount' => $data['total_amount'] ?? 0,
            'payment' => $data['payment'] ?? null,
            'remarks' => $data['remarks'] ?? null,

        ];

        $stock = Stock::find($id);


        $stock->update($stockData);
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

                $regularBaseQty = $this->unitConversionService
                    ->convertToBaseUnit(
                        $product['measure_unit_id'],
                        $product['quantity'] ?? 0
                    );

                $freeBaseQty = $this->unitConversionService
                    ->convertToBaseUnit(
                        $product['measure_unit_id'],
                        $product['free_quantity'] ?? 0
                    );

                $totalReturnQty = $regularBaseQty + $freeBaseQty;

                if ($totalReturnQty <= 0) {
                    continue;
                }


                $allocatedRows = $this->quantityAllocationService
                    ->allocateSalesReturnQuantity(
                        $salesStock->id,
                        $product['product_id'],
                        $totalReturnQty
                    );

                $allocatedTotal = collect($allocatedRows)->sum('quantity');

                if ($allocatedTotal < $totalReturnQty) {
                    throw new Exception(
                        'Return quantity exceeds sold quantity for product ID: '
                        . $product['product_id']
                    );
                }

                $regularRemaining = $regularBaseQty;
                $freeRemaining = $freeBaseQty;

                foreach ($allocatedRows as $alloc) {

                    $available = $alloc['quantity'];


                    if ($regularRemaining > 0) {

                        $consume = min($regularRemaining, $available);

                        $transactionData = [
                            'stock_id' => $stock->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'source_id' => $alloc['source_id'],
                            'source_type' => $alloc['source_type'],
                            'stock_product_id' => $alloc['stock_product_id'],
                            'stock_movement_id' =>
                                $alloc['source_type'] === 'stock_movement'
                                ? $alloc['source_id']
                                : null,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'sales_return',
                            'direction' => 'in',
                            'quantity' => $consume,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'party_id' => $data['party_id'] ?? null,
                            'sales_bill_number' => $data['bill_number'] ?? null,
                            'is_vatable' => $product['is_vatable'],
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'batch_no' => $product['batch_no'] ?? null,
                        ];

                        $tranasaction = StockTransaction::create($transactionData);

                        $regularRemaining -= $consume;
                        $available -= $consume;
                    }


                    if ($available > 0 && $freeRemaining > 0) {

                        $consume = min($freeRemaining, $available);

                        $stockMovementValidated = [
                            'stock_id' => $stock->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'stock_transaction_id' => $tranasaction->id ?? null,
                            'source_id' => $alloc['source_id'],
                            'source_type' => $alloc['source_type'],
                            'stock_product_id' => $alloc['stock_product_id'],
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'sales_return',
                            'stock_type' => 'free',
                            'direction' => 'in',
                            'quantity' => $consume,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'party_id' => $data['party_id'] ?? null,
                            'sales_bill_number' => $data['bill_number'] ?? null,
                            'is_vatable' => $product['is_vatable'],
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'batch_no' => $product['batch_no'] ?? null,
                        ];

                        StockMovement::create($stockMovementValidated);

                        $freeRemaining -= $consume;
                    }
                }


            } else {

                $grouped = [];

                foreach ($product['field_values'] as $group) {
                    foreach ($group as $field) {
                        $stockProductId = $field['stock_product_id'];
                        $stockTransactionId = $field['stock_transaction_id'] ?? null;
                        $stockMovementId = $field['stock_movement_id'] ?? null;
                        $quantityType = $field['quantity_type'] ?? 'regular';


                        if ($stockTransactionId && $stockMovementId) {
                            $sourceId = $stockMovementId;
                            $sourceType = 'stock_movement';
                        } elseif ($stockTransactionId) {
                            $sourceId = $stockTransactionId;
                            $sourceType = 'stock_transaction';
                        } elseif ($stockMovementId) {
                            $sourceId = $stockMovementId;
                            $sourceType = 'stock_movement';
                        } else {
                            $sourceId = null;
                            $sourceType = null;
                        }


                        $groupKey = $stockProductId . '_' . ($sourceId ?? 'null') . '_' . ($sourceType ?? 'null');

                        if (!isset($grouped[$groupKey])) {
                            $grouped[$groupKey] = [
                                'stock_product_id' => $stockProductId,
                                'source_id' => $sourceId,
                                'source_type' => $sourceType,
                                'regular' => ['quantity' => 0, 'fields' => []],
                                'free' => ['quantity' => 0, 'fields' => []],
                            ];
                        }

                        $grouped[$groupKey][$quantityType]['quantity']++;
                        $grouped[$groupKey][$quantityType]['fields'][] = $field;
                    }
                }

                foreach ($grouped as $types) {
                    $stockProductId = $types['stock_product_id'];
                    $sourceId = $types['source_id'];
                    $sourceType = $types['source_type'];

                    $stockTransaction = null;
                    $stockMovement = null;


                    if ($types['regular']['quantity'] > 0) {

                        $stockTransactionData = [
                            'stock_id' => $stock->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'source_id' => $sourceId,
                            'source_type' => $sourceType,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'sales_return',
                            'quantity' => $types['regular']['quantity'],
                            'direction' => 'in',
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'stock_product_id' => $stockProductId,
                            'sales_bill_number' => $data['bill_number'] ?? null,
                            'stock_movement_id' => null,
                            'is_vatable' => $product['is_vatable'],
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,

                        ];
                        $stockTransaction = StockTransaction::create($stockTransactionData);
                    }


                    if ($types['free']['quantity'] > 0) {

                        $stockValidatedData = [
                            'stock_id' => $stock->id,
                            'stock_transaction_id' => $stockTransaction->id ?? null,
                            'fiscal_year_id' => $fiscalYearId,
                            'source_id' => $sourceId,
                            'source_type' => $sourceType,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'sales_return',
                            'quantity' => $types['free']['quantity'],
                            'stock_type' => 'free',
                            'direction' => 'in',
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'stock_product_id' => $stockProductId,
                            'sales_bill_number' => $data['bill_number'] ?? null,
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                        ];
                        $stockMovement = StockMovement::create($stockValidatedData);
                    }


                    foreach ($types['regular']['fields'] as $field) {
                        TransactionPivot::create([
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'type' => 'sales_return',
                            'direction' => 'in',
                            'stock_product_id' => $stockProductId,
                            'stock_transaction_id' => $stockTransaction?->id,
                            'stock_movement_id' => null,
                            'product_id' => $product['product_id'],
                            'quantity_index' => $field['quantity_index'],
                            'quantity_type' => 'regular',
                        ]);
                    }


                    foreach ($types['free']['fields'] as $field) {
                        TransactionPivot::create([
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'type' => 'sales_return',
                            'direction' => 'in',
                            'stock_product_id' => $stockProductId,
                            'stock_transaction_id' => $stockTransaction?->id,
                            'stock_movement_id' => $stockMovement?->id,
                            'product_id' => $product['product_id'],
                            'quantity_index' => $field['quantity_index'],
                            'quantity_type' => 'free',
                        ]);
                    }
                }
            }
        }

        DB::commit();

        return $stock->load('stockTransactions');

    }




    public function list(array $filters)
    {

        return Stock::where('type', 'sales_return')
            ->whereNull('deleted_at')
            ->when($filters['company_id'] ?? null, function ($query, $companyId) {
                $query->where('company_id', $companyId);
            })
            ->orderBy('created_at', 'desc')
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
                $query->where('type', 'sales_return')
                    ->where('stock_type', 'free')
                    ->whereNull('deleted_at');
            }
        ])
            ->whereNull('deleted_at')
            ->where('type', 'sales_return')
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

        $stock = Stock::where('type', 'sales_return')
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
            ->where('type', 'sales_return')
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
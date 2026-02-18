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
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Exception;

use App\Interfaces\StockPurchaseReturnRepositoryInterface;

class StockPurchaseReturnRepository implements StockPurchaseReturnRepositoryInterface
{

    protected $unitConversionService;
    protected $quantityAllocationService;

    public function __construct(UnitConversionService $unitConversionService, QuantityAllocationService $quantityAllocationService)
    {
        $this->unitConversionService = $unitConversionService;
        $this->quantityAllocationService = $quantityAllocationService;

    }

    public function getAllBills()
    {
        return Stock::where('type', 'purchase')
            ->whereNull('deleted_at')
            ->pluck('bill_number')
            ->toArray();
    }




    public function create(array $data)
    {

        DB::beginTransaction();

        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        $purchaseBillNumber = $data['purchase_bill_number'];


        $purchaseStock = Stock::where('bill_number', $purchaseBillNumber)
            ->where('type', 'purchase')
            ->whereNull('deleted_at')
            ->first();

        if (!$purchaseStock) {
            throw new Exception('Purchase bill not found.');
        }


        $stockData = [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'store_id' => $data['store_id'] ?? null,
            'type' => 'purchase_return',
            'purchase_bill_number' => $data['purchase_bill_number'] ?? null,
            'bill_number' => $data['bill_number'] ?? null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'party_id' => $data['party_id'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'batch_no' => $data['batch_no'] ?? null,
            'credit_days' => $data['credit_days'] ?? null,
            'balance' => $data['balance'] ?? 0,
            'ref_bill_number' => $data['ref_bill_number'] ?? null,
            'return_bill_no' => $data['return_bill_no'] ?? null,
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
            if (empty($product['field_values'])) {


                $basequantity = $this->unitConversionService->convertToBaseUnit(
                    $product['measure_unit_id'],
                    $product['quantity']
                );




                $allocatedQtys = $this->quantityAllocationService->allocateBillWiseQuantity(
                    $purchaseStock->id,
                    $product['product_id'],
                    $basequantity
                );


                $totalAllocated = collect($allocatedQtys)->sum('quantity');

                if ($totalAllocated < $basequantity) {
                    DB::rollBack();
                    throw new Exception('Return quantity cannot be greater than purchased quantity for product ID: ' . $product['product_id']);
                }


                foreach ($allocatedQtys as $alloc) {
                    $stockTransaction = StockTransaction::create([
                        'stock_id' => $stock->id,
                        'fiscal_year_id' => $fiscalYearId,
                        'stock_product_id' => $alloc['stock_product_id'] ?? null,
                        'stock_movement_id' => $alloc['source'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,
                        'product_id' => $product['product_id'],
                        'measure_unit_id' => $product['measure_unit_id'],
                        'type' => 'purchase_return',
                        'quantity' => $alloc['quantity'],
                        'is_vatable' => $product['is_vatable'],
                        'stock_type' => 'regular',
                        'company_id' => $data['company_id'],
                        'branch_id' => $data['branch_id'],
                        'direction' => 'in',
                        'party_id' => $data['party_id'] ?? null,
                        'expiry_date' => $product['expiry_date'] ?? null,
                        'mfd' => $product['mfd'] ?? null,
                        'price' => $product['price'] ?? 0,
                        'discount_percent' => $product['discount_percent'] ?? 0,
                        'discount_amount' => $product['discount_amount'] ?? 0,
                        'amount' => $product['amount'] ?? 0,
                        'batch_no' => $product['batch_no'] ?? null,
                    ]);
                }


                if (!empty($product['free_quantity']) && $product['free_quantity'] > 0) {
                    $freeQuantity = $this->unitConversionService->convertToBaseUnit(
                        $product['measure_unit_id'],
                        $product['free_quantity']
                    );

                    $freeAllocated = $this->quantityAllocationService->allocateBillWiseQuantity(
                        $purchaseStock->id,
                        $product['product_id'],
                        $freeQuantity
                    );

                    foreach ($freeAllocated as $alloc) {
                        $stockMovementProduct = StockMovement::create([
                            'stock_id' => $stock->id,
                            'stock_transaction_id' => $stockTransaction->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $alloc['quantity'],
                            'stock_type' => 'free',
                            'is_vatable' => $product['is_vatable'],
                            'direction' => 'in',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $alloc['stock_product_id'] ?? null,
                        ]);
                    }


                }
            } else {
                $groupedFieldValues = [];


                foreach ($product['field_values'] as $group) {
                    foreach ($group as $field) {
                        $quantityType = $field['quantity_type'] ?? 'regular';
                        $key = $field['stock_product_id'] . '-' . $quantityType;

                        if (!isset($groupedFieldValues[$key])) {
                            $groupedFieldValues[$key] = [
                                'stock_product_id' => $field['stock_product_id'],
                                'quantity_type' => $quantityType,
                                'quantity_sum' => 0,
                                'fields' => [],
                            ];
                        }

                        $groupedFieldValues[$key]['quantity_sum'] += 1;
                        $groupedFieldValues[$key]['fields'][] = $field;
                    }
                }

                foreach ($groupedFieldValues as $groupData) {
                    $isFree = $groupData['quantity_type'] === 'free';
                    $quantity = $groupData['quantity_sum'];

                    if (!$isFree) {

                        $stockTransaction = StockTransaction::create([
                            'stock_id' => $stock->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $quantity,
                            'is_vatable' => $product['is_vatable'],
                            'stock_type' => 'regular',
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'direction' => 'out',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $groupData['stock_product_id'],
                        ]);

                    } elseif ($isFree) {

                        $stockMovement = StockMovement::create([
                            'stock_id' => $stock->id,
                            'stock_transaction_id' => $stockTransaction->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $quantity,
                            'stock_type' => 'free',
                            'is_vatable' => $product['is_vatable'],
                            'direction' => 'out',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $groupData['stock_product_id'],
                        ]);

                    }


                    foreach ($groupData['fields'] as $field) {
                        TransactionPivot::create([
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'type' => 'purchase_return',
                            'direction' => 'out',
                            'stock_product_id' => $field['stock_product_id'],
                            'stock_transaction_id' => $stockTransaction->id,
                            'stock_movement_id' => $isFree ? $stockMovement->id : null,
                            'product_id' => $product['product_id'],
                            'quantity_index' => $field['quantity_index'],
                            'quantity_type' => $field['quantity_type'] ?? 'regular',
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

        $purchaseBillNumber = $data['purchase_bill_number'];


        $purchaseStock = Stock::where('bill_number', $purchaseBillNumber)
            ->where('type', 'purchase')
            ->whereNull('deleted_at')
            ->first();




        if (!$purchaseStock) {
            throw new Exception('Purchase bill not found.');
        }

        $stock = Stock::findOrFail($id);




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
            'balance' => $data['balance'] ?? 0,
            'ref_bill_number' => $data['ref_bill_number'] ?? null,
            'return_bill_no' => $data['return_bill_no'] ?? null,
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

        $stock->update($stockData);

        $oldTransactionIds = StockTransaction::where('stock_id', $stock->id)->pluck('id');
        $oldMovementIds = StockMovement::where('stock_id', $stock->id)->pluck('id');


        TransactionPivot::whereIn('stock_transaction_id', $oldTransactionIds)
            ->orWhereIn('stock_movement_id', $oldMovementIds)
            ->delete();


        StockTransaction::where('stock_id', $stock->id)->delete();
        StockMovement::where('stock_id', $stock->id)->delete();



        foreach ($data['stock_transactions'] as $product) {
            if (empty($product['field_values'])) {


                $basequantity = $this->unitConversionService->convertToBaseUnit(
                    $product['measure_unit_id'],
                    $product['quantity']
                );




                $allocatedQtys = $this->quantityAllocationService->allocateBillWiseQuantity(
                    $purchaseStock->id,
                    $product['product_id'],
                    $basequantity
                );


                $totalAllocated = collect($allocatedQtys)->sum('quantity');

                if ($totalAllocated < $basequantity) {
                    DB::rollBack();
                    throw new Exception('Return quantity cannot be greater than purchased quantity for product ID: ' . $product['product_id']);
                }


                foreach ($allocatedQtys as $alloc) {
                    $stockTransaction = StockTransaction::create([
                        'stock_id' => $stock->id,
                        'fiscal_year_id' => $fiscalYearId,
                        'stock_product_id' => $alloc['stock_product_id'] ?? null,
                        'stock_movement_id' => $alloc['source'] === 'stock_movement' ? $alloc['stock_movement_id'] : null,
                        'product_id' => $product['product_id'],
                        'measure_unit_id' => $product['measure_unit_id'],
                        'type' => 'purchase_return',
                        'quantity' => $alloc['quantity'],
                        'is_vatable' => $product['is_vatable'],
                        'stock_type' => 'regular',
                        'company_id' => $data['company_id'],
                        'branch_id' => $data['branch_id'],
                        'direction' => 'in',
                        'party_id' => $data['party_id'] ?? null,
                        'expiry_date' => $product['expiry_date'] ?? null,
                        'mfd' => $product['mfd'] ?? null,
                        'price' => $product['price'] ?? 0,
                        'discount_percent' => $product['discount_percent'] ?? 0,
                        'discount_amount' => $product['discount_amount'] ?? 0,
                        'amount' => $product['amount'] ?? 0,
                        'batch_no' => $product['batch_no'] ?? null,
                    ]);
                }


                if (!empty($product['free_quantity']) && $product['free_quantity'] > 0) {
                    $freeQuantity = $this->unitConversionService->convertToBaseUnit(
                        $product['measure_unit_id'],
                        $product['free_quantity']
                    );

                    $freeAllocated = $this->quantityAllocationService->allocateBillWiseQuantity(
                        $purchaseStock->id,
                        $product['product_id'],
                        $freeQuantity
                    );

                    foreach ($freeAllocated as $alloc) {
                        $stockMovementProduct = StockMovement::create([
                            'stock_id' => $stock->id,
                            'stock_transaction_id' => $stockTransaction->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $alloc['quantity'],
                            'stock_type' => 'free',
                            'is_vatable' => $product['is_vatable'],
                            'direction' => 'in',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $alloc['stock_product_id'] ?? null,
                        ]);
                    }


                }
            } else {
                $groupedFieldValues = [];


                foreach ($product['field_values'] as $group) {
                    foreach ($group as $field) {
                        $quantityType = $field['quantity_type'] ?? 'regular';
                        $key = $field['stock_product_id'] . '-' . $quantityType;

                        if (!isset($groupedFieldValues[$key])) {
                            $groupedFieldValues[$key] = [
                                'stock_product_id' => $field['stock_product_id'],
                                'quantity_type' => $quantityType,
                                'quantity_sum' => 0,
                                'fields' => [],
                            ];
                        }

                        $groupedFieldValues[$key]['quantity_sum'] += 1;
                        $groupedFieldValues[$key]['fields'][] = $field;
                    }
                }

                foreach ($groupedFieldValues as $groupData) {
                    $isFree = $groupData['quantity_type'] === 'free';
                    $quantity = $groupData['quantity_sum'];

                    if (!$isFree) {

                        $stockTransaction = StockTransaction::create([
                            'stock_id' => $stock->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $quantity,
                            'is_vatable' => $product['is_vatable'],
                            'stock_type' => 'regular',
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'direction' => 'out',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $groupData['stock_product_id'],
                        ]);

                    } elseif ($isFree) {

                        $stockMovement = StockMovement::create([
                            'stock_id' => $stock->id,
                            'stock_transaction_id' => $stockTransaction->id,
                            'fiscal_year_id' => $fiscalYearId,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'product_id' => $product['product_id'],
                            'measure_unit_id' => $product['measure_unit_id'],
                            'type' => 'purchase_return',
                            'quantity' => $quantity,
                            'stock_type' => 'free',
                            'is_vatable' => $product['is_vatable'],
                            'direction' => 'out',
                            'party_id' => $data['party_id'] ?? null,
                            'expiry_date' => $product['expiry_date'] ?? null,
                            'mfd' => $product['mfd'] ?? null,
                            'price' => $product['price'] ?? 0,
                            'discount_percent' => $product['discount_percent'] ?? 0,
                            'discount_amount' => $product['discount_amount'] ?? 0,
                            'amount' => $product['amount'] ?? 0,
                            'batch_no' => $product['batch_no'] ?? null,
                            'stock_product_id' => $groupData['stock_product_id'],
                        ]);

                    }


                    foreach ($groupData['fields'] as $field) {
                        TransactionPivot::create([
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'type' => 'purchase_return',
                            'direction' => 'out',
                            'stock_product_id' => $field['stock_product_id'],
                            'stock_transaction_id' => $stockTransaction->id,
                            'stock_movement_id' => $isFree ? $stockMovement->id : null,
                            'product_id' => $product['product_id'],
                            'quantity_index' => $field['quantity_index'],
                            'quantity_type' => $field['quantity_type'] ?? 'regular',
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

    }

    public function show($id)
    {
        $stock = Stock::with([
            'stockTransactions' => function ($query) {
                $query->whereNull('deleted_at')
                    ->with([
                        'transactionPivots' => function ($q) {
                            $q->whereNull('deleted_at');
                        },
                        'stockMovements' => function ($q) {
                            $q->where('type', 'purchase_return')
                                ->where('stock_type', 'free')
                                ->whereNull('deleted_at');
                        }
                    ]);
            }
        ])
            ->whereNull('deleted_at')
            ->where('type', 'purchase_return')
            ->findOrFail($id);


        $stock->stockTransactions->transform(function ($product) {

            $freeQty = $product->stockMovements->sum('quantity') ?? 0;


            $attributes = $product->toArray();


            $fieldValues = $attributes['transaction_pivots'] ?? [];

            unset($attributes['transaction_pivots']);
            unset($attributes['stock_movements']);


            $newProduct = [];

            foreach ($attributes as $key => $value) {

                $newProduct[$key] = $value;


                if ($key === 'quantity') {
                    $newProduct['free_quantity'] = $freeQty;
                }
            }


            $newProduct['field_values'] = $fieldValues;

            return $newProduct;
        });

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
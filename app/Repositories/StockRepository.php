<?php

namespace App\Repositories;
use App\Services\TransactionImplementService;
use Illuminate\Support\Facades\DB;
use App\Models\Stock;
use App\Models\Vat;
use App\Models\StockProduct;
use App\Models\FiscalYear;
use App\Models\Product;
use App\Models\MeasureUnit;
use App\Models\ProductList;
use App\Services\UnitConversionService;
use App\Services\CurrencyFormatService;

use App\Models\StockProductFieldValue;

use App\Interfaces\StockRepositoryInterface;

class StockRepository implements StockRepositoryInterface
{

    protected $unitConversionService;
    protected $currencyFormatService;
    protected $taxImplementService;

    public function __construct(UnitConversionService $unitConversionService, CurrencyFormatService $currencyFormatService, TransactionImplementService $taxImplementService)
    {
        $this->unitConversionService = $unitConversionService;
        $this->currencyFormatService = $currencyFormatService;
        $this->taxImplementService = $taxImplementService;
    }
    public function create(array $data)
    {

        DB::beginTransaction();
        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');
        $appliedVat = Vat::where('status', 1)->pluck('vat_percent')->first() ?? 0;
        $stockValidated = [

            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'type' => 'opening_stock',
            'bill_number' => $data['bill_number'] ?? null,
            'address' => $data['address'] ?? null,
            'store_id' => $data['store_id'] ?? null,
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
            'vat_percent' => $data['vat_percent'] ?? 0,
            'health_insurance' => $this->currencyFormatService->cleanCurrency($data['health_insurance'] ?? 0) ?? 0,

            'freight_amount' => $this->currencyFormatService->cleanCurrency($data['freight_amount'] ?? 0) ?? 0,
            'roundoff_type' => $data['roundoff_type'] ?? null,
            'roundoff_amount' => $this->currencyFormatService->cleanCurrency($data['roundoff_amount'] ?? 0) ?? 0,
            $totalAmount = $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0,

            $taxableAmount = $this->currencyFormatService->cleanCurrency($data['taxable_amount'] ?? 0) ?? 0,

            $vatAmount = $this->taxImplementService->transactionImplement($appliedVat ?? 0, $taxableAmount) ?? 0,

            'total_amount' => $totalAmount + $vatAmount,
            'payment' => $data['payment'] ?? null,
            'remarks' => $data['remarks'] ?? null,

        ];

        $stock = Stock::create($stockValidated);


        foreach ($data['stock_products'] as $product) {

            $quantity = $this->unitConversionService->convertToBaseUnit(

                $product['measure_unit_id'],
                $product['quantity']
            );


            $productValidated = [
                'stock_id' => $stock->id,
                'product_id' => $product['product_id'],
                'measure_unit_id' => $product['measure_unit_id'],
                'type' => 'opening_stock',
                'quantity' => $quantity,
                'is_vatable' => $product['is_vatable'],
                'stock_type' => $product['stock_type'] ?? null,
                'fiscal_year_id' => $fiscalYearId,
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'],
                'direction' => $product['direction'] ?? 'in',
                'party_id' => $data['party_id'] ?? null,
                'expiry_date' => $product['expiry_date'] ?? null,
                'mfd' => $product['mfd'] ?? null,
                'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0),
                'discount_percent' => $product['discount_percent'] ?? 0,
                'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0),
                'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0),
                'batch_no' => $product['batch_no'] ?? null,
            ];


            $stockProduct = StockProduct::create($productValidated);


            if (!empty($product['field_values'])) {

                foreach ($product['field_values'] as $quantityIndex => $group) {
                    foreach ($group as $field) {

                        StockProductFieldValue::create([
                            'stock_id' => $stock->id,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'stock_product_id' => $stockProduct->id,
                            'product_id' => $stockProduct->product_id,
                            'quantity_index' => $quantityIndex,
                            'key' => $field['key'],
                            'value' => $field['value'],
                        ]);
                    }
                }
            }
        }
        DB::commit();

        return $stock->load('stockProducts.stockProductFieldValues');



    }

    public function update($id, array $data)
    {
        DB::beginTransaction();
        $stock = Stock::findOrFail($id);

        $fiscalYearId = FiscalYear::where('status', 1)
            ->whereNull('deleted_at')
            ->value('id');

        $appliedVat = Vat::where('status', 1)->pluck('vat_percent')->first() ?? 0;


        $stockValidated = [
            'fiscal_year_id' => $fiscalYearId,
            'company_id' => $data['company_id'],
            'branch_id' => $data['branch_id'],
            'invoice_date' => $data['invoice_date'] ?? null,
            'invoice_date_bs' => $data['invoice_date_bs'] ?? null,
            'bill_number' => $data['bill_number'] ?? null,
            'address' => $data['address'] ?? null,
            'type' => 'opening_stock',
            'store_id' => $data['store_id'] ?? null,
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
            'vat_percent' => $this->currencyFormatService->cleanCurrency($data['health_insurance'] ?? 0) ?? 0,
            'health_insurance' => $this->currencyFormatService->cleanCurrency($data['freight_amount'] ?? 0) ?? 0,
            'freight_amount' => $this->currencyFormatService->cleanCurrency($data['balance'] ?? 0) ?? 0,
            'roundoff_type' => $data['roundoff_type'] ?? null,
            'roundoff_amount' => $this->currencyFormatService->cleanCurrency($data['roundoff_amount'] ?? 0) ?? 0,
            $totalAmount = $this->currencyFormatService->cleanCurrency($data['total_amount'] ?? 0) ?? 0,

            $taxableAmount = $this->currencyFormatService->cleanCurrency($data['taxable_amount'] ?? 0) ?? 0,

            $vatAmount = $this->taxImplementService->transactionImplement($appliedVat ?? 0, $taxableAmount) ?? 0,

            'total_amount' => $totalAmount + $vatAmount,
            'payment' => $data['payment'] ?? null,
            'remarks' => $data['remarks'] ?? null,

        ];

        $stock->update($stockValidated);

        $incomingProductIds = collect($data['stock_products'])
            ->pluck('id')
            ->filter()
            ->toArray();


        $stock->stockProducts()
            ->whereNotIn('id', $incomingProductIds)
            ->delete();

        foreach ($data['stock_products'] as $product) {
            $quantity = $this->unitConversionService->convertToBaseUnit(

                $product['measure_unit_id'],
                $product['quantity']
            );

            $stockProductValidated = [
                'stock_id' => $stock->id,
                'product_id' => $product['product_id'],
                'measure_unit_id' => $product['measure_unit_id'],
                'type' => 'opening_stock',
                'quantity' => $quantity,
                'stock_type' => $product['stock_type'] ?? null,
                'is_vatable' => $product['is_vatable'],
                'fiscal_year_id' => $fiscalYearId,
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'],
                'direction' => $product['direction'] ?? 'in',
                'party_id' => $data['party_id'] ?? null,
                'expiry_date' => $product['expiry_date'] ?? null,
                'mfd' => $product['mfd'] ?? null,
                'price' => $this->currencyFormatService->cleanCurrency($product['price'] ?? 0) ?? 0,
                'discount_percent' => $product['discount_percent'] ?? 0,
                'discount_amount' => $this->currencyFormatService->cleanCurrency($product['discount_amount'] ?? 0) ?? 0,
                'amount' => $this->currencyFormatService->cleanCurrency($product['amount'] ?? 0) ?? 0,
                'batch_no' => $product['batch_no'] ?? null,

            ];

            $stockProduct = StockProduct::updateOrCreate([

                'id' => $product['id'] ?? null,
                'stock_id' => $stock->id,

            ], $stockProductValidated);

            $incomingFieldValueIds = [];


            if (!empty($product['field_values'])) {

                foreach ($product['field_values'] as $quantityIndex => $group) {
                    foreach ($group as $field) {

                        $fieldValuesValidated = [
                            'stock_id' => $stock->id,
                            'company_id' => $data['company_id'],
                            'branch_id' => $data['branch_id'],
                            'stock_product_id' => $stockProduct->id,
                            'product_id' => $stockProduct->product_id,
                            'quantity_index' => $quantityIndex,
                            'key' => $field['key'],
                            'value' => $field['value'],

                        ];

                        $fieldValue = StockProductFieldValue::updateOrCreate([
                            'id' => $field['id'] ?? null,
                            'stock_product_id' => $stockProduct->id,

                        ], $fieldValuesValidated);
                        $incomingFieldValueIds[] = $fieldValue->id;
                    }
                }
            }

            $stockProduct->stockProductFieldValues()
                ->when(!empty($incomingFieldValueIds), function ($query) use ($incomingFieldValueIds) {
                    $query->whereNotIn('id', $incomingFieldValueIds);
                })
                ->delete();
        }


        DB::commit();

        return $stock->load('stockProducts.stockProductFieldValues');

    }

    public function list(array $filters)
    {
        return Stock::where('type', 'opening_stock')->whereNull('deleted_at')->get();

    }

    public function show($id)
    {
        $stock = Stock::with('stockProducts.stockProductFieldValues')
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $stock->stockProducts->map(function ($stockProduct) {


            $stockProduct->field_values = $stockProduct->stockProductFieldValues;
            unset($stockProduct->stockProductFieldValues);
            $stockProduct->product_name = $stockProduct->product->name ?? null;
            unset($stockProduct->product);

            $productId = $stockProduct->product_id;


            $productUnitIds = Product::where('id', $productId)
                ->pluck('measure_unit_id');


            $productListUnitIds = ProductList::where('product_id', $productId)
                ->pluck('measure_unit_id');


            $unitIds = collect()
                ->merge($productUnitIds)
                ->merge($productListUnitIds)
                ->filter()
                ->unique()
                ->values();


            $measureUnits = MeasureUnit::whereIn('id', $unitIds)
                ->whereNull('deleted_at')
                ->get(['id', 'name', 'quantity'])
                ->map(function ($unit) {
                    return [
                        'id' => $unit->id,
                        'name' => $unit->name,
                        'measure_unit_quantity' => $unit->quantity ?? null,
                    ];
                });


            $stockProduct->measure_units = $measureUnits;

            return $stockProduct;
        });

        return $stock;
    }

    public function delete($id)
    {
        DB::beginTransaction();

        $stock = Stock::where('type', 'opening_stock')
            ->whereNull('deleted_at')
            ->findOrFail($id);

        $stockProductIds = StockProduct::where('stock_id', $stock->id)
            ->whereNull('deleted_at')
            ->pluck('id');

        StockProductFieldValue::whereIn('stock_product_id', $stockProductIds)
            ->whereNull('deleted_at')
            ->delete();


        StockProduct::whereIn('id', $stockProductIds)
            ->delete();

        $stock->delete();

        DB::commit();

        return true;



    }
}

?>
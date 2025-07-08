<?php

namespace App\Observers;

use App\Models\AccountGroup;
use App\Models\Purchase;
use App\Models\VoucherSummary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;

class PurchaseObserver
{
    /**
     * Handle the Purchase "created" event.
     */
    public function created(Purchase $purchase): void
    {
        $purchaseAccGroups = [
            'Purchase' => ['type' => 'debit', 'valueAmount' => 'sub_total_before_discount', 'payment_type' => ''],
            'Discount Income' => ['type' => 'credit', 'valueAmount' => 'discount_value', 'payment_type' => ''],
            'Excise Duty Expenses' => ['type' => 'debit', 'valueAmount' => 'excise_duty', 'payment_type' => ''],
            'VAT Account' => ['type' => 'debit', 'valueAmount' => 'vat_percent', 'payment_type' => ''],
            'Health insurance Expenses' => ['type' => 'debit', 'valueAmount' => 'health_insurance', 'payment_type' => ''],
            'Fright charge' => ['type' => 'debit', 'valueAmount' => 'freight_amount', 'payment_type' => ''],
            'Scheme Discount Income' => ['type' => 'credit', 'valueAmount' => 'discount_after_vat', 'payment_type' => ''],
        ];

        if ($purchase->roundoff_type === 'plus') {
            $purchaseAccGroups['Round Off Plus in Purchase'] = ['type' => 'debit', 'valueAmount' => 'roundoff_amount', 'payment_type' => ''];
        }

        if ($purchase->roundoff_type === 'minus') {
            $purchaseAccGroups['Round Off Minus in Purchase'] = ['type' => 'debit', 'valueAmount' => 'roundoff_amount', 'payment_type' => ''];
        }

        try {
            foreach ($purchaseAccGroups as $purchaseAccGroupKey => $purchaseAccGroupValue) {

                $accGroup = AccountGroup::where('name', $purchaseAccGroupKey)->firstOrFail();
                VoucherSummary::create([
                    'date' => $purchase->invoice_date,
                    'date_bs' => $purchase->invoice_date_bs,
                    'company_id' => $purchase->company_id,
                    'branch_id' => null,
                    'voucher_number' => "VOC-818200{$purchase->id}",
                    'particulars' => "Product Purchased from - {$purchase->customer->party_name} from Bill No. {$purchase->purchase_bill_number}",
                    'debit' => $purchaseAccGroupValue['type'] === 'debit' ? $purchase->{$purchaseAccGroupValue['valueAmount']} : 0,
                    'credit' => $purchaseAccGroupValue['type'] === 'credit' ? $purchase->{$purchaseAccGroupValue['valueAmount']} : 0,
                    'tr_bill_number' => $purchase->purchase_bill_number,
                    'cheque_number' => "",
                    'type' => "PURCHASE",
                    'payment_type' => "PURCHASE",
                    'account_group_id' => $accGroup->id,
                ]);
            }
        } catch (ModelNotFoundException $e) {
            \Log::error($e);

        } catch (QueryException $e) {
            \Log::error($e);

        } catch (\Exception $e) {
            \Log::error($e);

        }


    }

    /**
     * Handle the Purchase "updated" event.
     */
    public function updated(Purchase $purchase): void
    {
        //
    }

    /**
     * Handle the Purchase "deleted" event.
     */
    public function deleted(Purchase $purchase): void
    {
        //
    }

    /**
     * Handle the Purchase "restored" event.
     */
    public function restored(Purchase $purchase): void
    {
        //
    }

    /**
     * Handle the Purchase "force deleted" event.
     */
    public function forceDeleted(Purchase $purchase): void
    {
        //
    }
}

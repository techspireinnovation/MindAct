<?php

namespace App\Observers;

use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\Purchase;
use App\Models\VoucherSummary;

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
            $purchaseAccGroups['Round Off Plus in Purchase'] = ['type' => 'credit', 'valueAmount' => 'roundoff_amount', 'payment_type' => ''];
        }

        if ($purchase->roundoff_type === 'minus') {
            $purchaseAccGroups['Round Off Minus in Purchase'] = ['type' => 'debit', 'valueAmount' => 'roundoff_amount', 'payment_type' => ''];
        }
        dd($purchase->payment);

        switch ($purchase->customer->ledger_type) {
            case 'customer':
                if (isset($purchase->payment['credit']) && $purchase->payment['credit'] !== null)
                    $purchaseAccGroups['Accounts Receivable (Debtors)'] = ['type' => 'credit', 'valueAmount' => $purchase->payment['credit'], 'payment_type' => 'CREDIT'];
                if (isset($purchase->payment['cash']) && $purchase->payment['cash'] !== null)
                    $purchaseAccGroups['Cash in Hand'] = ['type' => 'credit', 'valueAmount' => $purchase->payment['cash'], 'payment_type' => 'CASH'];
                if (isset($purchase->payment['bank']) && $purchase->payment['bank'] !== null) {
                    $bankAccountGroup = AccountGroup::where('name', "Bank Accounts")->first();
                    $accHeadBank = AccountHead::where(['name', $purchase->payment['bank_name'], 'company_id' => $purchase->company_id, 'account_group_id' => $bankAccountGroup->id])->firstOrCreate();
                    $purchaseAccGroups[$accHeadBank->name] = ['type' => 'credit', 'valueAmount' => $purchase->payment['bank'], 'payment_type' => 'BANK'];
                }
                break;
            default:
                break;
        }

        try {
            foreach ($purchaseAccGroups as $purchaseAccGroupKey => $purchaseAccGroupValue) {

                $accGroup = AccountGroup::where('name', $purchaseAccGroupKey)->first();
                $accHead = AccountHead::where('name', $purchaseAccGroupKey)->first();
                \Log::info($purchaseAccGroupKey, [$accHead]);

                VoucherSummary::create([
                    'date' => $purchase->invoice_date,
                    'date_bs' => $purchase->invoice_date_bs,
                    'company_id' => $purchase->company_id,
                    'branch_id' => null,
                    'voucher_number' => "VOC-818200{$purchase->id}",
                    'particulars' => "Product Purchased from {$purchase->customer->party_name} - Bill No. {$purchase->purchase_bill_number}",
                    'debit' => $purchaseAccGroupValue['type'] === 'debit' ? $purchase->{$purchaseAccGroupValue['valueAmount']} : 0,
                    'credit' => $purchaseAccGroupValue['type'] === 'credit' ? $purchase->{$purchaseAccGroupValue['valueAmount']} : 0,
                    'tr_bill_number' => $purchase->purchase_bill_number,
                    'cheque_number' => "",
                    'type' => "PURCHASE",
                    'payment_type' => $purchaseAccGroupValue['payment_type'] ?? "PURCHASE",
                    'account_group_id' => $accGroup?->id,
                    'account_head_id' => $accHead?->id,
                ]);
            }
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

<?php

namespace App\Observers;

use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\Purchase;
use App\Models\VoucherSummary;
use App\Models\VoucherSummaryDetail;

class PurchaseObserver
{
    /**
     * Handle the Purchase "created" event.
     */
    public function created(Purchase $purchase): void
    {

        $accGroup = AccountGroup::where('name', "Purchase")->first();
        $accHead = AccountHead::where('name', "Purchase")->first();

        $voucher = VoucherSummary::firstOrCreate([
            'date' => $purchase->invoice_date,
            'date_bs' => $purchase->invoice_date_bs,
            'company_id' => $purchase->company_id,
            'branch_id' => null,
            'voucher_number' => "PCVOU-818200{$purchase->id}",
            'particulars' => "Product Purchased from {$purchase->customer->party_name} - Bill No. {$purchase->purchase_bill_number}",
            'debit' => $purchase->sub_total_before_discount,
            'credit' => 0,
            'tr_bill_number' => $purchase->purchase_bill_number,
            'type' => "PURCHASE",
            'payment_type' => "PURCHASE",
            'ref_bill_number' => $purchase->ref_bill_number,
            'account_group_id' => $accGroup?->id,
            'account_head_id' => $accHead?->id,

        ]);

        $purchaseAccGroups = [
            'Discount Income' => ['type' => 'credit', 'valueAmount' => $purchase->discount_value, 'payment_type' => ''],
            'Excise Duty Expenses' => ['type' => 'debit', 'valueAmount' => $purchase->excise_duty, 'payment_type' => ''],
            'VAT Account' => ['type' => 'debit', 'valueAmount' => $purchase->vat_percent, 'payment_type' => ''],
            'Health insurance Expenses' => ['type' => 'debit', 'valueAmount' => $purchase->health_insurance, 'payment_type' => ''],
            'Fright charge' => ['type' => 'debit', 'valueAmount' => $purchase->freight_amount, 'payment_type' => ''],
            'Scheme Discount Income' => ['type' => 'credit', 'valueAmount' => $purchase->discount_after_vat, 'payment_type' => ''],
        ];

        if ($purchase->roundoff_type === 'plus') {
            $purchaseAccGroups['Round Off Plus in Purchase'] = ['type' => 'credit', 'valueAmount' => $purchase->roundoff_amount, 'payment_type' => ''];
        }

        if ($purchase->roundoff_type === 'minus') {
            $purchaseAccGroups['Round Off Minus in Purchase'] = ['type' => 'debit', 'valueAmount' => $purchase->roundoff_amount, 'payment_type' => ''];
        }

        switch ($purchase->customer->ledger_type) {
            case 'customer':
                if (isset($purchase->payment['credit']) && $purchase->payment['credit'] !== null)
                    $purchaseAccGroups['Accounts Receivable (Debtors)'] = ['type' => 'credit', 'valueAmount' => (float) $purchase->payment["credit"], 'payment_type' => 'CREDIT'];
                break;

            default:
                if (isset($purchase->payment['credit']) && $purchase->payment['credit'] !== null)
                    $purchaseAccGroups['Accounts Payable (Creditors)'] = ['type' => 'credit', 'valueAmount' => (float) $purchase->payment["credit"], 'payment_type' => 'CREDIT'];
                break;
        }

        if (isset($purchase->payment['cash']) && $purchase->payment['cash'] !== null)
            $purchaseAccGroups['Cash in Hand'] = ['type' => 'credit', 'valueAmount' => $purchase->payment['cash'], 'payment_type' => 'CASH'];

        if (isset($purchase->payment['bank']) && $purchase->payment['bank'] !== null) {
            $bankAccountGroup = AccountGroup::where('name', '=', "Bank Accounts")->first();
            $accHeadBank = AccountHead::firstOrCreate(['name' => $purchase->payment['bank_name'], 'company_id' => $purchase->company_id, 'account_group_id' => $bankAccountGroup->id, 'is_active' => true, 'code' => ucfirst($purchase->payment['bank_name']), 'is_primary' => true]);
            $purchaseAccGroups[$accHeadBank->name] = ['type' => 'credit', 'valueAmount' => $purchase->payment['bank'], 'payment_type' => 'BANK'];
        }

        try {
            foreach ($purchaseAccGroups as $purchaseAccGroupKey => $purchaseAccGroupValue) {

                $accGroup = AccountGroup::where('name', $purchaseAccGroupKey)->first();
                $accHead = AccountHead::where('name', $purchaseAccGroupKey)->first();

                if (!$accHead && ($purchaseAccGroupKey == "Accounts Receivable (Debtors)" || $purchaseAccGroupKey == "Accounts Payable (Creditors)")) {
                    $accountHead = AccountHead::where(['account_group_id' => $accGroup->id])->orderBy('code', 'DESC')->first();
                    $code = $accountHead ? (int) $accountHead->code + 1 : 1;
                    $accHead = AccountHead::firstOrCreate(['name' => $purchase->customer->party_name, 'company_id' => $purchase->company_id, 'account_group_id' => $accGroup->id, 'is_active' => true, 'code' => $code, 'is_primary' => true]);
                }

                if ($accHead && $purchaseAccGroupKey == "Cash in Hand") {
                    $accountHead = AccountHead::where(['account_group_id' => $accGroup->id])->orderBy('code', 'DESC')->first();
                    $code = $accountHead ? (int) $accountHead->code + 1 : 1;
                    $accHead = AccountHead::firstOrCreate(['name' => $purchase->customer->party_name, 'company_id' => $purchase->company_id, 'account_group_id' => $accGroup->id, 'is_active' => true, 'code' => $code, 'is_primary' => true]);
                }

                if ($purchaseAccGroupValue['valueAmount'] > 0) {
                    VoucherSummaryDetail::create([
                        'date' => $purchase->invoice_date,
                        'voucher_summary_id' => $voucher->id,
                        'date_bs' => $purchase->invoice_date_bs,
                        'company_id' => $purchase->company_id,
                        'branch_id' => null,
                        'voucher_number' => "PCVOU-818200{$purchase->id}",
                        'particulars' => "Product Purchased from {$purchase->customer->party_name} - Bill No. {$purchase->purchase_bill_number}",
                        'debit' => $purchaseAccGroupValue['type'] === 'debit' ? $purchaseAccGroupValue['valueAmount'] : 0,
                        'credit' => $purchaseAccGroupValue['type'] === 'credit' ? $purchaseAccGroupValue['valueAmount'] : 0,
                        'tr_bill_number' => $purchase->purchase_bill_number,
                        'type' => "PURCHASE",
                        'payment_type' => $purchaseAccGroupValue['payment_type'] ?? "PURCHASE",
                        'account_group_id' => $accGroup?->id,
                        'account_head_id' => $accHead?->id,
                    ]);
                }
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

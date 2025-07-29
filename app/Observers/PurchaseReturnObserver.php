<?php

namespace App\Observers;

use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\PurchaseReturn;
use App\Models\VoucherInnerDetail;
use App\Models\VoucherSummary;
use App\Models\VoucherSummaryDetail;

class PurchaseReturnObserver
{
    /**
     * Handle the PurchaseReturn "created" event.
     */
    public function created(PurchaseReturn $purchaseReturn): void
    {
        $accGroup = AccountGroup::where('name', "Purchase Return")->first();
        $accHead = AccountHead::where('name', "Purchase Return")->first();

        $voucher = VoucherSummary::firstOrCreate([
            'date' => $purchaseReturn->invoice_date,
            'date_bs' => $purchaseReturn->invoice_date_bs,
            'company_id' => $purchaseReturn->company_id,
            'branch_id' => null,
            'voucher_number' => "PRVOU-828300{$purchaseReturn->id}",
            'particulars' => "Product Purchase Returned from {$purchaseReturn->customer->party_name} - Bill No. {$purchaseReturn->id}",
            'credit' => $purchaseReturn->sub_total_before_discount,
            'debit' => 0,
            'tr_bill_number' => $purchaseReturn->id,
            'type' => "PURCHASE_RETURN",
            'payment_type' => "PURCHASE_RETURN",
            'ref_bill_number' => $purchaseReturn->ref_bill_no,
            'account_group_id' => $accGroup?->id,
            'account_head_id' => $accHead?->id,

        ]);

        $purchaseAccGroups = [
            'Discount Expenses' => ['type' => 'debit', 'valueAmount' => $purchaseReturn->discount_value, 'payment_type' => ''],
            'Excise Duty Expenses' => ['type' => 'credit', 'valueAmount' => $purchaseReturn->excise_duty, 'payment_type' => ''],
            'VAT Account' => ['type' => 'credit', 'valueAmount' => $purchaseReturn->vat_percent, 'payment_type' => ''],
            'Health insurance Expenses' => ['type' => 'credit', 'valueAmount' => $purchaseReturn->health_insurance, 'payment_type' => ''],
            'Fright charge' => ['type' => 'credit', 'valueAmount' => $purchaseReturn->freight_amount, 'payment_type' => ''],
            'Scheme Discount Income' => ['type' => 'debit', 'valueAmount' => $purchaseReturn->discount_after_vat, 'payment_type' => ''],
        ];

        if ($purchaseReturn->roundoff_type === 'plus') {
            $purchaseAccGroups['Round Off Plus in Purchase'] = ['type' => 'debit', 'valueAmount' => $purchaseReturn->roundoff_amount, 'payment_type' => ''];
        }

        if ($purchaseReturn->roundoff_type === 'minus') {
            $purchaseAccGroups['Round Off Minus in Purchase'] = ['type' => 'credit', 'valueAmount' => $purchaseReturn->roundoff_amount, 'payment_type' => ''];
        }

        switch ($purchaseReturn->customer->ledger_type) {
            case 'customer':

                $partyAccountGroup = AccountGroup::where(['name' => "Accounts Receivable (Debtors)"])->orderBy('code', 'DESC')->first();
                $code = $partyAccountGroup ? (int) $partyAccountGroup->code + 1 : 1;
                $partyHead = AccountHead::where(['name' => $purchaseReturn->customer->party_name, 'company_id' => $purchaseReturn->company_id])->first();

                if (isset($purchaseReturn->payment['credit']) && $purchaseReturn->payment['credit'] !== null)
                    $purchaseAccGroups['Accounts Receivable (Debtors)'] = ['type' => 'credit', 'valueAmount' => (float) $purchaseReturn->payment["credit"], 'payment_type' => 'CREDIT'];
                break;

            default:

                $partyAccountGroup = AccountGroup::where(['name' => "Accounts Payable (Creditors)"])->orderBy('code', 'DESC')->first();
                $code = $partyAccountGroup ? (int) $partyAccountGroup->code + 1 : 1;
                $partyHead = AccountHead::where(['name' => $purchaseReturn->customer->party_name, 'company_id' => $purchaseReturn->company_id])->first();

                if (isset($purchaseReturn->payment['credit']) && $purchaseReturn->payment['credit'] !== null)
                    $purchaseAccGroups['Accounts Payable (Creditors)'] = ['type' => 'credit', 'valueAmount' => (float) $purchaseReturn->payment["credit"], 'payment_type' => 'CREDIT'];
                break;
        }

        try {
            foreach ($purchaseAccGroups as $purchaseAccGroupKey => $purchaseAccGroupValue) {

                $accGroup = AccountGroup::where('name', $purchaseAccGroupKey)->first();
                $accHead = AccountHead::where('name', $purchaseAccGroupKey)->first();

                if (!$accHead && ($purchaseAccGroupKey == "Accounts Receivable (Debtors)" || $purchaseAccGroupKey == "Accounts Payable (Creditors)")) {
                    $accountHead = AccountHead::where(['account_group_id' => $accGroup->id])->orderBy('code', 'DESC')->first();
                    $code = $accountHead ? (int) $accountHead->code + 1 : 1;
                    $accHead = AccountHead::firstOrCreate(['name' => $purchaseReturn->customer->party_name, 'company_id' => $purchaseReturn->company_id, 'account_group_id' => $accGroup->id, 'is_active' => true, 'code' => $code, 'is_primary' => true]);

                }

                if ($purchaseAccGroupValue['valueAmount'] > 0) {
                    VoucherSummaryDetail::create([
                        'date' => $purchaseReturn->invoice_date,
                        'voucher_summary_id' => $voucher->id,
                        'date_bs' => $purchaseReturn->invoice_date_bs,
                        'company_id' => $purchaseReturn->company_id,
                        'branch_id' => null,
                        'voucher_number' => "PRVOU-828300{$purchaseReturn->id}",
                        'particulars' => "Product Purchase Returned from {$purchaseReturn->customer->party_name} - Bill No. {$purchaseReturn->purchase_bill_number}",
                        'debit' => $purchaseAccGroupValue['type'] === 'debit' ? $purchaseAccGroupValue['valueAmount'] : 0,
                        'credit' => $purchaseAccGroupValue['type'] === 'credit' ? $purchaseAccGroupValue['valueAmount'] : 0,
                        'tr_bill_number' => $purchaseReturn->purchase_bill_number,
                        'type' => "PURCHASE",
                        'payment_type' => $purchaseAccGroupValue['payment_type'] ?? "PURCHASE",
                        'account_group_id' => $accGroup?->id,
                        'account_head_id' => $accHead?->id,
                    ]);
                }
            }

            VoucherSummaryDetail::create([
                'date' => $purchaseReturn->invoice_date,
                'voucher_summary_id' => $voucher->id,
                'date_bs' => $purchaseReturn->invoice_date_bs,
                'company_id' => $purchaseReturn->company_id,
                'branch_id' => null,
                'voucher_number' => "PRVOU-828300{$purchaseReturn->id}",
                'particulars' => "Product Purchase Returned from {$purchaseReturn->customer->party_name} - Bill No. {$purchaseReturn->purchase_bill_number}",
                'credit' => 0,
                'debit' => $purchaseReturn->total_amount,
                'tr_bill_number' => $purchaseReturn->purchase_bill_number,
                'type' => "PURCHASE",
                'payment_type' => "CASH",
                'account_group_id' => $partyAccountGroup?->id,
                'account_head_id' => $partyHead?->id,
            ]);


            VoucherInnerDetail::create([
                'voucher_summary_id' => $voucher->id,
                'company_id' => $purchaseReturn->company_id,
                'credit' => $purchaseReturn->total_amount,
                'debit' => 0,
                'particulars' => $partyHead->name,
            ]);


            if (isset($purchaseReturn->payment['cash']) && $purchaseReturn->payment['cash'] !== null && $purchaseReturn->payment['cash'] > 0) {
                $accHead = AccountHead::where(['name' => $purchaseReturn->customer->party_name, 'company_id' => $purchaseReturn->company_id])->first();
                VoucherInnerDetail::create([
                    'voucher_summary_id' => $voucher->id,
                    'company_id' => $purchaseReturn->company_id,
                    'debit' => $purchaseReturn->payment['cash'],
                    'credit' => 0,
                    'particulars' => "Cash",

                ]);
            }

            if (isset($purchaseReturn->payment['bank']) && $purchaseReturn->payment['bank'] !== null && $purchaseReturn->payment['bank'] > 0) {

                $accHead = AccountHead::where(['name' => $purchaseReturn->customer->party_name, 'company_id' => $purchaseReturn->company_id])->first();
                VoucherInnerDetail::create([
                    'voucher_summary_id' => $voucher->id,
                    'company_id' => $purchaseReturn->company_id,
                    'debit' => $purchaseReturn->payment['bank'],
                    'credit' => 0,
                    'particulars' => $purchaseReturn->payment['bank_name'],

                ]);
            }
        } catch (\Exception $e) {
            \Log::error($e);
        }
    }
}

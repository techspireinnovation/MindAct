<?php

namespace App\Observers;

use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\VoucherInnerDetail;
use App\Models\VoucherSummary;
use App\Models\VoucherSummaryDetail;


class SaleReturnObserver
{
    /**
     * Handle the SaleReturn "created" event.
     */
    public function created(SalesReturn $saleReturn): void
    {
        $accGroup = AccountGroup::where('name', "Sales Return")->first();
        $accHead = AccountHead::where('name', "Sales Return")->first();

        $voucher = VoucherSummary::firstOrCreate([
            'date' => $saleReturn->invoice_date,
            'date_bs' => $saleReturn->invoice_date_bs,
            'company_id' => $saleReturn->company_id,
            'branch_id' => null,
            'voucher_number' => "SRVOU-828300{$saleReturn->invoice_number}",
            'particulars' => "Product Sale Returned from {$saleReturn->customer->party_name} - Bill No. {$saleReturn->invoice_number}",
            'debit' => $saleReturn->sub_total_before_discount,
            'credit' => 0,
            'tr_bill_number' => $saleReturn->id,
            'type' => "SALE_RETURN",
            'payment_type' => "SALE_RETURN",
            'ref_bill_number' => $saleReturn->ref_bill_no,
            'account_group_id' => $accGroup?->id,
            'account_head_id' => $accHead?->id,

        ]);

        $purchaseAccGroups = [
            'Discount Expenses' => ['type' => 'credit', 'valueAmount' => $saleReturn->discount_value, 'payment_type' => ''],
            'Excise Duty Expenses' => ['type' => 'debit', 'valueAmount' => $saleReturn->excise_duty, 'payment_type' => ''],
            'VAT Account' => ['type' => 'debit', 'valueAmount' => $saleReturn->vat_percent, 'payment_type' => ''],
            'Health insurance Expenses' => ['type' => 'debit', 'valueAmount' => $saleReturn->health_insurance, 'payment_type' => ''],
            'Fright charge' => ['type' => 'debit', 'valueAmount' => $saleReturn->freight_amount, 'payment_type' => ''],
            'Scheme Discount Income' => ['type' => 'credit', 'valueAmount' => $saleReturn->discount_after_vat, 'payment_type' => ''],
        ];

        if ($saleReturn->roundoff_type === 'plus') {
            $purchaseAccGroups['Round Off Plus in Purchase'] = ['type' => 'debit', 'valueAmount' => $saleReturn->roundoff_amount, 'payment_type' => ''];
        }

        if ($saleReturn->roundoff_type === 'minus') {
            $purchaseAccGroups['Round Off Minus in Purchase'] = ['type' => 'credit', 'valueAmount' => $saleReturn->roundoff_amount, 'payment_type' => ''];
        }

        switch ($saleReturn->customer->ledger_type) {
            case 'customer':

                $partyAccountGroup = AccountGroup::where(['name' => "Accounts Receivable (Debtors)"])->orderBy('code', 'DESC')->first();
                $code = $partyAccountGroup ? (int) $partyAccountGroup->code + 1 : 1;
                $partyHead = AccountHead::firstOrCreate(['name' => $saleReturn->customer->party_name, 'company_id' => $saleReturn->company_id, 'account_group_id' => $partyAccountGroup->id, 'is_active' => true, 'code' => $code, 'is_primary' => true]);

                if (isset($saleReturn->payment['credit']) && $saleReturn->payment['credit'] !== null)
                    $purchaseAccGroups['Accounts Receivable (Debtors)'] = ['type' => 'credit', 'valueAmount' => (float) $saleReturn->payment["credit"], 'payment_type' => 'CREDIT'];
                break;

            default:

                $partyAccountGroup = AccountGroup::where(['name' => "Accounts Payable (Creditors)"])->orderBy('code', 'DESC')->first();
                $code = $partyAccountGroup ? (int) $partyAccountGroup->code + 1 : 1;
                $partyHead = AccountHead::firstOrCreate(['name' => $saleReturn->customer->party_name, 'company_id' => $saleReturn->company_id, 'account_group_id' => $partyAccountGroup->id, 'is_active' => true, 'code' => $code, 'is_primary' => true]);

                if (isset($saleReturn->payment['credit']) && $saleReturn->payment['credit'] !== null)
                    $purchaseAccGroups['Accounts Payable (Creditors)'] = ['type' => 'debit', 'valueAmount' => (float) $saleReturn->payment["credit"], 'payment_type' => 'CREDIT'];
                break;
        }

        try {
            foreach ($purchaseAccGroups as $purchaseAccGroupKey => $purchaseAccGroupValue) {

                $accGroup = AccountGroup::where('name', $purchaseAccGroupKey)->first();
                $accHead = AccountHead::where('name', $purchaseAccGroupKey)->first();

                if (!$accHead && ($purchaseAccGroupKey == "Accounts Receivable (Debtors)" || $purchaseAccGroupKey == "Accounts Payable (Creditors)")) {
                    $accountHead = AccountHead::where(['account_group_id' => $accGroup->id])->orderBy('code', 'DESC')->first();
                    $code = $accountHead ? (int) $accountHead->code + 1 : 1;
                    $accHead = AccountHead::firstOrCreate(['name' => $saleReturn->customer->party_name, 'company_id' => $saleReturn->company_id, 'account_group_id' => $accGroup->id, 'is_active' => true, 'code' => $code, 'is_primary' => true]);

                }

                if ($purchaseAccGroupValue['valueAmount'] > 0) {
                    VoucherSummaryDetail::create([
                        'date' => $saleReturn->invoice_date,
                        'voucher_summary_id' => $voucher->id,
                        'date_bs' => $saleReturn->invoice_date_bs,
                        'company_id' => $saleReturn->company_id,
                        'branch_id' => null,
                        'voucher_number' => "PRVOU-828300{$saleReturn->id}",
                        'particulars' => "Product Purchase Returned from {$saleReturn->customer->party_name} - Bill No. {$saleReturn->purchase_bill_number}",
                        'credit' => $purchaseAccGroupValue['type'] === 'debit' ? $purchaseAccGroupValue['valueAmount'] : 0,
                        'debit' => $purchaseAccGroupValue['type'] === 'credit' ? $purchaseAccGroupValue['valueAmount'] : 0,
                        'tr_bill_number' => $saleReturn->purchase_bill_number,
                        'type' => "PURCHASE",
                        'payment_type' => $purchaseAccGroupValue['payment_type'] ?? "PURCHASE",
                        'account_group_id' => $accGroup?->id,
                        'account_head_id' => $accHead?->id,
                    ]);
                }
            }

            VoucherSummaryDetail::create([
                'date' => $saleReturn->invoice_date,
                'voucher_summary_id' => $voucher->id,
                'date_bs' => $saleReturn->invoice_date_bs,
                'company_id' => $saleReturn->company_id,
                'branch_id' => null,
                'voucher_number' => "PRVOU-828300{$saleReturn->id}",
                'particulars' => "Product Purchase Returned from {$saleReturn->customer->party_name} - Bill No. {$saleReturn->purchase_bill_number}",
                'debit' => 0,
                'credit' => $saleReturn->total_amount,
                'tr_bill_number' => $saleReturn->purchase_bill_number,
                'type' => "PURCHASE",
                'payment_type' => "CASH",
                'account_group_id' => $partyAccountGroup?->id,
                'account_head_id' => $partyHead?->id,
            ]);


            VoucherInnerDetail::create([
                'voucher_summary_id' => $voucher->id,
                'company_id' => $saleReturn->company_id,
                'debit' => $saleReturn->total_amount,
                'credit' => 0,
                'particulars' => $partyHead->name,
            ]);


            if (isset($saleReturn->payment['cash']) && $saleReturn->payment['cash'] !== null && $saleReturn->payment['cash'] > 0) {
                $accHead = AccountHead::where(['name' => $saleReturn->customer->party_name, 'company_id' => $saleReturn->company_id])->first();
                VoucherInnerDetail::create([
                    'voucher_summary_id' => $voucher->id,
                    'company_id' => $saleReturn->company_id,
                    'credit' => $saleReturn->payment['cash'],
                    'debit' => 0,
                    'particulars' => "Cash",

                ]);
            }

            if (isset($saleReturn->payment['bank']) && $saleReturn->payment['bank'] !== null && $saleReturn->payment['bank'] > 0) {

                $accHead = AccountHead::where(['name' => $saleReturn->customer->party_name, 'company_id' => $saleReturn->company_id])->first();
                VoucherInnerDetail::create([
                    'voucher_summary_id' => $voucher->id,
                    'company_id' => $saleReturn->company_id,
                    'credit' => $saleReturn->payment['bank'],
                    'debit' => 0,
                    'particulars' => $saleReturn->payment['bank_name'],

                ]);
            }
        } catch (\Exception $e) {
            \Log::error($e);
        }
    }


}

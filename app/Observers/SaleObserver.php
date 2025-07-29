<?php

namespace App\Observers;

use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\Sale;
use App\Models\VoucherInnerDetail;
use App\Models\VoucherSummary;
use App\Models\VoucherSummaryDetail;

class SaleObserver
{
    /**
     * Handle the Sale "created" event.
     */
    public function created(Sale $sale): void
    {

        $accGroup = AccountGroup::where('name', "Sales")->first();
        $accHead = AccountHead::where('name', "Sales")->first();

        $customerName = isset($sale->customer) ? $sale->customer->party_name : $sale->customer_name;

        $voucher = VoucherSummary::firstOrCreate([
            'date' => $sale->invoice_date,
            'date_bs' => $sale->invoice_date_bs,
            'company_id' => $sale->company_id,
            'branch_id' => null,
            'voucher_number' => "SLVOU-818200{$sale->id}",
            'particulars' => "Product Sales to {$customerName} from Bill No. {$sale->invoice_number}",
            'credit' => $sale->sub_total_before_discount,
            'debit' => 0,
            'tr_bill_number' => $sale->invoice_number,
            'type' => "SALE",
            'payment_type' => "SALE",
            'ref_bill_number' => $sale->ref_number,
            'account_group_id' => $accGroup?->id,
            'account_head_id' => $accHead?->id,

        ]);

        $saleAccGroups = [
            'Discount Expenses' => ['type' => 'debit', 'valueAmount' => $sale->discount, 'payment_type' => ''],
            'Excise Duty Expenses' => ['type' => 'credit', 'valueAmount' => $sale->excise_duty, 'payment_type' => ''],
            'VAT Account' => ['type' => 'credit', 'valueAmount' => $sale->vat_amount, 'payment_type' => ''],
            'Health insurance income' => ['type' => 'credit', 'valueAmount' => $sale->health_insurance, 'payment_type' => ''],
            'Fright charge income' => ['type' => 'credit', 'valueAmount' => $sale->freight_charge, 'payment_type' => ''],
            'Scheme Discount' => ['type' => 'debit', 'valueAmount' => $sale->discount_after_vat, 'payment_type' => ''],
        ];

        if ($sale->roundoff_type === 'plus') {
            $saleAccGroups['Round Off Plus in Purchase'] = ['type' => 'debit', 'valueAmount' => $sale->round_off_amount, 'payment_type' => ''];
        }

        if ($sale->roundoff_type === 'minus') {
            $saleAccGroups['Round Off Minus in Purchase'] = ['type' => 'credit', 'valueAmount' => $sale->round_off_amount, 'payment_type' => ''];
        }

        switch ($sale->customer->ledger_type) {
            case 'customer':

                $partyAccountGroup = AccountGroup::where(['name' => "Accounts Receivable (Debtors)"])->orderBy('code', 'DESC')->first();
                $code = $partyAccountGroup ? (int) $partyAccountGroup->code + 1 : 1;
                $partyHead = AccountHead::where(['name' => $customerName, 'company_id' => $sale->company_id])->first();

                if (isset($sale->payment['credit']) && $sale->payment['credit'] !== null)
                    $saleAccGroups['Accounts Receivable (Debtors)'] = ['type' => 'credit', 'valueAmount' => (float) $sale->payment["credit"], 'payment_type' => 'CREDIT'];
                break;

            case 'vendor':
            case 'both':
                $partyAccountGroup = AccountGroup::where(['name' => "Accounts Payable (Creditors)"])->orderBy('code', 'DESC')->first();
                $code = $partyAccountGroup ? (int) $partyAccountGroup->code + 1 : 1;
                $partyHead = AccountHead::where(['name' => $customerName, 'company_id' => $sale->company_id])->first();

                if (isset($sale->payment['credit']) && $sale->payment['credit'] !== null)
                    $saleAccGroups['Accounts Payable (Creditors)'] = ['type' => 'credit', 'valueAmount' => (float) $sale->payment["credit"], 'payment_type' => 'CREDIT'];
                break;

            default:
                $partyAccountGroup = AccountGroup::where(['name' => "Accounts Payable (Creditors)"])->orderBy('code', 'DESC')->first();
                $code = $partyAccountGroup ? (int) $partyAccountGroup->code + 1 : 1;
                $partyHead = AccountHead::firstOrCreate(['name' => $sale->customer_name, 'company_id' => $sale->company_id, 'account_group_id' => $partyAccountGroup->id, 'is_active' => true, 'code' => $code, 'is_primary' => true]);

                if (isset($saleReturn->payment['credit']) && $sale->payment['credit'] !== null)
                    $saleAccGroups['Accounts Receivable (Debtors)'] = ['type' => 'debit', 'valueAmount' => (float) $sale->payment["credit"], 'payment_type' => 'CREDIT'];
                break;

        }

        try {
            foreach ($saleAccGroups as $saleAccGroupKey => $saleAccGroupValue) {

                $accGroup = AccountGroup::where('name', $saleAccGroupKey)->first();
                $accHead = AccountHead::where('name', $saleAccGroupKey)->first();

                if (!$accHead && ($saleAccGroupKey == "Accounts Receivable (Debtors)" || $saleAccGroupKey == "Accounts Payable (Creditors)")) {
                    $accountHead = AccountHead::where(['account_group_id' => $accGroup->id])->orderBy('code', 'DESC')->first();
                    $code = $accountHead ? (int) $accountHead->code + 1 : 1;
                    $accHead = AccountHead::firstOrCreate(['name' => $sale->customer_name, 'company_id' => $sale->company_id, 'account_group_id' => $accGroup->id, 'is_active' => true, 'code' => $code, 'is_primary' => true]);

                }

                if ($saleAccGroupValue['valueAmount'] > 0) {
                    VoucherSummaryDetail::create([
                        'date' => $sale->invoice_date,
                        'voucher_summary_id' => $voucher->id,
                        'date_bs' => $sale->invoice_date_bs,
                        'company_id' => $sale->company_id,
                        'branch_id' => null,
                        'voucher_number' => "SLVOU-818200{$sale->id}",
                        'particulars' => "Product Sales to {$customerName} from Bill No. {$sale->invoice_number}",
                        'debit' => $saleAccGroupValue['type'] === 'debit' ? $saleAccGroupValue['valueAmount'] : 0,
                        'credit' => $saleAccGroupValue['type'] === 'credit' ? $saleAccGroupValue['valueAmount'] : 0,
                        'tr_bill_number' => $sale->invoice_number,
                        'type' => "PURCHASE",
                        'payment_type' => $saleAccGroupValue['payment_type'] ?? "SALE",
                        'account_group_id' => $accGroup?->id,
                        'account_head_id' => $accHead?->id,
                    ]);
                }
            }

            VoucherSummaryDetail::create([
                'date' => $sale->invoice_date,
                'voucher_summary_id' => $voucher->id,
                'date_bs' => $sale->invoice_date_bs,
                'company_id' => $sale->company_id,
                'branch_id' => null,
                'voucher_number' => "SLVOU-818200{$sale->id}",
                'particulars' => "Product Sales to {$customerName} from Bill No. {$sale->invoice_number}",
                'credit' => 0,
                'debit' => $sale->total_amount,
                'tr_bill_number' => $sale->invoice_number,
                'type' => "PURCHASE",
                'payment_type' => "CASH",
                'account_group_id' => $partyAccountGroup?->id,
                'account_head_id' => $partyHead?->id,
            ]);


            VoucherInnerDetail::create([
                'voucher_summary_id' => $voucher->id,
                'company_id' => $sale->company_id,
                'credit' => $sale->total_amount,
                'debit' => 0,
                'particulars' => $partyHead->name,
            ]);


            if (isset($sale->payment['cash']) && $sale->payment['cash'] !== null && $sale->payment['cash'] > 0) {

                $accHead = AccountHead::where(['name' => $customerName, 'company_id' => $sale->company_id])->first();
                VoucherInnerDetail::create([
                    'voucher_summary_id' => $voucher->id,
                    'company_id' => $sale->company_id,
                    'debit' => $sale->payment['cash'],
                    'credit' => 0,
                    'particulars' => "Cash",

                ]);
            }

            if (isset($sale->payment['bank']) && $sale->payment['bank'] !== null && $sale->payment['bank'] > 0) {

                $accHead = AccountHead::where(['name' => $customerName, 'company_id' => $sale->company_id])->first();
                VoucherInnerDetail::create([
                    'voucher_summary_id' => $voucher->id,
                    'company_id' => $sale->company_id,
                    'debit' => $sale->payment['bank'],
                    'credit' => 0,
                    'particulars' => $sale->payment['bank_name'],

                ]);
            }
        } catch (\Exception $e) {
            \Log::error($e);
        }

    }

}

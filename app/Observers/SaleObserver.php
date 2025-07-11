<?php

namespace App\Observers;

use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\VoucherSummary;

class SaleObserver
{
    /**
     * Handle the Sale "created" event.
     */
    public function created(Sale $sale): void
    {
        $saleAccGroups = [
            'Sales' => ['type' => 'credit', 'valueAmount' => $sale->sub_total_before_discount, 'payment_type' => ''],
            'Discount Expenses' => ['type' => 'debit', 'valueAmount' => $sale->discount_value, 'payment_type' => ''],
            'Excise Duty Income' => ['type' => 'credit', 'valueAmount' => $sale->excise_duty, 'payment_type' => ''],
            'VAT Account' => ['type' => 'credit', 'valueAmount' => $sale->vat_amount, 'payment_type' => ''],
            'Health insurance Income' => ['type' => 'credit', 'valueAmount' => $sale->health_insurance, 'payment_type' => ''],
            'Fright Charge Income' => ['type' => 'credit', 'valueAmount' => $sale->freight_amount, 'payment_type' => ''],
            'Scheme Discount' => ['type' => 'debit', 'valueAmount' => $sale->discount_after_vat, 'payment_type' => ''],
        ];

        if ($sale->roundoff_type === 'plus') {
            $saleAccGroups['Round Off Plus in Sales'] = ['type' => 'credit', 'valueAmount' => $sale->roundoff_amount, 'payment_type' => ''];
        }

        if ($sale->roundoff_type === 'minus') {
            $saleAccGroups['Round Off Minus in Sales'] = ['type' => 'debit', 'valueAmount' => $sale->roundoff_amount, 'payment_type' => ''];
        }

        switch ($sale->customer->ledger_type) {
            case 'customer':
                if (isset($sale->payment['credit']) && $sale->payment['credit'] !== null)
                    $saleAccGroups['Accounts Payable (Creditors)'] = ['type' => 'credit', 'valueAmount' => (float) $sale->payment["credit"], 'payment_type' => 'CREDIT'];
                break;
            default:
                if (isset($sale->payment['credit']) && $sale->payment['credit'] !== null)
                    $saleAccGroups['Accounts Payable (Debtors)'] = ['type' => 'credit', 'valueAmount' => (float) $sale->payment["credit"], 'payment_type' => 'CREDIT'];
                break;
        }

        if (isset($sale->payment['cash']) && $sale->payment['cash'] !== null)
            $saleAccGroups['Cash in Hand'] = ['type' => 'debit', 'valueAmount' => $sale->payment['cash'], 'payment_type' => 'CASH'];

        if (isset($sale->payment['bank']) && $sale->payment['bank'] !== null) {
            $bankAccountGroup = AccountGroup::where('name', '=', "Bank Accounts")->first();
            $accHeadBank = AccountHead::firstOrCreate(['name' => $sale->payment['bank_name'], 'company_id' => $sale->company_id, 'account_group_id' => $bankAccountGroup->id, 'is_active' => true, 'code' => ucfirst($sale->payment['bank_name']), 'is_primary' => true]);
            $saleAccGroups[$accHeadBank->name] = ['type' => 'debit', 'valueAmount' => $sale->payment['bank'], 'payment_type' => 'BANK'];
        }

        try {
            foreach ($saleAccGroups as $saleAccGroupKey => $saleAccGroupValue) {

                $accGroup = AccountGroup::where('name', $saleAccGroupKey)->first();
                $accHead = AccountHead::where('name', $saleAccGroupKey)->first();

                VoucherSummary::create([
                    'date' => $sale->invoice_date,
                    'date_bs' => $sale->invoice_date_bs,
                    'company_id' => $sale->company_id,
                    'branch_id' => null,
                    'voucher_number' => "SLVOU-818200{$sale->id}",
                    'particulars' => "Product Sale to - {$sale->customer->party_name} from Bill No. {$sale->invoice_number}",
                    'debit' => $saleAccGroupValue['type'] === 'debit' ? $saleAccGroupValue['valueAmount'] : 0,
                    'credit' => $saleAccGroupValue['type'] === 'credit' ? $saleAccGroupValue['valueAmount'] : 0,
                    'tr_bill_number' => $sale->invoice_number,
                    'type' => "SALE",
                    'payment_type' => $saleAccGroups['payment_type'] ?? "SALE",
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
    public function updated(Sale $purchase): void
    {
        //
    }

    /**
     * Handle the Purchase "deleted" event.
     */
    public function deleted(Sale $sale): void
    {
        //
    }

    /**
     * Handle the Purchase "restored" event.
     */
    public function restored(Sale $sale): void
    {
        //
    }

    /**
     * Handle the Purchase "force deleted" event.
     */
    public function forceDeleted(Purchase $sale): void
    {
        //
    }
}

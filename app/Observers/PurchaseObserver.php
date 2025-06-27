<?php

namespace App\Observers;

use App\Models\Purchase;
use App\Models\VoucherSummary;

class PurchaseObserver
{
    /**
     * Handle the Purchase "created" event.
     */
    public function created(Purchase $purchase): void
    {
        VoucherSummary::create([
            'date' => $purchase->invoice_date,
            'date_bs' => $purchase->invoice_date_bs,
            'company_id' => $purchase->company_id,
            'branch_id' => null,
            'voucher_number' => "asdfdsf",
            'particulars' => "Product Purchased from- {$purchase->customer->party_name} from Bill No. {$purchase->purchase_bill_number}",
            'debit' => 0,
            'credit' => 0,
            'tr_bill_number' => $purchase->purchase_bill_number,
            'cheque_number' => "234",
            'type' => "PURCHASE",
            'account_head_id' => 9,
        ]);
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

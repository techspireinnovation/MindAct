<?php

namespace App\Observers;

use App\Models\ReceiptVoucherDetail;
use App\Models\VoucherSummary;

class ReceiptVoucherDetailObserver
{
    /**
     * Handle the ReceiptVoucherDetail "created" event.
     */
    public function created(ReceiptVoucherDetail $receiptVoucherDetail): void
    {
        VoucherSummary::create([
            'date' => $receiptVoucherDetail->receiptVoucher->date_ad,
            'date_bs' => $receiptVoucherDetail->receiptVoucher->date_bs,
            'company_id' => $receiptVoucherDetail->company_id,
            'branch_id' => null,
            'voucher_number' => $receiptVoucherDetail->receiptVoucher->receipt_voucher_number,
            'particulars' => $receiptVoucherDetail->remarks,
            //  'debit' => $journalVoucherTransaction->debit,
            // 'credit' => $journalVoucherTransaction->credit,
            // 'tr_bill_number' => $journalVoucherTransaction->journalVoucher->reference_number,
            //'cheque_number' => $journalVoucherTransaction->type,
            // 'type' => "RECEIPT_VOUCHER",
            // 'account_head_id' => $journalVoucherTransaction->account_head_id,
        ]);
    }

    /**
     * Handle the ReceiptVoucherDetail "updated" event.
     */
    public function updated(ReceiptVoucherDetail $receiptVoucherDetail): void
    {
        //
    }

    /**
     * Handle the ReceiptVoucherDetail "deleted" event.
     */
    public function deleted(ReceiptVoucherDetail $receiptVoucherDetail): void
    {
        //
    }

    /**
     * Handle the ReceiptVoucherDetail "restored" event.
     */
    public function restored(ReceiptVoucherDetail $receiptVoucherDetail): void
    {
        //
    }

    /**
     * Handle the ReceiptVoucherDetail "force deleted" event.
     */
    public function forceDeleted(ReceiptVoucherDetail $receiptVoucherDetail): void
    {
        //
    }
}

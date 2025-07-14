<?php

namespace App\Observers;

use App\Models\AccountGroup;
use App\Models\ReceiptVoucherDetail;
use App\Models\VoucherSummary;

class ReceiptVoucherDetailObserver
{
    /**
     * Handle the ReceiptVoucherDetail "created" event.
     */
    public function created(ReceiptVoucherDetail $receiptVoucherDetail): void
    {
        switch ($receiptVoucherDetail->customer->ledger_type) {
            case 'customer':
                $bankAccountGroup = AccountGroup::where('name', '=', "Accounts Receivable (Debtors)")->first();
                break;
            default:
                $bankAccountGroup = AccountGroup::where('name', '=', "Accounts Payable (Creditors)")->first();
                break;
        }

        VoucherSummary::create([
            'date' => $receiptVoucherDetail->receiptVoucher->date_ad,
            'date_bs' => $receiptVoucherDetail->receiptVoucher->date_bs,
            'company_id' => $receiptVoucherDetail->company_id,
            'branch_id' => null,
            'voucher_number' => $receiptVoucherDetail->receiptVoucher->receipt_voucher_number,
            'particulars' => $receiptVoucherDetail->remarks,
            'payment_type' => strtoupper($receiptVoucherDetail->contra_account),
            'credit' => $receiptVoucherDetail->amount,
            'debit' => 0,
            'tr_bill_number' => $receiptVoucherDetail->receiptVoucher->reference_number,
            'cheque_number' => $receiptVoucherDetail->cheque_slip,
            'type' => "RECEIPT_VOUCHER",
            'account_group_id' => $bankAccountGroup?->id,
            'is_parent' => true,
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

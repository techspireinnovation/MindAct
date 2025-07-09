<?php

namespace App\Observers;

use App\Models\AccountGroup;
use App\Models\PaymentVoucherDetail;
use App\Models\ReceiptVoucherDetail;
use App\Models\VoucherSummary;

class PaymentVoucherDetailObserver
{
    /**
     * Handle the ReceiptVoucherDetail "created" event.
     */
    public function created(PaymentVoucherDetail $paymentVoucherDetail): void
    {
        switch ($paymentVoucherDetail->customer->ledger_type) {
            case 'customer':
                $bankAccountGroup = AccountGroup::where('name', '=', "Accounts Receivable (Debtors)")->first();
                break;
            default:
                $bankAccountGroup = AccountGroup::where('name', '=', "Accounts Payable (Creditors)")->first();
                break;
        }

        VoucherSummary::create([
            'date' => $paymentVoucherDetail->receiptVoucher->date_ad,
            'date_bs' => $paymentVoucherDetail->receiptVoucher->date_bs,
            'company_id' => $paymentVoucherDetail->company_id,
            'branch_id' => null,
            'voucher_number' => $paymentVoucherDetail->receiptVoucher->payment_voucher_number,
            'particulars' => $paymentVoucherDetail->remarks,
            'payment_type' => strtoupper($paymentVoucherDetail->contra_acount),
            'credit' => $paymentVoucherDetail->amount,
            'debit' => 0,
            'tr_bill_number' => $paymentVoucherDetail->receiptVoucher->reference_number,
            'cheque_number' => $paymentVoucherDetail->cheque_slip,
            'type' => "PAYMENT_VOUCHER",
            'account_group_id' => $bankAccountGroup?->id,
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

<?php

namespace App\Observers;

use App\Models\JournalVoucherTransaction;
use App\Models\VoucherSummary;
use Pratiksh\Nepalidate\Services\EnglishDate;

class JournalVoucherTransactionObserver
{
    /**
     * Handle the JournalVoucherTransaction "created" event.
     */
    public function created(JournalVoucherTransaction $journalVoucherTransaction): void
    {
        VoucherSummary::create([
            'date' => EnglishDate::create($journalVoucherTransaction->journalVoucher->date)->toAD(),
            'date_bs' => $journalVoucherTransaction->journalVoucher->date,
            'company_id' => $journalVoucherTransaction->company_id,
            'branch_id' => null,
            'voucher_number' => $journalVoucherTransaction->journalVoucher->voucher_number,
            'particulars' => $journalVoucherTransaction->particulars,
            'debit' => $journalVoucherTransaction->debit,
            'credit' => $journalVoucherTransaction->credit,
            'tr_bill_number' => $journalVoucherTransaction->journalVoucher->reference_number,
            'cheque_number' => $journalVoucherTransaction->type,
            'type' => "JOURNAL_VOUCHER",
            'payment_type' => "BANK",
            'account_head_id' => $journalVoucherTransaction->account_head_id,
            'is_parent' => true,
        ]);
    }

    /**
     * Handle the JournalVoucherTransaction "updated" event.
     */
    public function updated(JournalVoucherTransaction $journalVoucherTransaction): void
    {
        //
    }

    /**
     * Handle the JournalVoucherTransaction "deleted" event.
     */
    public function deleted(JournalVoucherTransaction $journalVoucherTransaction): void
    {
        //
    }

    /**
     * Handle the JournalVoucherTransaction "restored" event.
     */
    public function restored(JournalVoucherTransaction $journalVoucherTransaction): void
    {
        //
    }

    /**
     * Handle the JournalVoucherTransaction "force deleted" event.
     */
    public function forceDeleted(JournalVoucherTransaction $journalVoucherTransaction): void
    {
        //
    }
}

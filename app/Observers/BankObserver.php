<?php

namespace App\Observers;

use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\Bank;

class BankObserver
{
    /**
     * Handle the Bank "created" event.
     */
    public function created(Bank $bank): void
    {
        $bankAccountGroup = AccountGroup::where('name', '=', "Bank Accounts")->first();

        AccountHead::firstOrCreate(['name' => $bank->name, 'company_id' => $bank->company_id, 'account_group_id' => $bankAccountGroup->id, 'is_active' => true, 'code' => ucfirst($bank->name), 'is_primary' => true]);
    }

    /**
     * Handle the Bank "updated" event.
     */
    public function updated(Bank $bank): void
    {
        //
    }

    /**
     * Handle the Bank "deleted" event.
     */
    public function deleted(Bank $bank): void
    {
        //
    }

    /**
     * Handle the Bank "restored" event.
     */
    public function restored(Bank $bank): void
    {
        //
    }

    /**
     * Handle the Bank "force deleted" event.
     */
    public function forceDeleted(Bank $bank): void
    {
        //
    }
}

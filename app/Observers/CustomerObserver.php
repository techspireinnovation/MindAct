<?php

namespace App\Observers;

use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\Customer;

class CustomerObserver
{
    /**
     * Handle the Customer "created" event.
     */
    public function created(Customer $customer): void
    {
        $name = match ($customer->ledger_type) {
            'customer' => "Accounts Receivable (Debtors)",
            default => "Accounts Payable (Creditors)",
        };

        $bankAccountGroup = AccountGroup::where('name', '=', $name)->first();
        $accountHead = AccountHead::where(['account_group_id' => $bankAccountGroup->id])->orderBy('code', 'DESC')->first();
        $code = $accountHead ? (int) $accountHead->code + 1 : 1;
        AccountHead::firstOrCreate(['name' => $customer->party_name, 'company_id' => $customer->company_id, 'account_group_id' => $bankAccountGroup->id, 'is_active' => true, 'code' => $code, 'is_primary' => true]);

    }

    /**
     * Handle the Customer "updated" event.
     */
    public function updated(Customer $customer): void
    {
        //
    }

    /**
     * Handle the Customer "deleted" event.
     */
    public function deleted(Customer $customer): void
    {
        //
    }

    /**
     * Handle the Customer "restored" event.
     */
    public function restored(Customer $customer): void
    {
        //
    }

    /**
     * Handle the Customer "force deleted" event.
     */
    public function forceDeleted(Customer $customer): void
    {
        //
    }
}

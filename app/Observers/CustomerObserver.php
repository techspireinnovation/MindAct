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
        switch ($customer->ledger_type) {

            case 'customer':
                $bankAccountGroup = AccountGroup::where('name', '=', "Accounts Receivable (Debtors)")->first();
                AccountHead::firstOrCreate(['name' => $customer->party_name, 'company_id' => $customer->company_id, 'account_group_id' => $bankAccountGroup->id, 'is_active' => true, 'code' => ucfirst($customer->party_name), 'is_primary' => true]);
                break;

            default:
                $bankAccountGroup = AccountGroup::where('name', '=', "Accounts Payable (Creditors)")->first();
                AccountHead::firstOrCreate(['name' => $customer->party_name, 'company_id' => $customer->company_id, 'account_group_id' => $bankAccountGroup->id, 'is_active' => true, 'code' => ucfirst($customer->party_name), 'is_primary' => true]);
                break;
        }

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

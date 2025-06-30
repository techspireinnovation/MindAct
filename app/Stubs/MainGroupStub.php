<?php

namespace App\Stubs;

use App\Models\AccountGroup;
use App\Models\MainGroup;
use App\Models\SubGroup;
use Str;


class MainGroupStub
{

    public static function createMainGroups(int $companyId)
    {
        $mainGroups = array(
            'Assets' => array(
                'Non-Current Asset' => array(
                    '1001' => 'Land',
                    '1002' => 'Building',
                    '1003' => 'Plant and Machinery',
                    '1004' => 'Furniture and Fixtures',
                    '1005' => 'Vehicles',
                    '1006' => 'Intangible Assets',
                ),
                'Investment' => array(
                    '1101' => 'Long-Term Investments',
                ),
                'Stock/Inventory' => array(
                    '1201' => 'Inventory / Stock',
                ),
                'Current Asset' => array(
                    '1202' => 'Accounts Receivable (Debtors)',
                    '1203' => 'Advance to Employees',
                    '1204' => 'Cash in Hand',
                    '1205' => 'Bank Accounts',
                    '1206' => 'Prepaid Expenses',
                    '1207' => 'Short-term Investments',
                ),
            ),
            'Liabilities' => array(
                'Equity' => array(
                    '1301' => 'Capital / Share Capital',
                    '1302' => 'Retained Earnings',
                    '1303' => 'Reserves',
                ),
                'Non-Current Liability' => array(
                    '1401' => 'Long-Term Loans',
                    '1402' => 'Lease Liabilities',
                ),
                'Current Liability' => array(
                    '1501' => 'Accounts Payable (Creditors)',
                    '1502' => 'Short-Term Loans',
                    '1503' => 'Accrued Expenses',
                    '1504' => 'Income Tax Payable',
                    '1505' => 'Provisions',
                    '1506' => 'Unearned Revenue',
                ),
            ),
            'Income' => array(
                'Revenue' => array(
                    '2001' => 'Sales Revenue',
                    '2002' => 'Service Revenue',
                    '2003' => 'Commission Income',
                    '2004' => 'Interest Income',
                    '2005' => 'Rental Income',
                    '2006' => 'Other Operating Income',
                ),
                'Other Income' => array(
                    '2403' => 'Miscellaneous Income',
                ),
            ),
            'Expenses' => array(
                'Direct Expense' => array(
                    '2101' => 'Cost of Goods Sold (COGS)',
                    '2102' => 'Direct Labor',
                    '2103' => 'Freight Inward',
                    '2104' => 'Power & Fuel',
                    '2105' => 'Raw Material Consumption',
                ),
                'Operating Expense' => array(
                    '2201' => 'Salaries & Wages',
                    '2202' => 'Rent Expense',
                    '2203' => 'Utilities',
                    '2204' => 'Repairs & Maintenance',
                    '2205' => 'Communication Expenses',
                    '2206' => 'Office Supplies',
                    '2207' => 'Insurance Expense',
                    '2208' => 'Marketing & Promotion',
                    '2209' => 'Depreciation Expense',
                    '2210' => 'Training & Development',
                ),
                'Financial Expense' => array(
                    '2301' => 'Bank Charges',
                    '2302' => 'Interest Expense',
                ),
                'Other Income' => array(
                    '2401' => 'Gain on Asset Sale',
                    '2403' => 'Miscellaneous Income',
                ),
                'Other Expense' => array(
                    '2402' => 'Loss on Asset Sale',
                ),
            ),
        );


        foreach ($mainGroups as $key => $mainGroup) {

            $newMainGroup = MainGroup::firstOrCreate([
                'name' => $key,
                'company_id' => $companyId
            ]);

            foreach ($mainGroup as $mainGroupKey1 => $accountGroups) {
                $subGroup = SubGroup::firstOrCreate([
                    'name' => $mainGroupKey1,
                    'company_id' => $companyId,
                    'main_group_id' => $newMainGroup->id,
                    'code' => Str::upper($mainGroupKey1),
                    'ranking_for_trial' => 1,
                    'is_active' => true,
                ]);

                foreach ($accountGroups as $accountGroupKey => $accountGroup) {

                    AccountGroup::firstOrCreate([
                        'name' => $accountGroup,
                        'company_id' => $companyId,
                        'main_group_id' => $newMainGroup->id,
                        'sub_group_id' => $subGroup->id,
                        'code' => $accountGroupKey,
                        'is_active' => true,

                    ]);
                }
            }

        }
    }
}
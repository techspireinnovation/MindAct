<?php

namespace App\Stubs;

use App\Models\AccountGroup;
use App\Models\AccountHead;
use App\Models\MainGroup;
use App\Models\SubGroup;


class MainGroupStub
{

    public static function createMainGroups(int $companyId)
    {
        $chartOfAccounts = [
            'Assets' => [
                'Non-Current Asset' => [
                    '1001' => [
                        'group' => 'Land',
                        'heads' => []
                    ],
                    '1002' => [
                        'group' => 'Building',
                        'heads' => []
                    ],
                    '1003' => [
                        'group' => 'Plant and Machinery',
                        'heads' => []
                    ],
                    '1004' => [
                        'group' => 'Furniture and Fixtures',
                        'heads' => []
                    ],
                    '1005' => [
                        'group' => 'Vehicles',
                        'heads' => []
                    ],
                    '1006' => [
                        'group' => 'Intangible Assets',
                        'heads' => []
                    ],
                ],
                'Investment' => [
                    '1007' => [
                        'group' => 'Long-Term Investments',
                        'heads' => []
                    ],
                ],
                'Stock/Inventory' => [
                    '1010' => [
                        'group' => 'Inventory / Stock',
                        'heads' => []
                    ],
                ],
                'Current Asset' => [
                    '1020' => [
                        'group' => 'Accounts Receivable (Debtors)',
                        'heads' => []
                    ],
                    '1030' => [
                        'group' => 'Advance',
                        'heads' => []
                    ],
                    '1040' => [
                        'group' => 'Cash Accounts',
                        'heads' => [
                            'Cash in Hand',
                            'Petty Cash',
                            'Counter 1'
                        ]
                    ],
                    '1041' => [
                        'group' => 'Bank Accounts',
                        'heads' => []
                    ],
                    '1050' => [
                        'group' => 'Prepaid Expenses',
                        'heads' => []
                    ],
                    '1060' => [
                        'group' => 'Short-term Investments',
                        'heads' => []
                    ],
                ],
            ],
            'Liabilities' => [
                'Equity' => [
                    '1301' => [
                        'group' => 'Capital / Share Capital',
                        'heads' => []
                    ],
                    '3010' => [
                        'group' => 'Retained Earnings',
                        'heads' => []
                    ],
                    '3020' => [
                        'group' => 'Reserves',
                        'heads' => []
                    ],
                ],
                'Non-Current Liability' => [
                    '2001' => [
                        'group' => 'Long-Term Loans',
                        'heads' => []
                    ],
                    '2002' => [
                        'group' => 'Lease Liabilities',
                        'heads' => []
                    ],
                ],
                'Current Liability' => [
                    '2010' => [
                        'group' => 'Accounts Payable (Creditors)',
                        'heads' => []
                    ],
                    '2020' => [
                        'group' => 'Short-Term Loans',
                        'heads' => []
                    ],
                    '2030' => [
                        'group' => 'Accrued Expenses',
                        'heads' => []
                    ],
                    '2040' => [
                        'group' => 'Provisions',
                        'heads' => []
                    ],
                    '2050' => [
                        'group' => 'Unearned Revenue',
                        'heads' => []
                    ],
                    '2090' => [
                        'group' => 'Payable',
                        'heads' => [
                            'TDS- Salary',
                            'TDS-Audit Fee',
                            'TDS-House Rent',
                            'TDS-Salary'
                        ]
                    ],
                    '2060' => [
                        'group' => 'VAT Account',
                        'heads' => [
                            'VAT 13%'
                        ]
                    ],
                    '2070' => [
                        'group' => 'Duties & Taxes',
                        'heads' => [
                            'Income Tax Payable'
                        ]
                    ],
                ],
            ],
            'Income' => [
                'Sales Income' => [
                    '4001' => [
                        'group' => 'Export Sales',

                    ],
                    '4002' => [
                        'group' => 'Export Sales-Return',

                    ],
                    '4010' => [
                        'group' => 'Sales',

                    ],
                    '4011' => [
                        'group' => 'Sales Return',

                    ],
                    '4020' => [
                        'group' => 'Capital Sales',

                    ],
                    '4021' => [
                        'group' => 'Capital Sales-Return',

                    ],
                    '4030' => [
                        'group' => 'Services Sales',

                    ],
                    '4031' => [
                        'group' => 'Services Sales-Return',

                    ],
                    '4040' => [
                        'group' => 'Excise duty income',

                    ],
                    '4050' => [
                        'group' => 'health insurance income',

                    ],
                    '4060' => [
                        'group' => 'fright charge income',

                    ],
                ],
                'Revenue' => [
                    '4070' => [
                        'group' => 'Service Revenue',
                        'heads' => []
                    ],
                    '4080' => [
                        'group' => 'Commission Income',
                        'heads' => []
                    ],
                    '4090' => [
                        'group' => 'Interest Income',
                        'heads' => []
                    ],
                    '4100' => [
                        'group' => 'Rental Income',
                        'heads' => []
                    ],
                    '4110' => [
                        'group' => 'Other Operating Income',
                        'heads' => []
                    ],
                ],
                'other income' => [
                    '4120' => [
                        'group' => 'Discount Income',
                        'heads' => []
                    ],
                    '4130' => [
                        'group' => 'Scheme Discount Income',

                    ],
                ],
                'Other Income' => [
                    '4140' => [
                        'group' => 'Miscellaneous Income',
                        'heads' => []
                    ],
                ],
                'indirect income' => [
                    '4150' => [
                        'group' => 'Round Off Plus in Sales',

                    ],
                    '4151' => [
                        'group' => 'Round Off Minus in Purchase',

                    ],
                ],
            ],
            'Expenses' => [
                'Direct Expenses' => [
                    '5001' => [
                        'group' => 'Purchase',

                    ],
                    '5002' => [
                        'group' => 'Purchase Return',

                    ],
                    '5010' => [
                        'group' => 'Freight Inward',

                    ],
                    '5011' => [
                        'group' => 'Carriage Inward',

                    ],
                    '5020' => [
                        'group' => 'import purchase',

                    ],
                    '5021' => [
                        'group' => 'import purchase return',

                    ],
                    '5030' => [
                        'group' => 'service purchase',

                    ],
                    '5031' => [
                        'group' => 'service purchase return',

                    ],
                    '5040' => [
                        'group' => 'capital purchase',

                    ],
                    '5041' => [
                        'group' => 'capital purchase return',

                    ],
                    '5050' => [
                        'group' => 'Custom Fee Expenses',

                    ],
                    '5060' => [
                        'group' => 'wages',

                    ],
                    '5070' => [
                        'group' => 'Excise Duty Expenses',

                    ],
                    '5080' => [
                        'group' => 'health insurance Expenses',

                    ],
                    '5090' => [
                        'group' => 'fright charge',

                    ],
                    '5100' => [
                        'group' => 'Discount Expenses',

                    ],
                    '5110' => [
                        'group' => 'Scheme Discount',

                    ],
                ],
                'Indirect Expense' => [
                    '5120' => [
                        'group' => 'Direct Expenses',
                        'heads' => [
                            'Fuel for Production',
                            'Packing Charges (Direct)',
                            'Factory Rent'
                        ]
                    ],
                    '5130' => [
                        'group' => 'Administrative Expenses',
                        'heads' => [
                            'Office Rent',
                            'Office Salaries',
                            'Stationery & Printing',
                            'Telephone & Internet',
                            'Electricity (Office)',
                            'Legal & Professional Fees',
                            'Software Subscription',
                            'Audit Fees',
                            'Postage & Courier'
                        ]
                    ],
                    '5140' => [
                        'group' => 'Selling & Distribution Expenses',
                        'heads' => [
                            'Advertisement & Promotion',
                            'Sales Commission',
                            'Packing Charges (Selling)',
                            'Carriage Outward',
                            'Delivery Charges',
                            'Trade Fair Expenses',
                            'Discount Allowed'
                        ]
                    ],
                    '5150' => [
                        'group' => 'Financial Expenses',
                        'heads' => [
                            'Bank Charges',
                            'Interest on Loan',
                            'Loan Processing Fees',
                            'Interest on Overdraft',
                            'Cheque Bounce Charges'
                        ]
                    ],
                    '5160' => [
                        'group' => 'Depreciation & Amortization',
                        'heads' => [
                            'Depreciation on Machinery',
                            'Depreciation on Furniture',
                            'Amortization of Intangible Assets'
                        ]
                    ],
                    '5170' => [
                        'group' => 'Miscellaneous Expenses',
                        'heads' => [
                            'General Expenses',
                            'Entertainment Expenses',
                            'Subscription Fees',
                            'Membership Fees',
                            'Gifts & Donations'
                        ]
                    ],
                    '5180' => [
                        'group' => 'Employee Benefit Expenses',
                        'heads' => [
                            'Staff Welfare'
                        ]
                    ],
                    '5190' => [
                        'group' => 'Round Off Minus in Sales',

                    ],
                    '5191' => [
                        'group' => 'Round Off Plus in Purchase',

                    ],
                ],
            ],
        ];



        foreach ($chartOfAccounts as $key => $mainGroup) {

            $newMainGroup = MainGroup::firstOrCreate([
                'name' => $key,
                'is_active' => true,
                'company_id' => $companyId,
                'is_primary' => true,
            ]);

            $subGroupCode = 1;
            foreach ($mainGroup as $mainGroupKey1 => $accountGroups) {
                $subGroup = SubGroup::firstOrCreate([
                    'name' => ucfirst($mainGroupKey1),
                    'company_id' => $companyId,
                    'main_group_id' => $newMainGroup->id,
                    'code' => $subGroupCode++,
                    'ranking_for_trial' => 1,
                    'is_active' => true,
                    'is_primary' => true,
                ]);

                $accountGroupCode = 1;



                foreach ($accountGroups as $accountGroup) {



                    if (isset($accountGroup['group'])) {
                        $newAccountGroup = AccountGroup::firstOrCreate([
                            'name' => ucfirst($accountGroup['group']),
                            'company_id' => $companyId,
                            'main_group_id' => $newMainGroup->id,
                            'sub_group_id' => $subGroup->id,
                            'code' => $accountGroupCode++,
                            'is_active' => true,
                            'is_primary' => true,
                        ]);
                    }



                    if (isset($accountGroup['heads'])) {

                        $accountHeadCode = 1;

                        foreach ($accountGroup['heads'] as $accountHead) {

                            AccountHead::firstOrCreate([
                                'name' => ucfirst($accountHead),
                                'company_id' => $companyId,
                                'account_group_id' => $newAccountGroup->id,
                                'code' => $accountHeadCode++,
                                'is_active' => true,
                                'is_primary' => true,

                            ]);
                        }
                    }
                }
            }

        }
    }
}
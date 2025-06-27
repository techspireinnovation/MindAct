<?php

namespace App\Stubs;

use App\Models\MainGroup;


class MainGroupStub
{

    public static function createMainGroups()
    {

        $request = request();
        $names = ['Assets', 'Liabilities', 'Income', 'Expenses'];

        foreach ($names as $name) {

            MainGroup::firstOrCreate([
                'name' => $name,
                'company_id' => $request->company_id,
            ]);
        }
    }
}
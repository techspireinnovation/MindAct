<?php

namespace App\Helpers;

use Sagautam5\LocalStateNepal\Entities\Province;

class PermissionsHelper
{
    public static function getPermissionsArray()
    {
        return ['branches', 'suppliers', 'banks'];

    }

}
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

abstract class BaseTenantModel extends Model
{
    /**
     * Use the tenant connection for all models extending this class.
     */
    protected $connection = 'tenant';
}

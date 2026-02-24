<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\Blameable;


abstract class BaseTenantModel extends Model
{
    /**
     * Use the tenant connection for all models extending this class.
     */
    use SoftDeletes, Blameable;
    protected $connection = 'tenant';
}

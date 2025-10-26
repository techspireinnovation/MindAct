<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\SalesReturn;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Scopes\CompanyIdScope;

class SaleReturnAdditional extends BaseTenantModel
{
    use HasFactory, SoftDeletes;

    protected $table = 'sale_return_additionals';

    protected $fillable = [
        'company_id',
        'branch_id',
        'sales_return_id',
        'place',
        'transport',
        'vehicle_number',
        'vehicle_name',
        'driver_name',
        'return_code',
        'driver_contact_number',
        'return_date',
        'return_time'
    ];

    protected $dates = ['deleted_at', 'return_date'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function salesReturn()
    {
        return $this->belongsTo(SalesReturn::class);
    }
}

<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Salesman extends BaseTenantModel
{
    use SoftDeletes, HasFactory;
    protected $dates = ['deleted_at'];

    

    protected $fillable = [
        'company_id',
        'email',
        'mobile',
        'salesman_id',
        'pan_number',
        'name',
        'address',
        'working_office',
        'joining_date',
        'designation',
        'dob',
        'area',
        'ward_no',
        'state',
        'country',
        'citizenship_number',
        'nationality',
        'zone',
        'district',
        'is_primary',
        'is_active',
        'vdc_municipality'
    ];

    public function sales()
    {
        return $this->hasMany(Sale::class, 'salesman_id');
    }

}

<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use \Illuminate\Database\Eloquent\Factories\HasFactory;
use Stancl\Tenancy\Database\Concerns\UsesTenantConnection;

use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends BaseTenantModel
{
    use SoftDeletes, HasFactory;

    protected $connection = 'tenant';


    protected $casts = [
        'is_active' => 'boolean'
    ];
    protected $fillable = [
        'name',
        'is_active',
        'is_primary',
        'branch_type',
        'deleted_at',
       
    ];

    protected $dates = ['deleted_at'];

   

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'branch_user', 'branch_id', 'user_id');
    }



    public function shrinkWorkLoss()
    {
        return $this->hasMany(ShrinkWorkLoss::class, 'branch_id');
    }

    public function stockReconciliation()
    {
        return $this->hasMany(StockReconciliation::class, 'branch_id');
    }
}

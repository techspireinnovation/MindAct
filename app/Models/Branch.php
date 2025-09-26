<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use \Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use SoftDeletes, HasFactory;


    protected $casts =[
        'is_active' => 'boolean'
    ];
    protected $fillable = [
        'name',
        'is_active',
        'is_primary',
        'deleted_at',
        'company_id',
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }

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

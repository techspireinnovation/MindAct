<?php

namespace App\Models;

use App\Models\MainGroup;
use App\Models\SubGroup;
use Illuminate\Database\Eloquent\Model;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class AccountGroup extends Model
{
    use softDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean'
    ];


    protected $fillable=[
        'name',
        'company_id',
        'main_group_id',
        'sub_group_id',
        'code',
        'is_active',
        'is_primary',
        'deleted_at'
    ];

    public function mainGroup(){
        return $this->belongsTO(MainGroup::class,'main_group_id');
    }

    public function subGroup(){
        return $this->belongsTO(SubGroup::class,'sub_group_id');
    }

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}

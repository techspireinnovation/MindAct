<?php

namespace App\Models;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
class MainGroup extends Model
{
    use softDeletes;

    protected $fillable=[
        'name',
        'company_id',
        'deleted_at'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}

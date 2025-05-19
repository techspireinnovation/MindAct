<?php

namespace App\Models;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class MainGroup extends Model
{
    use softDeletes, HasFactory;

    protected $casts =[
        'is_active' => 'boolean',
        'is_primary' => 'boolean'
    ];

    protected $fillable=[
        'name',
        'company_id',
        'is_active',
        'is_primary',
        'deleted_at'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}

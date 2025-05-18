<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;

class Salesman extends Model
{
    use softDeletes, HasFactory;

    protected $fillable = [
        'name',
        'company_id',
        'is_active',
    ];


    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}

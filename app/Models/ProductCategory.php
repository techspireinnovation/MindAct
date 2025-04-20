<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ProductCategory extends Model
{
    use HasFactory;
    use softDeletes;

    protected $casts = [
        'is_active' => 'boolean'
    ];

    protected $fillable=[
        'name',
        'company_id',
        'is_active',
        'deleted_at'
    ];


    protected $date = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}

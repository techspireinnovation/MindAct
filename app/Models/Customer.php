<?php

namespace App\Models;

use App\Models\Sale;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use SoftDeletes, HasFactory;
    
    protected $casts = [
        'is_active' => 'boolean'
    ];  
    protected $fillable = [
        'company_id',
        'name',
        'phone',
        'email',
        'address',
        'pan_vat_number',
        'is_active'
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}

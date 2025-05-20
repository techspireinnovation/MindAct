<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean'
    ];
    protected $fillable = [
        'name',
        'is_primary',
        'is_active',
        'deleted_at',
        'company_id',
        'contact_details',
        'starting_date',
        'ending_date',
        'budget',
        'manager_name',
        'contact_number'
    ];

    protected $dates = ['deleted_at', 'starting_date', 'ending_date'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}

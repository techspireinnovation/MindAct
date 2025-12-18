<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\softDeletes;

class Area extends BaseTenantModel
{

    use softDeletes, HasFactory;

    protected $casts = [
    'is_active'  => 'boolean',
    'is_primary' => 'boolean',
];

    protected $fillable = [
        'name',
       
        'is_active',
        'is_primary',
        'delete_status'

    ];
    protected $dates = ['deleted_at'];



   
}

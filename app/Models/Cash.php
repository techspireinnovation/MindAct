<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;

class Cash extends BaseTenantModel
{
    use softDeletes, HasFactory;


    protected $casts = [

        'is_active' => 'boolean',
        'is_primary' => 'boolean'
    ];
    protected $fillable = [
        
        'name',
        'is_primary',
        'is_active',
    ];



    protected $dates = ['deleted_at'];

   

    
}

<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Store extends BaseTenantModel
{
    use HasFactory, SoftDeletes;

    protected $casts = [
        'is_active' => 'boolean',
        'is_primary' => 'boolean'
    ];
    protected $fillable = [
        'name',
        'is_active',
        'is_primary',
        'deleted_at',
        
    ];

    protected $dates = ['deleted_at'];

    

    public function purchases()
    {
        return $this->hasMany(Stock::class, 'store_id')->where('type','purchase');
    }

    public function sales()
    {
        return $this->hasMany(Stock::class, 'store_id')->where('type','sale');
    }

   

}

<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use \Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\SoftDeletes;

class Branch extends Model
{
    use SoftDeletes, HasFactory;
  

    protected $casts =[
        'is_active' => 'boolean'
    ];
    protected $fillable = [
        'name',
        'is_active',
        'is_primary',
        'deleted_at',
        'company_id',
    ];
    
    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}

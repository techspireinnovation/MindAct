<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;

class Cash extends Model
{
    use softDeletes, HasFactory;


    protected $casts = [
       
        'is_active' => 'boolean'
    ];
   protected $fillable = [
        'company_id',
        'name',
        'is_primary',
        'is_active',
    ];

  

    protected $dates = ['deleted_at'];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
}

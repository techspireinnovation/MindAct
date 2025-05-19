<?php

namespace App\Models;

<<<<<<< HEAD
use App\Models\Scopes\CompanyIdScope;
=======
>>>>>>> e5476b003ebf28cb9a7a82dcca8df3f9e490c1a7
use Illuminate\Database\Eloquent\Model;

class Salesman extends Model
{
<<<<<<< HEAD
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
=======
    protected $fillable = [
        'company_id',
        'email',
        'mobile',
        'salesman_id',
        'pan_number',
        'name',
        'address',
        'working_office',
        'joining_date',
        'designation',
        'dob',
        'citizenship_number',
        'nationality',
        'zone',
        'district',
        'vdc/municipality'
    ];
>>>>>>> e5476b003ebf28cb9a7a82dcca8df3f9e490c1a7
}

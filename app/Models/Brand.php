<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Validator;

class Brand extends BaseTenantModel
{
    use softDeletes, HasFactory;

    protected $casts = [
        'is_primary' => 'boolean',
        'is_active' => 'boolean'
    ];

    protected $fillable = [
        'name',
        'company_id',
        'is_primary',
        'is_active',
    ];


    protected $dates = ['deleted_at'];

    


    //Below code for testing

    public function isValid()
    {
        $validator = Validator::make($this->attributes, [
            'name' => 'required|string|max:255',
            'company_id' => 'required|exists:companies,id',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors', $validator->errors()]);

        }

        return !$validator->fails();  
    }
    public function isActive()
    {
        return $this->is_active;
    }



    public function products()
    {
        return $this->hasMany(Product::class, 'brand_id');
    }


}

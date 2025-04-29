<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Facades\Validator;


use Illuminate\Database\Eloquent\Model;

class Brand extends Model
{
    use softDeletes, HasFactory;

    protected $fillable=[
        'name',
        'company_id',
        'is_active',
    ];


    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }


    //Below code for testing

    public function isValid()
{
    $validator = Validator::make($this->attributes, [
        'name' => 'required|string|max:255',
        'company_id' => 'required|exists:companies,id', 
        'is_active' => 'boolean', 
    ]);

    if ($validator->fails()) {
       return response()->json(['errors',$validator->errors()]);
     
    }

    return !$validator->fails();  // Returns true if validation passes, false if it fails
}
public function isActive()
{
    return $this->is_active;
}


}

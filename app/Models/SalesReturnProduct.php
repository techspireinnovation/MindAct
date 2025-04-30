<?php

namespace App\Models;
<<<<<<< HEAD

use Illuminate\Database\Eloquent\Factories\HasFactory;
=======
use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
>>>>>>> 0053a2b161c3fb291b3f7f8e9939c3557dcdab93
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class SalesReturnProduct extends Model
{
    use HasFactory, SoftDeletes;

    protected $casts = [
        'is_active' => 'boolean',
    ];
    protected $fillable = [
        'company_id',
<<<<<<< HEAD
        'item_id',
        'information',
        'expiry_date',
        'quantity',
        'expiry_date',
        'measure_unit_id',
        'rate',
        'discount_percent',
        'discount_amount',
        'is_active',

    ];
=======
        'product_id',  
        'expiry_date',
        'quantity',
        'free_quantity',  
        'price',  
        'discount_percent',
        'discount_amount',
        'is_vatable',  
        'measure_unit_id',
    ];
    
   
>>>>>>> 0053a2b161c3fb291b3f7f8e9939c3557dcdab93

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }


<<<<<<< HEAD
=======


>>>>>>> 0053a2b161c3fb291b3f7f8e9939c3557dcdab93
    
}

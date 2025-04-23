<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleProduct extends Model
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean'
    ];
    
    protected $fillable = [
        'company_id',
        'sale_id',
        'information',
        'available_quantity',
        'quantity',
        'uom',
        'rate',        
        'total_items',
        'discount',
        'is_active'
    ];
    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
    }
    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }
    
}

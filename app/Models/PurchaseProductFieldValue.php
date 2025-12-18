<?php

namespace App\Models;

use App\Models\Scopes\CompanyIdScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;

class PurchaseProductFieldValue extends BaseTenantModel
{
    use SoftDeletes, HasFactory;

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected $fillable = [
      
        'branch_id',
        'product_field_id',
        'product_id',
        'purchase_product_id',
        'quantity_index',
        'quantity_type',
        'value',
        'deleted_at',
    ];

    protected $dates = ['deleted_at'];

    

    public function productField()
    {
        return $this->belongsTo(ProductField::class, 'product_field_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseProduct()
    {
        return $this->belongsTo(PurchaseProduct::class, 'purchase_product_id');
    }
}
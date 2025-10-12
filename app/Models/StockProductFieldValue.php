<?php

namespace App\Models;
use App\Models\Product;
use App\Models\ProductField;
use App\Models\PurchaseProduct;
use App\Models\Scopes\CompanyIdScope;
use Illiuminate\Http\Request;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;



class StockProductFieldValue extends Model
{
    use SoftDeletes, HasFactory;

    protected $fillable = [
        'company_id',
        'product_field_id',
        'product_id',
        'stock_product_id',
        'quantity_index',
        
        'value',
        'deleted_at',
    ];

    protected $dates = ['deleted_at'];

    protected static function booted()
    {
        static::addGlobalScope(new CompanyIdScope());
        static::creating(function ($model) {
            // Only set if not already set
            if (empty($model->company_id)) {
                $headerValue = Request::input('company_id');
                $model->company_id = $headerValue;
            }
        });
    }

    public function productField()
    {
        return $this->belongsTo(ProductField::class, 'product_field_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stockProduct()
    {
        return $this->belongsTo(StockEntry::class, 'stock_product_id');
    }

}

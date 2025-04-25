<?php

namespace App\Models;

use App\Models\MeasureUnit;
use App\Models\Product;
use App\Models\Scopes\CompanyIdScope;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\softDeletes;

use Illuminate\Database\Eloquent\Model;

use Request;

class ProductList extends Model
{
  use softDeletes, HasFactory;

  protected $casts = [
    'is_active' => 'boolean',
  ];

  protected $fillable = [
    'product_id',
    'measure_unit_id',
    'company_id',
    'quantity',
    'barcode',
    'hs_code',
    'price',
    'discount',
    'final_price',
    'primary_measure_unit_id',
    'deleted_at',

  ];

  protected $dates = ['deleted_at'];

  protected static function booted()
  {
    static::addGlobalScope(new CompanyIdScope());
    static::creating(function ($model) {
      // Only set if not already set
      if (empty($model->company_id)) {
        // Get the header value, fallback to 'US'
        $headerValue = Request::input('company_id');
        $model->company_id = $headerValue;
      }
    });
  }

  public function product()
  {
    return $this->belongsTo(Product::class, 'product_id');
  }

  public function measureUnit()
  {
    return $this->belongsTo(MeasureUnit::class, 'measure_unit_id');
  }

}

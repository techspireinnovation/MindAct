<?php

namespace App\Models;

use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;





class DeleteTenant extends Model
{
    use SoftDeletes;

    
    public $incrementing = false;
    protected $connection = 'mysql';

    protected $table = 'tenants';
    protected $keyType = 'string';
    protected $casts = ['data' => 'array'];

    protected $fillable = ['id','database', 'data', 'company_id'];

   

    //    public function setDataAttribute($value)
// {
//     unset($value['database'], $value['created_at'], $value['updated_at']);
//     $this->attributes['data'] = json_encode($value, JSON_UNESCAPED_UNICODE);
// }


}
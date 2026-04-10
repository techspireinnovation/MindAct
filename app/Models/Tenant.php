<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDataColumn;
use Illuminate\Database\Eloquent\SoftDeletes;

use Stancl\Tenancy\Database\Concerns\HasDomains;

class Tenant extends Model
{
    use softDeletes;

    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';
    protected $casts = ['data' => 'array'];

    protected $fillable = ['id', 'database', 'data', 'company_id','tenancy_slug'];

    protected static function booted() // <-- use booted() in Laravel 8+
    {
        parent::booted();

        static::creating(function ($tenant) {
            // Generate UUID if not set
            if (empty($tenant->id)) {
                $tenant->id = (string) Str::uuid();
            }


        });
    }

    //    public function setDataAttribute($value)
// {
//     unset($value['database'], $value['created_at'], $value['updated_at']);
//     $this->attributes['data'] = json_encode($value, JSON_UNESCAPED_UNICODE);
// }


}
<?php

namespace App\Models;

use App\Models\User;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class CompanyUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'user_id',
    ];

    protected static function booted()
    {
        static::creating(function ($companyUser) {
            if (!isset($companyUser->company_id) && auth()->check() && auth()->user()->company_id) {
                $companyUser->company_id = auth()->user()->company_id;
                Log::info('CompanyUser::creating - Set company_id', [
                    'user_id' => $companyUser->user_id,
                    'company_id' => $companyUser->company_id,
                ]);
            } elseif (!isset($companyUser->company_id)) {
                Log::warning('CompanyUser::creating - No authenticated user or company_id found', [
                    'user_id' => $companyUser->user_id,
                ]);
                throw new \Exception('Cannot set company_id: No authenticated user or company_id available');
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class, 'company_id', 'id');
    }
}
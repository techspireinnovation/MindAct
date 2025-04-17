<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanyUser extends Model
{
    use HasFactory;
    protected $fillable = [
        'company_id',
        'user_id',
    ];



    public function user(){
        return $this->belogsTo(User::class,'user_id');
    }
}

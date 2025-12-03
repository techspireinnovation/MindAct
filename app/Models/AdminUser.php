<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AdminUser extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The connection name for the model.
     *
     * @var string
     */
    protected $connection = 'tenant';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'admin_users';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'name',
        'email',
        'phone_number',
        'address',
        'citizenship_number',
        'pan_number',
        'role'
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'deleted_at'
    ];

    /**
     * Scope a query to only include active records.
     * Note: We don't have is_active field in migration, but adding for future
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }

    /**
     * Scope a query to only include records of a specific role.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $role
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRole($query, $role)
    {
        return $query->where('role', $role);
    }

    /**
     * Scope a query to only include trashed records.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeTrashed($query)
    {
        return $query->whereNotNull('deleted_at');
    }

    /**
     * Find admin user by main user ID.
     *
     * @param  int  $userId
     * @return \App\Models\Tenant\AdminUser|null
     */
    public static function findByUserId($userId)
    {
        return static::where('user_id', $userId)->first();
    }

    /**
     * Find admin user by email.
     *
     * @param  string  $email
     * @return \App\Models\Tenant\AdminUser|null
     */
    public static function findByEmail($email)
    {
        return static::where('email', $email)->first();
    }

    /**
     * Find admin user by citizenship number.
     *
     * @param  string  $citizenshipNumber
     * @return \App\Models\Tenant\AdminUser|null
     */
    public static function findByCitizenshipNumber($citizenshipNumber)
    {
        return static::where('citizenship_number', $citizenshipNumber)->first();
    }

    /**
     * Find admin user by PAN number.
     *
     * @param  string  $panNumber
     * @return \App\Models\Tenant\AdminUser|null
     */
    public static function findByPanNumber($panNumber)
    {
        return static::where('pan_number', $panNumber)->first();
    }

    /**
     * Check if email already exists.
     *
     * @param  string  $email
     * @param  int|null  $exceptId
     * @return bool
     */
    public static function emailExists($email, $exceptId = null)
    {
        $query = static::where('email', $email);
        
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        
        return $query->exists();
    }

    /**
     * Check if citizenship number already exists.
     *
     * @param  string  $citizenshipNumber
     * @param  int|null  $exceptId
     * @return bool
     */
    public static function citizenshipNumberExists($citizenshipNumber, $exceptId = null)
    {
        $query = static::where('citizenship_number', $citizenshipNumber);
        
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        
        return $query->exists();
    }

    /**
     * Check if PAN number already exists.
     *
     * @param  string  $panNumber
     * @param  int|null  $exceptId
     * @return bool
     */
    public static function panNumberExists($panNumber, $exceptId = null)
    {
        $query = static::where('pan_number', $panNumber);
        
        if ($exceptId) {
            $query->where('id', '!=', $exceptId);
        }
        
        return $query->exists();
    }

    /**
     * Get the main user instance from central database.
     * Note: This is a manual method since we can't use Eloquent relationships across databases
     *
     * @return \App\Models\User|null
     */
    public function getMainUserAttribute()
    {
        // You need to switch to main database connection
        // Implementation depends on your database setup
        try {
            // Store current connection
            $currentConnection = config('database.default');
            
            // Switch to main database
            config(['database.default' => 'mysql']);
            
            // Get user from main database
            $user = \App\Models\User::find($this->user_id);
            
            // Restore connection
            config(['database.default' => $currentConnection]);
            
            return $user;
        } catch (\Exception $e) {
            // Restore connection on error
            config(['database.default' => $currentConnection ?? 'tenant']);
            return null;
        }
    }

    /**
     * Determine if the admin user is a super admin.
     *
     * @return bool
     */
    public function isSuperAdmin()
    {
        return $this->role === 'super_admin';
    }

    /**
     * Determine if the admin user is a company admin.
     *
     * @return bool
     */
    public function isCompanyAdmin()
    {
        return in_array($this->role, ['admin', 'super_admin', 'company_admin']);
    }

    /**
     * Determine if the admin user is a manager.
     *
     * @return bool
     */
    public function isManager()
    {
        return $this->role === 'manager';
    }

    /**
     * Get the display name with role.
     *
     * @return string
     */
    public function getDisplayNameAttribute()
    {
        return "{$this->name} ({$this->role})";
    }

    /**
     * Get formatted phone number.
     *
     * @return string
     */
    public function getFormattedPhoneAttribute()
    {
        $phone = preg_replace('/[^0-9]/', '', $this->phone_number);
        
        if (strlen($phone) === 10) {
            return '+977 ' . substr($phone, 0, 3) . ' ' . substr($phone, 3, 3) . ' ' . substr($phone, 6);
        }
        
        return $this->phone_number;
    }

    /**
     * Boot the model.
     *
     * @return void
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            // Ensure email is lowercase
            $model->email = strtolower($model->email);
            
            // Ensure citizenship number is uppercase
            if ($model->citizenship_number) {
                $model->citizenship_number = strtoupper($model->citizenship_number);
            }
            
            // Ensure PAN number is uppercase
            if ($model->pan_number) {
                $model->pan_number = strtoupper($model->pan_number);
            }
        });

        static::updating(function ($model) {
            // Ensure email is lowercase on update
            if ($model->isDirty('email')) {
                $model->email = strtolower($model->email);
            }
            
            // Ensure citizenship number is uppercase on update
            if ($model->isDirty('citizenship_number') && $model->citizenship_number) {
                $model->citizenship_number = strtoupper($model->citizenship_number);
            }
            
            // Ensure PAN number is uppercase on update
            if ($model->isDirty('pan_number') && $model->pan_number) {
                $model->pan_number = strtoupper($model->pan_number);
            }
        });
    }
}
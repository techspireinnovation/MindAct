<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Laravel\Sanctum\Sanctum;

class SuperAdminAuthTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'super_admin','guard_name' =>'api']);


    }


    public function test_super_admin_register(){
        
        $response = $this->postJson('/api/register',[
            'name' => 'Super Admin',
            'email' => 'super@gmail.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
    ]);
    
    $response->assertStatus(201)->assertJson([
        'success' =>true,
        'message' => 'Super admin registered successfully'

    ]);

    $this->assertDatabaseHas('users',[
        'email' =>'super@gmail.com'
    ]);


    }


    public function test_super_admin_login(){
        $user = User::factory()->create([
            'email' =>'admin@example.com',
            'password' =>Hash::make('password123')
        ]);

        $user->assignRole('super_admin');

        $response = $this->postJson('/api/login',[
            'email'=>'admin@example.com',
            'password' =>'password123'
        ]);

        $response->assertStatus(200)->assertJson([
            'success' =>true,
            'message' => 'Super admin login successfull'

        ]);

    }

    public function test_super_admin_can_change_password(){

        $user = User::factory()->create([

            'password' => Hash::make('oldpassword'),

        ]);
        $user->assignRole('super_admin');
        $token = $user->createToken('SuperAdminToken',['super_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization',"Bearer $token")
        ->putJson('api/admin/change-password',[
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword',
            'new_password_confirmation' => 'newpassword',

        ]);
        $response->assertstatus(200)->assertJson([
            'success' =>true,
            'message' => 'Password changed successfully'

        ]);
        

    }

    public function test_super_admin_logout(){
        $user = User::factory()->create();
        $user->assignRole('super_admin');
        $token = $user->createToken('SuperAdminToken',['super_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('api/admin/logout');

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Logout successful'
        ]);


    }
}
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;


use App\Models\User;
use App\Models\Company;
use App\Models\CompanyUser;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Laravel\Sanctum\Sanctum;

use Tests\TestCase;

class CompanyAuthTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'super_admin','guard_name' =>'api']);
        Role::create(['name' => 'company_admin','guard_name' =>'api']);


    }
    public function test_company_admin_login(){
        $user = User::factory()->create([
            'email' =>'admin@example.com',
            'password' =>Hash::make('password123')
        ]);

        $user->assignRole('super_admin');


        $companyAdmin = User::factory()->create([
            'email' =>'company@gmail.com',
            'password' =>Hash::make('password123')
        ]);
        $companyAdmin->assignRole('company_admin');
        $company = Company::factory()->create([
            'name' => 'Test Company',
            
        ]);
        $comapanyUser = CompanyUser::factory()->create([
            'company_id' => $company->id,
            'user_id' => $companyAdmin->id
        ]); 
      
        $token = $companyAdmin->createToken('MAatraErpToken',['company_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization',"Bearer $token");
        $response = $this->postJson('api/company/login',[
            'email'=>'company@gmail.com',
            'password' =>'password123'
        ]);
        $response->assertStatus(200)->assertJson([
            'success' =>true,
            'message' => 'Company Admin Login successful.'

        ]);
    }


    public function test_company_admin_can_update_profile(){
        $user = User::factory()->create([
            'name' => 'Test Company Admin',
            'email' => 'company@gmail.com',
            'password' => Hash::make('password123')
        ]);
        $user->assignRole('company_admin');
        $company = Company::factory()->create([
            'name' => 'Test Company',
            
        ]);
        $comapanyUser = CompanyUser::factory()->create([
            'company_id' => $company->id,
            'user_id' => $user->id
        ]);
        $token = $user->createToken('MatraErpToken',['company_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization',"Bearer $token")
        ->putJson('api/company/update',[
            'name' => 'Test Updated Company Name',
            'admin_name' => 'Updated Company Name',
            'admin_email' => 'update@gmail.com',

            ]);
        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Company details updated successfully',
        ])
        ->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'company' => [
                    'id',
                    'name',
                    
                  
                ],
                'user' => [
                    'id',
                    'name',
                    'email',
                   
                ],
            ],
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Updated Company Name',
            'email' => 'update@gmail.com',
        ]);
    }



    public function test_company_admin_can_change_password(){

        $user = User::factory()->create([

            'password' => Hash::make('oldpassword'),

        ]);

        $user->assignRole('company_admin');
        $token = $user->createToken('MatraErp',['company_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization',"Bearer $token")
        ->putJson('api/company/change-password',[
            'current_password' => 'oldpassword',
            'new_password' => 'newpassword',
            'new_password_confirmation' => 'newpassword',

        ]);
        $response->assertstatus(200)->assertJson([
            'success' =>true,
            'message' => 'Password changed successfully'

        ]);
        

    }

    public function test_company_admin_logout(){
        $user = User::factory()->create();
        $user->assignRole('company_admin');
        $token = $user->createToken('MatraErpToken',['company_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer $token")
        ->getJson('api/company/logout');

        $response->assertStatus(200)->assertJson([
            'success' => true,
            'message' => 'Company admin logout successful'
        ]);


    }
}

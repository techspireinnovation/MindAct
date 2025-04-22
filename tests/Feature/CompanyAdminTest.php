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

class CompanyAdminTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;
    public function setUp():void
    {
        parent::setUp();

        Role::create(['name' => 'super_admin', 'guard_name' => 'api']);
        Role::create(['name' => 'company_admin', 'guard_name' => 'api']);


    }

    public function test_super_admin_can_store_company_and_admin()
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');
        $token = $superAdmin->createToken('TestToken', ['super_admin'])->plainTextToken;

        $data = [
            "name" => "Test Company",   
            "licence_issue_date"=> "2025-10-12",
            "working_date"=> "2025-10-12",
            "reg_number"=> "234234",
            "full_address"=> "full addres",
            "email_address"=> "pelop@gmail.com",
            "website"=> "www.nay.com",
            "fax"=> "1031232025",
            "logo"=> "https=>//images.unsplash.com/photo-1498462440456-0dba182e775b?q=80&w=3087&auto=format&fit=crop&ixlib=rb-4.0.3&ixid=M3wxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8fA%3D%3D",
            "province"=> "price",
            "district"=> "makanwanpur",
            "palika_name"=> "thaha napakar",
            "ward_number"=> "3",
            "contact_number"=> "02-2323",
            "contact_person"=> "newperson",
            "contact_person_position"=> "manager",
            "agreement_holder_name"=> "holder",
            "phone"=> "98823232",
            "position"=> "holder",
            "license_number"=> "2343243",
            "activation_key"=> "234234",
            "url_link"=> "url.com",
            
            
            "admin_email" => "admin@test.com",
            "admin_name" => "Admin Name",
            "password" => "password123",
            "password_confirmation" => "password123",
        ];

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->postJson('/api/admin/companies', $data);

        $response->assertStatus(201)
                 ->assertJsonFragment(['success' => true]);
        $this->assertDatabaseHas('companies', ['name' => 'Test Company']);
        $this->assertDatabaseHas('users', ['email' => 'admin@test.com']);
    }


    public function test_super_admin_can_update_any_company_and_admin()
    {
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('super_admin');

        $company = Company::factory()->create(['name' => 'SuperTest']);
        $adminUser = User::factory()->create(['email' => 'original@company.com']);
        $adminUser->assignRole('company_admin');
        CompanyUser::create(['company_id' => $company->id, 'user_id' => $adminUser->id]);

        $token = $superAdmin->createToken('TestToken', ['super_admin'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
                         ->patchJson("/api/admin/company-update/{$company->id}", [
                             'name' => 'UpdatedBySuper',
                             'admin_email' => 'updated@company.com',
                             'admin_name' => 'Updated Admin',
                             'password' => 'newpassword',
                         ]);

        $response->assertStatus(200)
                 ->assertJsonFragment(['success' => true]);
        $this->assertDatabaseHas('companies', ['name' => 'UpdatedBySuper']);
        $this->assertDatabaseHas('users', ['email' => 'updated@company.com']);
    }
}

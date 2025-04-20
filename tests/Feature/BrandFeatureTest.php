<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Company;
use App\Models\Brand;
use App\Models\CompanyUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Spatie\Permission\Models\Role;
use Laravel\Sanctum\Sanctum;


class BrandFeatureTest extends TestCase
{
    use DatabaseTransactions;

    public function test_company_admin_can_create_brand()
    {
  
        $company = Company::factory()->create();

   
        $user = User::factory()->companyAdmin()->create();

 
        CompanyUser::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

 
        $role = Role::firstOrCreate([
            'name' => 'company_admin',
            'guard_name' => 'api'
        ]);
        $user->assignRole($role);

        Sanctum::actingAs($user, ['*']);

     
        $response = $this->withHeaders(['company_id' => $company->id])
                         ->postJson('/api/company/brands', [
                             'name' => 'Test Brand',
                             'is_active' => true,
                         ]);

       
        $response->assertStatus(201)
                 ->assertJsonFragment([
                     'name' => 'Test Brand',
                     'company_id' => $company->id,
                     'is_active' => true,
                 ]);

     
        $this->assertDatabaseHas('brands', [
            'name' => 'Test Brand',
            'company_id' => $company->id,
            'is_active' => true,
        ]);
    }
}
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

class BrandTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * Test that a company admin can create a brand.
     *
     * @return void
     */
    public function test_company_admin_can_create_brand(): void
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

    /**
     * Test that a company admin can view a brand.
     *
     * @return void
     */
    public function test_company_admin_can_view_brand(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->companyAdmin()->create();
        CompanyUser::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        $brand = Brand::factory()->create([
            'company_id' => $company->id,
            'name' => 'Existing Brand',
        ]);

        $role = Role::firstOrCreate([
            'name' => 'company_admin',
            'guard_name' => 'api'
        ]);
        $user->assignRole($role);

        Sanctum::actingAs($user, ['*']);

        $response = $this->withHeaders(['company_id' => $company->id])
                         ->getJson('/api/company/brands/' . $brand->id);

        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'name' => 'Existing Brand',
                     'company_id' => $company->id,
                 ]);
    }

    /**
     * Test that a company admin can update a brand.
     *
     * @return void
     */
    public function test_company_admin_can_update_brand(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->companyAdmin()->create();
        CompanyUser::factory()->create([
            'user_id' => $user->id,
            'company_id' => $company->id,
        ]);

        $brand = Brand::factory()->create([
            'company_id' => $company->id,
            'name' => 'Old Brand',
        ]);

        $role = Role::firstOrCreate([
            'name' => 'company_admin',
            'guard_name' => 'api'
        ]);
        $user->assignRole($role);

        Sanctum::actingAs($user, ['*']);

        $response = $this->withHeaders(['company_id' => $company->id])
                         ->putJson('/api/company/brands/' . $brand->id, [
                             'name' => 'Updated Brand',
                             'is_active' => false,
                         ]);

        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'name' => 'Updated Brand',
                     'is_active' => false,
                 ]);

        $this->assertDatabaseHas('brands', [
            'name' => 'Updated Brand',
            'company_id' => $company->id,
            'is_active' => false,
        ]);
    }

    public function test_fails_to_create_brand_with_invalid_data(): void
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
                             'name' => '',
                             'company_id' => 999, // you might even remove this to test only name validation
                         ]);
    
        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name']);
    }
    
    public function test_updates_only_specific_fields(): void
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
    
        $brand = Brand::factory()->create([
            'company_id' => $company->id,
            'name' => 'Original Brand',
            'is_active' => true,
        ]);
    
        $response = $this->withHeaders(['company_id' => $company->id])
                         ->putJson("/api/company/brands/{$brand->id}", [
                             'name' => 'New Name',
                         ]);
    
        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'name' => 'New Name',
                     'company_id' => $company->id,
                 ]);
    
        $this->assertDatabaseHas('brands', [
            'id' => $brand->id,
            'name' => 'New Name',
        ]);
    }
    

    /**
     * Test that a company admin can delete a brand.
     *
     * @return void
     */
    
}

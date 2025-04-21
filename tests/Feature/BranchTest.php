<?php

namespace Tests\Feature;


use App\Models\User;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Branch;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Spatie\Permission\Models\Role;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BranchTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use RefreshDatabase;


    protected $company;

    protected $companyAdmin;

    
    protected function setUp(): void
{
    parent::setUp();
    $this->company = Company::factory()->create();

    $this->companyAdmin = User::factory()->companyAdmin()->create();

    CompanyUser::factory()->create([
        'company_id' => $this->company->id,
        'user_id' => $this->companyAdmin->id,
    ]);
    Sanctum::actingAs($this->companyAdmin, ['*']);
    
   
    $this->actingAs($this->companyAdmin)->withHeaders([
        'company_id' => $this->company->id
    ]);

  
}

public function test_lists_all_branches(): void
{
   
    Branch::factory()->count(15)->create([
        'company_id' => $this->company->id 
    ]);

    $response = $this->getJson('/api/company/branches');

    $response->assertStatus(200)
             ->assertJsonStructure([
                 'data' => [
                     '*' => [
                         'id',
                         'name',
                         'company_id',
                         'is_active'
                     ]
                 ],
                 'links' => [
                     '*' => [
                         'url',
                         'label',
                         'active'
                     ]
                 ],
                 'current_page',
                 'first_page_url',
                 'from',
                 'last_page',
                 'last_page_url',
                 'next_page_url',
                 'path',
                 'per_page',
                 'prev_page_url',
                 'to',
                 'total'
             ]);

    // First check if we have any data at all
    $responseData = $response->json();
    $this->assertGreaterThan(0, count($responseData['data']), 
        'Expected at least one branch but got none. Check company filtering.');
    
    // Then verify pagination
    if (count($responseData['data']) > 0) {
        $this->assertEquals($this->company->id, $responseData['data'][0]['company_id'],
            'First item belongs to wrong company');
    }
    
    // Finally check pagination count
    $this->assertLessThanOrEqual(10, count($responseData['data']));
}

    public function test_creates_a_branch(): void
    {
        $response = $this->postJson('/api/company/branches', [
            'name' => 'Kathmandu',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $response->assertStatus(201)
                 ->assertJsonFragment([
                     'name' => 'Kathmandu',
                     'company_id' => $this->company->id,
                     'is_active' => true,
                 ]);
        $this->assertTrue(Branch::where('name', 'Kathmandu')->exists());
    }

  


    public function test_updates_a_branch(): void
    {
        $branch = Branch::create([
            'name' => 'Butwal',
            'is_active' => true,
            'company_id' => $this->company->id]);

        $response = $this->putJson("/api/company/branches/{$branch->id}", [
            'name' => 'Tikapur',
            'company_id' => $this->company->id,
            'is_active' => false,
        ]);

        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'name' => 'Tikapur',
                     'company_id' => $this->company->id,
                     'is_active' => false,
                 ]);
        $this->assertFalse(Branch::find($branch->id)->is_active);
    }

  
   

    public function test_deletes_a_branch(): void
    {
        $branch = Branch::create([
            'name' => 'Kamalpokhari',
            'is_active' => true,
            'company_id' => $this->company->id]);

        $response = $this->deleteJson("/api/company/branches/{$branch->id}");

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Branch deleted']);
        $this->assertNotNull(Branch::withTrashed()->find($branch->id)->deleted_at);
    }


}

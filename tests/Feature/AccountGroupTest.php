<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\SubGroup;
use App\Models\MainGroup;
use App\Models\AccountGroup;


use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Spatie\Permission\Models\Role;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountGroupTest extends TestCase
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

public function test_lists_all_account_groups(): void
{
   
    AccountGroup::factory()->count(15)->create([
        'company_id' => $this->company->id 
    ]);

    $response = $this->getJson('/api/company/account-groups');

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

   
    $responseData = $response->json();
    $this->assertGreaterThan(0, count($responseData['data']), 
        'Expected at least one Account Group but got none. Check company filtering.');
    
   
    if (count($responseData['data']) > 0) {
        $this->assertEquals($this->company->id, $responseData['data'][0]['company_id'],
            'First item belongs to wrong company');
    }
    
    
    $this->assertLessThanOrEqual(10, count($responseData['data']));
}

    public function test_creates_a_account_group(): void
    {
        $main_group = MainGroup::create([
            'name' => 'Assets',
            'is_active' => true,
            'company_id' => $this->company->id
        ]);

        $sub_group = SubGroup::create([
            'name' => 'Assets',
            'code' => 'AS',
            'ranking_for_trial' => '1',
            'company_id' => $this->company->id,
            'main_group_id' => $main_group->id,
            'is_active' => true,
        ]);    
     
        $response = $this->postJson('/api/company/account-groups', [
            'name' => 'Assets',
            'code' => 'AS',
            'main_group_id' => $main_group->id,
            'sub_group_id' => $sub_group->id,
            'company_id' => $this->company->id,       
            'is_active' => true,
        ]);

        $response->assertStatus(201)
                 ->assertJsonFragment([
                     'name' => 'Assets',
                     'code' => 'AS',
                     'sub_group_id' => $sub_group->id,
                     'company_id' => $this->company->id,
                     'main_group_id' => $main_group->id,
                     'is_active' => true,
                 ]);
        $this->assertTrue(AccountGroup::where('name', 'Assets')->where('code', 'AS')->exists());
    }

  


    public function test_updates_a_account_group(): void
    {
        $main_group = MainGroup::create([
            'name' => 'Liabilities',
            'is_active' => true,
            'company_id' => $this->company->id
        ]);
        $sub_group = SubGroup::create([
            'name' => 'Liabilities',
            'code' => 'LI',
            'ranking_for_trial' => '2',
            'main_group_id' => $main_group->id,
            'is_active' => true,
            'company_id' => $this->company->id
        ]);

        $group = AccountGroup::create([
            'name' => 'Liabilities',
            'code' => 'LI',
            'main_group_id' => $main_group->id,
            'sub_group_id' => $sub_group->id,
            'is_active' => true,
            'company_id' => $this->company->id
        ]);


        $response = $this->putJson("/api/company/account-groups/{$group->id}", [
            'name' => 'Liabilities Update',
            'code' => 'LIupdate',
            'sub_group_id' => $sub_group->id,
            'main_group_id' => $main_group->id,
            'company_id' => $this->company->id,
            'is_active' => false,
        ]);

        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'name' => 'Liabilities Update',
                     'code' => 'LIupdate',
                     'sub_group_id' => $sub_group->id,
                     'main_group_id' => $main_group->id,
                     'company_id' => $this->company->id,
                     'is_active' => false,
                 ]);
        $this->assertFalse(AccountGroup::find($group->id)->is_active);
    }

  
   

    public function test_deletes_a_account_group(): void
    {
        $main_group = MainGroup::create([
            'name' => 'Property',
            'is_active' => true,
            'company_id' => $this->company->id
        ]);
         $sub_group = SubGroup::create([
            'name' => 'Property',
            'code' => 'PR',
            'ranking_for_trial' => 3,
            'main_group_id' => $main_group->id,
            'is_active' => true,
            'company_id' => $this->company->id
        ]);
        
        $group = AccountGroup::create([
            'name' => 'Property',
            'code' => 'PR',
            'main_group_id' => $main_group->id,
            'sub_group_id' => $sub_group->id,
            'is_active' => true,
            'company_id' => $this->company->id
        ]);

        $response = $this->deleteJson("/api/company/account-groups/{$group->id}");

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Account Group deleted!!']);
        $this->assertNotNull(AccountGroup::withTrashed()->find($group->id)->deleted_at);
    }
   
}

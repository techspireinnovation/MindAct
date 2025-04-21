<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use App\Models\User;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\MeasureUnit;


use Spatie\Permission\Models\Role;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeasureUnitTest extends TestCase
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

public function test_lists_all_measure_units(): void
{
   
    MeasureUnit::factory()->count(15)->create([
        'company_id' => $this->company->id 
    ]);

    $response = $this->getJson('/api/company/measure-units');

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
        'Expected at least one measure unit but got none. Check company filtering.');
    
    // Then verify pagination
    if (count($responseData['data']) > 0) {
        $this->assertEquals($this->company->id, $responseData['data'][0]['company_id'],
            'First item belongs to wrong company');
    }
    
    // Finally check pagination count
    $this->assertLessThanOrEqual(10, count($responseData['data']));
}

    public function test_creates_a_measure_unit(): void
    {
        $response = $this->postJson('/api/company/measure-units', [
            'name' => 'Kilo gram',
            'quantity' => '20',
            'symbol' => 'shjdgvd15',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $response->assertStatus(201)
                 ->assertJsonFragment([
                     'name' => 'Kilo gram',
                     'quantity' => '20',
                     'symbol' => 'shjdgvd15',
                     'company_id' => $this->company->id,
                     'is_active' => true,
                 ]);
        $this->assertTrue(MeasureUnit::where('name','Kilo gram')->where('quantity','20')->where('symbol','shjdgvd15')->exists());
    }

  


    public function test_updates_a_measure_unit(): void
    {
        $units = MeasureUnit::create([
            'name' => 'Update me',
            'quantity' => '20',
            'symbol' => 'shjdgvd15',
            'is_active' => true,
            'company_id' => $this->company->id]);

        $response = $this->putJson("/api/company/measure-units/{$units->id}", [
            'name' => 'updated done',
            'quantity' => '202',
            'symbol' => 'shjdgvd15updated',
            'company_id' => $this->company->id,
            'is_active' => false,
        ]);

        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'name' => 'Tikapur',
                     'name' => 'updated done',
                     'quantity' => '202',
                     'symbol' => 'shjdgvd15updated',
                     'company_id' => $this->company->id,
                     'is_active' => false,
                 ]);
        $this->assertFalse(MeasureUnit::find($units->id)->is_active);
    }

  
   

    public function test_deletes_a_measure_unit(): void
    {
        $unit = MeasureUnit::create([
            'name' => 'Delete me',
            'quantity' => '20',
            'symbol' => 'shjdgvd15',           
            'is_active' => true,
            'company_id' => $this->company->id]);

        $response = $this->deleteJson("/api/company/measure-units/{$unit->id}");

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Unit of Measurement deleted!!']);
        $this->assertNotNull(MeasureUnit::withTrashed()->find($unit->id)->deleted_at);
    }
   
}

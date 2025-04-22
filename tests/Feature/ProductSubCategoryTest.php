<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use App\Models\User;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\ProductCategory;
use App\Models\ProductSubCategory;

use Spatie\Permission\Models\Role;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProductSubCategoryTest extends TestCase
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
    $this->category = ProductCategory::factory()->create();

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

public function test_lists_all_product_sub_categories(): void
{
   
    ProductSubCategory::factory()->count(15)->create([
        'company_id' => $this->company->id 
    ]);

    $response = $this->getJson('/api/company/product-sub-categories');

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
        'Expected at least one product sub category but got none. Check company filtering.');
    
    // Then verify pagination
    if (count($responseData['data']) > 0) {
        $this->assertEquals($this->company->id, $responseData['data'][0]['company_id'],
            'First item belongs to wrong company');
    }
    
    // Finally check pagination count
    $this->assertLessThanOrEqual(10, count($responseData['data']));
}

    public function test_creates_a_product_sub_category(): void
    {
        $response = $this->postJson('/api/company/product-sub-categories', [
            'name' => 'Sub Category',
          
            'category_id' => $this->category->id,
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $response->assertStatus(201)
                 ->assertJsonFragment([
                    'name' => 'Sub Category',
          
                    'category_id' => $this->category->id,
                    'company_id' => $this->company->id,
                    'is_active' => true,
                 ]);
        $this->assertTrue(ProductSubCategory::where('name','Sub Category')->exists());
    }

  


    public function test_updates_a_product_sub_category(): void
    {
        $sub_category = ProductSubCategory::create([
      
            'name' => 'Sub Category',
          
            'category_id' => $this->category->id,
            'company_id' => $this->company->id,
            'is_active' => true,

        ]);

        $response = $this->putJson("/api/company/product-sub-categories/{$sub_category->id}", [
            'name' => 'Sub update Category',
            'category_id' => $this->category->id,
       
            'company_id' => $this->company->id,
            'is_active' => false,
        ]);

        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'name' => 'Sub update Category',
                
                     'category_id' => $this->category->id,
                     'company_id' => $this->company->id,
                     'is_active' => false,
                 ]);
        $this->assertFalse(ProductSubCategory::find($sub_category->id)->is_active);
    }

  
   

    public function test_deletes_a_sub_cataegory(): void
    {
        $sub_category = ProductSubCategory::create([
            'name' => 'Delete me',
            'category_id' => $this->category->id,       
            'is_active' => true,
            'company_id' => $this->company->id]);

        $response = $this->deleteJson("/api/company/product-sub-categories/{$sub_category->id}");

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Product Sub Category deleted!!']);
        $this->assertNotNull(ProductSubCategory::withTrashed()->find($sub_category->id)->deleted_at);
    }
   
   
}

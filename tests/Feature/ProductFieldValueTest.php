<?php

namespace Tests\Feature;


use App\Models\User;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product;
use App\Models\ProductField;
use App\Models\ProductFieldValue;


use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Spatie\Permission\Models\Role;
use Laravel\Sanctum\Sanctum;

use Tests\TestCase;

class ProductFieldValueTest extends TestCase
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

public function test_lists_all_product_field_values(): void
{
 
    ProductFieldValue::factory()->count(15)->create([
        'company_id' => $this->company->id 
    ]);

    $response = $this->getJson('/api/company/product-field-values');

    $response->assertStatus(200)
             ->assertJsonStructure([
                 'data' => [
                     '*' => [
                         'id',
                         'value',
                         'company_id',
                        
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
        'Expected at least one Product Field Value but got none. Check company filtering.');
    
   
    if (count($responseData['data']) > 0) {
        $this->assertEquals($this->company->id, $responseData['data'][0]['company_id'],
            'First item belongs to wrong company');
    }
    
    
    $this->assertLessThanOrEqual(10, count($responseData['data']));
}

    public function test_creates_a_product_field_value(): void
    {
        $product = Product::factory()->create([
            'name' => 'Product 1',
            'company_id' => $this->company->id,
        ]);

        $producty_field = ProductField::factory()->create([
            'name' => 'Product Field Create',
            'company_id' => $this->company->id,
        ]);
        $response = $this->postJson('/api/company/product-field-values', [
            'value' => 'Trial',
            'product_id' => $product->id,
            'product_field_id' => $producty_field->id,
            'company_id' => $this->company->id,
           
        ]);

        $response->assertStatus(201)
                 ->assertJsonFragment([
                     'value' => 'Trial',
                     'product_id' => $product->id,
                     'product_field_id' => $producty_field->id,
                     'company_id' => $this->company->id,
                   
                 ]);
        $this->assertTrue(ProductFieldValue::where('value', 'Trial')->exists());
    }

  


    public function test_updates_a_product_field_value(): void
    {
        $product = Product::factory()->create([
            'name' => 'Product 2',
            'company_id' => $this->company->id,
        ]);

        $producty_field = ProductField::factory()->create([
            'name' => 'Product Field',
            'company_id' => $this->company->id,
        ]);

        $field = ProductFieldValue::create([
            'value' => 'Balance',
            'product_id' => $product->id,
            'product_field_id' => $producty_field->id,
           
            'company_id' => $this->company->id
        ]);

        $response = $this->putJson("/api/company/product-field-values/{$field->id}", [
            'value' => 'Balance update',
            'product_id' => $product->id,
            'product_field_id' => $producty_field->id,
            'company_id' => $this->company->id,
           
        ]);

        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'value' => 'Balance update',
                     'product_id' => $product->id,
                     'product_field_id' => $producty_field->id,
                     'company_id' => $this->company->id,
                   
                 ]);
       $this->assertDatabaseHas('product_field_values', [
            'value' => 'Balance update',
            'product_id' => $product->id,
            'product_field_id' => $producty_field->id,
            'company_id' => $this->company->id,
           
        ]);
    }

  
   

    public function test_deletes_a_product_field_value(): void
    {
        $product = Product::factory()->create([
            'name' => 'Product 3',
            'company_id' => $this->company->id,
        ]);

        $producty_field = ProductField::factory()->create([
            'name' => 'Product 3',
            'company_id' => $this->company->id,
        ]);
        $field = ProductFieldValue::create([
            'value' => 'Debit Book',
         
            'product_id' => $product->id,
            'product_field_id' => $producty_field->id,
            'company_id' => $this->company->id]);

        $response = $this->deleteJson("/api/company/product-field-values/{$field->id}");

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Product Field Value deleted!!']);
        $this->assertNotNull(ProductFieldValue::withTrashed()->find($field->id)->deleted_at);
    }
}

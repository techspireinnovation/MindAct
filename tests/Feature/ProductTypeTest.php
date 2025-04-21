<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class ProductTypeTest extends TestCase
{
    /**
     * A basic feature test example.
     */
    use DatabaseTransactions;

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
    
    // Add this line to ensure the company is available in requests
    $this->actingAs($this->companyAdmin)->withHeaders([
        'company_id' => $this->company->id
    ]);
}

public function test_lists_all_product_categories_with_pagination(): void
{
    // Create 15 categories specifically for the test company
    ProductCategory::factory()->count(15)->create([
        'company_id' => $this->company->id // Explicitly use test company
    ]);

    $response = $this->getJson('/api/company/product-categories');

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
        'Expected at least one product category but got none. Check company filtering.');
    
    // Then verify pagination
    if (count($responseData['data']) > 0) {
        $this->assertEquals($this->company->id, $responseData['data'][0]['company_id'],
            'First item belongs to wrong company');
    }
    
    // Finally check pagination count
    $this->assertLessThanOrEqual(10, count($responseData['data']));
}

    public function test_creates_a_product_category(): void
    {
        $response = $this->postJson('/api/company/product-categories', [
            'name' => 'Electronics',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $response->assertStatus(201)
                 ->assertJsonFragment([
                     'name' => 'Electronics',
                     'company_id' => $this->company->id,
                     'is_active' => true,
                 ]);
        $this->assertTrue(ProductCategory::where('name', 'Electronics')->exists());
    }

    public function test_fails_to_create_product_category_with_invalid_data(): void
    {
        $response = $this->postJson('/api/company/product-categories', [
            'name' => '',
            'company_id' => 999,
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name']);
    }

    public function test_shows_a_product_category(): void
    {
        $category = ProductCategory::factory()->create(['company_id' => $this->company->id]);

        $response = $this->getJson("/api/company/product-categories/{$category->id}");

        $response->assertStatus(200)
                 ->assertJsonFragment(['name' => $category->name]);
    }

    public function test_returns_404_for_non_existent_product_category(): void
    {
        $response = $this->getJson('/api/company/product-categories/999');

        $response->assertStatus(404)
                 ->assertJson(['error' => 'Product Category not found!!']);
    }

    public function test_updates_a_product_category(): void
    {
        $category = ProductCategory::factory()->create(['company_id' => $this->company->id]);

        $response = $this->putJson("/api/company/product-categories/{$category->id}", [
            'name' => 'Updated Electronics',
            'company_id' => $this->company->id,
            'is_active' => false,
        ]);

        $response->assertStatus(200)
                 ->assertJsonFragment([
                     'name' => 'Updated Electronics',
                     'is_active' => false,
                 ]);
        $this->assertFalse(ProductCategory::find($category->id)->is_active);
    }

    public function test_updates_only_specific_fields(): void
    {
        $category = ProductCategory::factory()->create(['company_id' => $this->company->id]);

        $response = $this->putJson("/api/company/product-categories/{$category->id}", [
            'name' => 'New Name',
        ]);

        $response->assertStatus(200)
                 ->assertJsonFragment(['name' => 'New Name']);
    }

    public function test_fails_to_update_with_invalid_data(): void
    {
        $category = ProductCategory::factory()->create(['company_id' => $this->company->id]);

        $response = $this->putJson("/api/company/product-categories/{$category->id}", [
            'name' => str_repeat('a', 256),
            'company_id' => 999,
        ]);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors(['name']);
    }

    public function test_deletes_a_product_category(): void
    {
        $category = ProductCategory::factory()->create(['company_id' => $this->company->id]);

        $response = $this->deleteJson("/api/company/product-categories/{$category->id}");

        $response->assertStatus(200)
                 ->assertJson(['message' => 'Product Category deleted!!']);
        $this->assertNotNull(ProductCategory::withTrashed()->find($category->id)->deleted_at);
    }

    public function test_returns_404_when_deleting_non_existent_product_category(): void
    {
        $response = $this->deleteJson('/api/company/product-categories/999');

        $response->assertStatus(404)
                 ->assertJson(['error' => 'Product Category not found']);
    }

    public function test_denies_access_to_non_company_admins(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $response = $this->postJson('/api/company/product-categories', [
            'name' => 'Electronics',
            'company_id' => $this->company->id,
        ]);

        $response->assertStatus(403);
    }
}

<?php

namespace Tests\Feature;


use App\Models\User;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\ProductType;
use App\Models\ProductField;
use App\Models\ProductFieldValue;
use App\Models\Brand;
use App\Models\Location;
use App\Models\MeasureUnit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;

use Spatie\Permission\Models\Role;
use Laravel\Sanctum\Sanctum;

use Tests\TestCase;

class ProductTest extends TestCase
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

public function test_lists_all_products(): void
{
   
    Product::factory()->count(15)->create([
        'company_id' => $this->company->id 
    ]);

    $response = $this->getJson('/api/company/products');

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
        'Expected at least one Product but got none. Check company filtering.');
    
   
    if (count($responseData['data']) > 0) {
        $this->assertEquals($this->company->id, $responseData['data'][0]['company_id'],
            'First item belongs to wrong company');
    }
    
    
    $this->assertLessThanOrEqual(10, count($responseData['data']));
}

public function test_creates_a_product(): void
{
    // Arrange: Create related models
    $category = ProductCategory::create([
        'name' => 'Liabilities',
        'is_active' => true,
        'company_id' => $this->company->id,
    ]);

    $brand = Brand::create([
        'name' => 'Liabilities',
        'is_active' => true,
        'company_id' => $this->company->id,
    ]);

    $unit = MeasureUnit::create([
        'name' => 'Liabilities',
        'is_active' => true,
        'company_id' => $this->company->id,
    ]);

    $productField = ProductField::create([
        'name' => 'Test Field',
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);

    $type = ProductType::create([
        'name' => 'Liabilities',
        'is_active' => true,
        'company_id' => $this->company->id,
    ]);

    $location = Location::create([
        'name' => 'Liabilities',
        'is_active' => true,
        'company_id' => $this->company->id,
    ]);

    // Prepare request data
    $productData = [
        'name' => 'New Product',
        'is_active' => true,
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'measure_unit_id' => $unit->id,
        'purchase_rate' => 100.0,
        'purchase_rate_vat' => 5.0,
        'retail_sales_price' => 120.0,
        'retail_sales_price_vat' => 6.0,
        'retail_sales_price_profit_percent' => 20.0,
        'wholesales_price' => 110.0,
        'wholesales_price_vat' => 5.5,
        'wholesales_price_profit_percent' => 18.0,
        'is_vatable' => true,
        'product_type_id' => $type->id,
        'location_id' => $location->id,
        'company_id' => $this->company->id,
        'field_values' => [
            [
                'product_field_id' => $productField->id,
                'value' => 'Test Value',
            ],
        ],
    ];

    // Act: Send POST request to create the product
    $response = $this->postJson('/api/company/products', $productData);

    // Assert: Check response status and structure
    $response->assertStatus(201)
        ->assertJsonStructure([
            'id',
            'name',
            'is_active',
            'category_id',
            'brand_id',
            'measure_unit_id',
            'purchase_rate',
            'purchase_rate_vat',
            'retail_sales_price',
            'retail_sales_price_vat',
            'retail_sales_price_profit_percent',
            'wholesales_price',
            'wholesales_price_vat',
            'wholesales_price_profit_percent',
            'is_vatable',
            'product_type_id',
            'location_id',
            'company_id',
            'created_at',
            'updated_at',
        ]);

    // Assert: Verify the product and field values in the database
    $this->assertDatabaseHas('products', [
        'name' => 'New Product',
        'company_id' => $this->company->id,
    ]);

    $this->assertDatabaseHas('product_field_values', [
        'product_field_id' => $productField->id,
        'value' => 'Test Value',
        'company_id' => $this->company->id,
    ]);
}

public function test_updates_a_product(): void
    {
        // Arrange: Create related models
        $category = ProductCategory::create([
            'name' => 'Liabilities',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $brand = Brand::create([
            'name' => 'Liabilities',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $unit = MeasureUnit::create([
            'name' => 'Liabilities',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $productField = ProductField::create([
            'name' => 'Test Field',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $type = ProductType::create([
            'name' => 'Liabilities',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        $location = Location::create([
            'name' => 'Liabilities',
            'is_active' => true,
            'company_id' => $this->company->id,
        ]);

        // Create a product
        $product = Product::create([
            'name' => 'Old Product Name',
            'company_id' => $this->company->id,
            'is_active' => true,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'measure_unit_id' => $unit->id,
            'purchase_rate' => 100.0,
            'purchase_rate_vat' => 5.0,
            'retail_sales_price' => 120.0,
            'retail_sales_price_vat' => 6.0,
            'retail_sales_price_profit_percent' => 20.0,
            'wholesales_price' => 110.0,
            'wholesales_price_vat' => 5.5,
            'wholesales_price_profit_percent' => 18.0,
            'is_vatable' => true,
            'product_type_id' => $type->id,
            'location_id' => $location->id,
        ]);

        // Create an existing product field value
        $productFieldValue = ProductFieldValue::create([
            'product_field_id' => $productField->id,
            'product_id' => $product->id,
            'value' => 'Old Value',
            'company_id' => $this->company->id,
        ]);

        // Data to update the product and product field value
        $updatedData = [
            'name' => 'Updated Product Name',
            'is_active' => false,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'measure_unit_id' => $unit->id,
            'purchase_rate' => 200.0,
            'purchase_rate_vat' => 10.0,
            'retail_sales_price' => 240.0,
            'retail_sales_price_vat' => 12.0,
            'retail_sales_price_profit_percent' => 40.0,
            'wholesales_price' => 220.0,
            'wholesales_price_vat' => 11.0,
            'wholesales_price_profit_percent' => 36.0,
            'is_vatable' => false,
            'product_type_id' => $type->id,
            'location_id' => $location->id,
            'company_id' => $this->company->id,
            'field_values' => [
                [
                    'id' => $productFieldValue->id,
                    'product_field_id' => $productField->id,
                    'value' => 'Updated Value',
                ],
                [
                    'product_field_id' => $productField->id,
                    'value' => 'New Value',
                ],
            ],
        ];

        // Act: Send PUT request to update the product
        $response = $this->putJson("/api/company/products/{$product->id}", $updatedData);

        // Assert: Check response status and message
        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Product Updated',
            ]);

        // Assert: Verify the updated product in the database
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'name' => 'Updated Product Name',
            'is_active' => false,
            'company_id' => $this->company->id,
        ]);

        // Assert: Verify the field values in the database (without relying on specific id)
        $this->assertDatabaseHas('product_field_values', [
            'product_id' => $product->id,
            'product_field_id' => $productField->id,
            'value' => 'Updated Value',
            'company_id' => $this->company->id,
        ]);

        $this->assertDatabaseHas('product_field_values', [
            'product_id' => $product->id,
            'product_field_id' => $productField->id,
            'value' => 'New Value',
            'company_id' => $this->company->id,
        ]);

        // Assert: Ensure the old field value is not present
        $this->assertDatabaseMissing('product_field_values', [
            'product_id' => $product->id,
            'product_field_id' => $productField->id,
            'value' => 'Old Value',
        ]);
    }

    

  

    public function test_deletes_a_product(): void
    {
        $category = ProductCategory::create([
            'name' => 'Cat 1',
            'is_active' => true,
            'company_id' => $this->company->id
        ]);
    
        $brand = Brand::create([
            'name' => 'Brand 1',
            'is_active' => true,
            'company_id' => $this->company->id
        ]);
    
        $unit = MeasureUnit::create([
            'name' => 'Unit 1',
            'is_active' => true,
            'company_id' => $this->company->id
        ]);
    
        $type = ProductType::create([
            'name' => 'Type 1',
            'is_active' => true,
            'company_id' => $this->company->id
        ]);
    
        $location = Location::create([
            'name' => 'Location 1',
            'is_active' => true,
            'company_id' => $this->company->id
        ]);
    
        $product = Product::create([
            'name' => 'Product to delete',
            'debit_note' => 'DN',
            'credit_note' => 'CN',
            'is_active' => true,
            'company_id' => $this->company->id,
            'category_id' => $category->id,
            'brand_id' => $brand->id,
            'measure_unit_id' => $unit->id,
            'purchase_rate' => 100,
            'purchase_rate_vat' => 10,
            'retail_sales_price' => 150,
            'retail_sales_price_vat' => 15,
            'retail_sales_price_profit_percent' => 20,
            'wholesales_price' => 120,
            'wholesales_price_vat' => 12,
            'wholesales_price_profit_percent' => 15,
            'is_vatable' => true,
            'product_type_id' => $type->id,
            'location_id' => $location->id,
        ]);
    
        $response = $this->deleteJson("/api/company/products/{$product->id}");
    
        $response->assertStatus(200)
                 ->assertJson(['message' => 'Product deleted!!']);
    
        $this->assertNotNull(Product::withTrashed()->find($product->id)->deleted_at);
    }
}

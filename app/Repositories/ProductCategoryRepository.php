<?php

namespace App\Repositories;

use App\Models\ProductCategory;

use App\Interfaces\ProductCategoryRepositoryInterface;
use App\Traits\Paginator;

use App\Http\Resources\ProductCategoryResource;
use Exception;

class ProductCategoryRepository implements ProductCategoryRepositoryInterface
{

    use Paginator;

    public function list(array $filters)
    {
        $query = ProductCategory::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $categories = $query->paginate(50);
        return $this->paginated($categories, ProductCategoryResource::collection($categories->items()));

    }



    public function categoryDetails(array $filters)
    {

        $partyName = $filters['category_name'] ?? null;

        

        $categoryDetail = ProductCategory::where('name', $partyName)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new ProductCategoryResource($categoryDetail);

    }



    public function create(array $data): ProductCategory
    {
        if (!empty($data['is_primary'])) {
            ProductCategory::where('is_primary', true)->update(['is_primary' => false]);
        }

        $data['is_primary'] = $data['is_primary'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;

        
        return ProductCategory::create($data);

    }



    public function update($id, array $data)
    {

        $productCategory = ProductCategory::findOrFail($id);
        if (!empty($data['is_primary']) && $data['is_primary'] === true) {
            ProductCategory::where('id', '!=', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }


        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        if (array_key_exists('is_primary', $data)) {
            $data['is_primary'] = (bool) $data['is_primary'];
        }

        $productCategory->update($data);

        return $productCategory->fresh();


    }

    public function delete($id)
    {
        $category = ProductCategory::findOrFail($id);

        $usedIn = [];

        if ($category->products()->exists()) {
            $usedIn[] = 'products';
        }



        if (!empty($usedIn)) {

            throw new \Exception('in_use:' . implode(',', $usedIn));
        }

        $category->delete();

        return true;
    }

    public function show($id)
    {

        $productCategory = ProductCategory::findOrFail($id);

        return new ProductCategoryResource($productCategory);
    }


    public function activeCategoryList()
    {
        $categories = ProductCategory::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name', 'is_primary']);


        $response = ($categories->count() > 0) ? ProductCategoryResource::collection($categories)->map(function ($category) {
            return collect($category)->only(['id', 'name']);
        }) : [];


        return $response;

    }




}
?>
<?php

namespace App\Repositories;

use App\Models\ProductCategory;

use App\Interfaces\ProductCategoryRepositoryInterface;

class ProductCategoryRepository implements ProductCategoryRepositoryInterface
{

    public function list(array $filters)
    {
        $query = ProductCategory::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        return $query->paginate(50);

    }

    public function categoryList()
    {
        $categories = ProductCategory::where('is_active', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'name'])
            ->map(fn($category) => ['id' => $category->id, 'name' => $category->name])
            ->values()
            ->toArray();

        return $categories;
    }

    public function categoryDetails($filters)
    {

        $categoryDetail = ProductCategory::where('name', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return $categoryDetail;

    }



    public function create(array $data): ProductCategory
    {
        if (!empty($data['is_primary'])) {
            ProductCategory::where('is_primary', true)->update(['is_primary' => false]);
        }

        $data['is_primary'] = $data['is_primary'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;

        // Create and return the product category
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

        return $productCategory;
    }


    public function activeCategoryList()
    {
        $categories = ProductCategory::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name', 'is_primary'])
            ->map(fn($category) => [
                'id' => $category->id,
                'name' => $category->name,
                'is_primary' => $category->is_primary,
            ])
            ->values()
            ->toArray();

        return $categories;

    }




}
?>
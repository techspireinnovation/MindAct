<?php

namespace App\Repositories;

use App\Models\Brand;

use App\Interfaces\BrandRepositoryInterface;

class BrandRepository implements BrandRepositoryInterface
{

    public function list(array $filters)
    {
        $query = Brand::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        return $query->paginate(50);

    }

   

    public function brandDetails($filters)
    {

        $brandDetail = Brand::where('name', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return $brandDetail;

    }



    public function create(array $data): Brand
    {
        if (!empty($data['is_primary'])) {
            Brand::where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $data['is_primary'] = $data['is_primary'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;



        return Brand::create($data);

    }



    public function update($id, array $data)
    {

        $brand = Brand::findOrFail($id);
        if (!empty($data['is_primary']) && $data['is_primary'] === true) {
            Brand::where('id', '!=', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }


        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        if (array_key_exists('is_primary', $data)) {
            $data['is_primary'] = (bool) $data['is_primary'];
        }



        $brand->update($data);

        return $brand->fresh();


    }

    public function delete($id)
    {
        $brand = Brand::findOrFail($id);

        $usedIn = [];

        if ($brand->products()->exists()) {
            $usedIn[] = 'products';
        }



        if (!empty($usedIn)) {

            throw new \Exception('in_use:' . implode(',', $usedIn));
        }

        $brand->delete();

        return true;
    }

    public function show($id)
    {

        $brand = Brand::findOrFail($id);

        return $brand;
    }


    public function activeBrandList()
    {
        $brands = Brand::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name']);
           
        return $brands;

    }




}
?>
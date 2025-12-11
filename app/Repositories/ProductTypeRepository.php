<?php

namespace App\Repositories;

use App\Models\ProductType;

use App\Interfaces\ProductTypeRepositoryInterface;

class ProductTypeRepository implements ProductTypeRepositoryInterface
{

    public function list(array $filters)
    {
        $query = ProductType::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        return $query->paginate(50);

    }

   

    public function productTypeDetails($filters)
    {

        $productTypeDetail = ProductType::where('name', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return $productTypeDetail;

    }



    public function create(array $data): ProductType
    {
        if (!empty($data['is_primary'])) {
            ProductType::where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $data['is_primary'] = $data['is_primary'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;



        return ProductType::create($data);

    }



    public function update($id, array $data)
    {

        $productType = ProductType::findOrFail($id);
        if (!empty($data['is_primary']) && $data['is_primary'] === true) {
            ProductType::where('id', '!=', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }


        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        if (array_key_exists('is_primary', $data)) {
            $data['is_primary'] = (bool) $data['is_primary'];
        }



        $productType->update($data);

        return $productType->fresh();


    }

    public function delete($id)
    {
        $productType = ProductType::findOrFail($id);
        $deleteStatus = $productType->delete_status;

        $usedIn = [];

        if ($productType->products()->exists()) {
            $usedIn[] = 'products';
        }



        if (!empty($usedIn)) {

            throw new \Exception('in_use:' . implode(',', $usedIn));
        }

        if ($deleteStatus === true) {

            $productType->delete();
        }

        return true;
    }

    public function show($id)
    {

        $productType = ProductType::findOrFail($id);

        return $productType;
    }


    public function activeProductTypeList()
    {
        $productTypes = ProductType::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name', 'is_primary']);
            

        return $productTypes;

    }




}
?>
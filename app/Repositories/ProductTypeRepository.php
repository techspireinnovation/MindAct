<?php

namespace App\Repositories;

use App\Models\ProductType;

use App\Interfaces\ProductTypeRepositoryInterface;
use App\Traits\Paginator;

use App\Http\Resources\ProductTypeResource;

class ProductTypeRepository implements ProductTypeRepositoryInterface
{

    use Paginator;

    public function list(array $filters)
    {
        $query = ProductType::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $productTypes = $query->paginate(50);
        return $this->paginated($productTypes, ProductTypeResource::collection($productTypes->items()));

    }



    public function productTypeDetails(array $filters)
    {

        $name = $filters['type_name'] ?? null;

        $productTypeDetail = ProductType::where('name', $name)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new ProductTypeResource($productTypeDetail);

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

        $usedIn = [];

        if ($productType->products()->exists()) {
            $usedIn[] = 'products';
        }

        if (!empty($usedIn)) {
            throw new \Exception('in_use:' . implode(',', $usedIn));
        }

        if (!$productType->delete_status) {
            throw new \Exception('cannot_delete');
        }

        $productType->delete();
        return true;
    }

    public function show($id)
    {

        $productType = ProductType::findOrFail($id);

        return new ProductTypeResource($productType);
    }


    public function activeProductTypeList()
    {
        $productTypes = ProductType::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name', 'is_primary']);


        $response = ($productTypes->count() > 0) ? ProductTypeResource::collection($productTypes)->map(function ($productType) {
            return collect($productType)->only(['id', 'name','is_primary']);
        }) : [];


        return $response;


    }




}
?>
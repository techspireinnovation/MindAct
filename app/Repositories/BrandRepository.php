<?php

namespace App\Repositories;

use App\Models\Brand;

use App\Traits\Paginator;
use App\Interfaces\BrandRepositoryInterface;
use App\Http\Resources\BrandResource;

class BrandRepository implements BrandRepositoryInterface
{
    use Paginator;

    public function list(array $filters): array
    {
        $query = Brand::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $brands = $query->paginate(50);
       
        return $this->paginated($brands, BrandResource::collection($brands->items()));

    }



    public function brandDetails($filters)
    {

        $brandDetail = Brand::where('name', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new BrandResource($brandDetail);

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

        return new BrandResource($brand);
    }


    public function activeBrandList()
    {
        $brands = Brand::whereNull('deleted_at')
            ->where('is_active', true)
            ->get();

        $response = ($brands->count() > 0) ? BrandResource::collection($brands)->map(function ($brand) {
            return collect($brand)->only(['id', 'name']);
        }) : [];


        return $response;

    }




}
?>
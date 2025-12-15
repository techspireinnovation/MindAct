<?php

namespace App\Repositories;

use App\Models\Store;

use App\Interfaces\StoreRepositoryInterface;

use App\Traits\Paginator;

use App\Http\Resources\StoreResource;

class StoreRepository implements StoreRepositoryInterface
{

    use Paginator;

    public function list(array $filters)
    {
        $query = Store::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $stores = $query->paginate(50);
        return $this->paginated($stores, StoreResource::collection($stores->items()));

    }



    public function storeDetails(array $filters)
    {
        $name = $filters['store_name'] ?? null;

        $storeDetail = Store::where('name', $name)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new StoreResource($storeDetail);

    }



    public function create(array $data): Store
    {
        if (!empty($data['is_primary'])) {
            Store::where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $data['is_primary'] = $data['is_primary'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;



        return Store::create($data);

    }



    public function update($id, array $data)
    {

        $store = Store::findOrFail($id);
        if (!empty($data['is_primary']) && $data['is_primary'] === true) {
            Store::where('id', '!=', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }


        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        if (array_key_exists('is_primary', $data)) {
            $data['is_primary'] = (bool) $data['is_primary'];
        }



        $store->update($data);

        return $store->fresh();


    }

    public function delete($id)
    {
        $store = Store::findOrFail($id);

        $usedIn = [];

        if ($store->purchases()->exists()) {
            $usedIn[] = 'purchases';
        }

        if ($store->sales()->exists()) {
            $usedIn[] = 'sales';
        }

        if (!empty($usedIn)) {
            throw new \Exception('in_use:' . implode(',', $usedIn));

        }

        $store->delete();

        return true;
    }

    public function show($id)
    {

        $store = Store::findOrFail($id);

        return new StoreResource($store);
    }


    public function activeStoreList()
    {
        $stores = Store::where('is_active', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'is_primary']);

        if ($stores->isEmpty()) {
            throw new \Exception('Active stores not found.');

        }

        $response = ($stores->count() > 0) ? StoreResource::collection($stores)->map(function ($store) {
            return collect($store)->only(['id', 'name']);
        }) : [];


        return $response;

    }

}
?>
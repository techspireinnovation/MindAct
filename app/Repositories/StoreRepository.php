<?php

namespace App\Repositories;

use App\Models\Store;

use App\Interfaces\StoreRepositoryInterface;

class StoreRepository implements StoreRepositoryInterface
{

    public function list(array $filters)
    {
        $query = Store::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        return $query->paginate(50);

    }



    public function storeDetails($filters)
    {

        $storeDetail = Store::where('name', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return $storeDetail;

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

        return $store;
    }


    public function activeStoreList()
    {
        $stores = Store::where('is_active', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'is_primary']);

        if ($stores->isEmpty()) {
            throw new \Exception('Active stores not found.');

        }

       

        return $stores;

    }




}
?>
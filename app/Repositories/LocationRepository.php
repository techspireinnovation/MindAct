<?php

namespace App\Repositories;

use App\Models\Location;

use App\Interfaces\LocationRepositoryInterface;

class LocationRepository implements LocationRepositoryInterface
{

    public function list(array $filters)
    {
        $query = Location::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        return $query->paginate(50);

    }

    public function locationList()
    {
        $locations = Location::where('is_active', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'name'])
            ->map(fn($brand) => ['id' => $brand->id, 'name' => $brand->name])
            ->values()
            ->toArray();

        return $locations;
    }

    public function locationDetails($filters)
    {

        $locatinoDetail = Location::where('name', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return $locatinoDetail;

    }



    public function create(array $data): Location
    {
        if (!empty($data['is_primary'])) {
            Location::where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $data['is_primary'] = $data['is_primary'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;



        return Location::create($data);

    }



    public function update($id, array $data)
    {

        $location = Location::findOrFail($id);
        if (!empty($data['is_primary']) && $data['is_primary'] === true) {
            Location::where('id', '!=', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }


        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        if (array_key_exists('is_primary', $data)) {
            $data['is_primary'] = (bool) $data['is_primary'];
        }



        $location->update($data);

        return $location->fresh();


    }

    public function delete($id)
    {
        $location = Location::findOrFail($id);

        // Track where it's being used
        $usedIn = [];

        if ($location->products()->exists()) {
            $usedIn[] = 'products';
        }
        if ($location->purchases()->exists()) {
            $usedIn[] = 'purchases';
        }
        if ($location->sales()->exists()) {
            $usedIn[] = 'sales';
        }
        if ($location->productionAssembles()->exists()) {
            $usedIn[] = 'production_assembles';
        }
        if ($location->stockAdjustments()->exists()) {
            $usedIn[] = 'stock_adjustments';
        }

        if (!empty($usedIn)) {
            throw new \Exception('in_use:' . implode(',', $usedIn));
        }

        $location->delete();
        return true;
    }

    public function show($id)
    {

        $location = Location::findOrFail($id);

        return $location;
    }


    public function activeLocationList()
    {
        $locations = Location::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name', 'is_primary'])
            ->map(fn($location) => [
                'id' => $location->id,
                'name' => $location->name,
                'is_primary' => $location->is_primary,
            ])
            ->values()
            ->toArray();

        return $locations;

    }




}
?>
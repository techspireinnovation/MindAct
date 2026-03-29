<?php

namespace App\Repositories;

use App\Models\Location;

use App\Traits\Paginator;

use App\Interfaces\LocationRepositoryInterface;

use App\Http\Resources\LocationResource;

class LocationRepository implements LocationRepositoryInterface
{
    use Paginator;

    public function list(array $filters)
    {
        $query = Location::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $locations = $query->paginate(50);

        return $this->paginated($locations, LocationResource::collection($locations->items()));

    }



    public function locationDetails($filters)
    {

        $locatinoDetail = Location::where('name', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new LocationResource($locatinoDetail);

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
       
       

        if (!empty($usedIn)) {
            throw new \Exception('in_use:' . implode(',', $usedIn));
        }

        $location->delete();
        return true;
    }

    public function show($id)
    {

        $location = Location::findOrFail($id);

        return new LocationResource($location);
    }


    public function activeLocationList()
    {
        $locations = Location::whereNull('deleted_at')
            ->where('is_active', true)
            ->get();


        $response = ($locations->count() > 0) ? LocationResource::collection($locations)->map(function ($location) {
            return collect($location)->only(['id', 'name']);
        }) : [];

        return $response;

    }




}
?>
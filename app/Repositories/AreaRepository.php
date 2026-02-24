<?php

namespace App\Repositories;

use App\Models\Area;

use App\Traits\Paginator;
use App\Interfaces\AreaRepositoryInterface;
use App\Http\Resources\AreaResource;

class AreaRepository implements AreaRepositoryInterface
{
    use Paginator;

    public function list(array $filters): array
    {
        $query = Area::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $areas = $query->paginate(50);

        return $this->paginated($areas, AreaResource::collection($areas->items()));

    }



    public function areaDetails(array $filters)
    {
        $areaName = $filters['area_name'] ?? null;

        $areaDetail = Area::where('name', $areaName)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new AreaResource($areaDetail);

    }



    public function create(array $data): Area
    {
        if (!empty($data['is_primary'])) {
            Area::where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $data['is_primary'] = $data['is_primary'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;



        return Area::create($data);

    }



    public function update($id, array $data)
    {

        $area = Area::findOrFail($id);
        if (!empty($data['is_primary']) && $data['is_primary'] === true) {
            Area::where('id', '!=', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }


        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        if (array_key_exists('is_primary', $data)) {
            $data['is_primary'] = (bool) $data['is_primary'];
        }



        $area->update($data);

        return $area->fresh();


    }

    public function delete($id)
    {
        $area = Area::findOrFail($id);

        $usedIn = [];

        if ($area->products()->exists()) {
            $usedIn[] = 'products';
        }


        if (!empty($usedIn)) {

            throw new \Exception('in_use:' . implode(',', $usedIn));
        }

        $area->delete();

        return true;
    }

    public function show($id)
    {

        $area = Area::findOrFail($id);

        return new AreaResource($area);
    }


    public function activeAreaList()
    {
        $areas = Area::whereNull('deleted_at')
            ->where('is_active', true)
            ->get();

        $response = ($areas->count() > 0) ? AreaResource::collection($areas)->map(function ($area) {
            return collect($area)->only(['id', 'name']);
        }) : [];


        return $response;

    }




}
?>
<?php

namespace App\Repositories;

use App\Models\MeasureUnit;

use App\Interfaces\MeasureUnitRepositoryInterface;

use App\Traits\Paginator;

use App\Http\Resources\MeasureUnitResource;

class MeasureUnitRepository implements MeasureUnitRepositoryInterface
{

    use Paginator;

    public function list(array $filters)
    {
        $query = MeasureUnit::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $measureUnits = $query->paginate(50);

        return $this->paginated($measureUnits, MeasureUnitResource::collection($measureUnits->items()));

    }



    public function measureUnitDetails($filters)
    {

        $measureUnitDetail = MeasureUnit::where('name', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new MeasureUnitResource($measureUnitDetail);

    }



    public function create(array $data): MeasureUnit
    {
        if (!empty($data['is_primary'])) {
            MeasureUnit::where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $data['is_primary'] = $data['is_primary'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;



        return MeasureUnit::create($data);

    }



    public function update($id, array $data)
    {

        $measureUnit = MeasureUnit::findOrFail($id);
        if (!empty($data['is_primary']) && $data['is_primary'] === true) {
            MeasureUnit::where('id', '!=', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }


        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        if (array_key_exists('is_primary', $data)) {
            $data['is_primary'] = (bool) $data['is_primary'];
        }



        $measureUnit->update($data);

        return $measureUnit->fresh();


    }

    public function delete($id)
    {
        $measureUnit = MeasureUnit::findOrFail($id);

        $usedIn = [];

        if ($measureUnit->products()->exists()) {
            $usedIn[] = 'products';
        }
        if ($measureUnit->productLists()->exists()) {
            $usedIn[] = 'product lists';
        }
        if ($measureUnit->productAssembleDetails()->exists()) {
            $usedIn[] = 'production assemble details';
        }
        if ($measureUnit->productionSettings()->exists()) {
            $usedIn[] = 'production settings';
        }
        if ($measureUnit->purchaseProducts()->exists()) {
            $usedIn[] = 'purchase products';
        }
        if ($measureUnit->saleProducts()->exists()) {
            $usedIn[] = 'sale products';
        }

        if (!empty($usedIn)) {

            throw new \Exception('in_use:' . implode(',', $usedIn));

        }

        $measureUnit->delete();
        return true;
    }

    public function show($id)
    {

        $measureUnit = MeasureUnit::findOrFail($id);

        return new MeasureUnitResource($measureUnit);
    }


    public function activeMeasureUnitList()
    {
        $measureUnits = MeasureUnit::where('is_active', 1)
            ->whereNull('deleted_at')
            ->get();

        if ($measureUnits->isEmpty()) {
            throw new \Exception('No Measure Units.');
        }



         $response = ($measureUnits->count() > 0) ? MeasureUnitResource::collection($measureUnits)->map(function ($measureUnit) {
            return collect($measureUnit)->only(['id', 'name']);
        }) : [];


        return $response;

    }




}
?>
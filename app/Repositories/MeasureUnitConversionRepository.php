<?php

namespace App\Repositories;

use App\Models\MeasureUnitConversion;

use App\Interfaces\MeasureUnitConversionRepositoryInterface;

use App\Traits\Paginator;

use App\Http\Resources\MeasureUnitConversionResource;

class MeasureUnitConversionRepository implements MeasureUnitConversionRepositoryInterface
{

    use Paginator;

    public function list(array $filters)
    {
        $query = MeasureUnitConversion::query();

        $conversions = $query->paginate(50);

        return $this->paginated($conversions, MeasureUnitConversionResource::collection($conversions->items()));

    }

  

    public function measureUnitConversionDetails($filters)
    {

        $measureUnitConversionDetail = MeasureUnitConversion::where('name', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new MeasureUnitConversionResource($measureUnitConversionDetail);

    }



    public function create(array $data): MeasureUnitConversion
    {

        return MeasureUnitConversion::create($data);

    }



    public function update($id, array $data)
    {

        $MeasureUnitConversion = MeasureUnitConversion::findOrFail($id);

        $MeasureUnitConversion->update($data);

        return $MeasureUnitConversion->fresh();


    }

    

    public function delete($id)
    {
        $MeasureUnitConversion = MeasureUnitConversion::findOrFail($id);


        $MeasureUnitConversion->delete();
        
        return true;
    }

    public function show($id)
    {

        $measureUnitConversion = MeasureUnitConversion::findOrFail($id);

        return new MeasureUnitConversionResource($measureUnitConversion );
    }


    public function activeMeasureUnitConversionList()
    {
        $measureUnitConversions = MeasureUnitConversion::where('is_active', 1)
            ->whereNull('deleted_at')
            ->get();

        if ($measureUnitConversions->isEmpty()) {
            throw new \Exception('No Measure Unit Conversions.');
        }



        $response = ($measureUnitConversions->count() > 0) ? MeasureUnitConversionResource::collection($measureUnitConversions)->map(function ($measureUnitConversion) {
            return collect($measureUnitConversion)->only(['id', 'product_id']);
        }) : [];


        return $response;

    }




}
?>
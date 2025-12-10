<?php

namespace App\Repositories;

use App\Models\MeasureUnitConversion;

use App\Interfaces\MeasureUnitConversionRepositoryInterface;

class MeasureUnitConversionRepository implements MeasureUnitConversionRepositoryInterface
{

    public function list(array $filters)
    {
        $query = MeasureUnitConversion::query();



        return $query->paginate(50);

    }

    public function measureUnitConversionList()
    {
        $MeasureUnitConversions = MeasureUnitConversion::whereNull('deleted_at')
            ->get();


        return $MeasureUnitConversions;
    }

    public function measureUnitConversionDetails($filters)
    {

        $MeasureUnitConversionDetail = MeasureUnitConversion::where('name', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return $MeasureUnitConversionDetail;

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

        $MeasureUnitConversion = MeasureUnitConversion::findOrFail($id);

        return $MeasureUnitConversion;
    }


    public function activeMeasureUnitConversionList()
    {
        $MeasureUnitConversions = MeasureUnitConversion::where('is_active', 1)
            ->whereNull('deleted_at')
            ->get();

        if ($MeasureUnitConversions->isEmpty()) {
            throw new \Exception('No Measure Units.');
        }



        return $MeasureUnitConversions;

    }




}
?>
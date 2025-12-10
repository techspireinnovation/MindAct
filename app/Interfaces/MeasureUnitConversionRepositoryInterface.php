<?php

namespace App\Interfaces;

interface MeasureUnitConversionRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters);

    public function measureUnitConversionList();


    public function measureUnitConversionDetails($filters);

    public function delete($id);


    public function show($id);

    public function activeMeasureUnitconversionList();

}


?>
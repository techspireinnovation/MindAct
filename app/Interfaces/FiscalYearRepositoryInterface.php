<?php

namespace App\Interfaces;

interface FiscalYearRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters);

   


    public function fiscalYearDetails($filters);

    public function delete($id);


    public function show($id);

    public function activefiscalYearList();

}


?>
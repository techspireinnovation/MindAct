<?php

namespace App\Interfaces;

interface LocationRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters);

    public function locationList();


    public function locationDetails($filters);

    public function delete($id);


    public function show($id);

    public function activeLocationList();

}


?>
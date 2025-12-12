<?php

namespace App\Interfaces;

interface ProductTypeRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters);

   


    public function productTypeDetails(array $filters);

    public function delete($id);


    public function show($id);

    public function activeProductTypeList();

}


?>
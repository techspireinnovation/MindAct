<?php

namespace App\Interfaces;

interface ProductRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters);
    public function applyFilters($query, array $filters);

  


    public function productDetails(array $filters);
    public function productFields();

    public function search(array $filters);

    public function delete($id);


    public function show($id);

    public function activeProductList();

}


?>
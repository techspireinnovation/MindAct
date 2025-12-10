<?php

namespace App\Interfaces;

interface ProductRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters, int $perPage = 50);
    public function applyFilters($query, array $filters);

    public function productList();


    public function productDetails($productId = Null, $productName = Null);

    public function search(array $filters);

    public function delete($id);


    public function show($id);

    public function activeProductList();

}


?>
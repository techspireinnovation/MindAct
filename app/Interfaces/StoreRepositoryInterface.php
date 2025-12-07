<?php

namespace App\Interfaces;

interface StoreRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters);

    public function storeList();


    public function storeDetails($filters);

    public function delete($id);


    public function show($id);

    public function activeStoreList();

}


?>
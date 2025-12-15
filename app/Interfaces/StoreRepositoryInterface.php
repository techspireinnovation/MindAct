<?php

namespace App\Interfaces;

interface StoreRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters);

    


    public function storeDetails(array $filters);

    public function delete($id);


    public function show($id);

    public function activeStoreList();

}


?>
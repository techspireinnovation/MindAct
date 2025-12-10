<?php

namespace App\Interfaces;

interface PartyRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters, int $perPage = 50);
   

    public function partyList();


    public function partyDetails($productId = Null, $productName = Null);

     public function search($partyName);

    public function delete($id);


    public function show($id);

    public function activePartyList();

}


?>
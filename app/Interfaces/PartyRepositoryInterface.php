<?php

namespace App\Interfaces;

interface PartyRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters, int $perPage = 50);
   

   


    public function partyDetails(array $filters);

     public function search(array $filters);

    public function delete($id);


    public function show($id);

    public function activePartyList();

}


?>
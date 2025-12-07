<?php

namespace App\Interfaces;

interface BranchRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters);

    public function branchList();


    public function branchDetails($filters);

    public function delete($id);


    public function show($id);

    public function activeBranchList();

}


?>
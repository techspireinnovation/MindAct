<?php

namespace App\Interfaces;

interface ProductCategoryRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters);

    public function categoryList();


    public function categoryDetails($filters);

    public function delete($id);


    public function show($id);

    public function activeCategoryList();

}


?>
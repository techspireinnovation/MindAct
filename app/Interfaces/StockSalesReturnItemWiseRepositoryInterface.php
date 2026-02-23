<?php

namespace App\Interfaces;

interface StockSalesReturnItemWiseRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters);

    public function show($id);

    public function delete($id);
}
?>
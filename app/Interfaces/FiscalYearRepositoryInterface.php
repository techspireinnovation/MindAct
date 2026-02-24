<?php

namespace App\Interfaces;

interface FiscalYearRepositoryInterface
{

    public function create(array $data);

    public function update($id, array $data);

    public function list(array $filters);

    public function fiscalYearDetails($filters);

    public function delete($id);


    public function show($id);

    public function activefiscalYearList();
    public function createAssignFiscalYear(array $data);
    public function getAssignFiscalYearList(array $data);
    public function getAssignFiscalYearDetails($companyId);

    public function deleteFiscalYear($companyId);

    public function updateAssignFiscalYear($companyId, array $data);

}


?>
<?php

namespace App\Exports\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;

class ProductListDetailsReport implements FromCollection
{
    protected $data;
    public function __construct($data)
    {
        $this->data = $data;
    }
    public function collection()
    {
        return collect($this->data);
    }
}

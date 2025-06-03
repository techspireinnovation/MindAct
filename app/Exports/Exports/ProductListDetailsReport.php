<?php

namespace App\Exports\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductListDetailsReport implements FromCollection, WithMapping, WithHeadings
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

    public function map($item): array
    {
        return [
            $item->id,
            $item->quantity,
            $item->is_vatable === 1 ? "Vatable" : "Non-Vatable",
            $item->primary_measure_unit ? $item->primary_measure_unit->name : "",
        ];
    }

    public function headings(): array
    {
        return ['ID', 'quantity', 'vatable', 'Measure Unit'];
    }
}

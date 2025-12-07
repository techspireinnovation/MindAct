<?php

namespace App\Repositories;

use App\Models\Salesman;

use App\Interfaces\SalesmanRepositoryInterface;

class SalesmanRepository implements SalesmanRepositoryInterface
{

    public function list(array $filters)
    {
        $query = Salesman::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        return $query->paginate(50);

    }

    public function salesmenList()
    {
        $salesmen = Salesman::where('is_active', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'name'])
            ->map(fn($salesman) => ['id' => $salesman->id, 'name' => $salesman->name])
            ->values()
            ->toArray();

        return $salesmen;
    }

    public function salesmanDetails($filters)
    {

        $salesmanDetail = Salesman::where('name', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return $salesmanDetail;

    }



    public function create(array $data): Salesman
    {
        if (!empty($data['is_primary'])) {
            Salesman::where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $data['is_primary'] = $data['is_primary'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;



        return Salesman::create($data);

    }



    public function update($id, array $data)
    {

        $salesman = Salesman::findOrFail($id);
        if (isset($data['is_primary']) && $data['is_primary'] === true) {
            $affectedRows = Salesman::where('company_id', $salesman->company_id)
                ->where('id', '!=', $id)
                ->where('is_primary', true)
                ->whereNull('deleted_at')
                ->update(['is_primary' => false]);

        }

        $salesman->update($data);

        return $salesman->fresh();


    }

    public function delete($id)
    {
        $salesman = Salesman::findOrFail($id);

        $usedIn = [];

        if ($salesman->sales()->exists()) {
            $usedIn[] = 'sales';
        }

        if (!empty($usedIn)) {
            throw new \Exception('in_use:' . implode(',', $usedIn));
        }

        $salesman->delete();

        return true;
    }

    public function show($id)
    {

        $salesman = Salesman::findOrFail($id);

        return $salesman;
    }


    public function activeSalesmanList()
    {
        $salesmen = Salesman::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name', 'is_primary'])
            ->map(fn($salesman) => [
                'id' => $salesman->id,
                'name' => $salesman->name,
                'is_primary' => $salesman->is_primary,
            ])
            ->values()
            ->toArray();

        return $salesmen;

    }




}
?>
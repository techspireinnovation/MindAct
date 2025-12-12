<?php

namespace App\Repositories;

use App\Models\Salesman;

use App\Interfaces\SalesmanRepositoryInterface;

use App\Traits\Paginator;

use App\Http\Resources\SalesmanResource;

class SalesmanRepository implements SalesmanRepositoryInterface
{

    use Paginator;

    public function list(array $filters)
    {
        $query = Salesman::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $salesmen = $query->paginate(50);

        return $this->paginated($salesmen, SalesmanResource::collection($salesmen->items()));

    }



    public function salesmanDetails(array $filters)
    {

        $name = $filters['name'] ?? null;

        $salesmanDetail = Salesman::where('name', $name)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new SalesmanResource($salesmanDetail);

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

        return new SalesmanResource($salesman);
    }


    public function activeSalesmanList()
    {
        $salesmen = Salesman::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name', 'is_primary']);


        $response = ($salesmen->count() > 0) ? SalesmanResource::collection($salesmen)->map(function ($salesman) {
            return collect($salesman)->only(['id', 'name']);
        }) : [];


        return $response;

    }




}
?>
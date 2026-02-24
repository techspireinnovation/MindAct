<?php

namespace App\Repositories;

use App\Models\Cash;

use App\Traits\Paginator;

use App\Interfaces\CashRepositoryInterface;

use App\Http\Resources\CashResource;

class CashRepository implements CashRepositoryInterface
{
    use Paginator;

    public function list(array $filters)
    {
        $query = Cash::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $cashes = $query->paginate(50);

        return $this->paginated($cashes, CashResource::collection($cashes->items()));

    }







    public function create(array $data): Cash
    {
        if (isset($data['is_primary']) && $data['is_primary'] === true) {
            Cash::where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $data['is_primary'] = $data['is_primary'] ?? false;


        return Cash::create($data);

    }



    public function update($id, array $data)
    {
        $cash = Cash::findOrFail($id);

        if (isset($data['is_primary']) && $data['is_primary'] === true) {
            Cash::where('id', '!=', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $data['is_primary'] = $data['is_primary'] ?? false;

        $cash->update($data);

        return $cash->fresh();


    }

    public function delete($id)
    {
        $cash = Cash::findOrFail($id);
        $cash->delete();

        return true;
    }

    public function show($id)
    {

        $cash = Cash::findOrFail($id);

        return new CashResource($cash);

       
    }


    public function activeCashList()
    {
        $cashes = Cash::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name', 'is_primary']);
          

        $response = ($cashes->count() > 0) ? CashResource::collection($cashes)->map(function ($cash) {
            return collect($cash)->only(['id', 'name']);
        }) : [];


        return $response;

    }




}
?>
<?php

namespace App\Repositories;

use App\Models\Cash;

use App\Interfaces\CashRepositoryInterface;

class CashRepository implements CashRepositoryInterface
{

    public function list(array $filters)
    {
        $query = Cash::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        return $query->paginate(50);

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

        return $cash;
    }


    public function activeCashList()
    {
        $cash = Cash::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name', 'is_primary']);
          

        return $cash;

    }




}
?>
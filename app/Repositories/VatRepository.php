<?php

namespace App\Repositories;

use App\Models\Vat;

use App\Traits\Paginator;
use App\Interfaces\VatRepositoryInterface;
use App\Http\Resources\VatResource;

class VatRepository implements VatRepositoryInterface
{
    use Paginator;

    public function list(array $filters): array
    {
        $query = Vat::query();


        if (!empty($filters['keywords'])) {
            $query->where('vat_percent', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $vats = $query->paginate(50);
       
        return $this->paginated($vats, VatResource::collection($vats->items()));

    }



    public function VatDetails($filters)
    {

        $vatDetail = Vat::where('vat_percent', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new VatResource($vatDetail);

    }



    public function create(array $data): Vat
    {
       

        
        $data['is_active'] = $data['is_active'] ?? true;



        return Vat::create($data);

    }



    public function update($id, array $data)
    {

        $Vat = Vat::findOrFail($id);
       

        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

      


        $Vat->update($data);

        return $Vat->fresh();


    }

    public function delete($id)
    {
        $vat = Vat::findOrFail($id);

        $vat->delete();

        return true;
    }

    public function show($id)
    {

        $vat = Vat::findOrFail($id);

        return new VatResource($vat);
    }


    public function activeVatList()
    {
        $vats = Vat::whereNull('deleted_at')
            ->where('is_active', 1)
            ->get();

        $response = ($vats->count() > 0) ? VatResource::collection($vats)->map(function ($vat) {
            return collect($vat)->only(['id', 'vat_percent']);
        }) : [];


        return $response;

    }




}
?>
<?php

namespace App\Repositories;

use App\Models\Party;

use App\Interfaces\PartyRepositoryInterface;

use App\Traits\Paginator;

use App\Http\Resources\PartyResource;

class PartyRepository implements PartyRepositoryInterface
{

    use Paginator;


    public function list(array $filters, int $perPage = 50)
    {
        $query = Party::query();


        $parties = $query->paginate($perPage);

        return $this->paginated($parties, PartyResource::collection($parties->items()));
    }




    public function partyDetails($partyId = Null, $partyName = Null)
    {

        if ($partyId) {
            $partyDetail = Party::where('id', $partyId)
                ->whereNull('deleted_at')
                ->first();

            if ($partyDetail) {
                return $partyDetail;
            }
        }


        if ($partyName) {
            $partyDetail = Party::where('name', $partyName)
                ->whereNull('deleted_at')
                ->firstOrFail();
        }


        return new PartyResource($partyDetail);

    }



    public function create(array $data): Party
    {


        return Party::create($data);

    }



    public function update($id, array $data)
    {

        $party = Party::findOrFail($id);

        $party->update($data);

        return $party->fresh();


    }

    public function search($partyName)
    {
        return Party::select(['id', 'name'])
            ->where('name', 'like', "%{$partyName}%")
            ->whereNull('deleted_at')
            ->get();
    }



    public function delete($id)
    {
        $party = Party::findOrFail($id);

        $party->delete();

        return true;
    }

    public function show($id)
    {

        $party = Party::findOrFail($id);

        return new PartyResource($party);
    }




    public function activePartyList()
    {
        $parties = Party::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name']);


        $response = ($parties->count() > 0) ? PartyResource::collection($parties)->map(function ($party) {
            return collect($party)->only(['id', 'name']);
        }) : [];


        return $response;

    }




}
?>
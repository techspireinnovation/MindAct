<?php

namespace App\Repositories;

use App\Models\Party;

use App\Interfaces\PartyRepositoryInterface;

class PartyRepository implements PartyRepositoryInterface
{


    public function list(array $filters, int $perPage = 50)
    {
        $query = Party::query();


        $parties = $query->paginate($perPage);

        return $query->paginate(50);
    }


    public function partyList()
    {
        $parties = Party::where('is_active', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'name'])
            ->map(fn($party) => ['id' => $party->id, 'name' => $party->name])
            ->values()
            ->toArray();

        return $parties;
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


        return $partyDetail;

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

        return $party;
    }




    public function activePartyList()
    {
        $parties = Party::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name']);
           

        return $parties;

    }




}
?>
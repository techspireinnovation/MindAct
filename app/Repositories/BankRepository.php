<?php

namespace App\Repositories;

use App\Models\Bank;

use App\Traits\Paginator;
use App\Interfaces\BankRepositoryInterface;
use App\Http\Resources\BankResource;

class BankRepository implements BankRepositoryInterface
{
    use Paginator;

    public function list(array $filters): array
    {
        $query = Bank::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $banks = $query->paginate(50);

        return $this->paginated($banks, BankResource::collection($banks->items()));

    }



    public function bankDetails(array $filters)
    {
        $bankName = $filters['bank_name'] ?? null;

        $bankDetail = Bank::where('name', $bankName)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new BankResource($bankDetail);

    }



    public function create(array $data): Bank
    {
        if (!empty($data['is_primary'])) {
            Bank::where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $data['is_primary'] = $data['is_primary'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;



        return Bank::create($data);

    }



    public function update($id, array $data)
    {

        $Bank = Bank::findOrFail($id);
        if (!empty($data['is_primary']) && $data['is_primary'] === true) {
            Bank::where('id', '!=', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }


        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        if (array_key_exists('is_primary', $data)) {
            $data['is_primary'] = (bool) $data['is_primary'];
        }



        $Bank->update($data);

        return $Bank->fresh();


    }

    public function delete($id)
    {
        $Bank = Bank::findOrFail($id);

        $usedIn = [];

        if ($Bank->products()->exists()) {
            $usedIn[] = 'products';
        }


        if (!empty($usedIn)) {

            throw new \Exception('in_use:' . implode(',', $usedIn));
        }

        $Bank->delete();

        return true;
    }

    public function show($id)
    {

        $bank = Bank::findOrFail($id);

        return new BankResource($bank);
    }


    public function activeBankList()
    {
        $banks = Bank::whereNull('deleted_at')
            ->where('is_active', true)
            ->orderBy('name', 'asc')
            ->get();

        $response = ($banks->count() > 0) ? BankResource::collection($banks)->map(function ($bank) {
            return collect($bank)->only(['id', 'name']);
        }) : [];


        return $response;

    }




}
?>
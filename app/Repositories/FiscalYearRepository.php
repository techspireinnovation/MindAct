<?php

namespace App\Repositories;

use App\Models\FiscalYear;

use App\Traits\Paginator;
use App\Interfaces\FiscalYearRepositoryInterface;
use App\Http\Resources\FiscalYearResource;

class FiscalYearRepository implements FiscalYearRepositoryInterface
{
    use Paginator;

    public function list(array $filters): array
    {
        $query = FiscalYear::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        $fiscalYears = $query->paginate(50);
       
        return $this->paginated($fiscalYears, FiscalYearResource::collection($fiscalYears->items()));

    }



    public function fiscalYearDetails($filters)
    {

        $fiscalYearDetail = FiscalYear::where('name', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return new FiscalYearResource($fiscalYearDetail);

    }



    public function create(array $data): FiscalYear
    {
        if (!empty($data['is_active'])) {
            FiscalYear::where('is_active', true)
                ->update(['is_active' => false]);
        }

        
        $data['is_active'] = $data['is_active'] ?? true;



        return FiscalYear::create($data);

    }



    public function update($id, array $data)
    {

        $fiscalYear = FiscalYear::findOrFail($id);
        if (!empty($data['is_active']) && $data['is_active'] === true) {
            FiscalYear::where('id', '!=', $id)
                ->where('is_active', true)
                ->update(['is_active' => false]);
        }


        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

      



        $fiscalYear->update($data);

        return $fiscalYear->fresh();


    }

    public function delete($id)
    {
        $fiscalYear = FiscalYear::findOrFail($id);      

        $fiscalYear->delete();

        return true;
    }

    public function show($id)
    {

        $fiscalYear = FiscalYear::findOrFail($id);

        return new FiscalYearResource($fiscalYear);
    }


    public function activeFiscalYearList()
    {
        $fiscalYears = FiscalYear::whereNull('deleted_at')
            ->where('is_active', true)
            ->get();

        $response = ($fiscalYears->count() > 0) ? FiscalYearResource::collection($fiscalYears)->map(function ($FiscalYear) {
            return collect($FiscalYear)->only(['id', 'name']);
        }) : [];


        return $response;

    }




}
?>
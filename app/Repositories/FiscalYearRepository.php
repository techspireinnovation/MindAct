<?php

namespace App\Repositories;

use App\Models\FiscalYear;

use App\Models\Company;

use App\Models\AssignFiscalYear;

use App\Traits\Paginator;
use DB;
use App\Interfaces\FiscalYearRepositoryInterface;
use App\Http\Resources\FiscalYearResource;
use App\Http\Resources\AssignFiscalYearResource;

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


    public function createAssignFiscalYear(array $data)
    {
        $createdRecords = [];

        DB::transaction(function () use ($data, &$createdRecords) {
            foreach ($data as $item) {

                $exists = AssignFiscalYear::where('fiscal_year_id', $item['fiscal_year_id'])
                    ->where('company_id', $item['company_id'])
                    ->exists();

                if ($exists) {
                    throw new \Exception("Fiscal Year ID {$item['fiscal_year_id']} is already assigned to Company ID {$item['company_id']}");
                }
                $createdRecords[] = AssignFiscalYear::create([
                    'title' => $item['title'],
                    'fiscal_year_id' => $item['fiscal_year_id'],
                    'company_id' => $item['company_id'],
                    'is_active' => $item['is_active'] ?? true,
                ]);
            }
        });

        return AssignFiscalYearResource::collection(collect($createdRecords));
    }

    public function updateAssignFiscalYear($companyId, array $data)
    {
        $updatedRecords = [];

        DB::transaction(function () use ($companyId, $data, &$updatedRecords) {

            $batchCheck = [];


            $requestIds = collect($data)->pluck('id')->filter()->toArray();


            AssignFiscalYear::where('company_id', $companyId)
                ->when(!empty($requestIds), function ($query) use ($requestIds) {
                    $query->whereNotIn('id', $requestIds);
                })
                ->delete();

            foreach ($data as $item) {

                $fiscalYearId = $item['fiscal_year_id'];
                $key = $companyId . '-' . $fiscalYearId;


                if (isset($batchCheck[$key])) {
                    throw new \Exception("Fiscal Year ID {$fiscalYearId} is duplicated in the request for this company.");
                }

                $batchCheck[$key] = true;

                if (!empty($item['id'])) {

                    $assignment = AssignFiscalYear::where('id', $item['id'])
                        ->where('company_id', $companyId)
                        ->first();

                    if ($assignment) {
                        $assignment->update([
                            'title' => $item['title'],
                            'fiscal_year_id' => $fiscalYearId,
                            'is_active' => $item['is_active'] ?? true,
                        ]);
                    } else {

                        throw new \Exception("AssignFiscalYear with ID {$item['id']} not found for this company.");
                    }

                } else {

                    $assignment = AssignFiscalYear::create([
                        'title' => $item['title'],
                        'fiscal_year_id' => $fiscalYearId,
                        'company_id' => $companyId,
                        'is_active' => $item['is_active'] ?? true,
                    ]);
                }

                $updatedRecords[] = $assignment;
            }
        });

        return AssignFiscalYearResource::collection(collect($updatedRecords));
    }




    public function getAssignFiscalYearList(array $data)
    {
        $assignFiscalYears = Company::whereHas('assignFiscalYears')->whereNull('deleted_at')
            ->select('id', 'name')
            ->get();

        return $assignFiscalYears;
    }


    public function getAssignFiscalYearDetails($companyId)
    {
        $assignFiscalYears = AssignFiscalYear::whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->get();

        return AssignFiscalYearResource::collection($assignFiscalYears);
    }

    public function deleteFiscalYear($companyId)
    {
        $assignFiscalYears = AssignFiscalYear::where('company_id', $companyId)
            ->delete();

        if ($assignFiscalYears === 0) {
            throw new \Illuminate\Database\Eloquent\ModelNotFoundException("No Assigned Fiscal Year found for Company ID {$companyId}");
        }

        return true;
    }

}
?>
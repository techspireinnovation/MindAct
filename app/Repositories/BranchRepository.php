<?php

namespace App\Repositories;

use App\Models\Branch;



use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Interfaces\BranchRepositoryInterface;

class BranchRepository implements BranchRepositoryInterface
{

    public function list(array $filters)
    {
        $query = Branch::query();


        if (!empty($filters['keywords'])) {
            $query->where('name', 'LIKE', '%' . $filters['keywords'] . '%');
        }

        return $query->paginate(50);

    }

    public function branchList()
    {
        $branches = Branch::where('is_active', 1)
            ->whereNull('deleted_at')
            ->get(['id', 'name'])
            ->map(fn($brand) => ['id' => $brand->id, 'name' => $brand->name])
            ->values()
            ->toArray();

        return $branches;
    }

    public function branchDetails($filters)
    {

        $branchDetail = Branch::where('name', $filters)
            ->whereNull('deleted_at')
            ->firstorFail();

        return $branchDetail;

    }



    public function create(array $data): Branch
    {
        if (!empty($data['is_primary'])) {
            Branch::where('is_primary', true)
                ->update(['is_primary' => false]);
        }

        $data['is_primary'] = $data['is_primary'] ?? false;
        $data['is_active'] = $data['is_active'] ?? true;



        return Branch::create($data);

    }



    public function update($id, array $data)
    {

        $branch = Branch::findOrFail($id);
        if (!empty($data['is_primary']) && $data['is_primary'] === true) {
            Branch::where('id', '!=', $id)
                ->where('is_primary', true)
                ->update(['is_primary' => false]);
        }


        if (array_key_exists('is_active', $data)) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        if (array_key_exists('is_primary', $data)) {
            $data['is_primary'] = (bool) $data['is_primary'];
        }



        $branch->update($data);

        return $branch->fresh();


    }

    public function delete($id)
    {
        $branch = Branch::on('tenant')->findOrFail($id);

        // Prevent deletion if this is main/primary branch
        if ($branch->is_primary || $branch->branch_type === 'Main') {
            throw new \Exception('is_primary');
        }

        $usedIn = [];

        // Check central users dynamically (default connection)
        if (Schema::hasTable('branch_user') && Schema::hasTable('users')) {
            $existsInCentral = DB::table('branch_user')
                ->join('users', 'branch_user.user_id', '=', 'users.id')
                ->where('branch_user.branch_id', $branch->id)
                ->exists();

            if ($existsInCentral) {
                $usedIn[] = 'users (central)';
            }
        }

        // Check tenant tables dynamically
        $tenantConnection = $branch->getConnectionName();

        if (Schema::connection($tenantConnection)->hasTable('shrink_work_losses')) {
            if ($branch->shrinkWorkLoss()->exists()) {
                $usedIn[] = 'shrink_work_loss (tenant)';
            }
        }

        if (Schema::connection($tenantConnection)->hasTable('stock_reconciliations')) {
            if ($branch->stockReconciliation()->exists()) {
                $usedIn[] = 'stock_reconciliation (tenant)';
            }
        }

        // Stop deletion if branch is in use
        if (!empty($usedIn)) {
            throw new \Exception('in_use:' . implode(',', $usedIn));
        }

        // Soft delete
        $branch->delete();


        return true;
    }

    public function show($id)
    {

        $branch = Branch::findOrFail($id);

        return $branch;
    }


    public function activeBranchList()
    {
        $branches = Branch::whereNull('deleted_at')
            ->where('is_active', true)
            ->get(['id', 'name', 'is_primary'])
            ->map(fn($branch) => [
                'id' => $branch->id,
                'name' => $branch->name,
                'is_primary' => $branch->is_primary,
            ])
            ->values()
            ->toArray();

        return $branches;

    }




}
?>
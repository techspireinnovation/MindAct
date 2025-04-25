<?php

namespace App\Helpers;

use App\Models\Sale;
use App\Models\SaleProduct;
use Illuminate\Database\Eloquent\Collection;

class Helper
{
    /**
     * Retrieve all Sales associated with a given product_id, including batch_no and related SaleProducts.
     *
     * @param int $productId
     * @param int|null $companyId Optional company_id to filter by (respects CompanyIdScope if null)
     * @return Collection
     */
    public static function getSalesByProductId(int $productId, ?int $companyId = null): Collection
    {
        $query = Sale::query()
            ->whereHas('saleProducts', function ($query) use ($productId, $companyId) {
                $query->where('product_id', $productId);
                if ($companyId) {
                    $query->where('company_id', $companyId);
                }
            })
            ->with(['saleProducts' => function ($query) use ($productId, $companyId) {
                $query->where('product_id', $productId);
                if ($companyId) {
                    $query->where('company_id', $companyId);
                }
            }]);

        return $query->get();
    }

    public static function getSalesByBatch(string $batchNo, ?int $companyId = null): Collection
    {
        $query = Sale::query()
            ->where(function ($query) use ($batchNo, $companyId) {
                $query->where('batch_no', $batchNo);
                if ($companyId) {
                    $query->where('company_id', $companyId);
                }
            })
            ->with('saleProducts');
            
                
           

        return $query->get();
    }


    public static function getSalesByCustomer(int $customerID, ?int $companyId = null): Collection
    {
        $query = Sale::query()
            ->where(function ($query) use ($customerID, $companyId) {
                $query->where('customer_id', $customerID);
                if ($companyId) {
                    $query->where('company_id', $companyId);
                }
            })
            ->with('saleProducts');
            
                
           

        return $query->get();
    }
}
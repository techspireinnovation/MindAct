<?php

namespace App\Helpers;

use App\Models\MeasureUnit;
use App\Models\Product;
use App\Models\ProductList;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\Sale;
use App\Models\SaleProduct;
use App\Models\SalesReturn;
use App\Models\SalesReturnProduct;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

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
            ->with([
                'saleProducts' => function ($query) use ($productId, $companyId) {
                    $query->where('product_id', $productId);
                    if ($companyId) {
                        $query->where('company_id', $companyId);
                    }
                }
            ]);

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
    public function getAllExpiryDates(): JsonResponse
    {
        $expiryDates = SaleProduct::select('expiry_date')
            ->distinct()
            ->orderBy('expiry_date', 'asc')
            ->pluck('expiry_date');

        return response()->json([
            'message' => 'Expiry dates retrieved successfully',
            'data' => $expiryDates
        ], 200);
    }



    public static function getSalesByExpiryDate($expiryDate, ?int $companyId = null): Collection
    {
        $query = Sale::query()
            ->whereHas('saleProducts', function ($query) use ($expiryDate, $companyId) {
                $query->where('expiry_date', $expiryDate);
                if ($companyId) {
                    $query->where('company_id', $companyId);
                }
            })
            ->with([
                'saleProducts' => function ($query) use ($expiryDate, $companyId) {
                    $query->where('expiry_date', $expiryDate);
                    if ($companyId) {
                        $query->where('company_id', $companyId);
                    }
                }
            ]);

        return $query->get();
    }


    public static function getPrimaryRateAmount(int $productId, int $purchaseProductId): mixed
    {
        // get product primary measure unit
        $primaryProductUnit = ProductList::where(['product_id' => $productId, 'is_primary' => 1])->first();

        $productPurchase = PurchaseProduct::find($purchaseProductId);

        // if primary uom
        if ($primaryProductUnit && $productPurchase) {

            // if same uom then no conversion
            if ($primaryProductUnit->measure_unit_id === $productPurchase->measure_unit_id)
                return $productPurchase->price;
            else {
                $productMeasureUnit = MeasureUnit::find($primaryProductUnit->measure_unit_id);
                $purchaseProductMeasureUnit = MeasureUnit::find($productPurchase->measure_unit_id);

                $fromQty = $purchaseProductMeasureUnit->quantity;
                $toQty = $productMeasureUnit->quantity;

                if ($toQty > $fromQty) {
                    // Conversion is to a **larger unit** (e.g. grams → kilograms)
                    // Quantity should **decrease**, so divide
                    $factor = $fromQty / $toQty;
                } elseif ($toQty < $fromQty) {
                    // Conversion is to a **smaller unit** (e.g. grams → milligrams)
                    // Quantity should **increase**, so multiply
                    $factor = $fromQty * $toQty;
                } else {
                    // Same unit
                    $factor = 1;
                }
                return $productPurchase->price * $factor;

            }
        }
        return 0;
    }

    public static function convertToPrimaryUnitQuantity(int $productId, int $fromMeasureUnit, int|null $qty): mixed
    {
        // get product primary measure unit
        $primaryProductUnit = ProductList::where(['product_id' => $productId, 'is_primary' => 1])->first();

        // if primary uom
        if ($primaryProductUnit && $fromMeasureUnit) {

            // if same uom then no conversion
            if ($primaryProductUnit->measure_unit_id === $fromMeasureUnit)
                return $qty;
            else {
                $productMeasureUnit = MeasureUnit::find($primaryProductUnit->measure_unit_id);
                $purchaseProductMeasureUnit = MeasureUnit::find($fromMeasureUnit);

                $fromQty = $purchaseProductMeasureUnit->quantity;
                $toQty = $productMeasureUnit->quantity;

                if ($toQty > $fromQty) {
                    // Conversion is to a **larger unit** (e.g. grams → kilograms)
                    // Quantity should **decrease**, so divide
                    $factor = $fromQty / $toQty;
                } elseif ($toQty < $fromQty) {
                    // Conversion is to a **smaller unit** (e.g. grams → milligrams)
                    // Quantity should **increase**, so multiply
                    $factor = $fromQty * $toQty;
                } else {
                    // Same unit
                    $factor = 1;
                }
                return $qty * $factor;

            }
        }
        return 0;
    }

    public static function convertToPrimaryUnitRate(int $productId, int $fromMeasureUnit, float $rate): mixed
    {
        // get product primary measure unit
        $primaryProductUnit = ProductList::where(['product_id' => $productId, 'is_primary' => 1])->first();

        // if primary uom
        if ($primaryProductUnit && $fromMeasureUnit) {

            // if same uom then no conversion
            if ($primaryProductUnit->measure_unit_id === $fromMeasureUnit)
                return $rate;
            else {
                $productMeasureUnit = MeasureUnit::find($primaryProductUnit->measure_unit_id);
                $purchaseProductMeasureUnit = MeasureUnit::find($fromMeasureUnit);

                $fromQty = $purchaseProductMeasureUnit->quantity;
                $toQty = $productMeasureUnit->quantity;

                if ($toQty > $fromQty) {
                    // Conversion is to a **larger unit** (e.g. grams → kilograms)
                    // Quantity should **decrease**, so divide
                    $factor = $fromQty / $toQty;
                } elseif ($toQty < $fromQty) {
                    // Conversion is to a **smaller unit** (e.g. grams → milligrams)
                    // Quantity should **increase**, so multiply
                    $factor = $fromQty * $toQty;
                } else {
                    // Same unit
                    $factor = 1;
                }
                return $rate * $factor;

            }
        }
        return 0;
    }

    public static function getProductVatableAmount(int $productId, mixed $amount): mixed
    {
        $product = Product::where(['id' => $productId])->first();
        if ($product->is_vatable) {
            return $amount + ($amount * .13);
        }
        return $amount;
    }

    public static function getSalesReturnByProductId(int $productId, ?int $companyId = null): Collection
    {
        $query = SalesReturn::query()
            ->whereHas('salesReturnProducts', function ($query) use ($productId, $companyId) {
                $query->where('product_id', $productId);
                if ($companyId) {
                    $query->where('company_id', $companyId);
                }
            })
            ->with([
                'salesReturnProducts' => function ($query) use ($productId, $companyId) {
                    $query->where('product_id', $productId);
                    if ($companyId) {
                        $query->where('company_id', $companyId);
                    }
                }
            ]);

        return $query->get();
    }

    public static function getSalesReturnByBatch(string $batchNo, ?int $companyId = null): Collection
    {
        $query = SalesReturn::query()
            ->where(function ($query) use ($batchNo, $companyId) {
                $query->where('batch_no', $batchNo);
                if ($companyId) {
                    $query->where('company_id', $companyId);
                }
            })
            ->with('salesReturnProducts');
        return $query->get();
    }


    public static function getSalesReturnByCustomer(int $customerID, ?int $companyId = null): Collection
    {
        $query = SalesReturn::query()
            ->where(function ($query) use ($customerID, $companyId) {
                $query->where('customer_id', $customerID);
                if ($companyId) {
                    $query->where('company_id', $companyId);
                }
            })
            ->with('salesReturnProducts');
        return $query->get();
    }
    public function getAllsalesReturnExpiryDates(): JsonResponse
    {
        $expiryDates = SalesReturnProduct::select('expiry_date')
            ->distinct()
            ->orderBy('expiry_date', 'asc')
            ->pluck('expiry_date');

        return response()->json([
            'message' => 'Expiry dates retrieved successfully',
            'data' => $expiryDates
        ], 200);
    }
    public static function getSalesReturnByExpiryDate($expiryDate, ?int $companyId = null): Collection
    {
        $query = SalesReturn::query()
            ->whereHas('salesReturnProducts', function ($query) use ($expiryDate, $companyId) {
                $query->where('expiry_date', $expiryDate);
                if ($companyId) {
                    $query->where('company_id', $companyId);
                }
            })
            ->with([
                'salesReturnProducts' => function ($query) use ($expiryDate, $companyId) {
                    $query->where('expiry_date', $expiryDate);
                    if ($companyId) {
                        $query->where('company_id', $companyId);
                    }
                }
            ]);

        return $query->get();
    }

    public static function getProductNames($company)
    {
        $productNames = Product::where('company_id', $company)
            ->pluck('name')->toArray();


        return [
            'message' => 'Sucessfull!!',
            'data' => $productNames
        ];
    }


    public static function getPurchaseBills($company)
    {
        $purchaseBills = Purchase::where('company_id', $company)
            ->pluck('purchase_bill_number')->toArray();


        return [
            'message' => 'Sucessfull!!',
            'data' => $purchaseBills
        ];
    }


    public static function getProdutDetailsByName($name, $company)
    {
        $productDetail = Product::with([
            'productLists',
            'productFieldValues' => function ($query) {
                $query->with('productField');
            }
        ])
            ->where('name', $name)
            ->where('company_id', $company)
            ->firstOrFail();

        // Transform the product detail to include type in product_field_values
        $productData = $productDetail->toArray();
        $productData['product_field_values'] = $productDetail->productFieldValues->map(function ($fieldValue) {
            return [
                'id' => $fieldValue->id,
                'company_id' => $fieldValue->company_id,
                'product_field_id' => $fieldValue->product_field_id,
                'product_id' => $fieldValue->product_id,
                'value' => $fieldValue->value,
                'type' => $fieldValue->productField->type ?? null, // Include type from ProductField, handle null case
                'values' => $fieldValue->productField->values ?? null,
                'deleted_at' => $fieldValue->deleted_at,
                'created_at' => $fieldValue->created_at,
                'updated_at' => $fieldValue->updated_at,
            ];
        })->toArray();

        return [
            'message' => 'Successful!!', // Fixed typo: "Sucessfull" to "Successful"
            'data' => $productData
        ];
    }


    public static function getPurchaseProductNames($company)
    {
        $productIds = PurchaseProduct::where('company_id', $company)
            ->pluck('product_id')
            ->unique();

        $productNames = Product::whereIn('id', $productIds)
            ->pluck('name')
            ->unique();


        return [
            'message' => 'Successful!!',
            'data' => $productNames
        ];
    }

    public static function getPurchaseProductDetails($name, $company)
    {
        // Find product(s) with the given name
        $products = Product::where('name', $name)
            ->where('company_id', $company) // Ensure product belongs to the company
            ->pluck('id');

        if ($products->isEmpty()) {
            return [
                'message' => 'No products found with the given name',
                'data' => []
            ];
        }

        // Get purchase product details for the matching product IDs
        $purchaseProducts = PurchaseProduct::whereIn('product_id', $products)
            ->where('company_id', $company)
            ->with(['fieldValues.productField']) // Include related field values
            ->get();

        if ($purchaseProducts->isEmpty()) {
            return [
                'message' => 'No purchase products found for the given product name',
                'data' => []
            ];
        }

        // Optionally, append product name to each purchase product
        $purchaseProducts->each(function ($purchaseProduct) use ($name) {
            $purchaseProduct->product_name = $name;
        });

        return [
            'message' => 'Successful!!',
            'data' => $purchaseProducts
        ];
    }


}
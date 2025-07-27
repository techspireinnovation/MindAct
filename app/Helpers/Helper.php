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
use Cache;
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

    public static function getPrimaryUnitWithPrice(int $productId, int $unitId, float $quantity, float $price)
    {
        $primaryEntities = self::convertToPrimaryUnitQuantityRate($productId, $unitId, $quantity, $price);
        return [
            'total_price' => $primaryEntities[1],
            'primary_units' => $primaryEntities[0],
        ];

    }

    public static function castToDouble($value)
    {
        return is_numeric($value) ? (double) $value : null;
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

    public static function convertToPrimaryUnitQuantityRate(int $productId, int $fromMeasureUnit, mixed $qty, float $rate): array
    {
        // get product primary measure unit
        $primaryProductUnit = ProductList::where(['product_id' => $productId, 'is_primary' => 1])->first();

        // if primary uom
        if ($primaryProductUnit && $fromMeasureUnit) {

            // if same uom then no conversion
            if ($primaryProductUnit->measure_unit_id === $fromMeasureUnit)
                return [$qty, $rate];
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
                return [$qty * $factor, $rate * $factor];

            }
        }
        return [0, 0];
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
        $getProductForMeasureUnits = Product::with('productLists')
            ->where('id', $productDetail->id)
            ->where('company_id', $company)
            ->whereNull('deleted_at')
            ->first();

        if ($getProductForMeasureUnits) {
            // Step 1: Get measure_unit_id from Product
            $unitIds = collect([$getProductForMeasureUnits->measure_unit_id]);

            // Step 2: Add all measure_unit_ids from ProductList
            $productListUnitIds = $getProductForMeasureUnits->productLists->pluck('measure_unit_id');

            // Step 3: Merge and make unique
            $allUnitIds = $unitIds->merge($productListUnitIds)->unique()->values();


        } else {
            dd('Product not found');
        }

        $productData['measure_units'] = MeasureUnit::whereIn('id', $allUnitIds)
            ->where('company_id', $company)
            ->whereNull('deleted_at')
            ->get(['id', 'name', 'quantity']) // Get as a collection
            ->map(function ($unit) {
                return [
                    'id' => $unit->id,
                    'name' => $unit->name,
                    'measure_unit_quantity' => $unit->quantity ?? null,
                ];
            });

        $productData['product_field_values'] = $productDetail->productFieldValues->filter(function ($fieldValue) {
            return $fieldValue->productField !== null;
        })->map(function ($fieldValue) {
            return [
                'id' => $fieldValue->id,
                'company_id' => $fieldValue->company_id,
                'product_field_id' => $fieldValue->product_field_id,
                'product_id' => $fieldValue->product_id,
                'value' => $fieldValue->value ?? null,
                'name' => $fieldValue->productField->name ?? null,

                'type' => $fieldValue->productField->type ?? null, // Include type from ProductField, handle null case
                'values' => $fieldValue->productField->values ?? null,
                'deleted_at' => $fieldValue->deleted_at,
                'created_at' => $fieldValue->created_at,
                'updated_at' => $fieldValue->updated_at,
            ];
        })->toArray();


        $purchasePrices = PurchaseProduct::where('product_id', $productDetail->id)
            ->where('company_id', $company)
            ->whereNull('deleted_at')
            ->pluck('price');

        $productData['average_price'] = round($purchasePrices->avg(), 2);
        $productData['min_price'] = round($purchasePrices->min(), 2);
        $productData['last_purchase_price'] = round(
            PurchaseProduct::where('product_id', $productDetail->id)
                ->where('company_id', $company)
                ->whereNull('deleted_at')
                ->orderBy('created_at', 'desc')
                ->value('price') ?? 0
        );


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

    /**
     * Buld Cache Key 
     */
    public static function buildCacheKey(string $requestOfClient)
    {
        return sha1($requestOfClient); // Hash to avoid long keys
    }


    public static function checkDataInCache(string $requestParams)
    {
        $cacheKey = self::buildCacheKey($requestParams);
        if (Cache::has($cacheKey)) {
            $compressed = Cache::get($cacheKey);
            return unserialize(gzuncompress($compressed));
        }
    }

    public static function applyCache(string $requestParams, mixed $rows)
    {
        $cacheKey = self::buildCacheKey($requestParams);
        $compressed = gzcompress(serialize($rows));
        Cache::remember($cacheKey, 3600, function () use ($compressed) {
            return $compressed;
        });
    }


    public static function storeInCache(string $keyParam, mixed $allData, int $timeSec)
    {
        Cache::remember($keyParam, $timeSec, function () use ($allData) {
            return $allData;
        });
    }


    public static function getFromCache(string $keyParam)
    {
        return Cache::get($keyParam);
    }

    public static function getDataFromCache(string $requestParams)
    {
        $cacheKey = self::buildCacheKey($requestParams);
        $compressed = Cache::get($cacheKey);
        return unserialize(gzuncompress($compressed));

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

    public static function calculatePieces(float $quantity, float $measureUnitQuantity): float
    {
        $integerPart = floor($quantity);
        $decimalPart = $quantity - $integerPart;
        $decimalPieces = $decimalPart > 0 ? (int) str_replace('.', '', (string) $decimalPart) : 0;
        return $integerPart * $measureUnitQuantity + $decimalPieces;
    }

    public static function convertToTargetMeasureUnit(float $regularPieces, float $freePieces, float $targetMeasureUnitQuantity): array
    {
        // Ensure targetMeasureUnitQuantity is not zero to prevent division by zero
        if ($targetMeasureUnitQuantity <= 0) {
            throw new \Exception('Target measure unit quantity must be greater than zero.');
        }

        // Calculate regular quantity
        $regularIntegerUnits = floor($regularPieces / $targetMeasureUnitQuantity);
        $regularRemainingPieces = $regularPieces - ($regularIntegerUnits * $targetMeasureUnitQuantity);
        // Convert remaining pieces to decimal (e.g., 567 -> 0.567)
        $regularDecimal = $regularRemainingPieces > 0 ? (float) ('0.' . (int) $regularRemainingPieces) : 0;
        $regularQuantity = $regularIntegerUnits + $regularDecimal;

        // Calculate free quantity
        $freeIntegerUnits = floor($freePieces / $targetMeasureUnitQuantity);
        $freeRemainingPieces = $freePieces - ($freeIntegerUnits * $targetMeasureUnitQuantity);
        // Convert remaining pieces to decimal (e.g., 567 -> 0.567)
        $freeDecimal = $freeRemainingPieces > 0 ? (float) ('0.' . (int) $freeRemainingPieces) : 0;
        $freeQuantity = $freeIntegerUnits + $freeDecimal;

        return [$regularQuantity, $freeQuantity];
    }

}
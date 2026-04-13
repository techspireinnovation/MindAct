<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\AvaialableProductsService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

use Illuminate\Http\Request;

class AvailableListController extends Controller
{
    protected $availableProductsService;

    public function __construct(AvaialableProductsService $availableProductsService)
    {

        $this->availableProductsService = $availableProductsService;

    }


    public function productListAvaialable(
        Request
        $request
    ) {
        try {
            $data = $this->availableProductsService->productListforTransaction($request->company_id, $request->branch_id);
            return response()->json([
                "message" => "Product List Retrieved Successfully!!",
                "data" => $data
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(["message" => "Item not Found !!"], 404);
        } catch (QueryException $e) {


            return response()->json(["message" => "Database error occurred !!"], 500);

        } catch (\Exception $e) {
            return response()->json(["message" => "An unexpected error occurred !!"], 500);

        }
    }

    public function productListAvaialableDetails(
        Request
        $request
    ) {
        try {
            $productId = $request->product_id;
            $data = $this->availableProductsService->productListforTransactionDetails($request->company_id, $request->branch_id, $productId);

            if ($data->isEmpty()) {
                return response()->json([
                    "message" => "Product not found"
                ], 404);
            }

            $product = $data->first();

           
            if ($product->available_quantity <= 0) {
                return response()->json([
                    "message" => "Product is out of stock !!",
                    "data" => []
                ]);
            }

            return response()->json([
                "message" => "Product Details Retrieved Successfully!!",
                "data" => $data
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(["message" => "Item not Found !"], 404);
        } catch (QueryException $e) {

            return response()->json(["message" => "Database error occurred !"], 500);
        } catch (\Exception $e) {

            return response()->json(["message" => "An unexpected error occurred !"], 500);
        }
    }

    public function productListforTransactionItemWiseSalesReturn(
        Request
        $request
    ) {
        try {
            $data = $this->availableProductsService->productListforTransactionItemWiseSalesReturn($request->company_id, $request->branch_id);
            return response()->json([
                "message" => "Product List Retrieved Successfully!!",
                "data" => $data
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(["message" => "Item not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["message" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["message" => "An unexpected error occurred !!"], 500);
        }
    }

    public function productListforTransactionItemWiseSalesReturnDetails(
        Request
        $request
    ) {
        try {
            $productId = $request->product_id;
            $data = $this->availableProductsService->productListforTransactionItemWiseSalesReturnDetails($request->company_id, $request->branch_id, $productId);
            return response()->json([
                "message" => "Product Details Retrieved Successfully !!",
                "data" => $data
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(["message" => "Item not Found !!"], 404);
        } catch (QueryException $e) {

            return response()->json(["message" => "Database error occurred !"], 500);
        } catch (\Exception $e) {

            return response()->json(["message" => "An unexpected error occurred !!"], 500);

        }
    }

    public function productListforTransactionBillWisePurchaseReturn(
        Request
        $request
    ) {
        try {

            $data = $this->availableProductsService->productListforTransactionBillWisePurchaseReturn($request->company_id, $request->branch_id);
            return response()->json([
                "message" => "Purchase Return Bills Retrieved Successfully!!",
                "data" => $data
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(["message" => "Item not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["message" => "Database error occurred !"], 500);
        } catch (\Exception $e) {
            return response()->json(["message" => "An unexpected error occurred !!"], 500);
        }
    }


    public function productforTransactionBillWisePurchaseReturnDetails(
        Request
        $request
    ) {
        try {
            $billNumber = $request->bill_number;
            $data = $this->availableProductsService->productforTransactionBillWisePurchaseReturnDetails($request->company_id, $request->branch_id, $billNumber);
            return response()->json([
                "message" => "Purchase Return Product Details Retrieved Successfully!!",
                "data" => $data
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(["message" => "Item not Found !"], 404);
        } catch (QueryException $e) {
            dd($e->getMessage());
            return response()->json(["message" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["message" => "An unexpected error occurred !!"], 500);

        }
    }

    public function productListforTransactionBillWiseSalesReturn(
        Request
        $request
    ) {
        try {

            $data = $this->availableProductsService->productListforTransactionBillWiseSalesReturn($request->company_id, $request->branch_id);
            return response()->json([
                "message" => "Product List Retrieved Successfully!!",
                "data" => $data
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(["message" => "Item not Found !"], 404);
        } catch (QueryException $e) {
            dd($e->getMessage());
            return response()->json(["message" => "Database error occurred !"], 500);
        } catch (\Exception $e) {
            return response()->json(["message" => "An unexpected error occurred !!"], 500);

        }
    }

    public function productforTransactionBillWiseSalesReturnDetails(
        Request
        $request
    ) {
        try {
            $billNumber = $request->bill_number;
            $data = $this->availableProductsService->productforTransactionBillWiseSalesReturnDetails($request->company_id, $request->branch_id, $billNumber);
            return response()->json([
                "message" => "Sales Return Product Details Retrieved Successfully !!",
                "data" => $data
            ]);
        } catch (ModelNotFoundException) {
            return response()->json(["message" => "Item not Found !!"], 404);
        } catch (QueryException $e) {
            \Log::error('SQL ERROR', [
                'message' => $e->getMessage(),
                'sql' => $e->getSql(),
                'bindings' => $e->getBindings()
            ]);

            return response()->json(["message" => "Database error occurred !"], 500);
        } catch (\Exception $e) {



            return response()->json(["message" => "An unexpected error occurred !!"], 500);

        }
    }



    public function productDetailsProductCodeSku(
        Request
        $request
    ) {
        try {
            $productCode = $request->product_code;
            $productSku = $request->sku;
            $product = Product::where('product_code', $productCode)->orWhere('sku', $productSku)->first();
            $data = $this->availableProductsService->productListforTransactionDetails($request->company_id, $request->branch_id, $product->id);
            return response()->json([
                "message" => "Product Details Retrieved Successfully!!",
                "data" => $data
            ]);

        } catch (ModelNotFoundException) {
            return response()->json(["message" => "Item not Found !!"], 404);
        } catch (QueryException $e) {
            return response()->json(["message" => "Database error occurred !!"], 500);
        } catch (\Exception $e) {
            return response()->json(["message" => "An unexpected error occurred !!"], 500);
        }
    }
}

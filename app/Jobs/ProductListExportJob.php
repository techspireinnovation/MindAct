<?php

namespace App\Jobs;

use App\Reports\ProductReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rap2hpoutre\FastExcel\FastExcel;

class ProductListExportJob implements ShouldQueue
{
    use Queueable;
    protected $request;

    /**
     * Create a new job instance.
     */
    public function __construct(array $request)
    {
        $this->request = $request;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $filename = "app/public/exports/product_list_{$this->request['company_id']}_" . now()->timestamp . ".xlsx";
        $items = ProductReport::productListDetails($this->request);

        $sn = 1;
        $rows = $items->cursor()->map(function ($item) use (&$sn) {

            // $item->last_purchase_rate_amount = Helper::getPrimaryRateAmount($item->id, $item->lastPurchase->id ?? 0);
            // $item->last_purchase_rate_amount_vat = Helper::getProductVatableAmount($item->id, $item->last_purchase_rate_amount ?? 0);


            return [

                'SN' => $sn++,
                'Product Id' => $item->product_unique_id,
                'Product Name' => $item->name,
                'HS Code' => optional($item->primaryProductItem)->hs_code,
                'Bar Code' => optional($item->primaryProductItem)->barcode,
                'UOM' => optional($item->primaryProductItem)->measureUnit->name,
                'Quantity' => $item->product_stock_quantity,
                // 'Rate With Vat',
                // 'Rate Without Vat',
                'Location' => optional($item->location)->name,
                'Category' => optional($item->category)->name,
                'Sub Category' => optional($item->subCategory)->name,
                'Brand' => optional($item->brand)->name,
                'Vat Type' => ($item->is_vatable) ? "Yes" : "No",
                'Product Type' => optional($item->productType)->name,
            ];
        })->collect();

        (new FastExcel($rows))->export(storage_path($filename));


    }
}

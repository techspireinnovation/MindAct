<?php

namespace App\Jobs;

use App\Events\ReportEvent;
use App\Helpers\Helper;
use App\Reports\ProductReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rap2hpoutre\FastExcel\FastExcel;
use Storage;
use Str;

class StockRegisterListExportJob implements ShouldQueue
{
    use Queueable;
    protected $request;
    public $timeout = 300; // 5 minutes

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
        try {
            $randomString = Str::random(5);
            $filename = "stock_register_list_{$this->request['company_id']}_{$randomString}_" . now()->timestamp . ".xlsx";

            $items = ProductReport::stockRegisterListDetails($this->request);

            $sn = 1;
            $rows = $items->cursor()->map(function ($item) use (&$sn) {
                $last_purchase_rate_amount = Helper::getPrimaryRateAmount($item->id, $item->lastPurchase->id ?? 0);
                return [
                    'SN' => $sn++,
                    'Product Id' => $item->product_unique_id,
                    'Product Name' => $item->name,
                    'HS Code' => optional($item->primaryProductItem)->hs_code,
                    'Bar Code' => optional($item->primaryProductItem)->barcode,
                    'UOM' => optional($item->primaryProductItem->measureUnit)->name,
                    'Quantity' => $item->product_stock_quantity,
                    'Rate With Vat' => round(Helper::getProductVatableAmount($item->id, $last_purchase_rate_amount ?? 0), 2),
                    'Rate Without Vat' => round($last_purchase_rate_amount, 2),
                    'Location' => optional($item->location)->name,
                    'Category' => optional($item->category)->name,
                    'Sub Category' => optional($item->subCategory)->name,
                    'Brand' => optional($item->brand)->name,
                    'Vat Type' => ($item->is_vatable) ? "Yes" : "No",
                    'Product Type' => optional($item->productType)->name,
                ];
            })->collect();

            (new FastExcel($rows))->export(Storage::disk('company')->path($filename));
            event(new ReportEvent($this->request['token_id'], ["stockRegisterListExportJob" => ['downloadCompleted' => true, 'fileUrl' => url("api/company/download-file/$filename")]]));

        } catch (\Exception $e) {
            \Log::error("---->> StockRegisterListExportJob Error <---");
            \Log::error($e->getMessage());
            \Log::error("---->> StockRegisterListExportJob Error End <---");
        }
    }
}

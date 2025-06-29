<?php

namespace App\Jobs;

use App\Events\ReportEvent;
use App\Helpers\Helper;
use App\Models\Notification;
use App\Reports\ProductReport;
use Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Log;
use Rap2hpoutre\FastExcel\FastExcel;
use Storage;
use Str;

class StockRegisterListExportJob implements ShouldQueue
{
    use Queueable;

    protected $requestUrl;
    protected $tokenId;
    public $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(string $tokenId, string $requestUrl)
    {
        $this->tokenId = $tokenId;
        $this->requestUrl = $requestUrl;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $cacheKey = Helper::buildCacheKey($this->requestUrl);

            $query = parse_url($this->requestUrl, PHP_URL_QUERY);
            parse_str($query, $params);
            $companyId = (int) $params['company_id'];
            $randomString = Str::random(5);

            $filename = "stock_register_list_{$companyId}_{$randomString}_" . now()->timestamp . ".xlsx";

            if (Cache::has($cacheKey)) {
                $compressed = Cache::get($cacheKey);
                $rows = unserialize(gzuncompress($compressed));
            } else {
                $items = ProductReport::stockRegisterListDetails($params);
                $sn = 1;
                $rows = $items->cursor()->map(function ($item) use (&$sn) {

                    //  $closingRate = ($item->opening_rate ?? 0) + (($item->product_purchase_rate ?? 0) + ($item->sale_rate ?? 0) + ($item->purchase_return_rate ?? 0) + ($item->sale_return_rate ?? 0)) / 4;

                    // $closingQty = ($item->opening_quantity ?? 0) + ($item->purchase_quantity ?? 0) - ($item->sale_quantity ?? 0) - ($item->purchase_return_quantity ?? 0) + ($item->sale_return_quantity ?? 0);

                    return [
                        'S.N' => $sn++,
                        'Product Id' => $item->product_unique_id,
                        'Product Name' => $item->name,
                        'UOM' => optional($item->primary_measure_unit)->name,
                        "Opening Qty" => $item->opening_quantity ?? 0,
                        "Purchase Qty" => $item->purchase_quantity ?? 0,
                        "Purchase Rate" => $item->product_purchase_rate ?? 0,
                        "Purchase Amount" => round(($item->product_purchase_amount ?? 0), 2),
                        "Debit Note" => "",
                        "Additional Pur. Cost" => "",
                        "Per Qty" => "",
                        "Total Per Qty" => "",
                        "Total" => "",
                        "Purchase Return Qty" => $item->purchase_return_quantity ?? 0,
                        "Purchase Return Rate" => $item->purchase_return_rate ?? 0,
                        "Purchase Return Amount" => round(($item->purchase_return_quantity ?? 0) * ($item->purchase_return_rate ?? 0), 2),

                        "Sales Qty" => $item->sale_quantity ?? 0,
                        "Sales Rate" => $item->sale_rate ?? 0,
                        "Sales Amount" => round(($item->sale_quantity ?? 0) * ($item->sale_rate ?? 0), 2),

                        "Credit Note" => "",

                        "Sales Return Qty" => $item->sale_return_quantity ?? 0,
                        "Sales Return Rate" => $item->sale_return_rate ?? 0,
                        "Sales Return Amount" => round(($item->sale_return_quantity ?? 0) * ($item->sale_return_rate ?? 0), 2),

                        "Adj. Qty" => 0,
                        "Adj. Qty Rate" => 0,
                        "Adj. Qty Quantity" => 0,

                        "Stock In" => 0,
                        "Stock In Rate" => 0,
                        "Stock In Quantity" => 0,

                        "Stock Out" => 0,
                        "Stock Out Rate" => 0,
                        "Stock Out Quantity" => 0,

                        "Production" => 0,
                        "Production Rate" => 0,
                        "Production Quantity" => 0,

                        // "Closing Qty" => $closingQty,
                        // "Closing Rate" => round($closingRate, 2),

                        // "Closing Amount" => round($closingRate * $closingQty, 2),

                        'Category' => optional($item->category)->name,
                        'Sub Category' => optional($item->subCategory)->name,
                        'Brand' => optional($item->brand)->name,
                        'Vat Type' => ($item->is_vatable) ? "Yes" : "No",
                        'Product Type' => optional($item->productType)->name,
                        'Location' => optional($item->location)->name,
                    ];
                })->collect();

                // compress and cached it
                $compressed = gzcompress(serialize($rows));
                Cache::remember($cacheKey, 3600, function () use ($compressed) {
                    return $compressed;
                });
            }

            (new FastExcel($rows))->export(Storage::disk('company')->path($filename));

            //create notifications to the user 
            $notification = [
                'user_id' => (int) $this->tokenId,
                'type' => "DOWNLOAD",
                "data" => [
                    'title' => 'Stock Register List Export is completed.',
                    'message' => 'Your stock register list has been successfully exported and is ready for download.',
                    'url' => url("api/company/download-file/$filename"),
                    'icon' => 'bell'
                ]
            ];
            Notification::create($notification);

            event(new ReportEvent($this->tokenId, ["exportJob" => ['downloadCompleted' => true, 'jobType' => 'stockRegisterExport', 'fileUrl' => url("api/company/download-file/$filename")]]));

        } catch (\Exception $e) {
            Log::error("---->> StockRegisterListExportJob Error <---");
            Log::error($e->getMessage());
            Log::error("---->> StockRegisterListExportJob Error End <---");
        }
    }

    public function failed()
    {
        event(new ReportEvent($this->tokenId, ["exportJob" => ['downloadCompleted' => false, 'jobType' => 'stockRegisterExport']]));
    }

}

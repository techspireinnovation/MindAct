<?php

namespace App\Jobs;

use App\Events\ReportEvent;
use App\Helpers\Helper;
use App\Reports\ProductReport;
use Cache;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Rap2hpoutre\FastExcel\FastExcel;
use Storage;
use Str;

class ProductListExportJob implements ShouldQueue
{
    use Queueable;
    protected $request;
    protected $tokenId;

    public $timeout = 300; // 5 minutes

    /**
     * Create a new job instance.
     */
    public function __construct(string $tokenId, array $request)
    {
        $this->tokenId = $tokenId;
        $this->request = $request;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {

            $queryParams = $this->request;
            ksort($this->request); // Sort parameters for consistency
            $queryString = http_build_query($queryParams);
            $fullUrl = "{$queryString}";
            $cacheKey = sha1($fullUrl); // Hash to avoid long keys

            $randomString = Str::random(5);
            $filename = "product_list_{$this->request['company_id']}_{$randomString}_" . now()->timestamp . ".xlsx";


            if (Cache::has($cacheKey)) {
                $compressed = Cache::get($cacheKey);
                $rows = unserialize(gzuncompress($compressed));
            } else {
                $items = ProductReport::productListDetails($this->request);
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

                // compress and cached it
                $compressed = gzcompress(serialize($rows));
                Cache::remember($cacheKey, 3600, function () use ($compressed) {
                    return $compressed;
                });
            }

            (new FastExcel($rows))->export(Storage::disk(name: 'company')->path($filename));
            event(new ReportEvent($this->tokenId, ["productListExportJob" => ['downloadCompleted' => true, 'fileUrl' => url("api/company/download-file/$filename")]]));
        } catch (\Exception $e) {
            \Log::error("---->> ProductListExportJob Error <---");
            \Log::error($e);
            \Log::error("---->> ProductListExportJob Error End <---");
        }
    }
}

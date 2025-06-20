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

class GrossProfitListExportJob implements ShouldQueue
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

                    $qtyIn = ($item->opening_quantity ?? 0) + ($item->purchase_quantity ?? 0) + ($item->sale_return_quantity ?? 0);
                    $qtyOut = ($item->sale_quantity ?? 0) + ($item->purchase_return_quantity ?? 0);

                    $qtyInRate = (($item->opening_rate ?? 0) + ($item->purchase_quantity ?? 0) + ($item->sale_return_quantity ?? 0)) / 3;
                    $qtyOutRate = (($item->sale_rate ?? 0) + ($item->purchase_return_rate ?? 0)) / 2;

                    $closingAmount = $qtyInRate * $qtyIn - $qtyOutRate * $qtyOut;
                    $closingQty = $qtyIn - $qtyOut;

                    return [
                        'S.N' => $sn++,
                        'Product Id' => $item->product_unique_id,
                        'Product Name' => $item->name,

                        "Opening Qty" => $item->opening_quantity ?? 0,
                        "Opening Amount" => round(($item->opening_rate ?? 0) * ($item->opening_quantity), 2),

                        "Qty In" => $item->qtyIn ?? 0,
                        "Amount In" => round($qtyInRate * $qtyIn, 2),

                        "Qty Out" => $item->$qtyOut ?? 0,
                        "Amount Out" => round($qtyOutRate * $qtyOut, 2),

                        "Closing Qty" => $closingQty,
                        "Closing Amount" => round($closingAmount, 2),

                        "Gross Profit" => round($qtyOutRate * $qtyOut - $qtyInRate * $qtyIn, 2),
                        "Gp Ratio Profit" => round(($qtyOutRate * $qtyOut - $qtyInRate * $qtyIn) / $qtyOutRate * $qtyOut, 2),

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
                    'title' => 'Gross Profit List Export is completed.',
                    'message' => 'Your Gross Profit list has been successfully exported and is ready for download.',
                    'url' => url("api/company/download-file/$filename"),
                    'icon' => 'bell'
                ]
            ];
            Notification::create($notification);

            event(new ReportEvent($this->tokenId, ["exportJob" => ['downloadCompleted' => true, 'jobType' => 'grossProfitListExport', 'fileUrl' => url("api/company/download-file/$filename")]]));

        } catch (\Exception $e) {
            Log::error("---->> GrossProfitListExportJob Error <---");
            Log::error($e->getMessage());
            Log::error("---->> GrossProfitListExportJob Error End <---");
        }
    }
}
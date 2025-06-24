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

use function PHPUnit\Framework\isFinite;

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

            $filename = "gross_profit_list_{$companyId}_{$randomString}_" . now()->timestamp . ".xlsx";

            if (Cache::has($cacheKey)) {
                $compressed = Cache::get($cacheKey);
                $rows = unserialize(gzuncompress($compressed));
            } else {

                $items = ProductReport::stockRegisterListDetails($params);
                $sn = 1;
                $rows = $items->cursor()->map(function ($item) use (&$sn) {

                    $purchase_detail = $item->purchase_detail;
                    $sale_detail = $item->sale_detail;
                    $sale_return_detail = $item->sale_return_detail;
                    $purchase_return_detail = $item->purchase_return_detail;

                    $qtyIn = ($item->opening_quantity ?? 0) + ($purchase_detail['qty'] ?? 0) + ($sale_return_detail['qty'] ?? 0);
                    $qtyOut = ($sale_detail['qty'] ?? 0) + ($purchase_return_detail['qty'] ?? 0);

                    $qtyInRate = (($item->opening_rate ?? 0) + ($purchase_detail['avg_price'] ?? 0) + ($sale_return_detail['avg_price'] ?? 0)) / 3;
                    $qtyOutRate = (($sale_detail['avg_price'] ?? 0) + ($purchase_return_detail['avg_price'] ?? 0)) / 3;

                    $qtyInAmt = $item->opening_rate * $item->opening_quantity + $purchase_detail['avg_price'] * $purchase_detail['qty'] + $sale_return_detail['avg_price'] * $sale_return_detail['qty'];

                    $qtyOutAmt = $sale_detail['avg_price'] * $sale_detail['qty'] + $purchase_return_detail['avg_price'] * $purchase_return_detail['qty'];

                    $closingQty = $qtyIn - $qtyOut;
                    $avgCost = $qtyIn !== 0 ? $qtyInAmt / $qtyIn : 0;
                    $closingAmount = $closingQty === 0 ? 0 : $closingQty * $avgCost;
                    $saleAmt = $sale_detail['avg_price'] * $sale_detail['qty'];
                    $cogs = $qtyInAmt - $qtyOutAmt;

                    $grossProfit = $saleAmt - $cogs;

                    $grossProfitRatio = $saleAmt > 0 && isFinite($grossProfit) ? ($grossProfit / $saleAmt) * 100 : 0;

                    return [
                        'S.N' => $sn++,
                        'Product Id' => $item->product_unique_id,
                        'Product Name' => $item->name,

                        "Opening Qty" => $item->opening_quantity ?? 0,
                        "Opening Amount" => round(($item->opening_rate ?? 0) * $item->opening_quantity, 2),

                        "Qty In" => $qtyIn ?? 0,
                        "Amount In" => round($qtyInAmt, 2),

                        "Qty Out" => $qtyOut ?? 0,
                        "Amount Out" => round($qtyOutAmt, 2),

                        "Closing Qty" => $closingQty,
                        "Closing Amount" => round($closingAmount, 2),

                        "Gross Profit" => round($grossProfit, 2),
                        "Gp Ratio Profit" => round($grossProfitRatio, 2),

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

    public function failed(\Throwable $exception)
    {
        event(new ReportEvent($this->tokenId, ["exportJob" => ['downloadCompleted' => false, 'jobType' => 'grossProfitListExport']]));
    }
}
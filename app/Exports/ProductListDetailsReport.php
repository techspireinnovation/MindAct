<?php

namespace App\Exports;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Rap2hpoutre\FastExcel\FastExcel;


class ProductListDetailsReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $companyId;
    protected $data;

    public function __construct($companyId, $data)
    {
        $this->data = $data;
        $this->companyId = $companyId;
    }
    public function handle()
    {

        $filename = "exports/product_list_{$this->companyId}__" . now()->timestamp . ".csv";

        // Prepare data with headings
        $headings = [
            ['ID', 'Name', 'Email']
        ];
        $rows = User::cursor()->map(function ($user) {
            return [
                $user->id,
                $user->name,
                $user->email,
            ];
        });

        // Combine headings and rows
        $data = collect($headings)->concat($rows);

        (new FastExcel($data))->export(storage_path($filename));

        // Optionally notify user here
    }
}

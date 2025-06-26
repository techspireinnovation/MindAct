<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Storage;

class ClearOldFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:clear-old-files';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $files = Storage::disk('company')->allFiles('/');
        foreach ($files as $file) {
            $lastModified = Storage::disk('company')->lastModified($file);
            $fileDate = Carbon::createFromTimestamp($lastModified);
            if (Carbon::now()->diffInDays($fileDate) > 5) {
                Storage::disk('company')->delete($file);
            }
        }
        $this->info('Old files deleted.');
    }
}

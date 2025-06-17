<?php

namespace App\Http\Controllers;

use Storage;



class DownloadController extends Controller
{
    public function download(string $filename)
    {
        $disk = Storage::disk('company');
        if (!$disk->exists($filename)) {
            abort(404, 'File not found');
        }

        $url = $disk->temporaryUrl(
            $filename,
            now()->addMinutes(5)
        );

        return response()->json(['url' => $url]);

        /// return $disk->download($filename, 'custom-' . $filename, [
        //  'Content-Type' => 'application/octet-stream'
        //]);
    }
}

<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileUploadController extends Controller
{

    public function upload(Request $request)
    {
        // Validate the uploaded file
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048', // Restrict file types and size
        ]);

        if ($request->file('file')->isValid()) {
            // Store the file securely in the private disk
            $path = $request->file('file')->store('', 'private');

            // Return success response with file path
            return response()->json(['message' => 'File uploaded successfully', 'path' => ($path)]);
        }

        return response()->json(['error' => 'Invalid file upload'], 400);
    }

    public function download($filename)
    {
        // Check if the file exists on the private disk
        if (Storage::disk('private')->exists($filename)) {
            return Storage::disk('private')->download($filename);
        }

        return response()->json(['error' => 'File not found'], 404);
    }
}

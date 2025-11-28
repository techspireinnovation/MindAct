<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class FileUploadController extends Controller
{

    // public function upload(Request $request)
    // {
    //     // Validate the uploaded file
    //     $request->validate([
    //         'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048', // Restrict file types and size
    //     ]);

    //     if ($request->file('file')->isValid()) {
    //         // Store the file securely in the private disk
    //         $path = $request->file('file')->store('', 'private');

    //         // Return success response with file path
    //         return response()->json(['message' => 'File uploaded successfully', 'path' => ($path)]);
    //     }

    //     return response()->json(['error' => 'Invalid file upload'], 400);
    // }

 public function upload(Request $request)
{
    try {
        // Validate the uploaded file
        $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,pdf|max:2048', // 2MB max
        ]);

        $file = $request->file('file');

        if ($file && $file->isValid()) {
            // Store the file in the private disk
            $path = $file->store('', 'private');

            return response()->json([
                'success' => true,
                'message' => 'File uploaded successfully.',
                'path' => $path,
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'Uploaded file is not valid.',
        ], 400);

    } catch (\Illuminate\Validation\ValidationException $e) {
       

        // Get the first error message
        $firstError = collect($e->errors())->flatten()->first();

        return response()->json([
            'success' => false,
            'message' => $firstError, // <-- set message to the first validation error
        ], 422);

    } catch (\Exception $e) {
       
        return response()->json([
            'success' => false,
            'message' => 'An unexpected error occurred during file upload.',
            'error' => $e->getMessage(),
        ], 500);
    }
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

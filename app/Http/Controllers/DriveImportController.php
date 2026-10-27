<?php

namespace App\Http\Controllers;

use App\Jobs\ImportDriveFolder;
use App\Jobs\ImportDriveImage;
use App\Services\GoogleDriveService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DriveImportController extends Controller
{
    public function store(Request $request, GoogleDriveService $drive): RedirectResponse
    {
        $data = $request->validate(['drive_link' => 'required|string|max:2048']);
        $link = $drive->parseLink($data['drive_link']);
        if (! filled(config('ocr.key'))) {
            return back()->withErrors(['drive_link' => 'Gemini is not configured. Set GEMINI_API_KEY in the server .env file before importing.'])->withInput();
        }
        if (! filled(config('drive.key'))) {
            return back()->withErrors(['drive_link' => 'Google Drive access is not configured. Set GOOGLE_DRIVE_API_KEY in the server .env file and enable Google Drive API for that key. The Gemini key is used separately for reading images.'])->withInput();
        }
        if (config('queue.default') !== 'database') {
            return back()->withErrors(['drive_link' => 'Drive imports require QUEUE_CONNECTION=database and a running queue worker.'])->withInput();
        }
        DB::transaction(function () use ($request, $link) {
            $id = DB::table('drive_imports')->insertGetId([
                'uploaded_by' => $request->user()->id, 'drive_id' => $link['id'],
                'resource_key' => $link['resource_key'], 'kind' => $link['kind'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            ImportDriveFolder::dispatch($id);
        });

        return redirect()->route('home', ['page' => 'queue'])->with('success', 'Drive import queued. You can close this page; imports continue in the background.');
    }

    public function retry(Request $request, int $import): RedirectResponse
    {
        $record = DB::table('drive_imports')->where('uploaded_by', $request->user()->id)->find($import);
        abort_unless($record, 404);
        DB::transaction(function () use ($import) {
            if (DB::table('drive_imports')->where('id', $import)->where('status', 'failed')->update(['status' => 'queued', 'error_information' => null, 'updated_at' => now()])) {
                ImportDriveFolder::dispatch($import);
            }
            DB::table('drive_import_items')->where('drive_import_id', $import)->where('status', 'failed')->chunkById(100, function ($items) {
                foreach ($items as $item) {
                    if (DB::table('drive_import_items')->where('id', $item->id)->where('status', 'failed')->update(['status' => 'queued', 'error_information' => null, 'updated_at' => now()])) {
                        ImportDriveImage::dispatch($item->id);
                    }
                }
            });
        });

        return back()->with('success', 'Failed Drive imports added to the queue.');
    }
}

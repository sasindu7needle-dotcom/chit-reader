<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessDocument;
use App\Models\FormDocument;
use App\Models\StudentInquiry;
use App\Models\User;
use App\Services\FieldNormalizationService;
use App\Services\SpreadsheetExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class WorkspaceController extends Controller
{
    public function index(Request $r)
    {
        if ($r->query('page') === 'templates') {
            return redirect()->route('home');
        }

        $documents = collect();
        $students = collect();
        $stats = ['total' => 0, 'review' => 0, 'verified' => 0, 'processing' => 0, 'failed' => 0];
        $selected = null;
        $driveImports = collect();
        if (Auth::check()) {
            if ($r->query('page') === 'queue') {
                $driveImports = DB::table('drive_imports')->where('uploaded_by', Auth::id())->latest()->limit(10)->get();
                foreach ($driveImports as $import) {
                    $import->counts = DB::table('drive_import_items')->where('drive_import_id', $import->id)->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
                    $import->failures = DB::table('drive_import_items')->where('drive_import_id', $import->id)->where('status', 'failed')->limit(3)->get(['filename', 'error_information']);
                }
            }
            $query = FormDocument::where('uploaded_by', Auth::id());
            $stats['total'] = (clone $query)->count();
            foreach (['review', 'verified', 'processing', 'failed'] as $s) {
                $stats[$s] = (clone $query)->where('processing_status', $s)->count();
            }$documents = (clone $query)->when($r->search, fn ($q) => $q->where('upload_filename', 'like', '%'.$r->search.'%'))->when($r->status, fn ($q) => $q->where('processing_status', $r->status))->latest()->paginate(10)->withQueryString();
            $students = StudentInquiry::whereHas('document', fn ($q) => $q->where('uploaded_by', Auth::id()))->when($r->search, function ($q) use ($r) {
                $q->where(function ($q) use ($r) {
                    foreach (array_keys(config('ocr.fields')) as $key) {
                        $q->orWhere($key, 'like', '%'.$r->search.'%');
                    }
                });
            })->latest()->get();
            if ($r->document) {
                $selected = (clone $query)->with('fields')->findOrFail($r->document);
            }
        }

        return view('workspace', compact('documents', 'students', 'stats', 'selected', 'driveImports'));
    }

    public function login()
    {
        if (app()->environment('local') && ! User::exists()) {
            return view('setup');
        }

        return view('login');
    }

    public function setup(Request $request)
    {
        abort_unless(app()->environment('local') && ! User::exists(), 403);
        $data = $request->validate(['name' => 'required|string|max:100', 'email' => 'required|email|unique:users', 'password' => 'required|string|min:12']);
        $user = User::create($data);
        $user->forceFill(['role' => 'admin'])->save();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/')->with('success', 'Your workspace is ready. Add your Gemini API key in the server environment to process forms.');
    }

    public function crop(FormDocument $document, string $key)
    {
        $this->authorizeDocument($document);
        $field = $document->fields()->where('field_key', $key)->firstOrFail();
        $path = $field->evidence['crop']['path'] ?? null;
        abort_unless($path && Storage::exists($path), 404);

        return response()->file(Storage::path($path), ['Cache-Control' => 'private, no-store']);
    }

    public function authenticate(Request $r)
    {
        $data = $r->validate(['email' => 'required|email', 'password' => 'required']);
        if (! Auth::attempt($data)) {
            return back()->withErrors(['email' => 'The email or password is incorrect.']);
        }$r->session()->regenerate();

        return redirect('/');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();

        return redirect('/');
    }

    private function authorizeDocument(FormDocument $document)
    {
        abort_unless($document->uploaded_by === Auth::id(), 403);
    }

    public function upload(Request $r)
    {
        if (! filled(config('ocr.key'))) {
            return back()->withErrors(['ocr' => 'OCR is not configured. Set GEMINI_API_KEY in the server .env file before uploading. Existing documents are preserved.']);
        }
        $r->validate(['template_id' => 'nullable|exists:form_templates,id', 'forms' => 'required|array|min:1|max:20', 'forms.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:15360|dimensions:min_width=600,min_height=600,max_width=5000,max_height=5000']);
        foreach ($r->file('forms') as $file) {
            $d = FormDocument::create(['template_id' => $r->input('template_id'), 'uploaded_by' => Auth::id(), 'original_image_path' => $file->store('documents'), 'upload_filename' => $file->getClientOriginalName(), 'image_checksum' => hash_file('sha256', $file->path())]);
            ProcessDocument::dispatch($d->id);
        }

        return redirect('/?page=queue')->with('success', 'Forms uploaded securely and added to the processing queue.');
    }

    public function image(FormDocument $document)
    {
        $this->authorizeDocument($document);

        return response()->file(Storage::path($document->original_image_path), ['Cache-Control' => 'private, no-store']);
    }

    public function retry(FormDocument $document)
    {
        $this->authorizeDocument($document);
        if (! filled(config('ocr.key'))) {
            return back()->withErrors(['ocr' => 'Set GEMINI_API_KEY in the server .env file before retrying extraction.']);
        }
        abort_unless($document->processing_status === 'failed', 422);
        $document->update(['processing_status' => 'queued', 'error_information' => null]);
        ProcessDocument::dispatch($document->id);

        return back()->with('success', 'Document added to the queue.');
    }

    public function processingStatus()
    {
        return response()->json(FormDocument::where('uploaded_by', Auth::id())->orderBy('id')->get(['id', 'processing_status', 'updated_at']))->header('Cache-Control', 'no-store');
    }

    public function review(Request $r, FormDocument $document)
    {
        $this->authorizeDocument($document);
        abort_unless($document->processing_status === 'review', 422);
        $rules = ['action' => 'required|in:draft,approve'];
        foreach (config('ocr.fields') as $key => $label) {
            $rules['fields.'.$key] = 'nullable|string|max:2000';
            $rules['decisions.'.$key] = 'required|in:accepted,corrected,blank,unreadable';
        }$v = $r->validate($rules);
        if ($v['action'] === 'approve') {
            $errors = [];
            $hasUnreadableFields = false;
            foreach (config('ocr.fields') as $key => $label) {
                if ($v['decisions'][$key] === 'blank') {
                    continue;
                }
                if ($v['decisions'][$key] === 'unreadable') {
                    $errors['decisions.'.$key] = 'Compare this value with the original, then choose Matches original or Blank on original.';
                    $hasUnreadableFields = true;
                }
                $result = app(FieldNormalizationService::class)->normalize($key, $v['fields'][$key] ?? null);
                if ($result['issues']) {
                    $errors['fields.'.$key] = $label.': '.implode(' ', $result['issues']);
                }
            }
            if ($hasUnreadableFields) {
                $errors['review'] = 'Check the highlighted fields below before approving.';
            }
            if ($errors) {
                return back()->withErrors($errors)->withInput();
            }
        }
        DB::transaction(function () use ($v, $document) {
            $values = [];
            foreach (config('ocr.fields') as $key => $label) {
                $value = $v['decisions'][$key] === 'blank' ? null : ($v['fields'][$key] ?? null);
                if ($key === 'inquiry_date' && $value !== null) {
                    $normalizedDate = app(FieldNormalizationService::class)->normalize($key, $value);
                    $value = $normalizedDate['value'] ?? $value;
                }
                $values[$key] = $value;
                $document->fields()->where('field_key', $key)->update(['reviewed_value' => $value, 'reviewer_status' => $v['decisions'][$key], 'review_required' => false]);
            }StudentInquiry::updateOrCreate(['form_document_id' => $document->id], $values + ['verification_status' => $v['action'] === 'approve' ? 'verified' : 'draft', 'verified_by' => $v['action'] === 'approve' ? Auth::id() : null, 'verified_at' => $v['action'] === 'approve' ? now() : null]);
            $document->update(['reviewed_at' => now(), 'approved_at' => $v['action'] === 'approve' ? now() : null, 'processing_status' => $v['action'] === 'approve' ? 'verified' : 'review']);
            DB::table('audit_logs')->insert(['user_id' => Auth::id(), 'form_document_id' => $document->id, 'action' => $v['action'], 'changes' => json_encode($v), 'created_at' => now(), 'updated_at' => now()]);
        });

        return redirect('/?page=students')->with('success', $v['action'] === 'approve' ? 'Student inquiry verified and saved.' : 'Review draft saved.');
    }

    public function export(Request $request)
    {
        if ($request->query('format') === 'xlsx') {
            $rows = [array_values(config('ocr.fields'))];
            $records = StudentInquiry::where('verification_status', 'verified')->whereHas('document', fn ($q) => $q->where('uploaded_by', Auth::id()))->cursor();
            foreach ($records as $row) {
                $rows[] = array_map(fn ($key) => $row->$key, array_keys(config('ocr.fields')));
            }
            $file = app(SpreadsheetExportService::class)->create($rows);

            return response()->download($file, 'student-inquiries.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
        }

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_values(config('ocr.fields')));
            StudentInquiry::where('verification_status', 'verified')->whereHas('document', fn ($q) => $q->where('uploaded_by', Auth::id()))->chunk(100, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(function ($key) use ($row) {
                        $v = $row->$key ?? '';

                        return preg_match('/^[=+@\-\t\r]/', $v) ? "'".$v : $v;
                    }, array_keys(config('ocr.fields'))));
                }
            });
            fclose($out);
        }, 'student-inquiries.csv', ['Content-Type' => 'text/csv']);
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AdministrationController extends Controller
{
    private function admin(): void
    {
        abort_unless(auth()->user()->role === 'admin', 403);
    }

    public function template(Request $r)
    {
        $this->admin();
        $data = $r->validate(['name' => 'required|string|max:100', 'anchors' => 'nullable|json']);
        $anchors = json_decode($data['anchors'] ?? '{}', true);
        foreach ($anchors as $key => $box) {
            abort_unless(array_key_exists($key, config('ocr.fields')) && is_array($box) && count($box) === 4, 422);
            foreach ($box as $n) {
                abort_unless(is_numeric($n) && $n >= 0 && $n <= 1000, 422);
            }abort_unless($box[2] > $box[0] && $box[3] > $box[1], 422);
        }DB::table('form_templates')->insert(['name' => $data['name'], 'configuration' => json_encode(['fields' => config('ocr.fields'), 'anchors' => $anchors]), 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit('template_created', ['name' => $data['name']]);

        return back()->with('success', 'Form template created. It is now available when uploading forms.');
    }

    public function deleteTemplate(int $template): RedirectResponse
    {
        $this->admin();

        return DB::transaction(function () use ($template): RedirectResponse {
            $record = DB::table('form_templates')->lockForUpdate()->find($template);
            abort_unless($record, 404);

            if (DB::table('form_documents')->where('template_id', $template)->exists()) {
                return redirect()->route('home', ['page' => 'templates'])->withErrors(['template' => 'This template is used by uploaded forms and cannot be deleted.']);
            }

            DB::table('form_templates')->where('id', $template)->delete();
            $this->audit('template_deleted', ['template_id' => $template, 'name' => $record->name]);

            return redirect()->route('home', ['page' => 'templates'])->with('success', 'Form template deleted.');
        });
    }

    public function settings(Request $r)
    {
        $this->admin();
        $data = $r->validate(['model' => 'required|regex:/^[a-zA-Z0-9.\-]+$/|max:100', 'retention_days' => 'required|integer|min:0|max:3650']);
        foreach ($data as $key => $value) {
            DB::table('workspace_settings')->updateOrInsert(['key' => $key], ['value' => $value]);
        }$this->audit('settings_updated', $data);

        return back()->with('success', 'Workspace settings saved. Restart the queue worker to apply the new model.');
    }

    public function user(Request $r)
    {
        $this->admin();
        $data = $r->validate(['name' => 'required|string|max:100', 'email' => 'required|email|unique:users', 'password' => 'required|string|min:12', 'role' => 'required|in:admin,reviewer']);
        $data['password'] = Hash::make($data['password']);
        $user = User::create(collect($data)->except('role')->all());
        $user->forceFill(['role' => $data['role']])->save();
        $this->audit('account_created', ['user_id' => $user->id, 'role' => $user->role]);

        return back()->with('success', 'Account created.');
    }

    private function audit(string $action, array $changes): void
    {
        DB::table('audit_logs')->insert(['user_id' => auth()->id(), 'action' => $action, 'changes' => json_encode($changes), 'created_at' => now(), 'updated_at' => now()]);
    }
}

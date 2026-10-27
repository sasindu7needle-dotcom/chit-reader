<?php

use App\Models\FormDocument;
use App\Models\User;
use App\Services\OcrEvaluationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inquira:prune', function () {
    $days = (int) DB::table('workspace_settings')->where('key', 'retention_days')->value('value');
    if ($days < 1) {
        $this->info('Retention is disabled.');

        return;
    }
    FormDocument::where('created_at', '<', now()->subDays($days))->whereNotIn('processing_status', ['processing', 'queued'])->chunkById(100, function ($documents) {
        foreach ($documents as $document) {
            Storage::delete($document->original_image_path);
            Storage::deleteDirectory('processed/'.$document->id);
            $document->delete();
        }
    });
    $this->info('Expired documents and linked student information removed.');
})->purpose('Apply configured document retention');
Schedule::command('inquira:prune')->daily()->withoutOverlapping();

Artisan::command('inquira:evaluate {ground_truth} {predictions}', function () {
    $truth = json_decode(file_get_contents($this->argument('ground_truth')), true, 512, JSON_THROW_ON_ERROR);
    $predictions = json_decode(file_get_contents($this->argument('predictions')), true, 512, JSON_THROW_ON_ERROR);
    $this->line(json_encode(app(OcrEvaluationService::class)->evaluate($truth, $predictions), JSON_PRETTY_PRINT));
})->purpose('Compare field-keyed extraction JSON with human-confirmed ground truth');

Artisan::command('inquira:user {email} {name=Admissions Administrator}', function () {
    $password = $this->secret('Set password (minimum 12 characters)');
    if (strlen($password) < 12) {
        $this->error('Password must be at least 12 characters.');

        return 1;
    }
    if (User::where('email', $this->argument('email'))->exists()) {
        $this->error('Account already exists.');

        return 1;
    }
    $user = User::create(['email' => $this->argument('email'), 'name' => $this->argument('name'), 'password' => Hash::make($password)]);
    $user->forceFill(['role' => 'admin'])->save();
    $this->info('Account created. Sign in at /login.');
})->purpose('Create a workspace account securely');

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

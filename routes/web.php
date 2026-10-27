<?php

use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\DriveImportController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkspaceController::class, 'index'])->name('home');
Route::get('/login', [WorkspaceController::class, 'login'])->name('login');
Route::post('/login', [WorkspaceController::class, 'authenticate'])->middleware('throttle:6,1');
Route::post('/setup', [WorkspaceController::class, 'setup'])->middleware('throttle:3,1');
Route::middleware('auth')->group(function () {
    Route::get('/processing-status', [WorkspaceController::class, 'processingStatus']);
    Route::post('/admin/templates', [AdministrationController::class, 'template']);
    Route::delete('/admin/templates/{template}', [AdministrationController::class, 'deleteTemplate'])->whereNumber('template')->name('templates.destroy');
    Route::post('/admin/settings', [AdministrationController::class, 'settings']);
    Route::post('/admin/users', [AdministrationController::class, 'user']);
    Route::post('/logout', [WorkspaceController::class, 'logout']);
    Route::post('/documents', [WorkspaceController::class, 'upload']);
    Route::post('/drive-imports', [DriveImportController::class, 'store'])->middleware('throttle:10,1')->name('drive-imports.store');
    Route::post('/drive-imports/{import}/retry', [DriveImportController::class, 'retry'])->whereNumber('import')->middleware('throttle:10,1')->name('drive-imports.retry');
    Route::get('/documents/{document}/image', [WorkspaceController::class, 'image']);
    Route::get('/documents/{document}/crop/{key}', [WorkspaceController::class, 'crop']);
    Route::post('/documents/{document}/retry', [WorkspaceController::class, 'retry']);
    Route::post('/documents/{document}/review', [WorkspaceController::class, 'review']);
    Route::get('/export', [WorkspaceController::class, 'export']);
});

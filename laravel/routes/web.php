<?php

use App\Http\Controllers\Admin\ChangeController;
use App\Http\Controllers\Admin\ClassificationRuleController;
use App\Http\Controllers\Admin\CollectorController;
use App\Http\Controllers\Admin\CommandController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\DeviceController;
use App\Http\Controllers\Admin\DownloadController;
use App\Http\Controllers\Admin\InventoryReviewController;
use App\Http\Controllers\Admin\PilotReadinessController;
use App\Http\Controllers\Admin\RawEvidenceController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\RunnerController;
use App\Http\Controllers\Admin\StorageHealthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/health', HealthController::class)->name('health');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::get('/', DashboardController::class)->name('admin.dashboard');
    Route::get('/devices', [DeviceController::class, 'index'])->name('admin.devices.index');
    Route::get('/devices/{device}', [DeviceController::class, 'show'])->name('admin.devices.show');
    Route::post('/devices/{device}/assignment', [DeviceController::class, 'updateAssignment'])->name('admin.devices.assignment.update');
    Route::get('/inventory-review', [InventoryReviewController::class, 'index'])->name('admin.inventory-review.index');
    Route::get('/pilot-readiness', PilotReadinessController::class)->name('admin.pilot-readiness.index');
    Route::get('/changes', [ChangeController::class, 'index'])->name('admin.changes.index');
    Route::get('/storage-health', [StorageHealthController::class, 'index'])->name('admin.storage-health.index');
    Route::get('/classification-rules', [ClassificationRuleController::class, 'index'])->name('admin.classification-rules.index');
    Route::post('/classification-rules', [ClassificationRuleController::class, 'store'])->name('admin.classification-rules.store');
    Route::post('/classification-rules/apply', [ClassificationRuleController::class, 'apply'])->name('admin.classification-rules.apply');
    Route::put('/classification-rules/{classificationRule}', [ClassificationRuleController::class, 'update'])->name('admin.classification-rules.update');
    Route::delete('/classification-rules/{classificationRule}', [ClassificationRuleController::class, 'destroy'])->name('admin.classification-rules.destroy');
    Route::get('/runners', [RunnerController::class, 'index'])->name('admin.runners.index');
    Route::get('/runners/{runner}', [RunnerController::class, 'show'])->name('admin.runners.show');
    Route::post('/runners/{runner}/manual-scan', [RunnerController::class, 'manualScan'])->name('admin.runners.manual-scan');
    Route::post('/runners/{runner}/repair', [RunnerController::class, 'repair'])->name('admin.runners.repair');
    Route::get('/collectors', [CollectorController::class, 'index'])->name('admin.collectors.index');
    Route::get('/commands', [CommandController::class, 'index'])->name('admin.commands.index');
    Route::get('/downloads', [DownloadController::class, 'index'])->name('admin.downloads.index');
    Route::get('/downloads/{filename}', [DownloadController::class, 'show'])->name('admin.downloads.show');
    Route::get('/raw-evidence', [RawEvidenceController::class, 'index'])->name('admin.raw-evidence.index');
    Route::get('/raw-evidence/{rawFile}', [RawEvidenceController::class, 'show'])->name('admin.raw-evidence.show');
    Route::get('/reports', [ReportController::class, 'index'])->name('admin.reports.index');
    Route::get('/reports/devices', [ReportController::class, 'devices'])->name('admin.reports.devices');
    Route::get('/reports/changes', [ReportController::class, 'changes'])->name('admin.reports.changes');
    Route::get('/reports/sites', [ReportController::class, 'sites'])->name('admin.reports.sites');
    Route::get('/reports/devices/export', [ReportController::class, 'exportDevices'])->name('admin.reports.devices.export');
    Route::get('/reports/changes/export', [ReportController::class, 'exportChanges'])->name('admin.reports.changes.export');
    Route::get('/reports/sites/export', [ReportController::class, 'exportSites'])->name('admin.reports.sites.export');
});

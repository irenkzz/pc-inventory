<?php

use App\Http\Controllers\Api\CollectorIntakeController;
use App\Http\Controllers\Api\DeviceReadController;
use App\Http\Controllers\Api\DirectRunnerCommandController;
use App\Http\Controllers\Api\DirectRunnerHeartbeatController;
use App\Http\Controllers\Api\DirectRunnerScanController;
use App\Http\Controllers\Api\InventoryIntakeController;
use App\Http\Controllers\Api\RunnerReadController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/intake/json', [InventoryIntakeController::class, 'json']);
Route::post('/intake/csv', [InventoryIntakeController::class, 'csv']);

Route::get('/devices', [DeviceReadController::class, 'index']);
Route::get('/devices/{device}', [DeviceReadController::class, 'show']);
Route::get('/runners', [RunnerReadController::class, 'index']);
Route::get('/runners/{runner}', [RunnerReadController::class, 'show']);

Route::post('/collector/status', [CollectorIntakeController::class, 'status']);
Route::post('/collector/heartbeat', [CollectorIntakeController::class, 'heartbeat']);
Route::post('/collector/intake/csv', [CollectorIntakeController::class, 'csv']);
Route::get('/collector/commands', [CollectorIntakeController::class, 'commands']);
Route::post('/collector/command-ack', [CollectorIntakeController::class, 'acknowledge']);

Route::post('/direct-runner/heartbeat', [DirectRunnerHeartbeatController::class, 'store']);
Route::post('/direct-runner/scans', [DirectRunnerScanController::class, 'store']);
Route::post('/direct-runner/commands/poll', [DirectRunnerCommandController::class, 'poll']);
Route::post('/direct-runner/commands/ack', [DirectRunnerCommandController::class, 'acknowledge']);

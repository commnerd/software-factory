<?php

use App\Http\Controllers\AgentRunController;
use App\Http\Controllers\AiSkillController;
use App\Http\Controllers\ApprovalGateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ModelConfigurationController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SelfIterationController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::resource('projects', ProjectController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
Route::controller(ProjectController::class)->prefix('projects/{project}')->name('projects.')->group(function () {
    Route::post('start', 'start')->name('start');
    Route::post('pause', 'pause')->name('pause');
    Route::put('settings', 'settings')->name('settings');
    Route::put('routes', 'routes')->name('routes');
});

Route::get('models', [ModelConfigurationController::class, 'index'])->name('models.index');
Route::post('models', [ModelConfigurationController::class, 'store'])->name('models.store');
Route::put('models/routing', [ModelConfigurationController::class, 'routing'])->name('models.routing');
Route::put('models/{model}', [ModelConfigurationController::class, 'update'])->name('models.update');
Route::delete('models/{model}', [ModelConfigurationController::class, 'destroy'])->name('models.destroy');

Route::controller(AiSkillController::class)->prefix('skills')->name('skills.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::get('create', 'create')->name('create');
    Route::post('/', 'store')->name('store');
    Route::get('{skill}', 'show')->name('show');
    Route::put('{skill}', 'update')->name('update');
    Route::patch('{skill}/toggle', 'toggle')->name('toggle');
    Route::post('{skill}/versions/{version}/activate', 'activate')->name('activate');
    Route::post('{skill}/test', 'test')->name('test');
});

Route::get('approvals', [ApprovalGateController::class, 'index'])->name('approvals.index');
Route::post('approvals/{gate}/decide', [ApprovalGateController::class, 'decide'])->name('approvals.decide');

Route::get('runs/{run}', [AgentRunController::class, 'show'])->name('runs.show');

Route::controller(SelfIterationController::class)->prefix('self')->name('self.')->group(function () {
    Route::get('/', 'index')->name('index');
    Route::post('analyze', 'analyze')->name('analyze');
    Route::post('proposals/{proposal}/process', 'process')->name('process');
    Route::delete('proposals/{proposal}', 'destroy')->name('destroy');
});

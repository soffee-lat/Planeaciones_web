<?php

use App\Http\Controllers\CurriculumMapController;
use App\Http\Controllers\InstitutionalFormatDesignerController;
use App\Http\Controllers\InstitutionalFormatVisualBindingController;
use App\Http\Controllers\InstitutionalFormatVisualPreviewController;
use App\Http\Controllers\PrivateDeliveryDownloadController;
use App\Http\Controllers\PrivateFormatSampleDownloadController;
use App\Http\Controllers\PrivateInstitutionalFormatSourceController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');
Route::redirect('/login', '/app/login')->name('login');

Route::middleware('auth')->group(function (): void {
    Route::get('/app/deliveries/{delivery}/files/{file}', PrivateDeliveryDownloadController::class)->name('planning-deliveries.download');
    Route::get('/app/format-samples/{sample}/files/{file}', PrivateFormatSampleDownloadController::class)->name('format-samples.download');

    Route::get('/app/planning-requests/{planningRequest}/curriculum-map', [CurriculumMapController::class, 'show'])
        ->name('planning.curriculum-map');
    Route::post('/app/planning-requests/{planningRequest}/curriculum-map/decision', [CurriculumMapController::class, 'decision'])
        ->name('planning.curriculum-map.decision');
    Route::post('/app/planning-requests/{planningRequest}/curriculum-map/accept-all', [CurriculumMapController::class, 'acceptAll'])
        ->name('planning.curriculum-map.accept-all');
    Route::post('/app/planning-requests/{planningRequest}/curriculum-map/add', [CurriculumMapController::class, 'add'])
        ->name('planning.curriculum-map.add');
    Route::post('/app/planning-requests/{planningRequest}/curriculum-map/confirm', [CurriculumMapController::class, 'confirm'])
        ->name('planning.curriculum-map.confirm');

    Route::get('/app/institutional-formats/{format}/designer', InstitutionalFormatDesignerController::class)
        ->name('institutional-formats.designer');
    Route::get('/app/institutional-formats/{format}/source', PrivateInstitutionalFormatSourceController::class)
        ->name('institutional-formats.source');
    Route::post('/app/institutional-formats/{format}/visual-binding', InstitutionalFormatVisualBindingController::class)
        ->name('institutional-formats.visual-binding');
    Route::post('/app/institutional-formats/{format}/visual-preview', InstitutionalFormatVisualPreviewController::class)
        ->name('institutional-formats.visual-preview');
});

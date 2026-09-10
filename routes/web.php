<?php

use App\Http\Controllers\PrivateDeliveryDownloadController;
use App\Http\Controllers\PrivateFormatSampleDownloadController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');
Route::redirect('/login', '/app/login')->name('login');

Route::middleware('auth')->group(function (): void {
    Route::get('/app/deliveries/{delivery}/files/{file}', PrivateDeliveryDownloadController::class)
        ->name('planning-deliveries.download');
    Route::get('/admin/format-samples/{sample}/files/{file}', PrivateFormatSampleDownloadController::class)
        ->name('format-samples.download');
});

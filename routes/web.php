<?php

use App\Http\Controllers\ConversionController;
use App\Http\Controllers\DownloadController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\UploadController;
use App\Http\Middleware\EnsureSameOriginWrite;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');

Route::middleware([EnsureSameOriginWrite::class])->group(function (): void {
    Route::post('/uploads', UploadController::class)->middleware('throttle:uploads')->name('uploads.store');
    Route::post('/conversions/{conversion}/regenerate', [ConversionController::class, 'regenerate'])->middleware('throttle:uploads')->name('conversions.regenerate');
    Route::delete('/conversions/{conversion}', [ConversionController::class, 'destroy'])->name('conversions.destroy');
});

Route::get('/conversions/{conversion}', [ConversionController::class, 'show'])
    ->middleware('throttle:conversion-reads')
    ->name('conversions.show');

Route::get('/conversions/{conversion}/attempts/{attempt}', [ConversionController::class, 'show'])
    ->whereNumber('attempt')
    ->middleware('throttle:conversion-reads')
    ->name('conversions.attempts.show');

Route::get('/downloads/{attempt}/{kind}', DownloadController::class)
    ->middleware('signed')
    ->whereIn('kind', ['pptx', 'pdf'])
    ->name('downloads.show');

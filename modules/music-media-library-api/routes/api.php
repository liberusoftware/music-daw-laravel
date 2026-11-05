<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Liberu\Music\MediaLibraryApi\Http\Controllers\MediaController;
use Liberu\Music\MediaLibraryApi\Http\Middleware\ApiBoundary;
use Liberu\Music\MediaLibraryApi\Http\Middleware\RequireMedia;

Route::prefix('api/v1/music-media/workspaces/{workspace}')->where(['workspace' => '[A-Za-z0-9_-]{1,128}'])
    ->name('music.media.api.')->middleware([ApiBoundary::class, 'auth:sanctum', RequireMedia::class, 'throttle:60,1'])->group(function () {
        Route::middleware(CheckAbilities::class.':music.media.read')->group(function () {
            Route::get('/capabilities', [MediaController::class, 'capabilities'])->name('capabilities');
            Route::get('/assets', [MediaController::class, 'index'])->name('index');
            Route::get('/assets/{asset}', [MediaController::class, 'show'])->whereUuid('asset')->name('show');
            Route::get('/waveform-tasks/{task}', [MediaController::class, 'task'])->whereUuid('task')->name('task');
            Route::get('/waveform-tasks/{task}/result', [MediaController::class, 'result'])->whereUuid('task')->name('result');
            Route::put('/waveform-tasks/{task}', [MediaController::class, 'process'])->whereUuid('task')->middleware(CheckAbilities::class.':music.media.process')->name('process');
            Route::delete('/waveform-tasks/{task}', [MediaController::class, 'cancel'])->whereUuid('task')->middleware(CheckAbilities::class.':music.media.process')->name('cancel');
        });
        Route::get('/upload-budget', [MediaController::class, 'budget'])->middleware(CheckAbilities::class.':music.media.quota.read')->name('budget');
    });

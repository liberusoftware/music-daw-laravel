<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Liberu\Music\MediaLibraryApi\Http\Controllers\AssetTasksController;
use Liberu\Music\MediaLibraryApi\Http\Controllers\DownloadsController;
use Liberu\Music\MediaLibraryApi\Http\Controllers\LifecycleController;
use Liberu\Music\MediaLibraryApi\Http\Controllers\MediaController;
use Liberu\Music\MediaLibraryApi\Http\Controllers\MetadataController;
use Liberu\Music\MediaLibraryApi\Http\Controllers\OfflinePinsController;
use Liberu\Music\MediaLibraryApi\Http\Controllers\PacksController;
use Liberu\Music\MediaLibraryApi\Http\Controllers\TrashRecoveryController;
use Liberu\Music\MediaLibraryApi\Http\Controllers\UploadsController;
use Liberu\Music\MediaLibraryApi\Http\Middleware\ApiBoundary;
use Liberu\Music\MediaLibraryApi\Http\Middleware\RequireMedia;

Route::prefix('api/v1/music-media/workspaces/{workspace}')->where(['workspace' => '[A-Za-z0-9_-]{1,128}'])
    ->name('music.media.api.')->middleware([ApiBoundary::class, 'auth:sanctum', RequireMedia::class, 'throttle:60,1'])->group(function () {
        Route::middleware(CheckAbilities::class.':music.media.read')->group(function () {
            Route::get('/trash', [LifecycleController::class, 'browse'])->middleware(CheckAbilities::class.':music.media.trash.read')->name('trash');
            Route::put('/trash-commands/{command}', [LifecycleController::class, 'trash'])->whereUuid('command')->middleware(CheckAbilities::class.':music.media.trash.write')->name('trash-command');
            Route::put('/assets/{asset}/trash-recovery-tasks/{task}', [TrashRecoveryController::class, 'request'])->whereUuid('asset')->whereUuid('task')->middleware(CheckAbilities::class.':music.media.restore')->name('trash-recovery-request');
            Route::get('/trash-recovery-tasks/{task}', [TrashRecoveryController::class, 'show'])->whereUuid('task')->middleware(CheckAbilities::class.':music.media.restore')->name('trash-recovery');
            Route::get('/trash-recovery-tasks/{task}/result', [TrashRecoveryController::class, 'result'])->whereUuid('task')->middleware(CheckAbilities::class.':music.media.restore')->name('trash-recovery-result');
            Route::delete('/trash-recovery-tasks/{task}', [TrashRecoveryController::class, 'cancel'])->whereUuid('task')->middleware(CheckAbilities::class.':music.media.restore')->name('trash-recovery-cancel');
            Route::get('/offline-pins/{pin}', [OfflinePinsController::class, 'show'])->whereUuid('pin')->middleware(CheckAbilities::class.':music.media.pins.read')->name('pin');
            Route::put('/offline-pins/{pin}', [OfflinePinsController::class, 'save'])->whereUuid('pin')->middleware(CheckAbilities::class.':music.media.pins.write')->name('pin-save');
            Route::get('/packs/{pack}', [PacksController::class, 'show'])->whereUuid('pack')->middleware(CheckAbilities::class.':music.media.packs.read')->name('pack');
            Route::put('/packs/{pack}', [PacksController::class, 'save'])->whereUuid('pack')->middleware(CheckAbilities::class.':music.media.packs.write')->name('pack-save');
            Route::get('/packs/{pack}/history', [PacksController::class, 'history'])->whereUuid('pack')->middleware(CheckAbilities::class.':music.media.packs.read')->name('pack-history');
            Route::get('/packs/{pack}/export', [PacksController::class, 'export'])->whereUuid('pack')->middleware(CheckAbilities::class.':music.media.packs.export')->name('pack-export');
            Route::post('/packs/{pack}/import-preview', [PacksController::class, 'preview'])->whereUuid('pack')->middleware(CheckAbilities::class.':music.media.packs.import')->name('pack-preview');
            Route::put('/packs/{pack}/import', [PacksController::class, 'import'])->whereUuid('pack')->middleware(CheckAbilities::class.':music.media.packs.import')->name('pack-import');
            Route::get('/assets/{asset}/download-url', [DownloadsController::class, 'issue'])->whereUuid('asset')->middleware(CheckAbilities::class.':music.media.download')->name('download-url');
            Route::get('/assets/{asset}/download', [DownloadsController::class, 'download'])->whereUuid('asset')->middleware(CheckAbilities::class.':music.media.download')->name('download');
            Route::put('/assets/{asset}/metadata', [MetadataController::class, 'update'])->whereUuid('asset')->middleware(CheckAbilities::class.':music.media.edit')->name('update');
            Route::put('/verification-tasks/{task}', [AssetTasksController::class, 'request'])->whereUuid('task')->middleware(CheckAbilities::class.':music.media.upload')->name('verify');
            Route::put('/assets/{asset}/restoration-tasks/{task}', [AssetTasksController::class, 'restore'])->whereUuid('asset')->whereUuid('task')->middleware(CheckAbilities::class.':music.media.restore')->name('restore');
            Route::get('/asset-tasks/{task}', [AssetTasksController::class, 'show'])->whereUuid('task')->name('asset-task');
            Route::get('/asset-tasks/{task}/result', [AssetTasksController::class, 'result'])->whereUuid('task')->name('asset-task-result');
            Route::delete('/asset-tasks/{task}', [AssetTasksController::class, 'cancel'])->whereUuid('task')->name('asset-task-cancel');
            Route::get('/capabilities', [MediaController::class, 'capabilities'])->name('capabilities');
            Route::get('/assets', [MediaController::class, 'index'])->name('index');
            Route::get('/assets/{asset}', [MediaController::class, 'show'])->whereUuid('asset')->name('show');
            Route::get('/waveform-tasks/{task}', [MediaController::class, 'task'])->whereUuid('task')->name('task');
            Route::get('/waveform-tasks/{task}/result', [MediaController::class, 'result'])->whereUuid('task')->name('result');
            Route::put('/waveform-tasks/{task}', [MediaController::class, 'process'])->whereUuid('task')->middleware(CheckAbilities::class.':music.media.process')->name('process');
            Route::delete('/waveform-tasks/{task}', [MediaController::class, 'cancel'])->whereUuid('task')->middleware(CheckAbilities::class.':music.media.process')->name('cancel');
        });
        Route::middleware(CheckAbilities::class.':music.media.upload')->group(function () {
            Route::put('/uploads/{upload}', [UploadsController::class, 'declare'])->whereUuid('upload')->name('declare');
            Route::get('/uploads/{upload}', [UploadsController::class, 'status'])->whereUuid('upload')->name('upload-status');
            Route::delete('/uploads/{upload}', [UploadsController::class, 'cancel'])->whereUuid('upload')->name('cancel-upload');
            Route::put('/uploads/{upload}/chunks/{offset}', [UploadsController::class, 'append'])->whereUuid('upload')->where('offset', '0|[1-9][0-9]{0,9}')->name('append');
        });
        Route::get('/upload-budget', [MediaController::class, 'budget'])->middleware(CheckAbilities::class.':music.media.quota.read')->name('budget');
    });

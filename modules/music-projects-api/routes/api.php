<?php

use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Liberu\Music\ProjectsApi\Http\Controllers\SessionQueriesController;
use Liberu\Music\ProjectsApi\Http\Middleware\ApiBoundary;
use Liberu\Music\ProjectsApi\Http\Middleware\RequireProjects;

Route::prefix('api/v1/music-projects/workspaces/{workspace}')->where(['workspace' => '[A-Za-z0-9_-]{1,128}'])
    ->name('music.projects.api.')->middleware([ApiBoundary::class, 'auth:sanctum', RequireProjects::class, 'throttle:60,1'])->group(function () {
        Route::middleware(CheckAbilities::class.':music.projects.read')->group(function () {
            Route::get('/capabilities', [SessionQueriesController::class, 'capabilities'])->name('capabilities');
            Route::get('/sessions', [SessionQueriesController::class, 'index'])->name('index');
            Route::get('/sessions/{project}', [SessionQueriesController::class, 'show'])->whereUuid('project')->name('show');
            Route::get('/sessions/{project}/revisions', [SessionQueriesController::class, 'history'])->whereUuid('project')->name('history');
            Route::get('/sessions/{project}/manifest', [SessionQueriesController::class, 'export'])->whereUuid('project')->name('export');
        });
        Route::post('/sessions/{project}/import-preview', [SessionQueriesController::class, 'preview'])->whereUuid('project')
            ->middleware(CheckAbilities::class.':music.projects.preview')->name('preview');
    });

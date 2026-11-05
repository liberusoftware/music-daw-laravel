<?php

use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Support\Facades\Route;
use Liberu\Music\ProjectsLivewire\Livewire\SessionWorkspace;

Route::middleware(['web', 'auth', SetCacheHeaders::class.':no_store;private'])->group(function () {
    Route::get('/music/workspaces/{workspace}/sessions/{project?}', SessionWorkspace::class)
        ->where('workspace', '[A-Za-z0-9_-]{1,128}')->whereUuid('project')->name('music.projects.livewire.workspace');
});

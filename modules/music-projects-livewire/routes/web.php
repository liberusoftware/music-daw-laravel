<?php

use Illuminate\Support\Facades\Route;
use Liberu\Music\ProjectsLivewire\Livewire\SessionWorkspace;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/music/workspaces/{workspace}/sessions/{project?}', SessionWorkspace::class)
        ->where('workspace', '[A-Za-z0-9_-]{1,128}')->whereUuid('project')->name('music.projects.livewire.workspace');
});

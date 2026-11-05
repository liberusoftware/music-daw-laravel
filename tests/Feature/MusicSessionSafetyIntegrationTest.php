<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MediaAccess;
use Liberu\Music\Projects\Commands\BackfillSessionRetention;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Contracts\VerifiedMedia;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Exceptions\RevisionConflict;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\Projects\Support\UnavailableMedia;

beforeEach(function () {
    $this->actor = User::factory()->create();
    foreach (['write', 'read', 'list', 'retain'] as $operation) {
        Gate::define('music.projects.'.$operation, fn ($actor, $workspace) => $actor->is($this->actor) && in_array($workspace, ['studio', 'other'], true));
    }
    $this->sessions = app(ProjectSessions::class);
    $this->projectId = (string) Str::uuid();
    for ($version = 0; $version < 3; $version++) {
        $this->sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Song '.$version, SessionDocument::empty(), $version, 'save-'.$version, autosave: $version === 1));
    }
});

it('reopens bounded document-bearing pages and immutable history without losing autosave markers', function () {
    $templateId = (string) Str::uuid();
    $this->sessions->save($this->actor, new SaveSession('studio', $templateId, 'Template', SessionDocument::empty(96000), 0, 'template', template: true));
    $this->sessions->save($this->actor, new SaveSession('other', (string) Str::uuid(), 'Private', SessionDocument::empty(), 0, 'other'));
    $first = $this->sessions->browse($this->actor, 'studio', limit: 1);
    expect($first->items)->toHaveCount(1)->and($first->items[0]->projectId)->toBe($templateId)
        ->and($first->items[0]->document->jsonSerialize()['sample_rate'])->toBe(96000)->and($first->nextCursor)->toBeInt();
    $last = $this->sessions->browse($this->actor, 'studio', limit: 1, before: $first->nextCursor);
    expect($last->items[0]->projectId)->toBe($this->projectId)->and($last->items[0]->version)->toBe(3)->and($last->nextCursor)->toBeNull();
    expect($this->sessions->browse($this->actor, 'studio', templates: true)->items[0]->projectId)->toBe($templateId)
        ->and($this->sessions->browse($this->actor, 'studio', templates: false)->items[0]->projectId)->toBe($this->projectId)
        ->and($this->sessions->browse($this->actor, 'studio', before: 1)->items)->toBe([]);
    $history = $this->sessions->history($this->actor, 'studio', $this->projectId, limit: 1, before: 3);
    expect($history)->toHaveCount(1)->and($history[0]->version)->toBe(2)->and($history[0]->autosave)->toBeTrue()
        ->and($history[0]->document->checksum())->toBe(SessionDocument::empty()->checksum());
});

it('rejects invalid document page bounds and revoked project access', function () {
    expect(fn () => $this->sessions->browse($this->actor, 'studio', limit: 101))->toThrow(ValidationException::class)
        ->and(fn () => $this->sessions->history($this->actor, 'studio', $this->projectId, before: 0))->toThrow(ValidationException::class);
    Gate::define('music.projects.read', fn () => false);
    expect(fn () => $this->sessions->browse($this->actor, 'studio'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->sessions->history($this->actor, 'studio', $this->projectId))->toThrow(AuthorizationException::class);
});

it('refuses corrupted stored revisions on reopen export recovery and browse', function () {
    DB::table('music_project_revisions')->where('project_id', $this->projectId)->where('version', 3)->update(['checksum' => str_repeat('0', 64)]);
    expect(fn () => $this->sessions->get($this->actor, 'studio', $this->projectId))->toThrow(ValidationException::class, 'integrity check')
        ->and(fn () => $this->sessions->export($this->actor, 'studio', $this->projectId))->toThrow(ValidationException::class)
        ->and(fn () => $this->sessions->recover($this->actor, 'studio', $this->projectId, 3, 3, 'recover-corrupt'))->toThrow(ValidationException::class)
        ->and(fn () => $this->sessions->browse($this->actor, 'studio'))->toThrow(ValidationException::class);
    expect($this->sessions->get($this->actor, 'studio', $this->projectId, 1)->title)->toBe('Song 0')
        ->and(DB::table('music_projects')->where('id', $this->projectId)->value('version'))->toBe(3)
        ->and(DB::table('music_project_revisions')->where('project_id', $this->projectId)->count())->toBe(3);
});

it('rejects oversized and altered portable manifests without reserving a destination', function () {
    $destination = (string) Str::uuid();
    $payload = json_decode($this->sessions->export($this->actor, 'studio', $this->projectId), true, flags: JSON_THROW_ON_ERROR);
    $payload['checksum'] = str_repeat('0', 64);
    expect(fn () => $this->sessions->previewImport($this->actor, 'studio', $destination, json_encode($payload, JSON_THROW_ON_ERROR)))->toThrow(ValidationException::class, 'checksum')
        ->and(fn () => $this->sessions->import($this->actor, 'studio', $destination, str_repeat(' ', 17 * 1024 * 1024 + 1), 'oversized'))->toThrow(ValidationException::class, '17 MiB');
    expect(DB::table('music_projects')->where('id', $destination)->exists())->toBeFalse()
        ->and(DB::table('music_project_revisions')->count())->toBe(3);
});

it('rejects a suppressed version transition without recording a false checkpoint', function () {
    DB::unprepared('CREATE TRIGGER ignore_host_revision BEFORE UPDATE OF version ON music_projects BEGIN SELECT RAISE(IGNORE); END');
    try {
        expect(fn () => $this->sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Unsaved', SessionDocument::empty(), 3, 'suppressed')))
            ->toThrow(RevisionConflict::class, 'during this save');
        expect(DB::table('music_project_revisions')->where('project_id', $this->projectId)->count())->toBe(3)
            ->and($this->sessions->get($this->actor, 'studio', $this->projectId)->title)->toBe('Song 2');
    } finally {
        DB::unprepared('DROP TRIGGER ignore_host_revision');
    }
});

it('fails closed when the verified catalogue or retention owner is disabled', function ($dependency) {
    $asset = ['id' => (string) Str::uuid(), 'sha256' => str_repeat('a', 64), 'sample_rate' => 48000, 'channels' => 1, 'frames' => 2];
    $input = SessionDocument::empty()->jsonSerialize();
    $input['assets'] = [$asset];
    $document = SessionDocument::fromArray($input);
    $destination = (string) Str::uuid();
    $mediaAccess = Mockery::mock(MediaAccess::class);
    $mediaAccess->shouldReceive('authorized')->once()->with($this->actor->id, $asset['id'], 'read')->andReturnTrue();
    app()->instance(MediaAccess::class, $mediaAccess);
    if ($dependency === 'retention') {
        $catalogue = Mockery::mock(VerifiedMedia::class);
        $catalogue->shouldReceive('resolve')->once()->with($this->actor, 'studio', $asset['id'])->andReturn($asset);
        app()->instance(VerifiedMedia::class, $catalogue);
    } else {
        expect(app(VerifiedMedia::class))->toBeInstanceOf(UnavailableMedia::class);
    }
    $sessions = app(ProjectSessions::class);
    expect(fn () => $sessions->save($this->actor, new SaveSession('studio', $destination, 'Unavailable media', $document, 0, 'missing-owner')))
        ->toThrow(LogicException::class, $dependency === 'retention' ? 'retention owner' : 'catalogue');
    expect(DB::table('music_projects')->where('id', $destination)->exists())->toBeFalse()
        ->and(DB::table('music_project_revisions')->count())->toBe(3);
})->with(['catalogue', 'retention']);

it('reports durable bounded retention progress and requires an explicit restart to include later revisions', function () {
    $initial = $this->sessions->retentionStatus($this->actor, 'studio');
    expect($initial->jsonSerialize())->toBe(['schema_version' => 1, 'workspace_id' => 'studio', 'through_revision_id' => null,
        'last_revision_id' => 0, 'processed_revisions' => 0, 'complete' => false]);
    $first = $this->sessions->retainHistory($this->actor, new BackfillSessionRetention('studio', limit: 2));
    expect($first->processedRevisions)->toBe(2)->and($first->complete())->toBeFalse();
    $this->sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Later edit', SessionDocument::empty(), 3, 'later'));
    $last = $this->sessions->retainHistory($this->actor, new BackfillSessionRetention('studio', limit: 2));
    expect($last->throughRevisionId)->toBe($first->throughRevisionId)->and($last->processedRevisions)->toBe(3)->and($last->complete())->toBeTrue()
        ->and($this->sessions->retentionStatus($this->actor, 'studio')->jsonSerialize())->toBe($last->jsonSerialize());
    $replayed = $this->sessions->retainHistory($this->actor, new BackfillSessionRetention('studio'));
    expect($replayed->jsonSerialize())->toBe($last->jsonSerialize());
    $restarted = $this->sessions->retainHistory($this->actor, new BackfillSessionRetention('studio', restart: true));
    expect($restarted->processedRevisions)->toBe(4)->and($restarted->complete())->toBeTrue()
        ->and($restarted->throughRevisionId)->toBeGreaterThan($last->throughRevisionId);
    Gate::define('music.projects.retain', fn () => false);
    expect(fn () => $this->sessions->retentionStatus($this->actor, 'studio'))->toThrow(AuthorizationException::class);
});

it('round trips nested folders MIDI tracks and unavailable module state through autosave recovery and portable reopen', function () {
    $folderId = (string) Str::uuid();
    $input = SessionDocument::empty()->jsonSerialize();
    $input['tracks'] = [
        ['id' => $folderId, 'name' => 'Keys', 'kind' => 'folder', 'parent_id' => null, 'media_ids' => []],
        ['id' => (string) Str::uuid(), 'name' => 'Piano', 'kind' => 'midi', 'parent_id' => $folderId, 'media_ids' => []],
    ];
    $input['modules'] = ['unavailable-instrument' => ['schema_version' => 7, 'state' => ['preset' => 'Grand', 'parameters' => ['attack' => 0.25]]]];
    $document = SessionDocument::fromArray($input);
    $this->sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Folder arrangement', $document, 3, 'arrange', autosave: true));
    $this->sessions->recover($this->actor, 'studio', $this->projectId, 4, 4, 'recover-arrangement');
    $copy = $this->sessions->import($this->actor, 'studio', (string) Str::uuid(), $this->sessions->export($this->actor, 'studio', $this->projectId), 'reopen-arrangement');
    expect($copy->document->jsonSerialize())->toBe($input)->and($copy->autosave)->toBeFalse()
        ->and($this->sessions->get($this->actor, 'studio', $this->projectId)->document->checksum())->toBe($document->checksum());
});

it('rejects invalid portable track graphs and unsupported document fields before saving', function ($defect) {
    $destination = (string) Str::uuid();
    $payload = json_decode($this->sessions->export($this->actor, 'studio', $this->projectId), true, flags: JSON_THROW_ON_ERROR);
    $trackId = (string) Str::uuid();
    $payload['document']['tracks'] = [['id' => $trackId, 'name' => 'Track', 'kind' => 'audio', 'parent_id' => null, 'media_ids' => []]];
    switch ($defect) {
        case 'unknown field':
            $payload['document']['unknown'] = true;
            break;
        case 'unresolved media':
            $payload['document']['tracks'][0]['media_ids'] = ['missing'];
            break;
        case 'self parent':
            $payload['document']['tracks'][0]['parent_id'] = $trackId;
            break;
        case 'missing parent':
            $payload['document']['tracks'][0]['parent_id'] = (string) Str::uuid();
            break;
        case 'non-folder parent':
            $payload['document']['tracks'][] = ['id' => (string) Str::uuid(), 'name' => 'Child', 'kind' => 'midi', 'parent_id' => $trackId, 'media_ids' => []];
            break;
        case 'folder media':
            $payload['document']['assets'] = [['id' => 'asset', 'sha256' => str_repeat('a', 64), 'sample_rate' => 48000, 'channels' => 1, 'frames' => 2]];
            $payload['document']['tracks'][0]['kind'] = 'folder';
            $payload['document']['tracks'][0]['media_ids'] = ['asset'];
            break;
        case 'oversized module':
            $payload['document']['modules'] = ['instrument' => ['schema_version' => 1, 'state' => ['data' => str_repeat('a', 16 * 1024 * 1024)]]];
            break;
    }
    $payload['checksum'] = hash('sha256', json_encode($payload['document'], JSON_THROW_ON_ERROR));
    expect(fn () => $this->sessions->import($this->actor, 'studio', $destination, json_encode($payload, JSON_THROW_ON_ERROR), 'reject-invalid'))
        ->toThrow(ValidationException::class);
    expect(DB::table('music_projects')->where('id', $destination)->exists())->toBeFalse()
        ->and(DB::table('music_project_revisions')->count())->toBe(3);
})->with(['unknown field', 'unresolved media', 'self parent', 'missing parent', 'non-folder parent', 'folder media', 'oversized module']);

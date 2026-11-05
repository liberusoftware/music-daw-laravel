<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\Projects\Services\SessionMetadata;

beforeEach(function () {
    $this->actor = User::factory()->create();
    foreach (['write', 'read', 'list'] as $operation) {
        Gate::define('music.projects.'.$operation, fn ($actor, $workspace) => $actor->is($this->actor) && in_array($workspace, ['studio', 'other'], true));
    }
    $this->sessions = app(ProjectSessions::class);
    $this->metadata = app(SessionMetadata::class);
    $this->projectId = (string) Str::uuid();
    for ($version = 0; $version < 3; $version++) {
        $this->sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Song '.$version, SessionDocument::empty(), $version, 'save-'.$version, autosave: $version === 1));
    }
});

it('lists current session metadata in bounded pages without selecting documents or receipts', function () {
    $secondId = (string) Str::uuid();
    $this->sessions->save($this->actor, new SaveSession('studio', $secondId, 'Second song', SessionDocument::empty(), 0, 'create-second'));
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $first = $this->metadata->browse($this->actor, 'studio', limit: 1);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }
    $metadataQueries = array_values(array_filter($queries, fn ($query) => str_contains($query['query'], 'music_project_revisions')));
    expect($metadataQueries)->toHaveCount(1)
        ->and($metadataQueries[0]['query'])->not->toContain('document', 'fingerprint', 'idempotency_key', 'offset')
        ->and($first->items)->toHaveCount(1)->and($first->items[0]['project_id'])->toBe($secondId)
        ->and($first->nextCursor)->toBeInt();
    $last = $this->metadata->browse($this->actor, 'studio', limit: 1, before: $first->nextCursor);
    expect($last->nextCursor)->toBeNull()->and($last->items)->toHaveCount(1)
        ->and($last->items[0])->toMatchArray(['project_id' => $this->projectId, 'title' => 'Song 2', 'version' => 3, 'autosave' => false, 'template' => false, 'checksum' => SessionDocument::empty()->checksum()])
        ->and($last->items[0])->not->toHaveKeys(['document', 'actor_id', 'idempotency_key', 'fingerprint']);
    expect($this->metadata->browse($this->actor, 'studio', before: 1)->items)->toBe([]);
});

it('filters session templates and isolates workspace lists', function () {
    $templateId = (string) Str::uuid();
    $this->sessions->save($this->actor, new SaveSession('studio', $templateId, 'Starter template', SessionDocument::empty(), 0, 'template', template: true));
    $this->sessions->save($this->actor, new SaveSession('other', (string) Str::uuid(), 'Other studio', SessionDocument::empty(), 0, 'other'));
    expect(array_column($this->metadata->browse($this->actor, 'studio')->items, 'project_id'))->toBe([$templateId, $this->projectId])
        ->and(array_column($this->metadata->browse($this->actor, 'studio', templates: true)->items, 'project_id'))->toBe([$templateId])
        ->and(array_column($this->metadata->browse($this->actor, 'studio', templates: false)->items, 'project_id'))->toBe([$this->projectId])
        ->and($this->metadata->browse($this->actor, 'other')->items[0]['title'])->toBe('Other studio');
});

it('paginates immutable checkpoint and autosave history by revision number', function () {
    $first = $this->metadata->history($this->actor, 'studio', $this->projectId, limit: 2);
    expect(array_column($first->items, 'version'))->toBe([3, 2])
        ->and(array_column($first->items, 'autosave'))->toBe([false, true])->and($first->nextCursor)->toBe(2);
    $last = $this->metadata->history($this->actor, 'studio', $this->projectId, limit: 2, before: $first->nextCursor);
    expect(array_column($last->items, 'version'))->toBe([1])->and($last->items[0]['title'])->toBe('Song 0')
        ->and($last->nextCursor)->toBeNull();
    expect(fn () => $this->metadata->history($this->actor, 'other', $this->projectId))->toThrow(RecordNotFoundException::class)
        ->and(fn () => $this->metadata->history($this->actor, 'studio', (string) Str::uuid()))->toThrow(RecordNotFoundException::class);
});

it('rejects invalid bounds for both metadata query surfaces', function ($limit, $before) {
    expect(fn () => $this->metadata->browse($this->actor, 'studio', $limit, $before))->toThrow(ValidationException::class)
        ->and(fn () => $this->metadata->history($this->actor, 'studio', $this->projectId, $limit, $before))->toThrow(ValidationException::class);
})->with([[0, null], [101, null], [25, 0], [25, -1]]);

it('reauthorizes list and history calls for the live actor and each referenced project', function () {
    $outsider = User::factory()->create();
    expect(fn () => $this->metadata->browse($outsider, 'studio'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->metadata->history($outsider, 'studio', $this->projectId))->toThrow(AuthorizationException::class);
    Gate::define('music.projects.list', fn () => false);
    expect(fn () => $this->metadata->browse($this->actor, 'studio'))->toThrow(AuthorizationException::class);
    Gate::define('music.projects.list', fn () => true);
    Gate::define('music.projects.read', fn () => false);
    expect(fn () => $this->metadata->browse($this->actor, 'studio'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->metadata->history($this->actor, 'studio', $this->projectId))->toThrow(AuthorizationException::class);
});

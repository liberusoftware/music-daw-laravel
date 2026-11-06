<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MediaAccess;
use Liberu\Music\AudioEngine\Commands\SaveGraph;
use Liberu\Music\AudioEngine\Data\GraphDefinition;
use Liberu\Music\AudioEngine\Services\SessionGraphs;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Contracts\SessionMediaRetention;
use Liberu\Music\Projects\Contracts\VerifiedMedia;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Events\SessionSaved;
use Liberu\Music\Projects\Exceptions\RevisionConflict;
use Liberu\Music\Projects\MusicProjectsServiceProvider;
use Liberu\Music\Projects\Services\ProjectSessions;

beforeEach(function () {
    $this->app->register(MusicProjectsServiceProvider::class);
    $path = dirname((new ReflectionClass(MusicProjectsServiceProvider::class))->getFileName(), 2).'/database/migrations';
    $this->artisan('migrate', ['--path' => $path, '--realpath' => true])->assertSuccessful();
    $this->actor = new GenericUser(['id' => 1]);
    $this->project = (string) Str::uuid();
    foreach (['read', 'write'] as $operation) {
        Gate::define('music.projects.'.$operation, fn ($actor, $workspace) => $actor->id === 1 && in_array($workspace, ['studio', 'empty'], true));
    }
    $this->sessions = app(ProjectSessions::class);
    $this->graphs = app(SessionGraphs::class);
    $this->document = SessionDocument::empty()->jsonSerialize();
    $this->document['modules'] = ['future-plugin' => ['schema_version' => 9, 'state' => ['frames' => PHP_INT_MAX, 'opaque' => 'preserve']]];
    $this->sessions->save($this->actor, new SaveSession('studio', $this->project, 'Template', SessionDocument::fromArray($this->document), 0, 'create', template: true));
    $this->events = [];
    app(Dispatcher::class)->listen(SessionSaved::class, function ($event) {
        $this->events[] = $event;
    });
    $source = (string) Str::uuid();
    $output = (string) Str::uuid();
    $this->input = ['schema_version' => 1, 'buffer_frames' => 128, 'nodes' => [
        ['id' => $source, 'kind' => 'silence', 'channels' => 2, 'latency_frames' => 0],
        ['id' => $output, 'kind' => 'output', 'channels' => 2, 'latency_frames' => 0],
    ], 'edges' => [['from' => $source, 'to' => $output]]];
    $this->command = fn (int $version = 1, string $key = 'graph') => new SaveGraph('studio', $this->project, GraphDefinition::fromArray($this->input), $version, $key);
});

it('saves and reopens pinned graph revisions while preserving templates and opaque state', function () {
    $saved = $this->graphs->save($this->actor, ($this->command)());
    $first = $saved->jsonSerialize();
    expect($first['project_version'])->toBe('2')->and($first['graph'])->toBe($this->input)
        ->and($this->graphs->snapshot($this->actor, 'studio', $this->project)->jsonSerialize())->toBe($first)
        ->and($this->graphs->save($this->actor, ($this->command)())->jsonSerialize())->toBe($first);
    $this->input['buffer_frames'] = 256;
    $this->graphs->save($this->actor, ($this->command)(2, 'second'));
    expect($this->graphs->snapshot($this->actor, 'studio', $this->project, 2)->jsonSerialize())->toBe($first)
        ->and($this->graphs->snapshot($this->actor, 'studio', $this->project)->jsonSerialize()['graph']['buffer_frames'])->toBe(256);
    $this->input['buffer_frames'] = 128;
    expect($this->graphs->save($this->actor, ($this->command)())->jsonSerialize())->toBe($first);
    $latest = $this->sessions->get($this->actor, 'studio', $this->project);
    expect($latest->template)->toBeTrue()->and($latest->title)->toBe('Template')
        ->and($latest->document->jsonSerialize()['modules']['future-plugin'])->toBe($this->document['modules']['future-plugin'])
        ->and(DB::table('music_project_revisions')->count())->toBe(3);
    expect($this->events)->toHaveCount(2);
});

it('rejects stale writes and conflicting command replay without creating revisions', function () {
    $this->graphs->save($this->actor, ($this->command)());
    expect(fn () => $this->graphs->save($this->actor, ($this->command)(1, 'stale')))->toThrow(RevisionConflict::class);
    $this->input['buffer_frames'] = 256;
    expect(fn () => $this->graphs->save($this->actor, ($this->command)()))->toThrow(RevisionConflict::class);
    expect(DB::table('music_project_revisions')->count())->toBe(2);
});

it('authorizes graph reads writes historical snapshots and command replays on every call', function () {
    $this->graphs->save($this->actor, ($this->command)());
    $other = new GenericUser(['id' => 2]);
    foreach ([fn () => $this->graphs->save($other, ($this->command)()),
        fn () => $this->graphs->snapshot($other, 'studio', $this->project),
        fn () => $this->graphs->snapshot($this->actor, 'foreign', $this->project, 2)] as $action) {
        expect($action)->toThrow(AuthorizationException::class);
    }
    expect(fn () => $this->graphs->snapshot($this->actor, 'empty', $this->project))->toThrow(RecordNotFoundException::class);
    Gate::define('music.projects.write', fn () => false);
    expect(fn () => $this->graphs->save($this->actor, ($this->command)()))->toThrow(AuthorizationException::class);
    Gate::define('music.projects.read', fn () => false);
    expect(fn () => $this->graphs->save($this->actor, ($this->command)(2, 'next')))->toThrow(AuthorizationException::class);
    expect(DB::table('music_project_revisions')->count())->toBe(2);
});

it('preserves unsupported engine state and refuses silent schema replacement', function () {
    expect(fn () => $this->graphs->snapshot($this->actor, 'studio', $this->project))->toThrow(ValidationException::class);
    $this->document['modules']['music.audio-engine'] = ['schema_version' => 99, 'state' => ['opaque' => PHP_INT_MAX]];
    $this->sessions->save($this->actor, new SaveSession('studio', $this->project, 'Template', SessionDocument::fromArray($this->document), 1, 'future', template: true));
    expect(fn () => $this->graphs->save($this->actor, ($this->command)(2)))->toThrow(ValidationException::class, 'supported');
    expect($this->sessions->get($this->actor, 'studio', $this->project)->document->jsonSerialize())->toBe($this->document)
        ->and(DB::table('music_project_revisions')->count())->toBe(2);
});

it('does not publish or retain a graph revision when its enclosing transaction fails', function () {
    DB::beginTransaction();
    $this->graphs->save($this->actor, ($this->command)());
    DB::rollBack();
    expect(DB::table('music_project_revisions')->count())->toBe(1);
    expect(fn () => $this->graphs->snapshot($this->actor, 'studio', $this->project))->toThrow(ValidationException::class);
    expect($this->events)->toBe([]);
});

it('revalidates immutable asset metadata and permission on snapshot and replay', function () {
    $asset = ['id' => 'verified-recording', 'sha256' => str_repeat('a', 64), 'sample_rate' => 48000, 'channels' => 2, 'frames' => PHP_INT_MAX];
    $allowed = true;
    $access = Mockery::mock(MediaAccess::class);
    $access->shouldReceive('authorized')->andReturnUsing(function ($actor, $media, $operation) use (&$allowed) {
        expect((string) $actor)->toBe('1')->and($media)->toBe('verified-recording')->and($operation)->toBe('read');

        return $allowed;
    });
    app()->instance(MediaAccess::class, $access);
    $verified = Mockery::mock(VerifiedMedia::class);
    $verified->shouldReceive('resolve')->with($this->actor, 'studio', 'verified-recording')->andReturnUsing(fn () => $asset);
    app()->instance(VerifiedMedia::class, $verified);
    $retained = [];
    $retention = Mockery::mock(SessionMediaRetention::class);
    $retention->shouldReceive('retain')->andReturnUsing(function ($actor, $workspace, $project, $version, $ids, $transaction) use (&$retained) {
        expect($actor)->toBe($this->actor)->and($workspace)->toBe('studio')->and($project)->toBe($this->project)
            ->and($ids)->toBe(['verified-recording'])->and($transaction->transactionLevel())->toBeGreaterThan(0);
        $retained[] = $version;
    });
    app()->instance(SessionMediaRetention::class, $retention);
    $this->document['assets'] = [$asset];
    $sessions = app(ProjectSessions::class);
    $sessions->save($this->actor, new SaveSession('studio', $this->project, 'Template', SessionDocument::fromArray($this->document), 1, 'audio', template: true));
    $this->input['nodes'][0]['kind'] = 'media';
    $this->input['nodes'][0]['media_id'] = $asset['id'];
    $graphs = app(SessionGraphs::class);
    $snapshot = $graphs->save($this->actor, ($this->command)(2));
    expect($snapshot->jsonSerialize()['assets'][0]['frames'])->toBe((string) PHP_INT_MAX)
        ->and($retained)->toBe([2, 3]);
    $allowed = false;
    foreach ([fn () => $graphs->snapshot($this->actor, 'studio', $this->project),
        fn () => $graphs->snapshot($this->actor, 'studio', $this->project, 3),
        fn () => $graphs->save($this->actor, ($this->command)(2))] as $action) {
        expect($action)->toThrow(AuthorizationException::class);
    }
    $allowed = true;
    expect($graphs->save($this->actor, ($this->command)(2))->checksum())->toBe($snapshot->checksum())
        ->and(DB::table('music_project_revisions')->count())->toBe(3);
});

it('refuses unresolved graph media before changing project history', function () {
    $this->input['nodes'][0]['kind'] = 'media';
    $this->input['nodes'][0]['media_id'] = 'not-in-revision';
    expect(fn () => $this->graphs->save($this->actor, ($this->command)()))->toThrow(ValidationException::class, 'resolve');
    expect(DB::table('music_project_revisions')->count())->toBe(1)->and($this->events)->toBe([]);
});

<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\QueryException;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Liberu\Foundation\Files\Contracts\MediaAccess;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Contracts\ProjectAccess;
use Liberu\Music\Projects\Contracts\SessionMediaRetention;
use Liberu\Music\Projects\Contracts\VerifiedMedia;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Events\SessionSaved;
use Liberu\Music\Projects\Exceptions\IdempotencyConflict;
use Liberu\Music\Projects\Exceptions\RevisionConflict;
use Liberu\Music\Projects\MusicProjectsServiceProvider;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\Projects\Services\SessionMetadata;
use Liberu\Music\Projects\Support\GateProjectAccess;
use Liberu\Music\Projects\Support\UnavailableMedia;

beforeEach(function () {
    $this->artisan('migrate', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    $this->actor = new GenericUser(['id' => 1]);
    $this->projectId = (string) Str::uuid();
    app(Gate::class)->define('music.projects.write', fn ($actor, $workspace, $project) => $actor->id === 1 && $workspace === 'studio');
    app(Gate::class)->define('music.projects.read', fn ($actor, $workspace, $project) => $actor->id === 1 && in_array($workspace, ['studio', 'empty']));
    $this->sessions = app(ProjectSessions::class);
    $this->savedEvents = [];
    app(Dispatcher::class)->listen(SessionSaved::class, function ($event) {
        $this->savedEvents[] = $event;
    });
    $this->command = fn (int $version = 0, string $key = 'create', bool $autosave = false, bool $template = false): SaveSession => new SaveSession('studio', $this->projectId, 'First song', SessionDocument::empty(), $version, $key, $autosave, $template);
});

it('creates tracks, saves checkpoints, recovers autosave and reopens a portable session', function () {
    $first = $this->sessions->save($this->actor, ($this->command)());
    $document = SessionDocument::empty()->jsonSerialize();
    $document['tracks'][] = ['id' => (string) Str::uuid(), 'name' => 'Piano', 'kind' => 'midi', 'parent_id' => null, 'media_ids' => []];
    $document = SessionDocument::fromArray($document);
    $checkpoint = $this->sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Piano song', $document, 1, 'checkpoint'));
    $autosave = $this->sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Interrupted edit', $document, 2, 'draft', true));
    $recovered = $this->sessions->recover($this->actor, 'studio', $this->projectId, 3, 3, 'recover');
    expect($first->version)->toBe(1)->and($checkpoint->version)->toBe(2)
        ->and($autosave->autosave)->toBeTrue()->and($recovered->autosave)->toBeFalse()
        ->and($recovered->version)->toBe(4)->and($recovered->title)->toBe('Interrupted edit');
    $latest = $this->sessions->get($this->actor, 'studio', $this->projectId);
    expect($latest->jsonSerialize())->toBe($recovered->jsonSerialize())
        ->and($this->sessions->get($this->actor, 'studio', $this->projectId, 2)->title)->toBe('Piano song');
    $manifest = $this->sessions->export($this->actor, 'studio', $this->projectId, 4);
    $imported = $this->sessions->import($this->actor, 'studio', (string) Str::uuid(), $manifest, 'import');
    expect($imported->document->jsonSerialize())->toBe($document->jsonSerialize())
        ->and($imported->title)->toBe('Interrupted edit')->and($imported->version)->toBe(1)
        ->and($this->savedEvents)->toHaveCount(5)
        ->and($this->savedEvents[0]->actorId)->toBe('1')
        ->and($this->savedEvents[0]->revision->projectId)->toBe($this->projectId);
});

it('replays identical commands once and refuses idempotency key reuse with different content', function () {
    $command = ($this->command)();
    $first = $this->sessions->save($this->actor, $command);
    expect($this->sessions->save($this->actor, $command)->jsonSerialize())->toBe($first->jsonSerialize());
    expect($this->savedEvents)->toHaveCount(1)->and(DB::table('music_project_revisions')->count())->toBe(1);
    expect(fn () => $this->sessions->save($this->actor, ($this->command)(0, 'different')))->toThrow(RevisionConflict::class);
    expect(fn () => $this->sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Different', SessionDocument::empty(), 0, 'create')))->toThrow(RevisionConflict::class, 'idempotency');
    expect(fn () => $this->sessions->save($this->actor, ($this->command)(0, 'create', true)))->toThrow(RevisionConflict::class);
    expect(DB::table('music_project_revisions')->count())->toBe(1);
});

it('rejects stale checkpoints, stale autosaves and changes to project type', function () {
    $this->sessions->save($this->actor, ($this->command)());
    $this->sessions->save($this->actor, ($this->command)(1, 'draft', true));
    foreach ([($this->command)(1, 'stale'), ($this->command)(1, 'stale-draft', true), ($this->command)(2, 'type-change', false, true)] as $command) {
        expect(fn () => $this->sessions->save($this->actor, $command))->toThrow(RevisionConflict::class);
    }
    expect(DB::table('music_projects')->value('version'))->toBe(2);
});

it('does not emit an event or retain a revision when the surrounding transaction rolls back', function () {
    DB::beginTransaction();
    $this->sessions->save($this->actor, ($this->command)());
    expect($this->savedEvents)->toBe([]);
    DB::rollBack();
    expect(DB::table('music_projects')->count())->toBe(0)->and(DB::table('music_project_revisions')->count())->toBe(0)->and($this->savedEvents)->toBe([]);
});

it('rolls back a refused compare and swap without emitting a saved event', function () {
    $this->sessions->save($this->actor, ($this->command)());
    DB::unprepared('CREATE TRIGGER refuse_music_write BEFORE UPDATE ON music_projects BEGIN SELECT RAISE(IGNORE); END');
    expect(fn () => $this->sessions->save($this->actor, ($this->command)(1, 'new')))->toThrow(RevisionConflict::class, 'during');
    expect(DB::table('music_project_revisions')->count())->toBe(1)->and($this->savedEvents)->toHaveCount(1);
});

it('authorizes every read, replay, export, recovery and write using the supplied actor and workspace', function () {
    $this->sessions->save($this->actor, ($this->command)());
    $otherActor = new GenericUser(['id' => 2]);
    foreach ([
        fn () => $this->sessions->save($otherActor, ($this->command)()),
        fn () => $this->sessions->get($otherActor, 'studio', $this->projectId),
        fn () => $this->sessions->history($otherActor, 'studio', $this->projectId),
        fn () => $this->sessions->export($otherActor, 'studio', $this->projectId),
        fn () => $this->sessions->recover($otherActor, 'studio', $this->projectId, 1, 1, 'recover'),
        fn () => $this->sessions->import($otherActor, 'studio', (string) Str::uuid(), '{}', 'import'),
        fn () => $this->sessions->get($this->actor, 'wrong', $this->projectId),
    ] as $action) {
        expect($action)->toThrow(AuthorizationException::class);
    }
    app(Gate::class)->define('music.projects.write', fn () => false);
    expect(fn () => $this->sessions->save($this->actor, ($this->command)()))->toThrow(AuthorizationException::class);
    expect($this->savedEvents)->toHaveCount(1);
});

it('scopes reads to a workspace even if a policy permits access to both workspaces', function () {
    $this->sessions->save($this->actor, ($this->command)());
    expect(fn () => $this->sessions->get($this->actor, 'empty', $this->projectId))->toThrow(RecordNotFoundException::class);
    expect($this->sessions->history($this->actor, 'empty', $this->projectId))->toBe([]);
    expect(fn () => $this->sessions->get($this->actor, 'studio', (string) Str::uuid()))->toThrow(RecordNotFoundException::class);
    expect(fn () => $this->sessions->save($this->actor, new SaveSession('studio', (string) Str::uuid(), 'Missing', SessionDocument::empty(), 5, 'missing')))->toThrow(RecordNotFoundException::class);
});

it('returns bounded cursor ordered revision history and rejects invalid pagination', function () {
    $this->sessions->save($this->actor, ($this->command)());
    $this->sessions->save($this->actor, ($this->command)(1, 'second', true));
    $this->sessions->save($this->actor, ($this->command)(2, 'third'));
    $first = $this->sessions->history($this->actor, 'studio', $this->projectId, 2);
    expect(array_column($first, 'version'))->toBe([3, 2]);
    $second = $this->sessions->history($this->actor, 'studio', $this->projectId, 2, $first[1]->version);
    expect(array_column($second, 'version'))->toBe([1]);
    foreach ([0, 101] as $limit) {
        expect(fn () => $this->sessions->history($this->actor, 'studio', $this->projectId, $limit))->toThrow(ValidationException::class);
    }
    expect(fn () => $this->sessions->history($this->actor, 'studio', $this->projectId, before: 0))->toThrow(ValidationException::class);
});

it('retains templates and restores prior revisions without rewriting history', function () {
    $template = $this->sessions->save($this->actor, ($this->command)(0, 'template', false, true));
    $this->sessions->save($this->actor, ($this->command)(1, 'draft', true, true));
    $restored = $this->sessions->recover($this->actor, 'studio', $this->projectId, 1, 2, 'restore');
    expect($restored->template)->toBeTrue()->and($restored->autosave)->toBeFalse()->and($restored->version)->toBe(3)
        ->and($this->sessions->get($this->actor, 'studio', $this->projectId, 1)->jsonSerialize())->toBe($template->jsonSerialize());
    $new = $this->sessions->import($this->actor, 'studio', (string) Str::uuid(), $this->sessions->export($this->actor, 'studio', $this->projectId), 'from-template');
    expect($new->template)->toBeFalse()->and($new->document->jsonSerialize())->toBe($template->document->jsonSerialize());
});

it('checks stored integrity before exposing revisions or portable manifests', function () {
    $this->sessions->save($this->actor, ($this->command)());
    DB::table('music_project_revisions')->update(['checksum' => str_repeat('0', 64)]);
    expect(fn () => $this->sessions->get($this->actor, 'studio', $this->projectId))->toThrow(ValidationException::class, 'integrity');
});

it('validates import format, version, checksum, size and JSON', function () {
    $this->sessions->save($this->actor, ($this->command)());
    $manifest = $this->sessions->export($this->actor, 'studio', $this->projectId);
    foreach ([['format' => 'other'], ['format_version' => 2], ['checksum' => str_repeat('0', 64)], ['document' => []]] as $change) {
        $input = json_encode(array_replace(json_decode($manifest, true), $change), JSON_THROW_ON_ERROR);
        expect(fn () => $this->sessions->import($this->actor, 'studio', (string) Str::uuid(), $input, 'bad'))->toThrow(ValidationException::class);
    }
    expect(fn () => $this->sessions->import($this->actor, 'studio', (string) Str::uuid(), 'null', 'bad'))->toThrow(ValidationException::class);
    expect(fn () => $this->sessions->import($this->actor, 'studio', (string) Str::uuid(), '{', 'bad'))->toThrow(JsonException::class);
    expect(fn () => $this->sessions->import($this->actor, 'studio', (string) Str::uuid(), str_repeat('x', 17 * 1024 * 1024 + 1), 'bad'))->toThrow(ValidationException::class);
    expect(DB::table('music_projects')->count())->toBe(1);
});

it('validates command identity, title, expected revision and replay key', function () {
    foreach ([['', $this->projectId, 'Song', 0, 'key'], ['studio', 'bad', 'Song', 0, 'key'], ['studio', $this->projectId, '', 0, 'key'], ['studio', $this->projectId, 'Song', -1, 'key'], ['studio', $this->projectId, 'Song', 0, '']] as [$workspace, $id, $title, $version, $key]) {
        expect(fn () => new SaveSession($workspace, $id, $title, SessionDocument::empty(), $version, $key))->toThrow(ValidationException::class);
    }
});

it('denies missing media authorization and validates authoritative workspace metadata on reads and writes', function () {
    $asset = ['id' => 'asset-1', 'sha256' => str_repeat('a', 64), 'sample_rate' => 48000, 'channels' => 1, 'frames' => 96000];
    $input = SessionDocument::empty()->jsonSerialize();
    $input['assets'] = [$asset];
    $input['tracks'][] = ['id' => (string) Str::uuid(), 'name' => 'Recorded audio', 'kind' => 'audio', 'parent_id' => null, 'media_ids' => ['asset-1']];
    $document = SessionDocument::fromArray($input);
    $command = new SaveSession('studio', $this->projectId, 'With audio', $document, 0, 'audio');
    expect(fn () => $this->sessions->save($this->actor, $command))->toThrow(AuthorizationException::class);
    $access = Mockery::mock(MediaAccess::class);
    $access->shouldReceive('authorized')->with(1, 'asset-1', 'read')->andReturn(true);
    app()->instance(MediaAccess::class, $access);
    $catalogue = Mockery::mock(VerifiedMedia::class);
    $catalogue->shouldReceive('resolve')->with($this->actor, 'studio', 'asset-1')->andReturn($asset);
    app()->instance(VerifiedMedia::class, $catalogue);
    $retention = Mockery::mock(SessionMediaRetention::class);
    $retention->shouldReceive('retain')->andReturnNull();
    app()->instance(SessionMediaRetention::class, $retention);
    $sessions = app(ProjectSessions::class);
    $sessions->save($this->actor, $command);
    expect($sessions->get($this->actor, 'studio', $this->projectId)->document->assets())->toBe([$asset]);
    expect($sessions->history($this->actor, 'studio', $this->projectId)[0]->document->assets())->toBe([$asset]);
    $imported = $sessions->import($this->actor, 'studio', (string) Str::uuid(), $sessions->export($this->actor, 'studio', $this->projectId), 'portable-audio');
    expect($imported->document->jsonSerialize())->toBe($document->jsonSerialize());
    $denied = Mockery::mock(MediaAccess::class);
    $denied->shouldReceive('authorized')->andReturn(false);
    app()->instance(MediaAccess::class, $denied);
    expect(fn () => app(ProjectSessions::class)->get($this->actor, 'studio', $this->projectId))->toThrow(AuthorizationException::class);
    app()->instance(MediaAccess::class, $access);
    $badCatalogue = Mockery::mock(VerifiedMedia::class);
    $badCatalogue->shouldReceive('resolve')->andReturn(['id' => 'asset-1', 'sha256' => str_repeat('b', 64)]);
    app()->instance(VerifiedMedia::class, $badCatalogue);
    expect(fn () => app(ProjectSessions::class)->save($this->actor, $command))->toThrow(ValidationException::class, 'metadata');
    $missingCatalogue = Mockery::mock(VerifiedMedia::class);
    $missingCatalogue->shouldReceive('resolve')->andReturn([]);
    app()->instance(VerifiedMedia::class, $missingCatalogue);
    expect(fn () => app(ProjectSessions::class)->get($this->actor, 'studio', $this->projectId))->toThrow(ValidationException::class);
});

it('fails closed without a verified media owner and supplies replaceable authorization bindings', function () {
    expect(app(ProjectAccess::class))->toBeInstanceOf(GateProjectAccess::class)
        ->and(app(VerifiedMedia::class))->toBeInstanceOf(UnavailableMedia::class);
    expect(fn () => app(VerifiedMedia::class)->resolve($this->actor, 'studio', 'missing'))->toThrow(LogicException::class);
    $replacement = Mockery::mock(ProjectAccess::class);
    app()->instance(ProjectAccess::class, $replacement);
    (new MusicProjectsServiceProvider(app()))->register();
    expect(app(ProjectAccess::class))->toBe($replacement);
});

it('can preview and reverse migrations explicitly without disablement deleting sessions', function () {
    $this->sessions->save($this->actor, ($this->command)());
    $this->artisan('migrate', ['--pretend' => true])->assertSuccessful();
    $this->artisan('migrate:rollback', ['--path' => realpath(__DIR__.'/../../database/migrations'), '--realpath' => true])->assertSuccessful();
    expect(Schema::hasTable('music_projects'))->toBeFalse()->and(Schema::hasTable('music_project_revisions'))->toBeFalse();
});

it('loads a page of revision history in one database query as history grows', function () {
    for ($version = 0; $version < 30; $version++) {
        $this->sessions->save($this->actor, ($this->command)($version, 'revision-'.$version));
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $history = $this->sessions->history($this->actor, 'studio', $this->projectId, 25);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($history)->toHaveCount(25)->and($queries)->toHaveCount(1)
        ->and($history[0]->version)->toBe(30)->and($history[24]->version)->toBe(6);
});

it('browses only current sessions in a bounded workspace and template catalogue', function () {
    app(Gate::class)->define('music.projects.list', fn ($actor, $workspace, $project) => $actor->id === 1 && in_array($workspace, ['studio', 'empty']) && $project === '*');
    $this->sessions->save($this->actor, ($this->command)());
    $this->sessions->save($this->actor, ($this->command)(1, 'latest', true));
    $second = $this->sessions->save($this->actor, new SaveSession('studio', (string) Str::uuid(), 'Template', SessionDocument::empty(), 0, 'template', template: true));
    $third = $this->sessions->save($this->actor, new SaveSession('studio', (string) Str::uuid(), 'Third', SessionDocument::empty(), 0, 'third'));
    $page = $this->sessions->browse($this->actor, 'studio', 2);
    expect(array_column($page->items, 'title'))->toBe(['Third', 'Template'])->and($page->nextCursor)->not->toBeNull();
    $next = $this->sessions->browse($this->actor, 'studio', 2, $page->nextCursor);
    expect(array_column($next->items, 'version'))->toBe([2])->and($next->nextCursor)->toBeNull()
        ->and($this->sessions->browse($this->actor, 'empty')->items)->toBe([])
        ->and(array_column($this->sessions->browse($this->actor, 'studio', templates: true)->items, 'projectId'))->toBe([$second->projectId])
        ->and(array_column($this->sessions->browse($this->actor, 'studio', templates: false)->items, 'projectId'))->toBe([$third->projectId, $this->projectId]);
    expect(fn () => $this->sessions->browse(new GenericUser(['id' => 2]), 'studio'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->sessions->browse($this->actor, 'studio', 0))->toThrow(ValidationException::class);
    expect(fn () => $this->sessions->browse($this->actor, 'studio', before: 0))->toThrow(ValidationException::class);
    app(Gate::class)->define('music.projects.read', fn ($actor, $workspace, $project) => $project !== $second->projectId);
    expect(fn () => $this->sessions->browse($this->actor, 'studio'))->toThrow(AuthorizationException::class);
});

it('fails closed without retention and rolls retention back when a revision cannot commit', function () {
    $asset = ['id' => 'external-media', 'sha256' => str_repeat('a', 64), 'sample_rate' => 48000, 'channels' => 1, 'frames' => 48000];
    $input = SessionDocument::empty()->jsonSerialize();
    $input['assets'] = [$asset];
    $command = new SaveSession('studio', $this->projectId, 'Audio', SessionDocument::fromArray($input), 0, 'save');
    $access = Mockery::mock(MediaAccess::class);
    $access->shouldReceive('authorized')->andReturn(true);
    app()->instance(MediaAccess::class, $access);
    $catalog = Mockery::mock(VerifiedMedia::class);
    $catalog->shouldReceive('resolve')->andReturn($asset);
    app()->instance(VerifiedMedia::class, $catalog);
    expect(fn () => app(ProjectSessions::class)->save($this->actor, $command))->toThrow(LogicException::class, 'retention owner');
    expect(DB::table('music_projects')->count())->toBe(0);
    Schema::create('retention_spy', function (Blueprint $table) {
        $table->string('media_id');
    });
    $retention = Mockery::mock(SessionMediaRetention::class);
    $retention->shouldReceive('retain')->andReturnUsing(function ($actor, $workspace, $project, $version, $ids, $transaction) {
        expect($transaction)->toBe(DB::connection())->and($transaction->transactionLevel())->toBeGreaterThan(0)
            ->and($workspace)->toBe('studio')->and($version)->toBe(1)->and($ids)->toBe(['external-media']);
        $transaction->table('retention_spy')->insert(['media_id' => $ids[0]]);
    });
    app()->instance(SessionMediaRetention::class, $retention);
    DB::unprepared("CREATE TRIGGER refuse_revision BEFORE INSERT ON music_project_revisions BEGIN SELECT RAISE(ABORT, 'revision failed'); END");
    expect(fn () => app(ProjectSessions::class)->save($this->actor, $command))->toThrow(QueryException::class);
    expect(DB::table('retention_spy')->count())->toBe(0)->and(DB::table('music_projects')->count())->toBe(0);
    DB::unprepared('DROP TRIGGER refuse_revision');
    $sessions = app(ProjectSessions::class);
    $sessions->save($this->actor, $command);
    expect(DB::table('retention_spy')->count())->toBe(1);
    DB::table('retention_spy')->delete();
    $sessions->save($this->actor, $command);
    expect(DB::table('retention_spy')->count())->toBe(1)->and(DB::table('music_project_revisions')->count())->toBe(1);
});

it('previews a portable session without writing revisions retaining assets or emitting events', function () {
    $document = SessionDocument::empty();
    $manifest = json_encode(['format' => 'liberu.music.session', 'format_version' => 1, 'title' => 'Preview song', 'document' => $document, 'checksum' => $document->checksum()], JSON_THROW_ON_ERROR);
    $preview = $this->sessions->previewImport($this->actor, 'studio', $this->projectId, $manifest);
    expect($preview->jsonSerialize())->toBe([
        'schema_version' => 1, 'title' => 'Preview song', 'document_schema_version' => 1,
        'checksum' => $document->checksum(), 'track_count' => 0, 'asset_count' => 0, 'module_names' => [],
    ])->and($preview->document->jsonSerialize())->toBe($document->jsonSerialize())
        ->and(DB::table('music_projects')->count())->toBe(0)
        ->and(DB::table('music_project_revisions')->count())->toBe(0)
        ->and($this->savedEvents)->toBe([]);
    expect(fn () => $this->sessions->previewImport(new GenericUser(['id' => 2]), 'studio', $this->projectId, $manifest))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->sessions->previewImport($this->actor, 'studio', 'invalid-destination', $manifest))->toThrow(ValidationException::class);
    app(Gate::class)->define('music.projects.write', fn () => false);
    expect(fn () => $this->sessions->import($this->actor, 'studio', $this->projectId, $manifest, 'confirmed'))->toThrow(AuthorizationException::class);
    expect(DB::table('music_project_revisions')->count())->toBe(0);
});

it('revalidates referenced media for preview and confirmation without requiring retention during preview', function () {
    $asset = ['id' => 'external-media', 'sha256' => str_repeat('a', 64), 'sample_rate' => 48000, 'channels' => 1, 'frames' => 48000];
    $input = SessionDocument::empty()->jsonSerialize();
    $input['assets'] = [$asset];
    $document = SessionDocument::fromArray($input);
    $manifest = json_encode(['format' => 'liberu.music.session', 'format_version' => 1, 'title' => 'Audio preview', 'document' => $document, 'checksum' => $document->checksum()], JSON_THROW_ON_ERROR);
    $access = Mockery::mock(MediaAccess::class);
    $access->shouldReceive('authorized')->andReturn(true);
    app()->instance(MediaAccess::class, $access);
    $media = Mockery::mock(VerifiedMedia::class);
    $media->shouldReceive('resolve')->once()->with($this->actor, 'studio', 'external-media')->andReturn($asset);
    app()->instance(VerifiedMedia::class, $media);
    $retention = Mockery::mock(SessionMediaRetention::class);
    $retention->shouldNotReceive('retain');
    app()->instance(SessionMediaRetention::class, $retention);
    $sessions = app(ProjectSessions::class);
    expect($sessions->previewImport($this->actor, 'studio', $this->projectId, $manifest)->jsonSerialize()['asset_count'])->toBe(1)
        ->and(DB::table('music_project_revisions')->count())->toBe(0);
    $revoked = Mockery::mock(MediaAccess::class);
    $revoked->shouldReceive('authorized')->andReturn(false);
    app()->instance(MediaAccess::class, $revoked);
    expect(fn () => app(ProjectSessions::class)->import($this->actor, 'studio', $this->projectId, $manifest, 'confirm'))->toThrow(AuthorizationException::class);
});

it('browses lightweight session metadata and paginates history without loading documents', function () {
    app(Gate::class)->define('music.projects.list', fn ($actor, $workspace) => $actor->id === 1 && $workspace === 'studio');
    $this->sessions->save($this->actor, ($this->command)());
    $this->sessions->save($this->actor, ($this->command)(1, 'draft', true));
    $template = (string) Str::uuid();
    $this->sessions->save($this->actor, new SaveSession('studio', $template, 'Template', SessionDocument::empty(), 0, 'template', template: true));
    $metadata = app(SessionMetadata::class);
    $page = $metadata->browse($this->actor, 'studio', 1);
    expect($page->items[0]['project_id'])->toBe($template)->and($page->nextCursor)->not->toBeNull()
        ->and($page->items[0])->not->toHaveKey('document');
    $next = $metadata->browse($this->actor, 'studio', 1, $page->nextCursor);
    expect($next->items[0]['version'])->toBe(2)->and($next->items[0]['autosave'])->toBeTrue()->and($next->nextCursor)->toBeNull();
    expect($metadata->browse($this->actor, 'studio', templates: true)->items[0]['template'])->toBeTrue()
        ->and($metadata->browse($this->actor, 'studio', templates: false)->items)->toHaveCount(1);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $history = $metadata->history($this->actor, 'studio', $this->projectId, 1);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($history->items[0]['version'])->toBe(2)->and($history->nextCursor)->toBe(2)
        ->and($queries)->toHaveCount(2)->and(strtolower($queries[1]['query']))->not->toContain('document', 'select *', 'offset');
    $last = $metadata->history($this->actor, 'studio', $this->projectId, 1, $history->nextCursor);
    expect($last->items[0]['version'])->toBe(1)->and($last->nextCursor)->toBeNull()
        ->and($metadata->history($this->actor, 'studio', $this->projectId, 1, 1)->items)->toBe([]);
});

it('authorizes metadata per workspace and every selected project without leaking a denied page', function () {
    app(Gate::class)->define('music.projects.list', fn () => true);
    $this->sessions->save($this->actor, ($this->command)());
    $metadata = app(SessionMetadata::class);
    expect(fn () => $metadata->history($this->actor, 'empty', $this->projectId))->toThrow(RecordNotFoundException::class)
        ->and(fn () => $metadata->history(new GenericUser(['id' => 2]), 'studio', $this->projectId))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.projects.read', fn () => false);
    expect(fn () => $metadata->browse($this->actor, 'studio'))->toThrow(AuthorizationException::class);
    app(Gate::class)->define('music.projects.list', fn () => false);
    expect(fn () => $metadata->browse($this->actor, 'studio'))->toThrow(AuthorizationException::class);
});

it('rejects unbounded metadata pages and invalid history cursors', function () {
    app(Gate::class)->define('music.projects.list', fn () => true);
    $metadata = app(SessionMetadata::class);
    expect(fn () => $metadata->browse($this->actor, 'studio', 101))->toThrow(ValidationException::class)
        ->and(fn () => $metadata->history($this->actor, 'studio', $this->projectId, before: 0))->toThrow(ValidationException::class);
});

it('distinguishes conflicting command reuse from a stale revision through typed exceptions', function () {
    $this->sessions->save($this->actor, ($this->command)());
    expect(fn () => $this->sessions->save($this->actor, new SaveSession('studio', $this->projectId, 'Changed', SessionDocument::empty(), 0, 'create')))
        ->toThrow(IdempotencyConflict::class);
    try {
        $this->sessions->save($this->actor, ($this->command)(0, 'stale'));
        test()->fail('Expected a stale revision conflict.');
    } catch (RevisionConflict $exception) {
        expect($exception)->not->toBeInstanceOf(IdempotencyConflict::class);
    }
});

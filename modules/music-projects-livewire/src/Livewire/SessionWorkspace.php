<?php

declare(strict_types=1);

namespace Liberu\Music\ProjectsLivewire\Livewire;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\RecordNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use Liberu\Music\Projects\Commands\SaveSession;
use Liberu\Music\Projects\Contracts\ProjectAccess;
use Liberu\Music\Projects\Data\SessionDocument;
use Liberu\Music\Projects\Data\SessionRevision;
use Liberu\Music\Projects\Exceptions\IdempotencyConflict;
use Liberu\Music\Projects\Exceptions\ProjectTypeConflict;
use Liberu\Music\Projects\Exceptions\RevisionConflict;
use Liberu\Music\Projects\MusicProjectsServiceProvider;
use Liberu\Music\Projects\Services\ProjectSessions;
use Liberu\Music\Projects\Services\SessionMetadata;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

#[Layout('music-projects-livewire::layouts.workspace')]
final class SessionWorkspace extends Component
{
    #[Locked]
    public string $workspaceId;

    #[Locked]
    public string $projectId;

    #[Locked]
    public int $version = 0;

    #[Locked]
    public bool $template = false;

    #[Locked]
    public string $commandKey;

    public string $title = 'Untitled session';

    public array $document = [];

    #[Locked]
    public ?string $preservedAssetsJson = null;

    #[Locked]
    public ?string $preservedModulesJson = null;

    public string $trackName = '';

    public string $trackKind = 'audio';

    public ?string $parentTrackId = null;

    public string $importManifest = '';

    #[Locked]
    public ?string $reviewedManifest = null;

    #[Locked]
    public ?string $importDestination = null;

    #[Locked]
    public ?array $importPreview = null;

    #[Locked]
    public ?int $recoveryVersion = null;

    #[Locked]
    public string $notice = '';

    #[Locked]
    public ?string $problem = null;

    #[Locked]
    public ?int $before = null;

    #[Locked]
    public ?int $nextCursor = null;

    #[Locked]
    public ?int $historyBefore = null;

    #[Locked]
    public ?int $historyNext = null;

    public function mount(string $workspace, ?string $project = null): void
    {
        $this->actor();
        Validator::make(['workspace' => $workspace], ['workspace' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/D']])->validate();
        $this->workspaceId = $workspace;
        $this->resetDraft();
        if ($project !== null) {
            $this->open($project);
        }
    }

    public function newSession(bool $template = false): void
    {
        $this->attempt(function ($actor) use ($template): void {
            app(ProjectAccess::class)->authorize($actor, $this->workspaceId, '*', 'write');
            $this->resetDraft();
            $this->template = $template;
            $this->notice = $template ? 'New template draft.' : 'New session draft.';
        });
    }

    public function open(string $project): void
    {
        $this->attempt(function ($actor) use ($project): void {
            $this->accept(app(ProjectSessions::class)->get($actor, $this->workspaceId, $project));
            $this->notice = 'Saved session opened.';
        });
    }

    public function addTrack(): void
    {
        $this->attempt(function ($actor): void {
            app(ProjectAccess::class)->authorize($actor, $this->workspaceId, $this->projectId, 'write');
            $input = Validator::make(['name' => $this->trackName, 'kind' => $this->trackKind, 'parent' => $this->parentTrackId === '' ? null : $this->parentTrackId], [
                'name' => ['required', 'string', 'max:255'], 'kind' => ['required', 'in:audio,midi,folder'], 'parent' => ['nullable', 'uuid'],
            ])->validate();
            $document = $this->document;
            $document['tracks'][] = ['id' => (string) Str::uuid(), 'name' => $input['name'], 'kind' => $input['kind'], 'parent_id' => $input['parent'], 'media_ids' => []];
            $this->document = SessionDocument::fromArray($document)->jsonSerialize();
            $this->trackName = '';
            $this->notice = 'Track added to this draft. Save a checkpoint to keep it.';
        });
    }

    public function moveTrack(string $trackId, ?string $parentId = null): void
    {
        $this->attempt(function ($actor) use ($trackId, $parentId): void {
            app(ProjectAccess::class)->authorize($actor, $this->workspaceId, $this->projectId, 'write');
            $document = SessionDocument::fromArray($this->document)->jsonSerialize();
            $index = array_search($trackId, array_column($document['tracks'], 'id'), true);
            if ($index === false) {
                throw ValidationException::withMessages(['tracks' => 'The selected track is no longer in this draft.']);
            }
            $document['tracks'][$index]['parent_id'] = $parentId === '' ? null : $parentId;
            $this->document = SessionDocument::fromArray($document)->jsonSerialize();
            $this->notice = 'Track move staged in this draft. Save a checkpoint to keep it.';
        });
    }

    public function removeTrack(string $trackId): void
    {
        $this->attempt(function ($actor) use ($trackId): void {
            app(ProjectAccess::class)->authorize($actor, $this->workspaceId, $this->projectId, 'write');
            $document = SessionDocument::fromArray($this->document)->jsonSerialize();
            $index = array_search($trackId, array_column($document['tracks'], 'id'), true);
            if ($index === false) {
                throw ValidationException::withMessages(['tracks' => 'The selected track is no longer in this draft.']);
            }
            array_splice($document['tracks'], $index, 1);
            $this->document = SessionDocument::fromArray($document)->jsonSerialize();
            $this->notice = 'Track removed from this draft. Audio files and media references are retained. Move children before removing a folder.';
        });
    }

    public function save(bool $autosave = false): void
    {
        $this->attempt(function ($actor) use ($autosave): void {
            $revision = app(ProjectSessions::class)->save($actor, new SaveSession($this->workspaceId, $this->projectId, $this->title,
                $this->documentForSave(), $this->version, $this->commandKey.($autosave ? ':autosave' : ':checkpoint'), $autosave, $this->template));
            $this->accept($revision);
            $this->notice = $autosave ? 'Autosave confirmed by the server.' : 'Checkpoint confirmed by the server.';
        });
    }

    public function reviewRecovery(int $version): void
    {
        $this->recoveryVersion = null;
        $this->attempt(function ($actor) use ($version): void {
            $source = app(ProjectSessions::class)->get($actor, $this->workspaceId, $this->projectId, $version);
            $this->recoveryVersion = $source->version;
            $this->notice = 'Recovery will append a new checkpoint from revision '.$source->version.'. Your current draft will be replaced only after confirmation.';
        });
    }

    public function confirmRecovery(): void
    {
        $this->attempt(function ($actor): void {
            if ($this->recoveryVersion === null) {
                throw ValidationException::withMessages(['recovery' => 'Choose a revision to review first.']);
            }
            $this->accept(app(ProjectSessions::class)->recover($actor, $this->workspaceId, $this->projectId, $this->recoveryVersion, $this->version, $this->commandKey.':recover:'.$this->recoveryVersion));
            $this->notice = 'Recovery checkpoint confirmed by the server.';
        });
    }

    public function previewImport(): void
    {
        $this->cancelReview();
        $this->attempt(function ($actor): void {
            $destination = (string) Str::uuid();
            $preview = app(ProjectSessions::class)->previewImport($actor, $this->workspaceId, $destination, $this->importManifest);
            $this->importPreview = $preview->jsonSerialize();
            $this->reviewedManifest = $this->importManifest;
            $this->importDestination = $destination;
            $this->notice = 'Import reviewed. Confirm to create a separate session; the source session is unchanged.';
        });
    }

    public function confirmImport(): void
    {
        $this->attempt(function ($actor): void {
            if ($this->reviewedManifest === null || $this->importDestination === null || $this->reviewedManifest !== $this->importManifest) {
                throw ValidationException::withMessages(['importManifest' => 'Review the current manifest before confirming its import.']);
            }
            $this->accept(app(ProjectSessions::class)->import($actor, $this->workspaceId, $this->importDestination, $this->reviewedManifest, $this->commandKey.':import'));
            $this->cancelReview();
            $this->notice = 'Portable session imported and opened.';
        });
    }

    public function cancelReview(): void
    {
        $this->reviewedManifest = null;
        $this->importDestination = null;
        $this->importPreview = null;
        $this->recoveryVersion = null;
    }

    public function exportSaved(): ?StreamedResponse
    {
        return $this->attempt(function ($actor) {
            $manifest = app(ProjectSessions::class)->export($actor, $this->workspaceId, $this->projectId, $this->version);

            return response()->streamDownload(function () use ($manifest): void {
                echo $manifest;
            }, 'session-'.$this->projectId.'.music.json', ['Content-Type' => 'application/json']);
        });
    }

    public function nextPage(): void
    {
        $this->before = $this->nextCursor;
    }

    public function firstPage(): void
    {
        $this->before = null;
    }

    public function olderHistory(): void
    {
        $this->historyBefore = $this->historyNext;
    }

    public function firstHistory(): void
    {
        $this->historyBefore = null;
    }

    public function restoreDraft(string $json): void
    {
        $this->attempt(function ($actor) use ($json): void {
            if (strlen($json) > 64 * 1024 * 1024) {
                throw ValidationException::withMessages(['draft' => 'Draft backups may not exceed 64 MiB.']);
            }
            $draft = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            $input = Validator::make(['draft' => $draft], ['draft' => ['required', 'array:format,schema_version,workspace_id,project_id,version,template,title,document,command_key,assets_json,modules_json'],
                'draft.format' => ['required', 'in:liberu.music.draft'], 'draft.schema_version' => ['required', 'integer:strict', 'in:1,2'],
                'draft.workspace_id' => ['required', 'in:'.$this->workspaceId], 'draft.project_id' => ['required', 'uuid'],
                'draft.version' => ['required', 'integer:strict', 'min:0'], 'draft.template' => ['required', 'boolean:strict'],
                'draft.assets_json' => ['required_if:draft.schema_version,2', 'string', 'max:16777216'],
                'draft.modules_json' => ['required_if:draft.schema_version,2', 'string', 'max:16777216'],
                'draft.title' => ['required', 'string', 'max:255'], 'draft.document' => ['required', 'array'], 'draft.command_key' => ['required', 'uuid'],
            ])->validate()['draft'];
            app(ProjectAccess::class)->authorize($actor, $this->workspaceId, $input['project_id'], 'write');
            $source = null;
            if ($input['version'] > 0) {
                $source = app(ProjectSessions::class)->get($actor, $this->workspaceId, $input['project_id'], $input['schema_version'] === 1 ? $input['version'] : null);
            }
            $candidate = $input['document'];
            if ($input['schema_version'] === 2) {
                $candidate['assets'] = json_decode($input['assets_json'], true, flags: JSON_THROW_ON_ERROR);
                $candidate['modules'] = json_decode($input['modules_json'], true, flags: JSON_THROW_ON_ERROR);
            } elseif ($source !== null) {
                // Legacy browser backups serialized unknown numeric state through JS.
                // Recover these read-only blocks from their immutable saved base.
                $candidate['assets'] = $source->document->assets();
                $candidate['modules'] = $source->document->jsonSerialize()['modules'];
            }
            $document = SessionDocument::fromArray($candidate);
            $this->projectId = $input['project_id'];
            $this->version = $input['version'];
            $this->template = $input['template'];
            $this->title = $input['title'];
            $this->document = $document->jsonSerialize();
            $this->preserveBlocks($document);
            $this->commandKey = $input['command_key'];
            $this->resetEditingContext();
            $this->notice = 'Draft restored without saving or rebasing. Save explicitly; newer server revisions will require reconciliation.';
        });
    }

    public function render(): View
    {
        $items = $history = [];
        $this->attempt(function ($actor) use (&$items, &$history): void {
            $page = app(SessionMetadata::class)->browse($actor, $this->workspaceId, 10, $this->before);
            $items = $page->items;
            $this->nextCursor = $page->nextCursor;
            if ($this->version > 0) {
                $page = app(SessionMetadata::class)->history($actor, $this->workspaceId, $this->projectId, 10, $this->historyBefore);
                $history = $page->items;
                $this->historyNext = $page->nextCursor;
            }
        }, false);

        return view('music-projects-livewire::livewire.session-workspace', compact('items', 'history'));
    }

    private function actor(): Authenticatable
    {
        $actor = auth()->guard()->user();
        abort_unless($actor instanceof Authenticatable, 401);
        abort_if(app()->getProvider(MusicProjectsServiceProvider::class) === null, 503);

        return $actor;
    }

    private function resetDraft(): void
    {
        $this->projectId = (string) Str::uuid();
        $this->commandKey = (string) Str::uuid();
        $this->title = 'Untitled session';
        $this->version = 0;
        $this->template = false;
        $this->document = SessionDocument::empty()->jsonSerialize();
        $this->preservedAssetsJson = $this->preservedModulesJson = null;
        $this->resetEditingContext();
    }

    private function accept(SessionRevision $revision): void
    {
        $this->projectId = $revision->projectId;
        $this->title = $revision->title;
        $this->document = $revision->document->jsonSerialize();
        $this->preserveBlocks($revision->document);
        $this->version = $revision->version;
        $this->template = $revision->template;
        $this->commandKey = (string) Str::uuid();
        $this->resetEditingContext();
    }

    private function preserveBlocks(SessionDocument $document): void
    {
        $this->preservedAssetsJson = json_encode($document->assets(), JSON_THROW_ON_ERROR);
        $this->preservedModulesJson = json_encode($document->jsonSerialize()['modules'], JSON_THROW_ON_ERROR);
    }

    private function documentForSave(): SessionDocument
    {
        $document = $this->document;
        if ($this->preservedAssetsJson !== null) {
            $document['assets'] = json_decode($this->preservedAssetsJson, true, flags: JSON_THROW_ON_ERROR);
        }
        if ($this->preservedModulesJson !== null) {
            $document['modules'] = json_decode($this->preservedModulesJson, true, flags: JSON_THROW_ON_ERROR);
        }

        return SessionDocument::fromArray($document);
    }

    private function resetEditingContext(): void
    {
        $this->trackName = '';
        $this->parentTrackId = null;
        $this->trackKind = 'audio';
        $this->historyBefore = $this->historyNext = null;
        $this->cancelReview();
    }

    private function attempt(Closure $action, bool $clear = true): mixed
    {
        $actor = $this->actor();
        if ($clear) {
            $this->problem = null;
            $this->notice = '';
            $this->resetValidation();
        }
        try {
            return $action($actor);
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->errors());
            $this->problem = 'Review the highlighted values. Your draft is preserved.';
        } catch (RevisionConflict|IdempotencyConflict|ProjectTypeConflict) {
            $this->problem = 'The saved session or command has changed. Your draft is preserved. Back it up before opening the current revision to reconcile.';
        } catch (AuthorizationException) {
            $this->problem = 'Access denied. No changes were saved.';
        } catch (RecordNotFoundException) {
            $this->problem = 'The session or referenced asset was not found. Your draft is preserved.';
        } catch (JsonException) {
            $this->problem = 'The backup is not valid JSON. Your draft is preserved.';
        } catch (Throwable $exception) {
            report($exception);
            $this->problem = 'The operation could not finish. Your draft is preserved. Restore missing media or dependencies, then retry.';
        }

        return null;
    }
}

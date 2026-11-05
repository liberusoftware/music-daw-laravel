<div x-data="{ backupError: '', draftText: '', draftName: '' }">
    <header class="row" style="justify-content:space-between;margin-bottom:1.5rem">
        <div><p class="muted">MUSIC · SESSION WORKSPACE</p><h1>Make room for your next idea.</h1><p class="muted">Workspace {{ $workspaceId }} · Session metadata and recovery</p></div>
        <div class="row"><button wire:click="newSession" wire:confirm="Replace the current draft? Download a draft backup first if you need to keep it.">New session</button><button wire:click="newSession(true)" wire:confirm="Replace the current draft with a new template?">New template</button></div>
    </header>
    <p class="status muted">Audio execution is unavailable here. Recording, live monitoring, playback and DSP need a capable audio host. Existing media references and unfamiliar module state are preserved.</p>
    <p wire:offline class="status problem" role="status">Connection lost. Keep this page open. You can download your draft backup below while offline, then restore it after reconnecting.</p>
    <p wire:loading role="status" aria-live="polite">Working… wait for server confirmation.</p>
    @if($problem)<div class="status problem" role="alert">{{ $problem }}</div>@endif
    @if($notice)<p class="status" role="status" aria-live="polite">{{ $notice }}</p>@endif
    @if($errors->any())<ul role="alert" class="status problem">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>@endif
    <div class="grid">
        <aside class="card" aria-label="Session navigator">
            <h2>Your sessions</h2>
            @forelse($items as $item)
                <p wire:key="session-{{ $item['project_id'] }}"><button wire:click="open('{{ $item['project_id'] }}')" wire:confirm="Open this saved session and replace the current draft?">{{ $item['title'] }}</button><br><small class="muted">Revision {{ $item['version'] }} · {{ $item['template'] ? 'Template' : 'Session' }}{{ $item['autosave'] ? ' · Autosave' : '' }}</small></p>
            @empty<p class="muted">No sessions on this page, or access is unavailable. Create a session when your workspace grants write access.</p>@endforelse
            <div class="row"><button wire:click="firstPage" @disabled($before === null)>First page</button><button wire:click="nextPage" @disabled($nextCursor === null)>More sessions</button></div>
        </aside>
        <section aria-label="Session draft">
            <div class="card">
                <div class="row" style="justify-content:space-between"><h2>{{ $template ? 'Template' : 'Session' }} draft</h2><span class="muted">Based on revision {{ $version }} · {{ number_format(is_numeric($document['sample_rate'] ?? null) ? $document['sample_rate'] : 0) }} Hz</span></div>
                <form wire:submit="save">
                    <div class="field"><label for="session-title">Session name</label><input id="session-title" wire:model="title" maxlength="255" required autocomplete="off"></div>
                    <div class="scroll"><table><caption style="text-align:left">Tracks and folders · changes remain in your draft until saved</caption><thead><tr><th scope="col">Name</th><th scope="col">Kind</th><th scope="col">Parent folder</th><th scope="col">Media references</th><th scope="col">Actions</th></tr></thead><tbody>
                    @forelse(is_array($document['tracks'] ?? null) ? $document['tracks'] : [] as $index => $track)
                        @if(is_array($track) && is_string($track['id'] ?? null) && \Illuminate\Support\Str::isUuid($track['id']) && is_string($track['name'] ?? null))
                        <tr wire:key="track-{{ $track['id'] }}"><td><label class="muted" for="track-{{ $index }}">Track {{ $loop->iteration }}</label><input id="track-{{ $index }}" wire:model="document.tracks.{{ $index }}.name" maxlength="255" required></td><td>{{ is_string($track['kind'] ?? null) ? $track['kind'] : 'Unknown' }}</td><td><label class="muted" for="folder-{{ $index }}">Parent folder</label><select id="folder-{{ $index }}" wire:change="moveTrack(@js($track['id']), $event.target.value)"><option value="">Top level</option>@foreach($document['tracks'] as $folder)@if(is_array($folder) && ($folder['kind'] ?? null) === 'folder' && is_string($folder['id'] ?? null) && \Illuminate\Support\Str::isUuid($folder['id']) && is_string($folder['name'] ?? null) && $folder['id'] !== $track['id'])<option value="{{ $folder['id'] }}" @selected(($track['parent_id'] ?? null) === $folder['id'])>{{ $folder['name'] }}</option>@endif @endforeach</select></td><td>{{ is_array($track['media_ids'] ?? null) ? count($track['media_ids']) : 0 }}</td><td><button type="button" wire:click="removeTrack(@js($track['id']))" aria-label="Remove {{ $track['name'] }}" wire:confirm="Remove this track from the draft? Audio files will be retained. Move children before removing a folder.">Remove</button></td></tr>
                        @endif
                    @empty<tr><td colspan="5" class="muted">No tracks yet. Add an audio track, MIDI track or folder below.</td></tr>@endforelse
                    </tbody></table></div>
                    <p class="muted">{{ is_array($document['assets'] ?? null) ? count($document['assets']) : 0 }} immutable media references · {{ is_array($document['modules'] ?? null) ? count($document['modules']) : 0 }} preserved module states</p>
                    <div class="row"><button class="primary" type="submit" wire:loading.attr="disabled">Save checkpoint</button><button type="button" wire:click="save(true)" wire:loading.attr="disabled">Save autosave</button><button type="button" wire:click="exportSaved" wire:loading.attr="disabled" @disabled($version === 0)>Export saved revision</button></div>
                </form>
            </div>
            <form class="card" wire:submit="addTrack">
                <h2>Add a track or folder</h2>
                <div class="field"><label for="track-name">Name</label><input id="track-name" wire:model="trackName" maxlength="255" required></div>
                <div class="row"><div><label for="track-kind">Kind</label><select id="track-kind" wire:model="trackKind"><option value="audio">Audio track</option><option value="midi">MIDI track</option><option value="folder">Folder</option></select></div><div><label for="parent-track">Parent folder</label><select id="parent-track" wire:model="parentTrackId"><option value="">Top level</option>@foreach(is_array($document['tracks'] ?? null) ? $document['tracks'] : [] as $track)@if(is_array($track) && ($track['kind'] ?? null) === 'folder' && is_string($track['id'] ?? null) && \Illuminate\Support\Str::isUuid($track['id']) && is_string($track['name'] ?? null))<option value="{{ $track['id'] }}">{{ $track['name'] }}</option>@endif @endforeach</select></div><button type="submit" wire:loading.attr="disabled">Add to draft</button></div>
            </form>
            <section class="card" aria-label="Revision recovery">
                <h2>Revision history</h2><p class="muted">Ten revisions per page. Recovery appends a checkpoint; it does not rewrite history.</p>
                @forelse($history as $revision)<p wire:key="revision-{{ $projectId }}-{{ $revision['version'] }}"><button wire:click="reviewRecovery({{ $revision['version'] }})">Review revision {{ $revision['version'] }}</button> {{ $revision['title'] }} · {{ $revision['autosave'] ? 'Autosave' : 'Checkpoint' }}</p>@empty<p class="muted">Save a checkpoint to start revision history.</p>@endforelse
                <div class="row"><button wire:click="firstHistory" @disabled($historyBefore === null)>Newest revisions</button><button wire:click="olderHistory" @disabled($historyNext === null)>Older revisions</button></div>
                @if($recoveryVersion !== null)<div class="row"><span>Recover revision {{ $recoveryVersion }}?</span><button wire:click="confirmRecovery" wire:confirm="Replace your draft with this historical revision and append a new checkpoint?" wire:loading.attr="disabled">Confirm recovery</button><button wire:click="cancelReview">Cancel</button></div>@endif
            </section>
            <details class="card"><summary>Open a portable session</summary><p class="muted">Review the manifest first. Its media must already be verified and available in this workspace. Import creates a separate session.</p><label for="manifest">Portable session manifest</label><textarea id="manifest" rows="5" wire:model="importManifest" spellcheck="false"></textarea><button wire:click="previewImport" wire:loading.attr="disabled">Review import</button>
                @if($importPreview)<p>{{ $importPreview['title'] }} · {{ $importPreview['track_count'] }} tracks · {{ $importPreview['asset_count'] }} assets</p><div class="row"><button wire:click="confirmImport" wire:confirm="Create and open this imported session? Back up your current draft first." wire:loading.attr="disabled">Confirm import</button><button wire:click="cancelReview">Cancel</button></div>@endif
            </details>
            <details class="card"><summary>Draft backup and offline recovery</summary><p class="muted">A draft backup includes unsaved metadata, not audio files. Keep it private. Restoring keeps its original base revision so newer server edits cause a conflict instead of being overwritten.</p>
                <button type="button" x-on:click="try { const draft = { format: 'liberu.music.draft', schema_version: 1, workspace_id: $wire.workspaceId, project_id: $wire.projectId, version: $wire.version, template: $wire.template, title: $wire.title, document: $wire.document, command_key: $wire.commandKey }; const url = URL.createObjectURL(new Blob([JSON.stringify(draft)], { type: 'application/json' })); const link = document.createElement('a'); link.href = url; link.download = 'draft-' + $wire.projectId + '.music-draft.json'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 0); backupError = ''; } catch { backupError = 'Draft backup could not be downloaded. Keep this page open and retry.'; }">Download current draft backup</button>
                <div class="field"><label for="draft-file">Restore a draft backup after reconnecting</label><input id="draft-file" type="file" accept=".json,application/json" x-on:change="const file = $event.target.files[0]; draftText = ''; draftName = ''; if (file) { if (file.size > 17825792) { backupError = 'Draft backups may not exceed 17 MiB.'; } else { file.text().then(text => { draftText = text; draftName = file.name; backupError = ''; }).catch(() => backupError = 'The draft file could not be read.'); } }"></div><p class="muted" x-text="draftName"></p><button type="button" x-bind:disabled="!draftText" x-on:click="$wire.restoreDraft(draftText)" wire:loading.attr="disabled">Restore draft for review</button><p role="alert" x-text="backupError" x-cloak></p>
            </details>
        </section>
    </div>
</div>

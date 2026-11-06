# Music Projects Filament

Tenant-scoped Filament 5 administration for Music sessions. Under development against [Music issue #8](https://github.com/liberusoftware/project-music-daw-laravel-filament/issues/8) and the [Projects Filament plan](https://github.com/liberusoftware/documentation/blob/main/projects/music/filament/projects.md).

## Installation and enablement

Requires 64-bit PHP 8.5, Laravel 13, Filament 5, Projects Core and Projects Livewire. Install using Composer, then enable all three Music modules through the host module registry. Installation alone enables no routes or panel plugins. Register `MusicProjectsFilamentPlugin::make()` on a standalone Filament panel and boot all three declared providers when using a host without the Liberu registry.

The plugin supplies a `Music > Sessions` page to the declared app/admin panels. A workspace must be selected on the server. The default `WorkspaceContext` resolves the current Filament tenant ID; a non-tenant panel denies access until its host binds this contract. The Music host resolves the foundation tenant context or the actor's current team. A selected workspace is a context, never proof of membership: Projects Core policies check list/read/write/media access independently on each operation.

## Supported workflow

The page composes the public Projects Livewire workspace: create sessions/templates, add/move/remove track metadata, checkpoint, explicit autosave, review historical revisions, confirm recovery, export a saved revision, review/confirm a portable manifest and download/restore an offline draft. Recovery appends a revision through Core, preserving immutable history and media references. Core provides `SessionAudit` inside the save transaction. The Music host binds it to the foundation audit recorder: new checkpoints, autosaves, recoveries and imports are audited atomically; recovery records the source version, retries after rollback are safe and command replays add no duplicate audit. Generic hosts must bind and verify their own sink; saved events alone do not provide that guarantee. The adapter does not introduce a second database, a domain model, bulk deletion or a real-time DSP editor.

The locked embedding scope is checked on every child action and metadata render. Switching the current workspace or revoking list access denies further operations in an old component and preserves the client draft. Returning to an authorized workspace allows explicit retry. Identity/workspace/scope properties cannot be changed by browser hydration. The parent page rechecks context on hydration too.

The shared workspace includes loading, denied, conflict, invalid import, missing dependency, offline and recovery states. Opaque asset/module JSON is preserved through browser draft recovery, including native signed 64-bit integers. Audio execution is explicitly unavailable; recordings, device control and live monitoring require a supported local runtime. See the [surface support matrix](https://github.com/liberusoftware/documentation/blob/main/projects/music/PRESENTATION.md).

## Host integration and privacy

Keep all workspace membership and media gates authoritative. Bind `Contracts\WorkspaceContext` to a server-owned resolver; never derive it from an editable field or arbitrary query parameter. The panel's authentication guard must match the embedded Livewire guard. A different actor on the panel guard denies access. The plugin applies private/no-store response headers to the enabled panel. Configure HTTPS, request-size and memory budgets for combined snapshots, and session/logout policy. Offline draft files contain private project state; store them only in a location the user controls. Draft restore requires explicit save and never silently rebases a stale version.

## Verification and release limits

Run `composer install`, `composer test`, `composer test:coverage` and `vendor/bin/phpstan analyse src --level=5 --no-progress`. The package uses the shared package testbench and includes its own source in the 100% line-coverage gate. Host CI must also include this source and exercise the enabled panel with actual authentication, policies and media retention.

Full release remains subject to host-wide 100% coverage, browser and keyboard/screen-reader acceptance, maximum-workload body/memory tests, production workspace/media policy and audit retention/export operations. Browser emulation is not physical mobile-device certification. No complete DAW or native/audio support is claimed by installing this management adapter.

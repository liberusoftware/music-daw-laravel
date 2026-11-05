# Music Projects Livewire

Server-rendered Music session workspace built on the Projects public application boundary. Package identity: `liberusoftware/music-projects-livewire`; module identity: `music-projects-livewire`.

This module is under development. It supplies metadata editing and recovery, not an audio runtime. Recording, monitoring, playback, DSP, device/plugin discovery and remote transport are unavailable. Unfamiliar module state and immutable media references are preserved when editing supported metadata. Full Music delivery and physical-device acceptance are not claimed.

## Install and enable

Require this package through Composer, install its `music-projects` dependency and enable both modules in the host module manager. The Livewire adapter is disabled by default; installing it does not expose routes. Enable it using the host's `MODULES_ENABLED=music-projects-livewire` override. Core Projects must remain enabled. Normal Composer autoloading and module discovery register its provider.

The host supplies session authentication and the `music.projects.list`, `read` and `write` workspace policies through the Projects `ProjectAccess` contract. An authenticated session alone grants no workspace permission. Configure those policies against the canonical identity/team authority; this package does not create a second membership system. Supply the core's verified-media and retention owners before opening/saving audio-bearing sessions. No media or project tables are accessed by this adapter.

The authenticated web route is `/music/workspaces/{workspace}/sessions/{project?}`, named `music.projects.livewire.workspace`. The host owns login/logout routes. The standalone layout includes Livewire assets and responsive styles without requiring host assets. Hosts may override namespaced views in `resources/views/vendor/music-projects-livewire` using Laravel's normal view override facilities. Components are registered as `music-session-workspace` and can also be embedded in an authenticated host page.

## Workflow

- Create a session or template draft. Add and rename audio/MIDI metadata tracks and folders with keyboard-accessible forms. Move tracks between folders or remove them from a draft; core graph validation requires moving children before removing a folder. Removing a track never deletes audio or removes the immutable asset manifest. A track has no audio until referenced verified media is supplied through the owning modules. No recording or sample scheduling is performed by the server.
- Save a checkpoint or explicit autosave. The server confirms the committed revision; locked base versions and command keys preserve core optimistic concurrency and replay behavior. There is no timer or background audio-control loop.
- Open sessions from bounded ten-entry pages. Review the revision summaries in bounded ten-entry pages and explicitly confirm recovery, which appends a checkpoint. Export a saved revision as a portable manifest; unsaved edits are deliberately separate.
- Paste a portable session manifest, review the title/track/asset summary, then confirm creation of a separate session. Changed manifests require a new review. Core authorization and asset verification run again on confirmation.
- Download the current draft from the browser, including while the loaded page is offline. It contains metadata, the original base version and replay identity, not audio bytes. After reconnecting, select that file and restore it for review. Restoration never silently rebases or saves. If a newer server revision exists, save conflicts and preserves the draft; back it up and explicitly open the current revision before reconciling.

Draft backup is an explicit download, not automatic device storage. Refreshing or closing an unsaved page without a backup can lose local edits. Keep downloaded drafts private and remove them when no longer needed. Object URLs are revoked after download; there are no global listeners, timers or waveform/meter subscriptions to clean up on navigation. No private assets are placed in a shared service-worker cache.

## Limits and errors

Use the core's declared schema, graph, media and manifest limits. Draft backups are versioned (`liberu.music.draft`, schema 1), workspace scoped and limited to 17 MiB. Large JSON payloads can expand during HTTP encoding; configure the host web/PHP request-body budget accordingly (64 MiB accommodates the supported metadata payloads). Production jobs, scanner/distribution policies and audio runtime integration remain owners' responsibilities.

Validation errors, permission denial, missing sessions, conflicts, invalid JSON and dependency failures preserve the current draft and show safe messages. Unexpected failures are reported through the host exception reporter without exposing private paths to the browser. Explicit save/retry, cancel-review, backup and recovery controls show authoritative results. No error handling bypasses core policies or creates a revision on failure.

## Verification

Run `composer install`, `vendor/bin/phpstan analyse src --level=5 --no-progress` and `composer test:coverage -- --coverage-clover=coverage/clover.xml`. Tests use the shared package testbench and include ownership, locked-property tampering, conflict preservation, reviewed imports, export, draft restore and missing dependencies. The declared source coverage threshold is 100%.

The Install and Tests workflows reproduce normal Composer installation and package checks on PHP 8.5. A host integration test and source coverage are required when composing the package. Browser download/file-picker, keyboard/screen-reader, offline/reconnect, responsive rendering and production policy integration still need target-browser operational verification before full issue closure. No physical mobile/native runtime release is claimed.

Scope and remaining acceptance: [Projects Livewire issue](https://github.com/liberusoftware/project-music-daw-laravel-filament/issues/9), [module plan](https://github.com/liberusoftware/documentation/blob/main/projects/music/livewire/projects.md), [presentation support](https://github.com/liberusoftware/documentation/blob/main/projects/music/PRESENTATION.md).

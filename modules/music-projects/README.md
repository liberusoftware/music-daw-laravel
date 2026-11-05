# Music Projects

Versioned session metadata for Laravel 13 and PHP 8.5. This package owns project documents and checkpoint persistence. Audio execution belongs to a compatible local runtime.

Implementation scope and acceptance tracking: [host issue #6](https://github.com/liberusoftware/music-daw-laravel/issues/6).

This package is under development and has not been released. Install development dependencies with Composer, then run `composer test` or `composer test:coverage` from this directory. Its tests use the shared Liberu package testbench and do not require a host application.

Media-bearing session writes require the public `SessionMediaRetention` contract to retain references in the same active database connection and transaction as the revision. The host composes this with Media Library `MediaRetention`. The `music.media.retain` permission and per-asset read permission are required, including on replay. Older revision pins remain after autosave and recovery.

Retention is additive and protects shared content within a workspace. Garbage collection, grace periods and pin release are still pending; no asset deletion is enabled by this feature.


`ProjectSessions::previewImport($actor, $workspaceId, $projectId, $manifest)` validates a portable session before confirmation. It returns an immutable `SessionImportPreview` with title, document schema, checksum, track/asset counts and module names. The preview validates the destination identity, write permission, manifest checksum and every referenced asset through the public media owner. It writes no revisions or retention records and emits no saved event. Unsupported document/format schemas fail validation; this does not migrate older schemas or prove local engine/plugin compatibility. Import revalidates the submitted manifest and permissions when saving; a prior preview grants no continuing access.

# Music Projects

Versioned session metadata for Laravel 13 and PHP 8.5. This package owns project documents and checkpoint persistence. Audio execution belongs to a compatible local runtime.

Implementation scope and acceptance tracking: [host issue #6](https://github.com/liberusoftware/music-daw-laravel/issues/6).

This package is under development and has not been released. Install development dependencies with Composer, then run `composer test` or `composer test:coverage` from this directory. Its tests use the shared Liberu package testbench and do not require a host application.

Media-bearing session writes require the public `SessionMediaRetention` contract to retain references in the same active database connection and transaction as the revision. The host composes this with Media Library `MediaRetention`. The `music.media.retain` permission and per-asset read permission are required, including on replay. Older revision pins remain after autosave and recovery.

Retention is additive and protects shared content within a workspace. Garbage collection, grace periods and pin release are still pending; no asset deletion is enabled by this feature.


`ProjectSessions::previewImport($actor, $workspaceId, $projectId, $manifest)` validates a portable session before confirmation. It returns an immutable `SessionImportPreview` with title, document schema, checksum, track/asset counts and module names. The preview validates the destination identity, write permission, manifest checksum and every referenced asset through the public media owner. It writes no revisions or retention records and emits no saved event. Unsupported document/format schemas fail validation; this does not migrate older schemas or prove local engine/plugin compatibility. Import revalidates the submitted manifest and permissions when saving; a prior preview grants no continuing access.

`SessionChanges::since($actor, $workspaceId, $projectId, $after, $limit)` returns durable revision notifications in ascending project-version order. Cursors belong to the supplied workspace/project, start at zero and advance only through contiguous committed revisions. Pages contain at most 100 notifications with checksum, autosave/template flags and creation time; session documents, asset references and actor identities are omitted. The query fixes an upper version before reading and uses two bounded indexed queries. A missing sequence or cursor ahead of the authoritative project version returns `resync_required: true` without partial changes; consumers must fetch the authorized current session and restart from its version. Every poll rechecks project read access; obtaining the full revision independently rechecks media access. This is a polling query, not an HTTP/SSE transport, audio clock or remote transport control.

`SessionMetadata` provides lightweight, cursor-paginated catalogue/history queries for presentations. It returns titles, versions, document checksums, creation times and autosave/template flags without loading session documents, actor identities or media references. Each selected project is authorized; asset-bearing snapshots still go through `ProjectSessions` and its media policy. Page sizes are bounded at 100 and history reads use two indexed queries regardless of page size.

Revision and retention-backfill timestamps are written in UTC independently of the host display timezone.

## Transactional revision auditing

Hosts may bind `Contracts\SessionAudit` (globally or contextually for `ProjectSessions`) to record each newly created checkpoint, autosave, recovery or imported revision. The hook receives the authenticated actor, immutable resulting revision, operation, optional recovery source version and the active `ConnectionInterface`. It executes after revision/retention writes and before commit/events. A sink must use that same transaction or throw; sink failure rolls back the revision, any new project reservation and retention. No saved event is dispatched for that failed attempt.

Command replay returns the original receipt without another audit record. Preview/read/export do not create revision audit records. Existing command fingerprints are unchanged. Audit provenance describes the operation which originally created a revision; replay does not rewrite its history. A retry after a rolled-back failure can safely reuse the command key. Standalone Core remains independent of a particular audit backend; an unconfigured optional sink is not an audited deployment. The Music host explicitly requires its foundation audit recorder and records minimal checksums/counts/version provenance without document contents or titles. Other hosts must supply and verify their own atomic audit integration.

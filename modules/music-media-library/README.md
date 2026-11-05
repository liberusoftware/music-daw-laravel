# Music Media Library

Audio asset verification for Laravel 13 and PHP 8.5. Under development; implementation and remaining scope are tracked in [issue #62](https://github.com/liberusoftware/music-daw-laravel/issues/62).

Run `composer install`, then `composer test` or `composer test:coverage` in this directory. Tests use the shared Liberu package testbench.

Media-bearing session writes require the public `SessionMediaRetention` contract to retain references in the same active database connection and transaction as the revision. The host composes this with Media Library `MediaRetention`. The `music.media.retain` permission and per-asset read permission are required, including on replay. Older revision pins remain after autosave and recovery.

Retention protects shared content within a workspace. `OfflinePins::save` accepts a `SaveOfflinePin` command containing a caller-generated UUID, expected version, command key and asset UUIDs. Replacing the asset set with an empty list releases that device pin. Replays return the original immutable result without reapplying an older asset set or lease. `OfflinePins::get` returns the current version and assets. Pin ownership is actor and workspace scoped, even when the workspace policy allows multiple users.

Offline pin operations require `music.media.pin`, per-asset read permission and, for mutations, `music.media.retain`. An optional UTC expiry uses `YYYY-MM-DDTHH:MM:SSZ`; new leases must expire in the future. Omit it for permanent protection. Expired leases stop protecting bytes at their declared boundary; renewal requires the current version and a new command key. Expiry and release do not delete files, stop local recording or release session/pack/other-device references. `OfflinePinSaved` is dispatched after the encompassing transaction commits.

`QuarantineCleanup::collect` accepts `CollectCancelledUploads` for a workspace, with a default dry run, a UTC grace period of at least 24 hours, a limit of 1–100 chunks, and an optional numeric chunk cursor. It requires `music.media.collect` for the workspace and every selected upload. Reports contain schema-v1 chunk identities, a cutoff, declared bytes and a continuation cursor; they never expose private object paths. Declared bytes include already-missing chunks acknowledged during recovery and are not a measurement of physical disk space freed.

Pass `dryRun: false` to remove eligible cancelled-upload chunks. Storage failures preserve the ledger for retry, and retries acknowledge objects removed before a crash or database rollback. Cancellation is terminal and repeating it preserves its original grace period. Upload identities, provenance and status remain for audit. `QuarantineCollected` is dispatched after commit. Legacy cancellations receive a fresh grace period when upgrading. Only cancelled quarantine is collected; uploading and ready chunks, published audio, saved sessions and active pins are preserved.

Published-asset garbage collection and its shared-content retention checks remain pending. Projects provides historical revision retention backfill; operators must complete it before any future published-asset deletion is enabled.

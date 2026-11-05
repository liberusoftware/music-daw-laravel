# Music Media Library

Audio asset verification for Laravel 13 and PHP 8.5. Under development; implementation and remaining scope are tracked in [issue #62](https://github.com/liberusoftware/music-daw-laravel/issues/62).

Run `composer install`, then `composer test` or `composer test:coverage` in this directory. Tests use the shared Liberu package testbench.

Media-bearing session writes require the public `SessionMediaRetention` contract to retain references in the same active database connection and transaction as the revision. The host composes this with Media Library `MediaRetention`. The `music.media.retain` permission and per-asset read permission are required, including on replay. Older revision pins remain after autosave and recovery.

Retention protects shared content within a workspace. `OfflinePins::save` accepts a `SaveOfflinePin` command containing a caller-generated UUID, expected version, command key and asset UUIDs. Replacing the asset set with an empty list releases that device pin. Replays return the original immutable result without reapplying an older asset set or lease. `OfflinePins::get` returns the current version and assets. Pin ownership is actor and workspace scoped, even when the workspace policy allows multiple users.

Offline pin operations require `music.media.pin`, per-asset read permission and, for mutations, `music.media.retain`. An optional UTC expiry uses `YYYY-MM-DDTHH:MM:SSZ`; new leases must expire in the future. Omit it for permanent protection. Expired leases stop protecting bytes at their declared boundary; renewal requires the current version and a new command key. Expiry and release do not delete files, stop local recording or release session/pack/other-device references. `OfflinePinSaved` is dispatched after the encompassing transaction commits.

Garbage collection, grace periods, historical revision backfill and crash-safe physical deletion remain pending. No asset deletion is enabled by this feature.

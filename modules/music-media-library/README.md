# Music Media Library

Audio asset verification for Laravel 13 and PHP 8.5. Under development; implementation and remaining scope are tracked in [issue #62](https://github.com/liberusoftware/music-daw-laravel/issues/62).

Run `composer install`, then `composer test` or `composer test:coverage` in this directory. Tests use the shared Liberu package testbench.

Media-bearing session writes require the public `SessionMediaRetention` contract to retain references in the same active database connection and transaction as the revision. The host composes this with Media Library `MediaRetention`. The `music.media.retain` permission and per-asset read permission are required, including on replay. Older revision pins remain after autosave and recovery.

Retention is additive and protects shared content within a workspace. Garbage collection, grace periods, pin release and backfilling historical revisions are still pending; no asset deletion is enabled by this feature.

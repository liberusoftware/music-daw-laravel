# Music Projects

Versioned session metadata for Laravel 13 and PHP 8.5. This package owns project documents and checkpoint persistence. Audio execution belongs to a compatible local runtime.

Implementation scope and acceptance tracking: [host issue #6](https://github.com/liberusoftware/music-daw-laravel/issues/6).

This package is under development and has not been released. Install development dependencies with Composer, then run `composer test` or `composer test:coverage` from this directory. Its tests use the shared Liberu package testbench and do not require a host application.

Media-bearing session writes require the public `SessionMediaRetention` contract to retain references in the same active database connection and transaction as the revision. The host composes this with Media Library `MediaRetention`. The `music.media.retain` permission and per-asset read permission are required, including on replay. Older revision pins remain after autosave and recovery.

Retention is additive and protects shared content within a workspace. Garbage collection, grace periods, pin release and backfilling historical revisions are still pending; no asset deletion is enabled by this feature.

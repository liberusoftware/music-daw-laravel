# Changelog

## Unreleased

- Add durable owner-scoped queued upload verification and original-identity restoration, commit-bound events, retry recovery and serialized cancellation/publication.
- Distinguish metadata command reuse from stale versions without breaking parent exception catches; reject version overflow.

- Add owner-scoped offline asset sets with optimistic versions, replay-safe renewal/release, explicit UTC leases and commit-bound events.

- Retain session media references in the revision transaction; fail closed when retention cannot participate.

- Add bounded streaming WAVE metadata and checksum verification.
- Add private resumable uploads, verified publication, scoped duplicate detection and retry-safe recovery.
- Add verified per-channel waveform previews with bounded output and explicit sample-frame positions.
- Add scoped foundation media authorization through the configured principal provider.
- Add retry-safe versioned media names/tags and authorized foundation catalog search.
- Restore missing/corrupt media from verified replacement uploads while preserving references; verify staging before publication.

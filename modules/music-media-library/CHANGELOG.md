# Changelog

## Unreleased

- Add purpose-bound resumable archive intake using the existing quarantine and reservation ledger, with owned scanner/checksum-verified snapshots.
- Reject trailing-newline checksum declarations before accepting upload bytes.
- Preserve legacy audio fingerprints and limits, reject archive-to-audio publication, and guard rollback of active archive purpose metadata.

- Document the optional archive export HTTP consumer and distinguish remaining queued import scope.

- Add durable canonical-requester pack ZIP export tasks, private checksum-verified results, expiry and bounded operator cleanup.
- Serialize export publication/cancellation and retain task-owned private staging for safe retry after interrupted acknowledgement.

- Add durable owned queued trash recovery with live worker policies, safe failure/retry outcomes and serialized cancellation/publication.

- Add a typed offline pin version conflict while preserving UploadConflict parent compatibility.

- Distinguish conflicting pack idempotency keys from stale revisions through the existing typed metadata command conflict.

- Add private integrity-verified download snapshots with live domain and foundation transfer approval.

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

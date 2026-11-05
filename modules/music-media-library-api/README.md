# Music Media Library API

Optional Laravel HTTP adapter for `liberusoftware/music-media-library`. The module is disabled by default. It invokes the core's public commands, query DTOs and policies; it owns no audio, identity or domain persistence.

This is an **incomplete module increment**, not the full Media Library API release. The OpenAPI 3.1 contract in [openapi/media.json](openapi/media.json) covers verified metadata, keyset browsing, conditional metadata edits, authenticated resumable upload intake and queued verification/restoration/waveform tasks. Short-lived scoped download URLs, packs/provenance commands, offline pins and lifecycle HTTP operations remain to be implemented. Capability discovery advertises these limitations. The complete core/API/Filament/Livewire DAW scope remains open.

## Installation and host configuration

Install this package and its matching core, then explicitly enable both through the host module manager. Run the core migrations. The package does not enable itself through Laravel auto-discovery. The host provides Sanctum authentication, canonical workspace/asset gates, private core storage, a trusted cache, a production malware scanner and a queue worker. Use an asynchronous Laravel queue connection for waveform work; a sync connection executes inline. Keep the queue `retry_after` above the core job timeout (60 seconds). Failed jobs/retries remain the host's normal Laravel/Horizon responsibility.

All routes start at `/api/v1/music-media/workspaces/{workspace}`. Workspaces are URL-safe identifiers of 1–128 letters, digits, underscores or hyphens. Sanctum abilities never replace live core policy checks:

| Operation | Method and path | Token ability |
| --- | --- | --- |
| Discover supported operations | GET `/capabilities` | `music.media.read` |
| Browse verified catalog | GET `/assets` | `music.media.read` |
| Read verified metadata and availability | GET `/assets/{asset}` | `music.media.read` |
| Read logical upload reservation budget | GET `/upload-budget` | `music.media.quota.read` |
| Request/replay waveform job | PUT `/waveform-tasks/{task}` | `music.media.read` + `music.media.process` |
| Read owned job status/progress | GET `/waveform-tasks/{task}` | `music.media.read` |
| Read cached peaks | GET `/waveform-tasks/{task}/result` | `music.media.read` |
| Cancel queued/in-flight owned job | DELETE `/waveform-tasks/{task}` | `music.media.read` + `music.media.process` |

The core enforces workspace/per-asset permissions and task ownership. Status/cancellation metadata remains available with live read permission if source audio disappears; no cached preview bypasses ready status, authorization or source-presence checks.

## Quick start

For waveform work, use an existing verified asset UUID from the catalog, a newly generated stable task UUID and a properly scoped host token:

```sh
curl -H "Authorization: Bearer $TOKEN" \
  'https://your-music-host/api/v1/music-media/workspaces/studio/assets?page[size]=25'

curl -X PUT -H "Authorization: Bearer $TOKEN" \
  -H "Idempotency-Key: $TASK_UUID" -H 'Content-Type: application/json' \
  --data '{"asset_id":"11111111-1111-4111-8111-111111111111","sample_frames_per_peak":1024}' \
  "https://your-music-host/api/v1/music-media/workspaces/studio/waveform-tasks/$TASK_UUID"

curl -H "Authorization: Bearer $TOKEN" \
  "https://your-music-host/api/v1/music-media/workspaces/studio/waveform-tasks/$TASK_UUID/result"
```

The task path UUID is the immutable request identity. PUT and DELETE require an `Idempotency-Key` header equal to that UUID. Replaying PUT with changed asset/window arguments returns 409. Cancel replay is safe; replaying a cancelled task cannot reopen it. Cancellation prevents result publication and late completion but does not kill an executing CPU operation or remove a shared derived cache. Ready or failed tasks reject cancellation. A new task UUID starts a distinct request.

Task responses contain `Location` plus status/result/cancel links and schema-v1 terminal/cancellable flags. Progress uses explicit completed/total `waveform_build` steps, not fabricated sample-frame progress. PUT returns 202 while queued and 200 for a terminal replay. A queued result query returns 202 with `Retry-After: 2`; terminal cancelled/failed results return 409. A ready result evicted from cache returns 410: replay the original PUT to regenerate it. Queries never synchronously index audio. Missing/corrupt audio fails closed.

## Pagination, errors and privacy

Catalog filters are `filter[query]`, `filter[tags][]` and `filter[sha256]`; `page[size]` is 1–100, default 25. Follow `meta.next_cursor` as `page[after]`. Cursors expire after 15 minutes and bind the actor, workspace, normalized filters and page size. They are encrypted, not authorization grants. Pages use descending asset UUID keysets, not recording-time order or offset pagination. Concurrent inserts ahead of the cursor appear on a fresh traversal. Every page rechecks policies.

Responses use `data`, `meta.request_id`, `meta.next_cursor` and `links`. Requests get server-generated `X-Request-ID` and `Cache-Control: no-store`. Errors use `application/problem+json` with structured validation pointers, status, request ID and a generic detail; raw exception text/private paths are never serialized. Normal outcomes include 401/403/404/409/410/422/428/429/503. JSON mutation bodies are bounded to 8 KiB and routes to 60 requests per minute per authenticated throttle identity. Binary chunks are accepted only by the upload-chunk route as documented below.

No server action invokes native audio, MIDI, transport or device scheduling. No source object keys, scanner paths or private retention records are exposed. Core scanner/rights-policy requirements are unchanged.

## Verification

Run `composer install`, `composer test:coverage`, `vendor/bin/phpstan analyse src --level=5 --no-progress`, and `npx --yes @redocly/cli@2.15.0 lint openapi/media.json`. Source coverage must remain 100%. Tests cover live policies, scopes, ownership, cancellation/replay, task results/cache eviction, cursor tampering/expiry, dependency failure, validation, request bounds and correspondence between routes and the reviewed OpenAPI operations. Host installation and integration are verified separately; standalone test success does not complete the full module issue.


## Conditional metadata edits

GET `/assets/{asset}` returns the current name/tags/version and a strong quoted `ETag`, including version `"0"` before the first annotation. PUT `/assets/{asset}/metadata` requires read/edit token abilities, live core annotate/read permission, the original quoted version in `If-Match`, a printable ASCII `Idempotency-Key` of 1–128 characters, and JSON `{ "name": "Kick", "tags": ["drums"] }`. Treat ETags as opaque strings and copy the header rather than converting a large version through a JavaScript number. Weak tags, wildcards, lists, leading zeroes and overflow are rejected.

The command replaces name/tags together. Core normalizes and sorts tags, preserves audio/provenance, and returns a versioned receipt plus ETag. A stale new command returns 412; reusing a key for changed arguments returns 409 with a metadata-command-conflict problem type. Replay the original key, body and precondition to recover its original receipt, even after later edits. Fetch the asset to obtain current metadata; replay never overwrites later changes. Missing preconditions return 428. Metadata edits require available verified audio under the current core contract.

## Resumable intake and queued publication/recovery

| Operation | Method and path | Token ability in addition to live core policies |
| --- | --- | --- |
| Declare an upload | PUT `/uploads/{upload}` | `music.media.upload` |
| Read byte progress | GET `/uploads/{upload}` | `music.media.upload` |
| Append/replay a chunk | PUT `/uploads/{upload}/chunks/{offset}` | `music.media.upload` |
| Cancel unpublished intake | DELETE `/uploads/{upload}` | `music.media.upload` |
| Queue verified publication | PUT `/verification-tasks/{task}` | `music.media.read` + `music.media.upload` |
| Queue matching-byte restoration | PUT `/assets/{asset}/restoration-tasks/{task}` | `music.media.read` + `music.media.restore` |
| Read/cancel an asset task | GET/DELETE `/asset-tasks/{task}` | read + the original upload/restore operation ability |
| Read a verified result reference | GET `/asset-tasks/{task}/result` | read + the original upload/restore operation ability |

Declarations accept a name, whole-file byte count (44 bytes–1 GiB), lowercase SHA-256 and optional bounded source/license/attribution strings. The path UUID and equal Idempotency-Key identify the declaration; changed declaration data conflicts. A new declaration atomically reserves the core workspace budget. Budget exhaustion returns 429 with an upload-budget-exceeded problem type, distinct from request throttling. Replays and already accepted intake are not debited again.

Send 1 byte–4 MiB chunks as `application/octet-stream` at an explicit decimal byte offset. `X-Chunk-SHA256` is the lowercase SHA-256 of those exact raw bytes. Idempotency-Key must be `uploadUUID:offset:chunkSHA256`. The route never extracts an archive, accepts a server path or trusts a filename/MIME type as audio verification. Replaying the same bytes/offset is safe; changed bytes and gaps conflict. Wrong checksums, types and sizes are rejected before persistence. Query byte progress to resume. For example, with a prepared bounded chunk:

```sh
curl -X PUT -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/octet-stream' -H "X-Chunk-SHA256: $CHUNK_SHA256" \
  -H "Idempotency-Key: $UPLOAD_UUID:$OFFSET:$CHUNK_SHA256" --data-binary @chunk.bin \
  "https://your-music-host/api/v1/music-media/workspaces/studio/uploads/$UPLOAD_UUID/chunks/$OFFSET"
```

After all declared bytes arrive, PUT `/verification-tasks/{task}` with `{ "upload_id": "..." }` and Idempotency-Key equal to the task UUID. The server returns an owned task reference; the host worker executes required malware scanning, WAVE/checksum verification and publication through the authoritative core action. Incomplete, cancelled or other-workspace intake cannot become available to a session. Retries use the same immutable task identity. Duplicate successful delivery does not publish a second asset.

To resolve a missing/corrupt source, read its verified checksum/size from asset metadata, declare/receive a separate matching replacement upload, then PUT `/assets/{original}/restoration-tasks/{task}` with `{ "replacement_upload_id": "..." }`. The worker checks live source-upload and target restore/read policies and invokes core restoration. It preserves the original asset identity, annotations, provenance and retained revision references. Different bytes cannot replace an original identity. A successful result returns a currently authorized verified reference with explicit sample-frame units; the asset link supplies current annotated metadata.

Asset tasks serialize explicit completed/total `asset_verification` or `asset_restoration` steps, terminal/cancellable flags and a generic failure code without private exception text. While queued, result queries return 202/Retry-After. Failures remain quarantined; replay after correcting the underlying problem retries the same command. Ready/cancelled task identities are terminal. Task cancellation does not discard the source upload; intake cancellation is a separate operation and follows core grace-period cleanup/reservation rules. Task cancellation and publication are serialized: cancellation wins before the publication transaction, or waits and receives 409 if publication/completion wins. It never reports successful cancellation after committing publication, and does not interrupt an executing CPU operation. Once ready, querying a missing/retired result fails closed rather than granting access from historical status.

Run the core asset-task migration before enabling this increment. Job messages contain only the task UUID; workers resolve the canonical configured user and recheck permissions. Configure an asynchronous connection, queue retries, failed-job storage and workers; the adapter does not start Horizon. The core timeout remains 60 seconds and retry_after must be greater. Task-schema rollback preserves recordings; deployment/worker coordination remains the host's responsibility.

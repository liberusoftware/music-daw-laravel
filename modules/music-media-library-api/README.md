# Music Media Library API

Optional Laravel HTTP adapter for `liberusoftware/music-media-library`. The module is disabled by default. It invokes the core's public commands, query DTOs and policies; it owns no audio, identity or domain persistence.

This is an **incomplete module increment**, not the full Media Library API release. The OpenAPI 3.1 contract in [openapi/media.json](openapi/media.json) covers verified metadata, keyset browsing, conditional metadata edits, authenticated resumable upload intake, queued verification/restoration/waveform tasks, short-lived scoped downloads, versioned rights/provenance pack manifests and queued private ZIP imports/exports. Production operational integrations and broader acceptance remain unfinished. Capability discovery advertises these limitations. The complete core/API/Filament/Livewire DAW scope remains open.

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

Responses use `data`, `meta.request_id`, `meta.next_cursor` and `links`. Requests get server-generated `X-Request-ID` and `Cache-Control: no-store`. Errors use `application/problem+json` with structured validation pointers, status, request ID and a generic detail; raw exception text/private paths are never serialized. Normal outcomes include 401/403/404/409/410/422/428/429/503. JSON mutation bodies are bounded to 8 KiB and routes to 60 requests per minute per authenticated throttle identity. Binary chunks are accepted only by the audio/archive chunk routes as documented below.

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

### Private downloads

`GET assets/{asset}/download-url` returns a signed URL valid for 60 seconds, its UTC expiry, verified SHA-256 and byte count. Both issuance and redemption require `music.media.read` and `music.media.download` token abilities, current `music.media.read`/`music.media.download` domain gates, and the foundation `TransferAuthorizer` approving `music-media-asset` / `export`. Missing foundation integration fails closed. The URL is bound to the actor, workspace, asset and immutable checksum. It is not a public sharing link: consumers send their bearer token on redemption; revoking it immediately prevents access.

The download response is private `audio/wav`, uses an asset UUID attachment filename, includes the quoted checksum ETag and Content-Length, disables caching and MIME sniffing, and streams an integrity-verified temporary disk snapshot. Corrupt or missing media fails before successful response headers. Copying a snapshot uses bounded memory but requires temporary disk space and time proportional to the asset size. Range requests return 416; this contract provides full downloads and resumable uploads. No object keys, local paths or storage credentials are returned. Authorization is evaluated when the request opens the snapshot; an already streaming response cannot be recalled.

### Versioned packs and rights manifests

All pack routes require `music.media.read`, current Core policies, and the operation ability below. Rights records describe provenance; they do not by themselves grant distribution permission.

| Method and workspace-relative route | Additional token ability | Contract |
| --- | --- | --- |
| GET `packs/{pack}` | `music.media.packs.read` | Latest pack, or `?version=N`; quoted revision ETag. |
| PUT `packs/{pack}` | `music.media.packs.write` | `name`, 1–1000 distinct `asset_ids`, and `rights` containing source/license/attribution. Requires a printable Idempotency-Key and one strong quoted If-Match version; zero creates. |
| GET `packs/{pack}/history` | `music.media.packs.read` | Descending revision summaries; `page[limit]` 1–100 (default 25), exclusive `page[before]` version. `meta.next_cursor` contains the next version and `links.next` supplies its scoped URL. A full last page can yield one empty page. |
| GET `packs/{pack}/export` | `music.media.packs.export` | Checksummed portable manifest; optional historical `version`. Requires foundation transfer approval, current pack distribution policy, per-asset distribution permission and complete provenance. |
| POST `packs/{pack}/import-preview` | `music.media.packs.import` | Body contains manifest object and exact source UUID → destination UUID `asset_map`. Validates source schema/checksum and map shape without writing a pack. Destination availability and reference/provenance matching are checked at commit. |
| PUT `packs/{pack}/import` | `music.media.packs.import` | Same reviewed body; requires Idempotency-Key and If-Match `"0"`. Creates a new destination pack with verified mapped assets and retained references. |

Core rechecks workspace/asset authorization and retains every saved revision atomically. Same-command replay returns its original revision; reusing the key for different content returns 409, and stale versions return 412. Reads/exports fail closed for revoked asset access, missing audio or provenance drift. Import/export require the existing foundation `TransferAuthorizer` for `music-media-pack` / `import` or `export`; missing integration denies the operation. Pack read/write/import/export gates remain separate from token abilities. Preview does not certify licensing or authorize a later commit.

Save bodies are limited to 64 KiB; preview/import JSON bodies to 8 MiB, with Core's bounded portable document checks also applied. A manifest contains verified references and rights metadata, never audio, storage keys or credentials. These synchronous bounded metadata routes do not implement queued ZIP/audio archive transfer. Use the asset upload and private download contracts for audio bytes.

### Actor-owned offline pins

`GET offline-pins/{pin}` requires `music.media.read` and `music.media.pins.read`. `PUT offline-pins/{pin}` requires `music.media.read` and `music.media.pins.write`, an Idempotency-Key and one strong quoted If-Match version (zero creates). Both enforce the live Core pin policy and asset read policies; saves and replays also require retention authorization. Pin identity is owned by the authenticated actor inside its workspace.

Send `asset_ids` as a distinct list of at most 10,000 verified UUIDs and optional nullable `expires_at` in exact UTC `YYYY-MM-DDTHH:mm:ssZ` format. The JSON body limit is 512 KiB. Omit expiry or send null for no lease expiry; a new non-null lease must be in the future. Replace the list to update a pin, use a new key/current revision to renew, or send an empty list to release this pin's references. Other pins, packs and session revisions stay protected independently.

Responses include the Core pin receipt and quoted version ETag. Conflicting key reuse returns 409; a stale version returns 412. Replays return the original receipt without renewing an expired lease or reverting later changes; GET returns current pin state. Expired leases remain readable metadata but no longer protect audio through that pin. Downloading, verifying and durably saving bytes on a device is a separate client responsibility; server pin success is not proof of local availability or a completed offline synchronization workflow.

### Trash and queued recovery

`GET trash` requires `music.media.read` and `music.media.trash.read`, the live `trash_list` policy and per-item read/trash approval. It returns trash/purging metadata with an encrypted, actor/workspace/page-size-bound `page[after]` cursor (15 minute lifetime). `page[size]` is 1–100, default 25. Metadata is not a playback or recovery guarantee.

`PUT trash-commands/{command}` requires `music.media.read`, `music.media.trash.write`, matching UUID Idempotency-Key and live per-asset read/trash policies. Supply 1–100 distinct `asset_ids` and optional integer `grace_hours` (24–8760, default 24). Core checks current lifecycle state and retention under locks; a protected revision, pack or active pin rejects the whole batch with 409. This immutable-identity transition does not edit metadata revisions and uses Core state/retention checks rather than If-Match. Successful trash hides assets from ready queries without physically deleting audio. Replays return the original receipt; use current queries to reconcile subsequent recovery.

`PUT assets/{asset}/trash-recovery-tasks/{task}` requires `music.media.read`, `music.media.restore` and matching UUID Idempotency-Key. Core queues an actor-owned recovery task after commit. GET `trash-recovery-tasks/{task}` reads status, GET its `/result` returns 202/Retry-After while queued or a currently available verified asset reference when ready, and DELETE cancels a queued task using the same key. All routes recheck live read/restore policies; workers resolve the canonical requester again. Failed/cancelled results return 409; failures expose a safe code. Same-command failed retries may requeue; cancelled and ready identities never reopen.

Recovery and cancellation serialize on the task record. Recovery validates original private audio and commits its publication and task acknowledgement in one transaction; a failed acknowledgement rolls back both. Cancellation does not interrupt CPU already holding the lock: it waits, then conflicts if publication won. A queued task does not extend trash grace or prevent operator GC; recovery fails safely if bytes were purged, missing or corrupt. Queue workers must use retry_after greater than the 60 second job timeout. Physical garbage collection stays in the trusted Core operator workflow; these routes do not expose purge, storage discovery or arbitrary filesystem operations.

### Queued private archive exports

All archive export routes require `music.media.read`, `music.media.packs.export` and `music.media.archives.export`, current Core pack/asset distribution policies and foundation transfer approval for `music-media-pack` / `export`. Tasks belong to the authenticated actor inside the workspace. A manifest-export token alone does not authorize archive bytes.

| Method and workspace-relative route | Contract |
| --- | --- |
| PUT `packs/{pack}/archive-export-tasks/{task}` | Supply an explicit integer `version` and matching task UUID Idempotency-Key. Returns 202 while queued; replay returns its original task. No If-Match is needed because this reads an immutable pack revision. |
| GET `archive-export-tasks/{task}` | Current owned task status, bounded progress, safe error code and result expiry. |
| DELETE `archive-export-tasks/{task}` | Matching task UUID Idempotency-Key cancels queued work. Cancellation and publication serialize; terminal tasks cannot be cancelled. |
| GET `archive-export-tasks/{task}/result` | 202 with Retry-After while queued, verified transfer receipt when ready, 409 for failed/cancelled and 410 for expired artifacts. |
| GET `archive-export-tasks/{task}/download-url` | Signed actor/checksum-bound URL valid for at most 60 seconds, capped at artifact expiry. A valid URL still requires a current authorized bearer token. |
| GET `archive-export-tasks/{task}/download` | Full private ZIP attachment with checksum ETag, Content-Length, no-store and nosniff headers. Range requests return 416. |

Workers use the canonical requester and recheck rights before generating a bounded archive. Result/status reads and downloads recheck current policies too. Core verifies the full ZIP size/checksum into a private disk snapshot before successful binary headers; missing/corrupt artifacts fail closed. Temporary disk/time scale with archive size while PHP memory remains bounded. Issuing a URL authorizes retrieval but cannot guarantee storage availability at redemption. Tokens can be revoked immediately; an already streaming response cannot be recalled.

Artifacts expire one hour after generation and cannot be reopened with the same task identity. Failed same-command retries can requeue; cancelled/ready/expired identities remain terminal. A queue-dispatch outage after SQL commit leaves a queued task which the same command can safely redispatch. Operator cleanup remains the Core public action described in its README; no HTTP cleanup/purge endpoint is exposed. Existing synchronous manifest import does not accept ZIP/audio bytes, and ZIP import uses the separate intake/import task routes below.


### Private archive intake and queued imports

Archive intake requires both `music.media.upload` and `music.media.archives.import`, plus the live Core upload policy. Every intake status/chunk/cancel operation is owned by the authenticated actor and fixed to archive purpose. Audio upload routes are fixed to audio purpose and owner; a workspace-wide upload grant cannot read or modify another actor's intake. Wrong purpose/owner/workspace returns 404. Declaration UUID collisions return 409 without revealing private content.

| Method and workspace-relative route | Contract |
| --- | --- |
| PUT `archive-inputs/{upload}` | Same declaration shape as audio (`name`, integer `bytes`, SHA-256, optional provenance), with Idempotency-Key equal to upload UUID. Reserves private Core capacity. |
| GET `archive-inputs/{upload}` | Owned byte progress, with no private storage paths. |
| PUT `archive-inputs/{upload}/chunks/{offset}` | Raw octet-stream bytes, lowercase `X-Chunk-SHA256`, and Idempotency-Key `upload UUID:offset:sha256`. Append the next explicit byte offset or replay identical bytes. |
| DELETE `archive-inputs/{upload}` | Matching UUID Idempotency-Key cancels intake; charges remain until Core quarantine cleanup actually removes retained chunks. |

Each chunk is 1 byte–4 MiB, with at most 8192 chunks. Archive declarations accept 44 bytes through configured `MUSIC_MEDIA_ARCHIVE_MAX_AUDIO_BYTES + 16 MiB` (default 1 GiB + 16 MiB; hard ceiling 16 GiB + 16 MiB). Plan chunk sizes to fit that bound. JSON declarations are limited to 8 KiB. Archive ZIP inputs never become verified playable audio, even if an audio verification job is requested. Complete intake still requires worker scanner approval, whole-file checksum verification and bounded ZIP/manifest/rights validation.

Import task routes require all four abilities: `music.media.read`, `music.media.upload`, `music.media.packs.import`, and `music.media.archives.import`. Core also checks current import/upload/retention/pack policies and foundation `music-media-pack` / `import` authorization. Token abilities do not grant rights in a pack's contained recordings.

| Method and workspace-relative route | Contract |
| --- | --- |
| PUT `packs/{pack}/archive-import-tasks/{task}` | Matching task UUID Idempotency-Key and JSON `input_id` plus exact source UUID → distinct destination UUID `asset_map` (1–1000 assets). New pack UUID, stable task/input/mapping identities. Body limit 128 KiB. |
| GET `archive-import-tasks/{task}` | Owned task status, processed-asset progress, safe error code and terminal/cancellable/retryable flags. |
| GET `archive-import-tasks/{task}/result` | 202 with Retry-After: 2 while queued/running; verified imported pack and quoted revision ETag when ready; 409 for failed/cancelled. Current read policies and audio availability are rechecked. |
| DELETE `archive-import-tasks/{task}` | Matching task UUID Idempotency-Key. Queued work cancels immediately; running work returns 202 with a cooperative cancellation flag. |

For example, submit an already uploaded archive using a new destination pack and task UUID:

```json
{
  "input_id": "11111111-1111-4111-8111-111111111111",
  "asset_map": {
    "22222222-2222-4222-8222-222222222222": "33333333-3333-4333-8333-333333333333"
  }
}
```

Replay the original PUT to redispatch queued work, retry failure or reclaim an expired lease. Changed identities conflict. One input has one queued/running consumer at a time; failed/cancelled consumers allow a new task, while ready/cancelled task UUIDs never reopen. PUT returns 202 for queued/running and 200 for terminal replays. Status responses include Location and status/result/cancel links. Cancellation checks between assets and 1 MiB chunks; scanner/copy preparation is not interruptible. Already verified destination recordings survive interruption and remain available for a stable retry; progress counts restart each attempt. No incomplete pack is published. Pack publication, retention, source-input cancellation and ready acknowledgement commit atomically.

Use an asynchronous worker connection for HTTP imports. The Core job timeout defaults to 60 seconds and may be configured from 60–3600; queue retry_after must exceed that timeout plus 15 seconds. A sync queue runs import inline and is unsuitable for large HTTP imports. Run Core migrations before workers and drain them before rollback. Source chunks cancelled on successful import remain charged until acknowledged quarantine cleanup. Production scanner, rights policies, queue supervision, quota capacity and operator scheduling remain host responsibilities. No public purge/cleanup endpoint is exposed.

# Music Media Library API

Optional Laravel HTTP adapter for `liberusoftware/music-media-library`. The module is disabled by default. It invokes the core's public commands, query DTOs and policies; it owns no audio, identity or domain persistence.

This is an **incomplete module increment**, not the full Media Library API release. The initial OpenAPI 3.1 contract in [openapi/media.json](openapi/media.json) covers verified metadata, keyset browsing, upload reservation views and cancellable waveform tasks. Upload/verification transfer, short-lived scoped audio URLs, metadata edits, restoration, packs/provenance commands, offline pins and lifecycle HTTP operations remain to be implemented. Capability discovery advertises these limitations. The complete core/API/Filament/Livewire DAW scope remains open.

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

Use an existing verified asset UUID from the catalog, a newly generated stable task UUID and a properly scoped host token:

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

Responses use `data`, `meta.request_id`, `meta.next_cursor` and `links`. Requests get server-generated `X-Request-ID` and `Cache-Control: no-store`. Errors use `application/problem+json` with structured validation pointers, status, request ID and a generic detail; raw exception text/private paths are never serialized. Normal outcomes include 401/403/404/409/410/422/428/429/503. JSON mutation bodies are bounded to 8 KiB and routes to 60 requests per minute per authenticated throttle identity. Binary transfer is not accepted by these routes.

No server action invokes native audio, MIDI, transport or device scheduling. No source object keys, scanner paths or private retention records are exposed. Core scanner/rights-policy requirements are unchanged.

## Verification

Run `composer install`, `composer test:coverage`, `vendor/bin/phpstan analyse src --level=5 --no-progress`, and `npx --yes @redocly/cli@2.15.0 lint openapi/media.json`. Source coverage must remain 100%. Tests cover live policies, scopes, ownership, cancellation/replay, task results/cache eviction, cursor tampering/expiry, dependency failure, validation, request bounds and correspondence between routes and the reviewed OpenAPI operations. Host installation and integration are verified separately; standalone test success does not complete the full module issue.

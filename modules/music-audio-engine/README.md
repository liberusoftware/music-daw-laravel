# Music Audio Engine

Experimental, disabled by default, version 0.1.0. Package identity: `liberusoftware/music-audio-engine`; module identity: `music-audio-engine`.

This initial implementation owns validated engine graph metadata, declared latency plans and immutable project-pinned snapshots. It does not yet implement native audio execution, playback, transport, offline rendering, resampling or hardware latency measurement. The complete Audio Engine Core acceptance issue remains open.

## Install and enable

Require this package and Music Projects on 64-bit PHP 8.5 and Laravel 13. Enable `music-audio-engine` explicitly through the host module manager with Projects enabled. Composer installation alone does not enable the module. The module exposes no HTTP routes or presentation adapters and adds no separate database tables.

Graph state is stored in `SessionDocument.modules['music.audio-engine']`, with outer schema version 1 and a versioned graph inside its state. Projects owns revisions, optimistic concurrency, idempotency, backups/export/import, media retention, audit integration and after-commit `SessionSaved` events. No duplicate graph database or save event is introduced. Disabling the graph module leaves project revisions and recordings intact; Projects preserves unfamiliar module state. Existing unsupported engine schemas are refused for replacement until a supported migration workflow exists.

## Public application boundary

Resolve `Services\SessionGraphs` through the container. `save($actor, new Commands\SaveGraph($workspace, $project, $graph, $expectedVersion, $commandKey))` updates an existing saved project and returns a `Data\GraphSnapshot`. Construct the graph using `Data\GraphDefinition::fromArray($input)`. `snapshot($actor, $workspace, $project, $version = null)` reads the current or specified immutable revision.

The supplied actor must pass Projects read authorization on every operation and write authorization on save, including replay. Workspaces and projects are scoped by Projects. Referenced media is reauthorized and verified through Projects' public contracts, including when reading historical snapshots and replaying commands. Missing verification, retention or host audit dependencies fail closed. No actor or workspace is inferred from graph input.

Saving uses the exact expected revision as its base, preserving its title, template flag, tracks, asset manifest and unrelated opaque module state. Stale revisions and command-key reuse with different content fail through Projects' conflict exceptions. An identical retry returns the original pinned snapshot even after a later checkpoint, subject to current authorization. Transactions roll back graph revision, retention and host audit together. The owning Projects event is emitted after commit and is not duplicated on replay.

## Graph schema 1

The root fields are `schema_version: 1`, `buffer_frames`, `nodes` and `edges`. Buffers are powers of two from 16 through 8192 frames. Up to 256 UUID-identified nodes and 1024 edges are allowed; serialized metadata is limited to 256 KiB. Nodes declare `id`, `kind`, `channels` (1–64) and `latency_frames` (0–1048576). Edges declare `from` and `to` node UUIDs.

Supported kinds are `silence`, `media`, `gain`, `mixer` and `output`. Media nodes additionally require `media_id`; gain nodes require finite `gain_linear` from 0 through 16. Source nodes have no inputs, gain and output nodes have exactly one input, mixers have at least one input, and outputs have no outgoing edges. Every node must reach an output. Duplicate connections, unequal channel counts, cycles and unknown fields or processor kinds are rejected.

Media references must resolve in the saved session with the graph's channel count and session sample rate. This schema does not silently resample or adapt channels. Latency planning computes the longest declared path and nonnegative per-edge delays at each fan-in. This is a static plan based on supplied declarations, not measured device latency or a claim that a native runtime applied compensation.

## Snapshot protocol 1

Snapshots identify `liberu.music.engine-graph`, protocol version 1, workspace, project, project version, project document checksum, sample rate, graph, deduplicated public immutable asset references and the declared latency plan. The SHA-256 snapshot checksum covers the payload excluding the checksum field itself. Project versions and asset frame counts use decimal strings to preserve signed 64-bit precision in JavaScript; positions and latency use explicit sample-frame units, and gain uses linear amplitude.

Snapshots set `runtime_required: true`. They contain no download URLs, filesystem paths, project titles, actor identities or unrelated module state. An authorized runtime integration must resolve bytes separately and enforce current access. These DTOs are metadata exchanged outside the audio callback; the package supplies no callback or device driver.

## Verification and remaining acceptance

Run `composer install`, `composer test:coverage` and `vendor/bin/phpstan analyse src --level=5`. The test suite checks graph validation, fan-in delay plans, bounded serialization, sample-rate/channel compatibility, lossless frame counts, revision snapshots, denied access, conflicts, replay, unsupported-schema preservation, transaction interruption and public dependency composition. Package coverage requires 100% of `src`; host coverage also includes `modules/*/src`. Host integration tests use actual verified WAVE intake, retention and transactional auditing.

The required load/play/seek/stop journey, offline rendering, separately versioned native runtime, migration workflow, callback deadline and underrun measurements, reference hardware seek accuracy and applied latency compensation still need implementation and verification. Passing the metadata tests does not complete those requirements or the wider DAW.

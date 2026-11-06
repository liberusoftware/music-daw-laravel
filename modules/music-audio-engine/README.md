# Music Audio Engine

Experimental, disabled by default, version 0.1.0.

This initial work in progress defines validated directed acyclic engine graphs, declared latency plans, and immutable project-pinned snapshots through the public Music Projects API. Graph state belongs to the project revision; the module has no separate tables.

This package does not provide native audio execution, playback, transport, offline rendering, resampling, or verified hardware latency. Its graph and snapshot implementation is still awaiting dedicated tests and acceptance review. Do not treat this release as a working audio engine.

The broader Audio Engine Core issue remains open. Full product acceptance and the repository's strict 100% source coverage gate remain outstanding.

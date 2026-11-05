# Changelog

## Unreleased

- Add authenticated metadata queries, bounded cursor pagination, capability discovery and read-only portable import preview over Projects core.
- Define the initial OpenAPI 3.1 fragment and scoped redacted Problem Details.

- Add conditional checkpoint/autosave, historical recovery and confirmed import commands with required revision/idempotency headers and distinct stale/reuse outcomes.

- Add bounded revision polling, encrypted resource-scoped cursors, snapshot resume cursors and explicit gap/ahead-state resynchronization.
- Require read and write token abilities for historical recovery.

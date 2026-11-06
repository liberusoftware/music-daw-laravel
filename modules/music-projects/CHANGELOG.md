# Changelog

## Unreleased

- Retain session media references in the revision transaction; fail closed when retention cannot participate.

- Introduce versioned session documents and checkpoint persistence.
- Require the explicit actor when resolving verified session media.

- Add an optional transactional revision audit contract for checkpoints, autosaves, recovery and import, preserving existing replay fingerprints and rolling back failed sink writes.

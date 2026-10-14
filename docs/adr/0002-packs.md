# ADR-0002: Packs are in-repo bundles enabled by detection or config

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-04
- Status: Superseded by ADR-0015 (tool units replace packs). Earlier superseded in part by ADR-0006.

## Context
Different machines need different skills and tools. A seedbox needs app know-how; a server with root needs administration skills. Bundles should install automatically based on what the machine looks like, and stay easy to add.

## Options Considered
- **Download bundles at install time from a separate source.** That is a second distribution channel to secure and version.
- **Ship bundles in the repository and enable them.** Git already transports and versions them.
- **Let the model choose bundles from the profile.** The input is enumerable, so a fixed rule is cheaper and deterministic.

## Decision
- **Format.** Bundles ("packs") live in `packs/<name>/` with a `pack.json` (`name`, `description`, `detect`). Optional contents: `skills/`, `tools/<target>/`, `hooks/<event>/`, `doctrine.md`, read-only lesson files, and `README.md`.
- **Enabling.** A pack is enabled when listed in `local/config.php`, or when its `detect` rule matches the machine profile and `packs.auto` is on. Detect keys are `pmss`, `root` and `apps`. There is no always-on rule: anything every machine needs belongs in the core runtime.
- **No downloads.** Enabling writes Hermes config only; nothing is downloaded or copied.
- **Index.** `pikkumies packs index` generates `packs/INDEX.md` from the manifests. The index is committed, and a test fails when it is stale. Install and update never write it, because a runtime write into the tracked tree would make the next fast-forward update refuse.
- **Third-party packs.** Later, as a separate directory under `local/`.

## Consequences
- Adding a pack is adding a directory and a manifest.
- The `root` pack enables only when the OS grants root and the user has not turned it off (ADR-0004).

## References
- ADR-0001

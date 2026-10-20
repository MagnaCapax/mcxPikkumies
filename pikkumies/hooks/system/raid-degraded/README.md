# raid-degraded

Runs before each turn (`pre_llm_call`) and may add one sentence to it ([ADR-0095](../../../../docs/adr/0095-md-raid-build-recover-and-tune-through-one-tool.md)).

When a Linux software RAID array needs attention, the agent is told which one and how, for example: "Software RAID needs attention: md0 is degraded ([UU_]); md1 is rebuilding (12.6%). Run mdraid status (server-admin/mdraid) before touching those disks, and follow doctrine/SOP/recover-a-degraded-or-failed-array.md."

- **What it reads:** `/proc/mdstat`, which any account can read. An active array whose member map has a `_` is degraded; a running `recovery` is a rebuild; an `inactive` array may have failed or been assembled by itself from a used disk. A `resync` or `check` alone is not reported.
- **Only with root:** the note helps only where the agent can act, so it needs `root.available` in the host cache (`local/host.json`, a hint, [ADR-0004](../../../../docs/adr/0004-provider-key-and-root-detection.md)) and no `root.disabled`. With no cache it stays silent; it never probes sudo.
- **Once per change:** a marker file named after the sha1 of the session id under `local/state/raid-degraded/` holds the state last told; the note comes again only when it changes, and nothing is said once the arrays are healthy. Markers older than 7 days are removed.

Fails open: an error only means no note.

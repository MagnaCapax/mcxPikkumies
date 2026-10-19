---
name: memory-check
event: pre_tool_call
description: Holds the first call in a session that uses a file a memory links to, a Pikkumies verb that changes, sends, deletes or loads, or a Hermes tool that changes something, and shows the agent the linked memories; running it again proceeds. Never asks the user.
failClosed: false
timeout: 3
---
Targets come from `MemoryCheckTargets.php`: a file named in a memory's `tools` frontmatter (`save --tool`) whose name the call names; a Pikkumies tool unit verb whose USAGE.md `changes` cell does not start with `no`; a Hermes tool outside the quiet list unless its `action` only reads. One block per target per session, with at most 5 linked memories (path and title), asking the agent to search memory, say in one line why the call is right and run it again; the marker under `local/state/memory-check/` then lets it through. A call naming the memory unit is never held. No session id or an unwritable marker: no hold. Fails open. Off with `memoryCheck.disabled` ([ADR-0081](../../../../docs/adr/0081-tool-linked-memories-and-an-agent-facing-soft-confirm.md)).

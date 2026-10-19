---
name: decide-yourself
event: pre_tool_call
description: Blocks Hermes' clarify tool and tells the agent to decide from what it found and do the work (ADR-0073).
failClosed: false
timeout: 2
---
Runs only for `clarify` calls (a Hermes-tool hook, [ADR-0054](../../../../../docs/adr/0054-tool-hooks-and-local-hooks.md)). Every call is blocked with one fixed message: decide from what was found and do the work; when only the user can give a fact (a name, an address, or a login they type in their own terminal), finish everything else and say in the answer exactly what is missing. Fails open: a crash lets the question through, which is the old behaviour ([ADR-0073](../../../../../docs/adr/0073-no-questions-no-deferrals-one-reviewed-exception.md)).

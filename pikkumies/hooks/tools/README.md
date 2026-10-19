# Tool hooks

A hook unit in `hooks/tools/<tool>/<hook>/` runs only for calls of one tool ([ADR-0054](../../../docs/adr/0054-tool-hooks-and-local-hooks.md)). It is laid out like every hook unit (`hook.md`, `README.md`, `<hook>.php`, [ADR-0030](../../../docs/adr/0030-hooks-as-units-run-by-one-orchestrator.md)) and runs on `pre_tool_call` (block or ask the user) or `post_tool_call` (observe; a printed context reaches the agent on the next turn).

- `<tool>` is a Pikkumies tool unit, such as `rtorrent`, or a Hermes tool, such as `terminal` or `write_file` (`HookUnits::HERMES_TOOLS`).
- A Hermes-tool hook runs when the call's `tool_name` is that tool. A unit hook runs when the call names `<tool>.php` or `tools/<category>/<tool>`, when Hermes runs inside that unit, or when the call runs `php` on a variable; the hook still decides which verbs matter.
- A tool hook cannot change a call: `modify` is ignored.
- The same layout under `~/pikkumies/local/hooks/tools/<tool>/<hook>/` is a local tool hook for one machine; it runs after the shipped hooks from the next start, never blocks by failing, and like every hook sees Hermes' environment, credentials included.

No tool hook ships yet. How to write one: [the SOP](../../doctrine/SOP/make-a-skill-tool-hook-or-pack.md#hook).

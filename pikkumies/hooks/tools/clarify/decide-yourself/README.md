# decide-yourself

Hermes' `clarify` tool is described to the agent as the way to ask the user when it needs a decision. Under [ADR-0073](../../../../../docs/adr/0073-no-questions-no-deferrals-one-reviewed-exception.md) the agent decides and does the work instead, so this tool hook blocks every `clarify` call with one fixed message. New installs also get `clarify` in `agent.disabled_toolsets`, seeded once into the user's Hermes config, which keeps the tool out of the model's list; this hook is the rule, the seeded line only saves a turn. A user who removes that line still gets the block.

- **When:** `pre_tool_call`, only when `tool_name` is `clarify`.
- **Answer:** `block`, with a message built from fixed text only, ending with the method sentence (investigate, attack the plan, iterate, do it); nothing from the call is echoed back.
- **Logged:** one line in `local/logs/halt-guard/` with source `clarify` and the fixed deferral class `asked-user-to-decide`, never the question or the choices; not with `halt.disabled` ([ADR-0102](../../../../../docs/adr/0102-deferral-log-and-nudge.md)).
- **Fails open:** a crash lets the question through.

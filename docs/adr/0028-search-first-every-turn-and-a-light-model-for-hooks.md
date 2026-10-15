# ADR-0028: Search first on every substantive turn; hooks use the agent's provider with a light model

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-15
- Status: Accepted

## Context
The operator's request of 2026-10-15: hooks that enforce searching memory and documentation "when something is requested which might need this". Also: "Hooks which need LLM use the same settings as the main pikkumies agent ofc, but if bigger model is chosen then the ligthweight version."

Until now, the `pre_llm_call` hook spoke only on a session's first turn, and the search-first gate opened once per session (ADR-0011, ADR-0018). In Hermes `pre_llm_call` runs once per turn (`agent/turn_context.py`), and the hook payload carries the session id (`agent/shell_hooks.py`). The documentation the hook searches is ADR-0027.

## Options Considered
- **A model router on every turn** deciding which sources to search. Rejected: a model call on every turn of a metered provider, for a decision the offline search makes for free on most turns.
- **First turn only (status quo).** Rejected: a later question in the same session acted without a search.
- **Re-arm the gate on substantive turns, deterministically; call a model only to reword a question nothing matched (chosen).**

## Decision
- **Re-arm:**
  - A later turn with a substantive message re-arms the gate: at least four content words after dropping common filler words, or a question mark, and not a slash command.
  - The hook touches `<session marker>.armed`. The gate opens again only when a search marker is newer.
  - Short follow-ups ("ok thanks", "restart it") do not re-arm.
  - `searchFirst.disabled` still turns both off.
- **Titles:**
  - The hook lists related titles from the local copies.
  - Titles are cleaned of control and line-break characters, at most 120 characters each, 1500 bytes in total. Never page text.
- **Reword step:**
  - When to call: the offline search found nothing and the message is a question.
  - What is sent: only the user's message, to the agent's own provider with the same endpoint and key (`Provider::resolve`), asking for at most eight English technical search terms.
  - Output handling: the reply is cut to plain words. Anything sentence-like is dropped, so it can only ever become an offline search query. It is never shown as text.
  - Failure behaviour: a six-second timeout; the step is silent on any failure.
  - Off switches: `hooks.reword` and `hooks.llm.disabled`.
  - Why a model here: this is a translation and paraphrase step, the one place the hook needs a model.
- **Light model** (`Provider::lightModel`, written to `local/llm.json` at generate time, reused for a day while the main model is unchanged). The order:
  1. `hooks.model` if set.
  2. Otherwise the agent's model, if its name already marks it small.
  3. Otherwise the endpoint's model named after the agent's model plus a small-size word.
  4. Otherwise a small model with the same vendor prefix.
  5. Otherwise the agent's model.
  Further rules:
  - The size words are generic (AGENTS.md: no vendor or product names).
  - Hooks never call `/models`. They use the stored light model only while its recorded main model matches the current one.

## Consequences
- The agent searches before acting on each new question in a session, not just the first.
- The cost is one local search per substantive turn. A question nothing matched sometimes costs one short call to the light model.
- A turn with a stale gate costs the agent one extra command. That is accepted (it is what "enforce" asks for), and short follow-ups do not pay it.
- Hooks stay safety prompts, not security (AGENTS.md): every marker file is writable by the agent's own user.

## References
- ADR-0011, ADR-0018, ADR-0022, ADR-0027.
- Hermes `agent/turn_context.py`, `agent/shell_hooks.py` (v2026.9.24).

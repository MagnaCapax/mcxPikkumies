# ADR-0026: Hermes uses only its own logins

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-15
- Status: Accepted

## Context
Facts checked 2026-10-15 in the pinned Hermes v2026.9.24 (`hermes_cli/config_defaults.py:1709-1731`): `auth.adopt_external_logins` defaults `True`. Its own comment states the mechanism and the failure mode plainly:

> Borrow and refresh the Codex CLI (`~/.codex/auth.json`) and Claude Code (`~/.claude/.credentials.json`) logins automatically when Hermes has no usable login of its own. Their refresh tokens are single-use and rotate, so two programs on one login can log each other out.

Pikkumies already names a configured, endpoint-pointed provider under `providers.pikkumies` (`model.provider: "custom:pikkumies"`, ADR-0005) — Hermes is never meant to be short a login it needs to borrow one for. On a machine where the same account also runs Codex CLI or Claude Code (plausible on a developer's own box, not just a seedbox account), leaving the default on means Hermes can silently pick up that tool's login, use its single-use refresh token, and rotate the other program's session out from under it — a cross-tool outage with no Pikkumies-side symptom to point at.

## Options Considered
- **Leave Hermes' default (`True`).** The failure above is silent and intermittent (only triggers when Hermes judges its own login "unusable" at that moment) and would land on the operator as an unexplained Codex/Claude Code logout with no connection drawn back to Hermes.
- **Pin `false`.** Hermes always uses its own configured login (`hermes auth add <provider>`); `hermes auth add openai-codex` still offers the interactive import explicitly, so a user who WANTS to borrow a login can still do so, just never silently.

## Decision
- `HermesConfig::renderYaml()` adds `auth.adopt_external_logins: false`.
- The explicit, interactive import path (`hermes auth add openai-codex`) is untouched — this ADR closes the silent, automatic borrowing only.

## Consequences
- Hermes never touches another program's `~/.codex/auth.json` or `~/.claude/.credentials.json` on its own initiative; a missing/unusable Pikkumies-configured login surfaces as Hermes' own "no usable login" condition instead of a borrowed one that can later log the other program out.
- A user who wants Hermes to run on a Codex or Claude Code login imports it once, explicitly, via `hermes auth add openai-codex` (or the equivalent for the other provider) — a deliberate choice instead of an implicit fallback.

## References
- ADR-0005 (provider wiring, `providers.pikkumies`), ADR-0024 (the feature pins installed alongside this)

# ADR-0110: Refuse to start without a default model

## Metadata
- Author: MCX
- Date: 2026-10-25
- Status: Accepted. Extends ADR-0005 and ADR-0062.

## Context
When no model is configured, a rejected key, unreachable endpoint or empty `/models`
answer resolved to an empty string. Config generation wrote that as both defaults,
and Hermes could start a gateway without a model or report a misleading storage error.

## Options Considered
- Keep starting and let Hermes diagnose it: hides the discovery failure.
- Fall back to an old default: hides failed discovery and can use a stale model.
- Refuse generation and preserve existing files: explains the cause at its origin.

## Decision
- Keep the configured model or select the first listed model, as in ADR-0005.
- Carry the HTTP status alongside discovery's model list. When no model resolves,
  refuse generation before writing either config layer or the light-model decision.
  The existing run error path refuses startup, including gateways.
- Explain a rejected key (HTTP 401/403), an unreachable endpoint or timeout (status 0),
  an endpoint listing no models (2xx), or another HTTP failure, and point to
  `pikkumies doctor`. Never print the key or response body as the reason.
- Doctor uses the same reason and reuses discovery's result for its reachability line.
- The YAML renderer also refuses an empty model, covering direct config writers.
  Existing non-empty defaults stay on disk; a failed discovery still refuses a start.

## Consequences
A first start cannot seed an empty default. Failed discovery cannot overwrite good
defaults on later starts. An explicitly configured model keeps its existing behavior:
startup does not require model discovery to succeed.

## References
- [ADR-0005](0005-hermes-wiring-verified-against-source.md)
- [ADR-0062](0062-hermes-config-seeded-once-pins-in-hermes-managed-scope.md)
- `tests/ProviderModelTest.php`, `tests/ModelStartTest.php`

## Amendment 2026-10-28: provision before the model key arrives

Install can run before the platform delivers a key. A missing key or endpoint sends
no discovery request, so calling either case an unreachable endpoint was incorrect.

- Discovery carries separate no-key and no-endpoint results, distinct from HTTP 0.
  `Provider::modelFailure` remains the one classifier: no key reuses the launcher's
  message naming `~/.virsilipasKey`; no endpoint names the key file and its missing
  `endpoint=` line. Connection failures and timeouts keep the unreachable reason;
  HTTP rejection, empty model lists and other HTTP failures keep their reasons.
- Install and update's post-update step catch only the missing-model refusal, for
  any discovery failure. They exit 0, report `model: unavailable` with the reason
  and `pikkumies doctor`, and finish skills, the tool index, both schedules, runtime
  setup and background documentation fill. Other generation/write errors still fail.
- Both Hermes config layers and `local/llm.json` remain untouched when no model
  resolves. `generate()` and `HermesConfig::write` still refuse the empty model;
  explicit `pikkumies generate` still exits 1. Sessions and gateways still refuse
  to start, including when old defaults remain on disk.
- Doctor reports that no request was sent for missing credentials, with the same
  reason, rather than claiming an HTTP 0 network failure.
- Regression coverage: `tests/ModelInstallTest.php`, `tests/ModelStartTest.php`,
  `tests/ProviderModelTest.php`, `tests/CoreTest.php`.

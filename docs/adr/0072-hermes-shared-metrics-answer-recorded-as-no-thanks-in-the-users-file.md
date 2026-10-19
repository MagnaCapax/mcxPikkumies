# ADR-0072: Hermes' shared-metrics question is answered "No thanks" in the user's own file

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-19
- Status: Accepted. Amends [ADR-0062](0062-hermes-config-seeded-once-pins-in-hermes-managed-scope.md): `telemetry.shared_metrics` leaves the pinned list; only `telemetry.shared_metrics.send` stays pinned, and the answer is seeded and repaired in the user's `config.yaml`. Keeps [ADR-0032](0032-settings-configure-skill-first-run-and-install-flags.md)'s "Hermes' own telemetry stays off".

## Context
The operator asked on 2026-10-19 for the Hermes web dashboard on PMSS, run as Pikkumies. The live pilot that day found that every dashboard page shows the banner "Help improve Hermes? ... Send to Nous / Local only / No thanks", although Pikkumies sets `telemetry.shared_metrics` to `enabled: false, send: false`. AGENTS.md: nothing leaves the machine that the user did not ask for. A one-click offer to send data to the Hermes vendor does not belong in a Pikkumies dashboard.

What the banner reads (Hermes v0.22.2, read in source):
- The banner hides only when `GET /api/shared-metrics/consent` says `decided` or `managed` (`web/src/components/SharedMetricsConsentBanner.tsx:49`).
- The route returns `consent_state(read_raw_config())` plus `managed: is_managed()` (`hermes_cli/web_routers/shared_metrics.py:26-31`). `read_raw_config` is the user's `config.yaml` as written: no defaults and no managed scope (`hermes_cli/config.py:1933`, `1887-1932`). `is_managed()` means a package-manager install (`HERMES_MANAGED` or a `.managed` marker, `hermes_constants.py:788-811`), not `HERMES_MANAGED_DIR`.
- `consent_state` (`hermes_cli/observability/shared_metrics_consent.py:58-71`): the question is answered when `enabled` or `send` is in that section, and an "off" answer counts only with `offer_version` equal to `OFFER_VERSION` (2). Without it, Hermes asks again. Pikkumies wrote no `offer_version`, so every page showed the re-ask banner. For the same reason an interactive chat would ask once in the terminal (`offer_consent_before_chat`, same file `:167-190`).
- The collector and sender read the same raw file: `relay_shared_metrics.py:1313-1329` ("managed overlays cannot opt a profile in or out") and `resolve_send_config` (`shared_metrics_send_config.py:47-90`). So ADR-0062's managed pin of `telemetry.shared_metrics` never reached them. A click on "Send to Nous" writes `enabled: true, send: true` into the user's file (`save_consent`, `shared_metrics_consent.py:92-119`, which writes the raw file directly and skips the managed-key refusal), and Hermes then collects and sends. Before ADR-0062 the next start rewrote the file. Since ADR-0062 the click would have stayed.
- Pinning `enabled` also breaks the answer. Hermes' `save_config` removes every pinned key from the user's file (`config.py:2425-2436`, `2466`), so the first Hermes save would make the question unanswered again.

ADR-0062 already states the rule: "a reader of the raw user file ... cannot be pinned, so the key goes in the seed and is repaired like `skills.external_dirs`". `telemetry.shared_metrics` was pinned without that check.

## Options Considered
- **Set `HERMES_MANAGED` so the route reports `managed`.** That hides the banner, but Hermes then refuses every config save, which undoes ADR-0062.
- **Patch the banner, or hide it in the proxy.** That is a change to Hermes or a hack in the path, and the sender would still read the user's file.
- **Keep the managed pin and add `offer_version`.** `save_config` strips the pinned `enabled` and `send`, and the question comes back.
- **Record Hermes' own "No thanks" in the user's file and pin only `send`.** Chosen.

## Decision
- **The seed** (the user's `config.yaml`, written at the first start) carries what Hermes writes for "No thanks" (`set_answer`, `shared_metrics_consent.py:80-89`): `enabled: false`, `send: false`, `offer_version: <OFFER_VERSION>`. The version is read from the installed `shared_metrics_consent.py`; 2 (v0.22.2) when it cannot be read (`HermesTelemetry::offerVersion`).
- **Every start** checks the user's file with one read (`HermesTelemetry::isOff`). If `enabled: false` and the current `offer_version` are not both under `telemetry.shared_metrics`, Pikkumies puts back only what differs: `hermes config set telemetry.shared_metrics.enabled false` and/or `... offer_version <N>`. Each edit is backed up and logged as `repair-telemetry-answer` (`HermesUserConfig::setValue`), as ADR-0062's edits are. The reader takes block YAML, as Hermes and the seed write it. Anything else counts as unsettled and is repaired through Hermes, which writes block YAML, so a misread costs one logged edit. Without a built Hermes the step waits for the next start, and the seed covers new installs.
- **Managed scope:** `telemetry.shared_metrics.send: false` only. `hermes config set` refuses it, so a casual `send true` from the agent is refused. When Hermes drops it from the user's file on a save, the answer stays settled, because it rests on `enabled`. With `enabled: false`, Hermes collects and sends nothing, whatever `send` says (`resolve_send_config`).
- **An opt-in in the user's file goes back to off at the next start.** This keeps the AGENTS.md and ADR-0032 rule ("Hermes' own telemetry stays off") and what every start did before ADR-0062. Nothing in Pikkumies turns Hermes' shared metrics on.
- **Start check:** `CONFIG_FACTS` lists `telemetry.shared_metrics.offer_version` (the text `section["offer_version"] = OFFER_VERSION`), so a Hermes that records the answer another way is refused until Pikkumies follows ([ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md)).

## Consequences
- The dashboard shows no telemetry banner, and a Pikkumies chat never asks the question. Both read the settled answer.
- Clicking "Send to Nous" in a dashboard opened before this change still takes effect until the next start. That start sets `enabled` back to false, and from then on Hermes neither collects nor sends (`relay_shared_metrics.py` `enabled()`, `resolve_send_config`). This change does not determine whether anything collected before that start was already sent; it was not checked. The repair uses `config set`, which does not run `save_consent`'s consent-window close and receipt purge.
- If a later Hermes raises `OFFER_VERSION`, the next start writes the new number without asking anyone.
- One more key that Pikkumies edits in the user's file, logged with the others.
- Not verified on a live host: the banner gone after a restart, and `hermes config set` writing `offer_version` (source read: `config.py:3577-3686`; an unknown sub-key is written with a notice, not refused). Hermes' own `consent_state` and `resolve_send_config` were run on the rendered config: decided, nothing sent.

## References
- Hermes v0.22.2: the files and lines above.
- `pikkumies/lib/HermesTelemetry.php`, `pikkumies/lib/HermesUserConfig.php`, `pikkumies/lib/HermesConfig.php` (`sessionsYaml`); tests `tests/HermesUserTelemetryTest.php`.
- Live pilot of 2026-10-19 (gap G7: banner on every dashboard page).

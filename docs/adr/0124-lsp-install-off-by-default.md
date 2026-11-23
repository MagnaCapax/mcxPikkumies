# ADR-0124: Hermes' language-server install is off by default, because it is a runtime install that fills the file quota

## Metadata
- Author: Väinämöinen, the orchestrating agent. This is the agent's decision, not an operator ruling.
- Date: 2026-11-23
- Status: Accepted. Amends [ADR-0021](0021-web-access-off-unless-configured.md) (runtime installs are off unless `hermes.lazyInstalls` is on) and extends the pin list of [ADR-0062](0062-hermes-config-seeded-once-pins-in-hermes-managed-scope.md). Keeps [ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md): Hermes stays vanilla, only a setting changes.

## Context
GH #10: the runtime's file count does not fit small seedbox file quotas. [ADR-0114](0114-daily-update-runs-upstream-gc.md) removes the superseded dependency generation (about 8,600 files). This ADR looks at what else grows after the build.

Facts, read in Hermes v0.22.2 and v0.22.5 source:
- `lsp` (`hermes_cli/config_defaults.py`) is on by default and runs in the post-write lint check of `write_file` and `patch`, when the working directory or the edited file is inside a git worktree. `lsp.install_strategy` defaults to `auto`: a missing language-server binary is installed on first use with npm, go or pip into `HERMES_HOME/lsp` (`agent/lsp/install.py` `try_install`, `_install_npm`; the npm packages land in `HERMES_HOME/lsp/node_modules`).
- `security.allow_lazy_installs` does not gate it: `rg allow_lazy_installs agent/lsp/` finds nothing. ADR-0021 turns runtime installs off to avoid unpinned downloads from registries and the cost they put on every account of a shared server. This one runs regardless.
- The install is not in a fresh install's file count (measured 2026-11-14: `local/hermes` has `installs`, `tools`, `cache`, `bootstrap-python` and no `lsp`). It appears after the agent has edited a file in a git worktree. How many files it adds depends on the server: an npm install brings the server and every dependency as separate files [UNVERIFIED: the count was not measured by this change, and the link to the growth of `local/hermes` seen on the issue beyond a fresh build was not reproduced].
- Pikkumies' own tree is a git worktree, and so is any repository the user keeps in the account.

## Options Considered
- **Leave it.** Thousands of files can appear on an account that edits code in a git worktree, on top of a runtime that already needs 58,000 to 66,000. Rejected.
- **`lsp.enabled: false`.** Also removes the diagnostics of a server the user installed on PATH. More than the problem needs.
- **Prune `HERMES_HOME/lsp` ourselves.** Pikkumies deletes nothing in Hermes' state (ADR-0033), and the next edit installs it again. Rejected.
- **`lsp.install_strategy: manual`, pinned, following `hermes.lazyInstalls`.** Chosen. Only servers already on PATH are used (`try_install` returns `_existing_binary` for anything but `auto`), and the one existing switch for runtime installs decides.

## Decision
- The generated Hermes config sets `lsp.install_strategy: manual`, or `auto` when `hermes.lazyInstalls` is on. It is written in both layers, as `security.allow_lazy_installs` is, so it reaches installs that were seeded before this change (ADR-0062: a default that must reach every install goes in the managed file).
- `lsp.install_strategy` joins `HermesInstall::CONFIG_FACTS`: a Hermes without the key is refused until it is updated. The key exists in v0.22.2 and v0.22.5, the oldest and the newest release this repository was built against.
- No setting is added. `hermes.lazyInstalls` already means "Hermes may download things at runtime".

## Consequences
- No more language-server downloads from npm, go or pip on an account: fewer files, less disk, no unpinned code fetched into the account at runtime.
- The agent loses the diagnostics of a language server it would have installed itself. A server already on PATH, or a custom server configured by the user, still works.
- Accounts that already have a `local/hermes/lsp` keep it: nothing here deletes it. Its owner can remove it.
- `hermes config set lsp.install_strategy` is refused while the pin is in place ("managed by your administrator"); `hermes.lazyInstalls` in `local/config.php` is the way back.
- The residual of GH #10 stays what [ADR-0114](0114-daily-update-runs-upstream-gc.md) leaves: the Hermes checkout (22,000 files, of which about 14,800 are `tests`, `apps/desktop`, `contributors` and `website`), the tool store, the uv cache and two Python trees. A sparse checkout of the never-run paths would change [ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md)'s vanilla-checkout rule and needs its own ADR and a proof against Hermes' updater.

## References
- GH MagnaCapax/mcxPikkumies#10.
- Hermes v0.22.5: `hermes_cli/config_defaults.py` (`lsp`), `agent/lsp/install.py` (`try_install`, `_install_npm`), `agent/lsp/manager.py` (`install_strategy` read through `load_config_readonly`, which applies the managed overlay).
- `pikkumies/lib/HermesConfig.php`, `pikkumies/lib/HermesInstall.php` (`CONFIG_FACTS`); tests `tests/CoreTest.php`, `tests/HermesManagedScopeTest.php`, `tests/HermesInstallTest.php` (every key HermesConfig writes is a fact).

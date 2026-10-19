# low-balance

Runs before each turn (`pre_llm_call`) and may add one sentence to it, once per session ([ADR-0050](../../../../docs/adr/0050-credits-usage-models-and-low-balance-notice.md)).

When credits are low, the agent is told so with the numbers, for example: "AI credits are low (350k credits left, 7% of the newest grant, at least 87.5k tokens): prefer the cheapest steps that do the job, and tell the user once that credits are running low."

- **Where the numbers come from:** the credits line's own cache, `local/state/credits/line.json`, which `credits line`, the start banner and the `pikkumies tmux` status line write ([ADR-0040](../../../../docs/adr/0040-credits-line.md)). The hook uses it only when it is under 10 minutes old and was written for this endpoint, key and model. It never asks the AI endpoint itself, so with no recent line (no tmux session, a provider without a usage route) it stays silent.
- **What counts as low:** `CreditsFormat::isLow`, the same test the tmux bar turns red on: under 10% of the newest grant left, or fewer than 200k tokens at the model's highest rate.
- **Once per session:** an empty marker file named after the sha1 of the session id under `local/state/low-balance/`; markers older than 7 days are removed. A session id that is not 1-128 letters, digits, dots, dashes or underscores means no notice.

Off with `lowBalance.disabled` in `local/config.php`. Fails open: an error only means no notice.

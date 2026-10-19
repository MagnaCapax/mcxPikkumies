# include

Install state, never committed:

- `.hermes-ready`: written when the Hermes install finished; `.hermes-build.lock`: held while it runs.

Hermes itself is not here: it is an unmodified upstream checkout in `pikkumies/hermes/`, and its Python, tools and dependencies live under `local/hermes/` ([ADR-0033](../../docs/adr/0033-vanilla-hermes-git-checkout-updated-separately.md)).

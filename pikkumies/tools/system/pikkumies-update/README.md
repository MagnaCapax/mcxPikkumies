# pikkumies-update

How Pikkumies updates itself: a fast-forward of its git checkout, then the agent configuration and the skills are generated again. Local changes to tracked files stop the update instead of being lost (ADR-0001). Hermes is a vanilla checkout in `pikkumies/hermes` that updates separately, with its own updater (ADR-0033).

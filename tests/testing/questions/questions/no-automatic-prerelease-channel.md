---
tags: task, update
contains_any: stable
not_contains: beta channel
judge: Says Pikkumies only fast-forwards its own main branch and the agent engine only follows upstream's stable release tags, and does not invent a beta or experimental channel setting.
source: docs/adr/0001-layout-install-and-update.md (fast-forward only, "Release tags and channels come later"); docs/adr/0033-vanilla-hermes-git-checkout-updated-separately.md ("the updater follows the stable channel by default... only moves forward")
---
Will Pikkumies ever automatically jump to some experimental pre-release version of itself or the agent engine?

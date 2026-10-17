#!/usr/bin/env bash
# Pikkumies installer: clones (or updates) this repository into ~/.mcxPikkumies and runs
# `pikkumies install` in your own account. No root. Install flags (docs/install.md) pass through:
#   bash install.sh --name=Helper
set -euo pipefail
dir="$HOME/.mcxPikkumies"
command -v git >/dev/null && command -v php >/dev/null || { echo "Pikkumies needs git and php (7.3 or newer)." >&2; exit 1; }
if [ -d "$dir/.git" ]; then
    "$dir/pikkumies/bin/pikkumies" update
else
    GIT_TERMINAL_PROMPT=0 git clone -q --depth 1 https://github.com/MagnaCapax/mcxPikkumies "$dir"
fi
exec "$dir/pikkumies/bin/pikkumies" install "$@"

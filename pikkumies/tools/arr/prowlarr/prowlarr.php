<?php
/**
 * prowlarr: the user's own Prowlarr through its API on 127.0.0.1 (tools/arr/README.md, this unit's README.md).
 * Everything is shared (lib/Arr.php); this file names the app and its usage line.
 */
require_once dirname(__DIR__) . '/lib/Arr.php';

$usage = 'usage: php prowlarr.php <verb> [arguments] [--human]
verbs: list [--search T] [--limit N] | show <id>... | stats | health | history [--since TIME] | restart
       releases <text> [--indexer ID,ID] [--limit N] | test [<id>...] | sync
ids: the indexer ids `list` prints. See USAGE.md.';

Arr::run($argv, 'prowlarr', $usage);

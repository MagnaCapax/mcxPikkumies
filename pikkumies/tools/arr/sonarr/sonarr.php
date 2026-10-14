<?php
/**
 * sonarr: the user's own Sonarr through its API on 127.0.0.1 (tools/arr/README.md, this unit's README.md).
 * Everything is shared (lib/Arr.php); this file names the app and its usage line.
 */
require_once dirname(__DIR__) . '/lib/Arr.php';

$usage = 'usage: php sonarr.php <verb> [arguments] [--human]
verbs: list [--search T] [--limit N] | show <id>... [--files] | stats | health | history [--since TIME] | restart
       lookup <title|tvdb:N> | add <tvdb:N>... [--profile P] [--root DIR] [--no-search] | remove <id>... [--with-data --expect SIZE]
       search <id>... | queue | wanted [--cutoff]
ids: the series ids `list` prints; tvdb:N ids come from `lookup`. See USAGE.md.';

Arr::run($argv, 'sonarr', $usage);

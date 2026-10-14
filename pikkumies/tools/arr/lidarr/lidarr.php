<?php
/**
 * lidarr: the user's own Lidarr through its API on 127.0.0.1 (tools/arr/README.md, this unit's README.md).
 * Everything is shared (lib/Arr.php, lib/ArrAppsMore.php); this file names the app and its usage line.
 */
require_once dirname(__DIR__) . '/lib/Arr.php';

$usage = 'usage: php lidarr.php <verb> [arguments] [--human]
verbs: list [--search T] [--limit N] | show <id>... [--files] | stats | health | history [--since TIME] | restart
       lookup <name|mbid:UUID> | add <mbid:UUID>... [--profile P] [--root DIR] [--no-search]
       remove <id>... [--with-data --expect SIZE] | search <id>... | queue | wanted [--cutoff]
ids: the artist ids `list` prints; mbid: ids (MusicBrainz) come from `lookup`. See USAGE.md.';

Arr::run($argv, 'lidarr', $usage);

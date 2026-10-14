<?php
/**
 * readarr: the user's own Readarr through its API on 127.0.0.1, read verbs only (tools/arr/README.md, this
 * unit's README.md): upstream archived the project, so nothing is added, removed or searched through it.
 */
require_once dirname(__DIR__) . '/lib/Arr.php';

$usage = 'usage: php readarr.php <verb> [arguments] [--human]
verbs: list [--search T] [--limit N] | show <id>... | stats | health | history [--since TIME] | restart
       queue | wanted [--cutoff]
ids: the author ids `list` prints. See USAGE.md.';

Arr::run($argv, 'readarr', $usage);

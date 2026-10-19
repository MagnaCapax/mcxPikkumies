<?php
/**
 * whisparr: the user's own Whisparr through its API on 127.0.0.1 (tools/arr/README.md, this unit's README.md).
 * Whisparr v2 (Sonarr-based) and v3 (Radarr-based) are both supported: WhisparrRun asks system/status which one
 * answers, then runs the shared Servarr verbs (lib/Arr.php) with that version's table row (lib/ArrAppsMore.php).
 */
require_once dirname(__DIR__) . '/lib/Arr.php';
require_once __DIR__ . '/WhisparrRun.php';

$usage = 'usage: php whisparr.php <verb> [arguments] [--human]
verbs: list [--search T] [--limit N] | show <id>... [--files] | stats | health | history [--since TIME] | restart
       remove <id>... [--with-data --expect SIZE] | search <id>... | queue | wanted [--cutoff]
       login-check | set-login | repair | config show [SECTION] | config set SECTION FIELD VALUE
ids: the ids `list` prints (v3: movies and scenes; v2: sites). No lookup or add: see README.md. See USAGE.md.';

WhisparrRun::run($argv, $usage);

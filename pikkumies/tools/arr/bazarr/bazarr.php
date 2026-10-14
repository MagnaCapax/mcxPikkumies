<?php
/**
 * bazarr: the user's own Bazarr (subtitles) through its API on 127.0.0.1 (tools/arr/README.md, README.md here).
 * Bazarr has its own API, so its own verbs (BazarrVerbs) and config reader (BazarrConfig); the HTTP client,
 * Docker, restart and output are the shared *ARR code. PIKKUMIES_PMSS_DIR, PIKKUMIES_ARR_TIMEOUT and
 * PIKKUMIES_ARR_WAIT exist for the tests only, as for the other units.
 */
require_once dirname(__DIR__) . '/lib/Arr.php';
require_once __DIR__ . '/BazarrConfig.php';
require_once __DIR__ . '/BazarrVerbs.php';
require_once __DIR__ . '/BazarrSearch.php';
require_once __DIR__ . '/BazarrRun.php';

$usage = 'usage: php bazarr.php <verb> [arguments] [--human]
verbs: list [--search T] [--limit N] | show <id>... | stats | health | history [--limit N] | restart
       wanted [--limit N] | search <id>...
ids: series:<n> or movie:<n>, as `list` prints them (the Sonarr series or Radarr movie id). See USAGE.md.';

BazarrRun::run($argv, $usage);

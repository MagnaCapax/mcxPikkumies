<?php
/**
 * autobrr: the user's own autobrr (release automation from IRC announces and feeds) through its API on
 * 127.0.0.1 (tools/arr/README.md, README.md here). autobrr has its own API and keeps its keys in its own
 * database, so the user stores one with `login`; config.toml gives only the address.
 * Test seams: a test may set $autobrrSecretDir and $autobrrPrompt before including this file; nothing here is
 * read from the environment except the shared PIKKUMIES_PMSS_DIR, PIKKUMIES_ARR_TIMEOUT and PIKKUMIES_ARR_WAIT.
 */
require_once dirname(__DIR__) . '/lib/Arr.php';
foreach (['AutobrrSecret', 'AutobrrConfig', 'AutobrrVerbs', 'AutobrrChange', 'AutobrrRun'] as $autobrrClass) {
    require_once __DIR__ . "/$autobrrClass.php";
}

$usage = 'usage: php autobrr.php <verb> [arguments] [--human]
verbs: list [--search T] [--limit N] | show <id>... | stats | health | history [--limit N] | restart
       toggle <id>... --on|--off
       login             (the user types an API key made in autobrr, Settings, API keys, at a terminal; stored for this tool)
ids: the filter ids `list` prints. See USAGE.md.';

$autobrrSecretDir = isset($autobrrSecretDir) ? $autobrrSecretDir : Config::localDir() . '/secrets';
$autobrrPrompt = isset($autobrrPrompt) ? $autobrrPrompt : ['AutobrrSecret', 'prompt'];
AutobrrRun::run($argv, $usage, new AutobrrSecret($autobrrSecretDir), $autobrrPrompt);

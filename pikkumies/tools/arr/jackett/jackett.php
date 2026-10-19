<?php
/**
 * jackett: the user's own Jackett (indexer proxy) through its API on 127.0.0.1 (tools/arr/README.md, README.md here).
 * Jackett has its own API, so its own verbs (JackettRun, JackettIndexers) and config reader (JackettConfig); the HTTP
 * client, Docker, restart and output are the shared *ARR code. Jackett takes its API key only as the `apikey` query
 * parameter (README.md "The key in the URL"); every text the tool prints has it replaced.
 */
require_once dirname(__DIR__) . '/lib/Arr.php';
require_once __DIR__ . '/JackettConfig.php';
require_once __DIR__ . '/JackettIndexers.php';
require_once __DIR__ . '/JackettLogin.php';
require_once __DIR__ . '/JackettRun.php';

$usage = 'usage: php jackett.php <verb> [arguments] [--human]
verbs: list [--search T] [--limit N] | show <id>... | stats | health | history | restart | test [<id>...] | login-check
ids: the indexer ids `list` prints (Jackett\'s own names, like 1337x). history: Jackett keeps none. See USAGE.md.';

JackettRun::run($argv, $usage);

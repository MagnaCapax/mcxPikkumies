<?php
/**
 * browse: the account's isolated browser for Hermes' browser tools (agent-browser + the system Chromium in a private
 * network namespace, a CDP guard in front). `up`, `down` and `status` for people and the agent; two internal entries
 * that are not verbs: `cli -- <agent-browser args>` is what the generated agent-browser command on Hermes' PATH runs
 * (BrowseArgv decides what may pass), and `watch` is the resident watcher a start leaves behind (idle stop, and the
 * stop when the guard or any other part is gone).
 */
umask(0077); // as bin/pikkumies: everything it writes (state, logs, the intent) is the account's alone from creation
$lib = dirname(__DIR__, 3) . '/lib';
foreach (glob("$lib/*.php") as $f) { // HermesConfig, Detect and Cgroup pull in most of lib/
    require_once $f;
}
require_once __DIR__ . '/browse.inc.php';

if (isset($argv[1]) && $argv[1] === 'cli') { // the agent-browser wrapper: its own output, never Cli records
    $args = array_slice($argv, isset($argv[2]) && $argv[2] === '--' ? 3 : 2);
    exit(BrowseCli::main($args, new BrowseSupervisor()));
}
if (isset($argv[1]) && $argv[1] === 'watch') { // detached by a start; ends when the browser stops
    $sup = new BrowseSupervisor();
    do {
        $sup->proc->sleep(BrowseSupervisor::TICK);
        $why = $sup->tick(getmypid());
    } while ($why === '');
    exit(0);
}

$usage = <<<'U'
usage: php browse.php up | down | status [--human]
  up      start the isolated browser now (it also starts by itself on the first browser call); refuses with the reason
  down    stop it now (it also stops by itself after 15 minutes without a browser call)
  status  whether it may run here, whether it runs, and the versions
U;

Cli::run($argv, [
    'up' => function () {
        list($ok, $msg) = (new BrowseSupervisor())->up();
        if (!$ok) {
            throw new CliError($msg, 'the browser stays off; `pikkumies doctor` shows the browse line');
        }
        return ['records' => [BrowseCli::statusRecord()], 'status' => ['verified' => BrowseState::read() !== null]];
    },
    'down' => function () {
        $had = (new BrowseSupervisor())->down();
        return ['records' => [['stopped' => $had, 'running' => BrowseState::read() !== null]], 'status' => ['verified' => BrowseState::read() === null]];
    },
    'status' => function () {
        return [BrowseCli::statusRecord()];
    },
], $usage);

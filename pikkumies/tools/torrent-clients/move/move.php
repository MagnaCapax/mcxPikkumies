<?php
/**
 * move: torrents from another box of the user's (the services list) to this one, with their data, seeding here before
 * the old copy is ever touched (README.md in this directory). php move.php <verb> [arguments] [--human].
 */
$root = dirname(__DIR__, 3);
foreach (['Cli', 'CliHuman', 'Config', 'Quota', 'ToolProc'] as $lib) {
    require_once "$root/lib/$lib.php";
}
if (is_file("$root/lib/Services.php")) {
    require_once "$root/lib/Services.php";
}
foreach (['MoveMeta', 'MoveState', 'MoveProc', 'MoveRunner', 'MoveSource', 'MoveCopy', 'MoveRoots', 'MovePlace', 'MovePlan', 'MoveTarget', 'MoveStepCopy', 'MoveRun', 'MoveFree'] as $class) {
    require_once __DIR__ . "/$class.php";
}

$usage = <<<'TXT'
usage: php move.php <verb> [arguments] [--human]
  plan <id|name>... --from <service> [--client <c>] [--dir <folder>] [--to local]
  run <move> [--free-source --ids <id,...> --expect <size> --intent "<the user's own words>"]
  status [<move>]
  list
Run on the box that receives the torrents. Full usage: USAGE.md next to this file.
TXT;

$moveHome = rtrim((string) getenv('HOME'), '/');
$moveState = new MoveState(Config::localDir() . '/state/moves');
$moveRunner = new MoveRunner(dirname(__DIR__));

Cli::run($argv, [
    'plan' => function ($args, $opt) use ($moveRunner, $moveState, $moveHome) {
        return (new MovePlan($moveRunner, $moveState, $moveHome, Config::localDir() . '/state/tmp'))->plan($args, $opt);
    },
    'run' => function ($args, $opt) use ($moveRunner, $moveState, $moveHome) {
        if (count($args) !== 1) {
            throw new CliError('run takes one move id', 'run <move>; `list` shows the moves');
        }
        return (new MoveRun($moveRunner, $moveState, $moveHome, Config::localDir() . '/state/tmp'))->run($args[0], $opt);
    },
    'status' => function ($args, $opt) use ($moveState) {
        $all = $args ? [] : $moveState->all();
        if (!$args && !$all) {
            return [];
        }
        $move = $args ? $moveState->load($args[0]) : $all[0]; // no id: the newest move
        $log = $moveState->logLines($move['id']);
        return ['records' => MoveRun::summary($move), 'status' => ['move' => $move['id'], 'from' => "{$move['from']} {$move['source_client']}",
            'to' => "here {$move['target_client']}", 'expect' => $move['expect'], 'last' => $log ? end($log) : null]];
    },
    'list' => function ($args, $opt) use ($moveState) {
        return array_map(function ($m) {
            $rows = MoveRun::summary($m);
            $next = array_unique(array_column($rows, 'next'));
            return ['move' => $m['id'], 'created_at' => $m['created_at'], 'from' => "{$m['from']} {$m['source_client']}", 'to' => $m['target_client'],
                'torrents' => count($m['items']), 'bytes' => $m['bytes'], 'next' => implode(', ', $next)];
        }, $moveState->all());
    },
], $usage, ['free-source'], ['from', 'to', 'client', 'dir', 'ids', 'expect', 'intent']);

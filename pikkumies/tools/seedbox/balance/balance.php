<?php
/**
 * balance: plan moves of completed torrents between the user's own seedboxes to even out free disk
 * space or spread upload load (README.md). Read-only: `plan` only ever prints proposals, each with
 * the exact `move plan ...` command to act on it -- it never calls Services::fanout's remote servers
 * with anything but read verbs, and it never runs a move itself.
 *
 * php balance.php plan [--tag seedbox] [--goal free|upload] [--max-moves N] [--human]
 */
require dirname(__DIR__, 3) . '/lib/Cli.php';
require dirname(__DIR__, 3) . '/lib/CliHuman.php';
require_once dirname(__DIR__, 3) . '/lib/ToolProc.php';
require_once __DIR__ . '/BalanceGather.php';
require_once __DIR__ . '/BalancePlan.php';

// Test seams: a test may set these globals before including this file (same pattern as
// tools/system/system-status/system-status.php's $systemStatusLocalDir and friends). Production
// never sets them, so BalanceGather::services() runs its real local/Services defaults.
$balanceSeams = isset($balanceSeams) && is_array($balanceSeams) ? $balanceSeams : array();

$usage = <<<'TXT'
usage: php balance.php plan [--tag seedbox] [--goal free|upload] [--max-moves N] [--human]
  plan   Proposes torrent moves between this account and every service tagged --tag (default
         "seedbox") to even out free disk space (--goal free, default) or spread upload load
         (--goal upload). Read-only: prints each proposal with its exact `move plan ...` command,
         size and reason; never moves anything. --max-moves caps how many proposals are made.
Full usage: USAGE.md next to this file.
TXT;

Cli::run($argv, array(
    'plan' => function ($args, $opt) use ($balanceSeams) {
        if (isset($opt['goal']) && $opt['goal'] !== 'free' && $opt['goal'] !== 'upload') {
            throw new CliError('--goal must be "free" or "upload"', 'plan --goal free|upload');
        }
        if (isset($opt['max-moves']) && (!is_numeric($opt['max-moves']) || (int) $opt['max-moves'] < 1)) {
            throw new CliError('--max-moves must be a positive whole number', 'plan --max-moves N (N >= 1)');
        }
        $services = BalanceGather::services(array('tag' => isset($opt['tag']) ? $opt['tag'] : null), $balanceSeams);
        $plan = BalancePlan::build($services, array(
            'goal' => isset($opt['goal']) ? $opt['goal'] : 'free',
            'max_moves' => isset($opt['max-moves']) ? $opt['max-moves'] : null,
        ));
        return array('records' => $plan['moves'], 'status' => $plan['status']);
    },
), $usage, array(), array('tag', 'goal', 'max-moves'));

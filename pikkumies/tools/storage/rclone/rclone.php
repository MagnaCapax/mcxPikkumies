<?php
/**
 * rclone: the user's own rclone remotes, transfers and mounts (tools/storage/README.md, README.md
 * here). Reads remote names and types, never their settings; previews are read verbs (`check`);
 * sync and move need the --expect count the user saw; copy never overwrites without --replace.
 */
$lib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Host', 'ToolProc', 'HomePath'] as $rcloneClass) {
    require_once "$lib/$rcloneClass.php";
}
foreach (['ToolText', 'ToolList'] as $toolLib) {
    require_once dirname(__DIR__) . "/lib/$toolLib.php";
}
foreach (['RcloneCmd', 'RclonePlace', 'RclonePlan', 'RcloneInfo', 'RcloneTransfer'] as $rcloneClass) {
    require_once __DIR__ . "/$rcloneClass.php";
}

$usage = <<<'TXT'
usage: php rclone.php <verb> [arguments] [--human]
  list                                   remotes: name and type (never their settings)
  show <remote:path> [--size] [--limit N]   one directory level
  stats                                  version, config, WebUI, mounts, running transfers
  check <src> <dst> [--one-way]          what copy, sync and move would do, and the --expect values
  copy <src> <dst> [--replace]           copies; never deletes; overwrites only with --replace
  sync <src> <dst> --expect <n>          makes dst like src: deletes and replaces n files on dst
  move <src> <dst> --expect <n>          moves n files: removes them from src
  unmount <path>                         fusermount -u on this account's FUSE mount
Places: remote:path (a remote from `list`) or a full local path inside the home. See USAGE.md.
TXT;

// Test seams: a test may set these before including this file. Nothing is read from the environment.
$rcloneMountinfo = isset($rcloneMountinfo) ? $rcloneMountinfo : '/proc/self/mountinfo';
$rcloneProcRoot = isset($rcloneProcRoot) ? $rcloneProcRoot : '/proc';
$rcloneFamily = isset($rcloneFamily) ? $rcloneFamily : null;
$rcloneMake = function () {
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    return new RcloneCmd(HomePath::home(), $uid);
};

Cli::run($argv, [
    'list' => function ($args, $opt) use ($rcloneMake) {
        return RcloneInfo::remotes($rcloneMake());
    },
    'show' => function ($args, $opt) use ($rcloneMake) {
        return RcloneInfo::show($rcloneMake(), $args, $opt);
    },
    'stats' => function ($args, $opt) use ($rcloneMake, $rcloneFamily, $rcloneMountinfo, $rcloneProcRoot) {
        return RcloneInfo::stats($rcloneMake(), $rcloneFamily !== null ? $rcloneFamily : Host::family(), $rcloneMountinfo, $rcloneProcRoot);
    },
    'check' => function ($args, $opt) use ($rcloneMake) {
        $c = $rcloneMake();
        if (count($args) !== 2) {
            throw new CliError('check needs a source and a destination', 'check <src> <dst> [--one-way]');
        }
        $c->ready();
        $oneWay = !empty($opt['one-way']);
        $plan = RclonePlan::build($c, RclonePlace::of($args[0], true, $c->home), RclonePlace::of($args[1], false, $c->home), $oneWay);
        return RclonePlan::show($plan, $oneWay, $c->version());
    },
    'copy' => function ($args, $opt) use ($rcloneMake) {
        return RcloneTransfer::copy($rcloneMake(), $args, $opt);
    },
    'sync' => function ($args, $opt) use ($rcloneMake) {
        return RcloneTransfer::sync($rcloneMake(), $args, $opt);
    },
    'move' => function ($args, $opt) use ($rcloneMake) {
        return RcloneTransfer::move($rcloneMake(), $args, $opt);
    },
    'unmount' => function ($args, $opt) use ($rcloneMake, $rcloneMountinfo) {
        return RcloneTransfer::unmount($rcloneMake(), $args, $rcloneMountinfo);
    },
], $usage, ['size', 'one-way', 'replace'], ['expect']);

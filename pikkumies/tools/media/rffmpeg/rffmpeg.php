<?php
/**
 * rffmpeg: delegates this account's Jellyfin/Emby transcodes to ffmpeg + a GPU on another box of the
 * user's own, over one entry from the Services list (tools/seedbox/services, owner A). This unit
 * talks to that list only through RffmpegServices, so a missing pikkumies/lib/Services.php never
 * breaks plan/install/status -- SPEC.md: "Builders ... code against the Services contract ... until A
 * lands, write tests with a fake Services (inject a runner callable)". Everything this unit writes
 * lives under ~/pikkumies/local/state/rffmpeg (AGENTS.md: nothing tracked, never /tmp); nothing is
 * ever written to a system path (SPEC.md: "never system paths"). Upstream: github.com/joshuaboniface/
 * rffmpeg (GPL-3.0) -- see README.md "Sources" for the pinned commit and the memory lesson this design follows.
 */
$lib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'Host', 'Http', 'HomePath', 'ToolProc'] as $class) {
    require_once "$lib/$class.php";
}
require_once __DIR__ . '/RffmpegServices.php';
require_once __DIR__ . '/RffmpegState.php';
require_once __DIR__ . '/RffmpegLocal.php';
require_once __DIR__ . '/RffmpegRemote.php';
require_once __DIR__ . '/RffmpegPlan.php';
require_once __DIR__ . '/RffmpegVenv.php';
require_once __DIR__ . '/RffmpegHost.php';

$usage = <<<'TXT'
usage: php rffmpeg.php <verb> [arguments] [--human]
  plan --gpu <nick> [--mount <remote-path>]    is Jellyfin/Emby + that GPU host ready; exact next steps
  install [--force]                            venv + pinned rffmpeg under this account, nothing system-wide
  add-host <nick> --allow-shell --mount <path> wires one GPU service in (needs a shell-transport key)
  status                                        what is installed, and rffmpeg's own host/process table
  remove [--with-data] [--allow-shell]          undoes exactly what install/add-host created
<nick>: a name from the Services list (tools/seedbox/services). Output: JSON Lines. See USAGE.md.
TXT;

// Test seams: a test sets $rfSeams before including this file. Nothing here is read from the environment.
$rfSeams = isset($rfSeams) ? $rfSeams : [];
$rfGw = new RffmpegServices(isset($rfSeams['services']) ? $rfSeams['services'] : []);
$rfLocalDir = isset($rfSeams['localDir']) ? $rfSeams['localDir'] : Config::localDir();
$rfHome = isset($rfSeams['home']) ? $rfSeams['home'] : HomePath::home();
$rfUid = isset($rfSeams['uid']) ? $rfSeams['uid'] : (function_exists('posix_geteuid') ? posix_geteuid() : getmyuid());

Cli::run($argv, [
    'plan' => function ($args, $opt) use ($rfGw, $rfHome, $rfUid) {
        return RffmpegPlan::run($rfGw, $args, $opt, $rfHome, $rfUid);
    },
    'install' => function ($args, $opt) use ($rfSeams, $rfLocalDir) {
        return RffmpegVenv::install(isset($rfSeams['venv']) ? $rfSeams['venv'] : [], $rfLocalDir, $opt);
    },
    'add-host' => function ($args, $opt) use ($rfGw, $rfLocalDir, $rfHome) {
        return RffmpegHost::addHost($rfGw, $args, $opt, $rfLocalDir, $rfHome);
    },
    'status' => function ($args, $opt) use ($rfLocalDir) {
        return RffmpegHost::status($rfLocalDir);
    },
    'remove' => function ($args, $opt) use ($rfGw, $rfLocalDir) {
        return RffmpegHost::remove($rfGw, $opt, $rfLocalDir);
    },
], $usage, ['force', 'allow-shell', 'with-data'], ['gpu', 'mount']);

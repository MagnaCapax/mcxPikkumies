<?php
/**
 * session-rewrite: offline account-rename support for a STOPPED torrent client (README.md in this
 * directory). Rewrites only the known path keys (SessionRewriteKeys.php) of a client's own session/resume
 * files, raw-preserving (lib/Bencode.php): a file's "info" dictionary, where one exists, is never touched
 * and is verified byte-identical (SessionRewriteEngine.php). `rewrite --apply` backs the whole session
 * directory up first (SessionRewriteBackup.php). Deluge is refused outright: its on-disk state
 * (torrents.state) is a Python pickle, never bencode, and this tool never edits it; use the live
 * `deluge repath` instead. For a client that is actually running, use each client's own live `repath` verb,
 * not this tool.
 */
$lib = dirname(__DIR__, 3) . '/lib';
foreach (['Cli', 'CliHuman', 'Config', 'OwnProcs', 'Detect', 'ToolProc', 'PathName'] as $class) {
    require_once "$lib/$class.php";
}
require_once dirname(__DIR__) . '/lib/Bencode.php';
foreach (['SessionRewriteKeys', 'SessionRewriteLocate', 'SessionRewriteEngine', 'SessionRewriteBackup'] as $class) {
    require_once __DIR__ . "/$class.php";
}

// Test seam: a test may set this before including this file (the same pattern qbittorrent.php's
// $qbitSecretDir uses), so a backup never lands in the real pikkumies/local/ during a test run.
$sessionRewriteLocalDir = isset($sessionRewriteLocalDir) ? $sessionRewriteLocalDir : Config::localDir();

$usage = <<<'TXT'
usage: php session-rewrite.php rewrite --client rtorrent|qbittorrent|transmission --from <prefix> --to <prefix> [--apply] [--human]
  Refuses while the named client is running. Dry-run by default: lists every file and key that would
  change. --apply backs the whole session directory up first, then rewrites it, raw-preserving: nothing
  outside the known path keys changes, and a torrent's info dictionary (if the file ever carries one) is
  verified byte-identical before anything is written. deluge is refused (its state is a Python pickle,
  never bencode); use that client's own live `repath` verb instead. See USAGE.md.
TXT;

Cli::run($argv, [
    'rewrite' => function ($args, $opt) use ($sessionRewriteLocalDir) {
        if ($args) {
            throw new CliError('rewrite takes no positional arguments: nothing was changed',
                'rewrite --client rtorrent|qbittorrent|transmission --from <prefix> --to <prefix> [--apply]');
        }
        $client = isset($opt['client']) && is_string($opt['client']) ? $opt['client'] : '';
        if (!in_array($client, SessionRewriteKeys::clients(), true)) {
            if ($client === 'deluge') {
                throw new CliError("deluge is refused: its on-disk state (torrents.state) is a Python pickle, and this tool never edits it",
                    'use the live `deluge repath <id>... --to <dir>` instead (tools/torrent-clients/deluge/USAGE.md)');
            }
            throw new CliError('--client must be one of: ' . implode(', ', SessionRewriteKeys::clients()) . ' (deluge is refused by design)',
                'rewrite --client rtorrent|qbittorrent|transmission --from <prefix> --to <prefix> [--apply]');
        }
        $from = isset($opt['from']) && is_string($opt['from']) && $opt['from'] !== '' ? $opt['from'] : null;
        $to = isset($opt['to']) && is_string($opt['to']) && $opt['to'] !== '' ? $opt['to'] : null;
        if ($from === null || $to === null) {
            throw new CliError('rewrite needs --from <prefix> and --to <prefix>; nothing was changed',
                'rewrite --client ' . $client . ' --from <old prefix> --to <new prefix> [--apply]');
        }
        if ($from === $to) {
            throw new CliError('--from and --to are the same; nothing to rewrite', 'give the actual old and new path prefixes');
        }
        $home = rtrim((string) getenv('HOME'), '/');
        if ($home === '' || !is_dir($home)) {
            throw new CliError('cannot find your home directory ($HOME is not set or not a directory)', 'run the tool as the seedbox user, from a login shell');
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        // PIKKUMIES_FAKE_PROC exists for the tests (a fake /proc), the same seam the torrent-client units'
        // restart proofs use (RtorrentRestart::$proc, QbitProc::$root); unset in production, so the real /proc is read.
        $procRoot = getenv('PIKKUMIES_FAKE_PROC') ?: '/proc';
        $running = OwnProcs::pids(Detect::KNOWN_APPS[$client], $uid, $procRoot);
        if ($running) {
            throw new CliError("$client is running (pid " . implode(', ', $running) . '); nothing was changed',
                "stop $client first (the panel, or the provider's own way; a client's own `restart` verb does not stop it), then run rewrite again");
        }
        $dir = SessionRewriteLocate::dir($client, $home);
        $files = (array) glob($dir . '/' . SessionRewriteLocate::GLOB[$client]);
        sort($files);
        $keys = SessionRewriteKeys::KEYS[$client];
        $records = [];
        $planned = [];
        foreach ($files as $file) {
            $raw = (string) @file_get_contents($file);
            if ($raw === '' || $raw[0] !== 'd') {
                $records[] = ['file' => $file, 'reason' => 'skipped: not a bencoded dictionary'];
                continue;
            }
            try {
                $changes = SessionRewriteEngine::plan($raw, $keys, $from);
            } catch (CliError $e) {
                throw new CliError("$file: " . $e->getMessage(), $e->hint);
            }
            if (!$changes) {
                continue;
            }
            $planned[$file] = ['raw' => $raw, 'changes' => $changes];
            $records[] = ['file' => $file, 'keys' => array_map(function ($c) use ($from, $to) {
                return ['key' => $c['key'], 'from' => $c['old'], 'to' => $to . substr($c['old'], strlen($from))];
            }, $changes)];
        }
        $apply = !empty($opt['apply']);
        $backup = null;
        if ($apply && $planned) {
            $expect = [];
            foreach ($planned as $file => $p) {
                $expect[PathName::base($file)] = strlen($p['raw']); // the CURRENT size: what the backup must hold before anything is rewritten
            }
            $backup = SessionRewriteBackup::make($client, $dir, $sessionRewriteLocalDir, $expect);
            foreach ($planned as $file => $p) {
                list($newRaw) = SessionRewriteEngine::apply($p['raw'], $p['changes'], $from, $to);
                // Atomic: a temp file written in the same directory, then renamed over the original, so a
                // crash or a concurrent reader never sees a partially-written session file (security review N2).
                $tmp = $file . '.tmp' . getmypid();
                $ok = @file_put_contents($tmp, $newRaw, LOCK_EX) !== false && @rename($tmp, $file);
                if (!$ok) {
                    @unlink($tmp);
                    throw new CliError("could not write $file after the backup was made at $backup; STOP and check it before retrying",
                        'the backup at ' . Config::shown($backup) . ' has every original file; check free space and permissions, then retry');
                }
            }
        }
        return ['records' => $records, 'status' => ['client' => $client, 'dir' => $dir, 'files_scanned' => count($files),
            'files_changed' => count($planned), 'applied' => $apply && (bool) $planned, 'backup' => $backup]];
    },
], $usage, ['apply'], ['client', 'from', 'to']);

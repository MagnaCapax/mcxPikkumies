<?php
/**
 * RemoteRun: `run --mode meta|full` (ADR-0049). One job per account (flock), lowest CPU and disk priority, then
 * tar --one-file-system | pigz -p 2 | gpg --symmetric | ssh put <name>. Every stage's exit code is checked (tar's 1,
 * "file changed as we read it", is a warning), the bytes handed to ssh must equal the bytes the receiver stored, and only
 * then `commit` makes the archive count; an interrupted upload stays a .part file the receiver removes after a day.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class RemoteRun
{
    /** PMSS's panel backup set, verbatim: pmssCustomerBackupRelativePaths(), PMSS etc/skel/www/scriptsIncBackup.php:6-19. */
    const PMSS_SET = ['.rtorrent.rc', '.rtorrent.rc.custom', 'session', '.config/deluge', '.delugeSession', '.sessionDeluge',
        '.config/qBittorrent', '.local/share/qBittorrent/BT_backup', '.local/share/data/qBittorrent/BT_backup', '.config/pmss/rutorrent/users',
        '.local/share/pmss/rutorrent/share'];
    /** The rest of meta mode; top-level dotfiles and the pikkumies local directory are added at run time. */
    const META_MORE = ['.config', '.lighttpd'];
    /** Never archived (tar patterns; ./x is top level only): caches, programs, shell history (it may hold a typed password), the AI key. */
    const EXCLUDE = ['.cache', './.bin', '.*_history', '.lesshst', './.pikkumiesKey', './.virsilipasKey'];
    /** Also left out of meta mode: application caches inside ~/.config. */
    const META_EXCLUDE = ['cache', 'Cache', 'CachedData', 'GPUCache', 'Code Cache'];
    const FULL_MAX_KIB = 104857600; // 100 GiB: a daily full above this needs --allow-large (1 TB takes about 2.5 h at 1 Gbit/s)
    const LIST_NAME = 'pikkumies-file-list.tsv.gz';

    /** Every failure, also one before anything is sent (lock, host key, free space), lands in state.json as last_run, ok false. */
    public static function run(RemoteDest $dest, array $opt, $home, $localDir)
    {
        $rec = [];
        try {
            return self::attempt($dest, $opt, $home, $localDir, $rec);
        } catch (CliError $e) {
            if (is_file($dest->path('dest.json'))) {
                $dest->save('state.json', ['last_run' => ['ok' => false, 'error' => $e->getMessage(), 'at' => time()]
                    + $rec + ['mode' => isset($opt['mode']) ? (string) $opt['mode'] : null]] + $dest->state());
            }
            throw $e;
        }
    }

    private static function attempt(RemoteDest $dest, array $opt, $home, $localDir, array &$rec)
    {
        $mode = isset($opt['mode']) ? (string) $opt['mode'] : '';
        if (!in_array($mode, ['meta', 'full'], true)) {
            throw new CliError('run needs --mode meta or --mode full', 'meta: settings and torrent state only, small enough to run often');
        }
        $d = $dest->load();
        $dest->refuseDenied($d['host']); // before any work: a scheduled run no hook sees
        $lock = fopen($dest->path('run.lock'), 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new CliError('another backup run of this account is still going; nothing started', 'wait for it to finish');
        }
        foreach (['tar', 'pigz', 'gpg', 'ssh'] as $bin) {
            ToolProc::need($bin, "install $bin; the backup uses tar, pigz, gpg and ssh");
        }
        $pass = $dest->passFile(false);
        $excl = self::excludes($home, $localDir, $mode);
        $paths = $mode === 'meta' ? self::metaPaths($home, $localDir) : ['.'];
        $est = self::estimate($home, $paths, $excl);
        if ($mode === 'full' && empty($opt['allow-large']) && ($est === null || $est > self::FULL_MAX_KIB)) {
            throw new CliError('the home is ' . ($est === null ? 'too big to measure in time' : round($est / 1048576, 1) . ' GiB')
                . '; a full run sends all of it every time, so nothing was sent', 'use --mode meta for frequent runs, or add --allow-large');
        }
        $avail = $dest->avail($d);
        if ($est !== null && $est * 1024 > $avail) {
            throw new CliError('the backup machine has ' . round($avail / 1073741824, 1) . ' GiB free, less than the ' . round($est / 1048576, 1)
                . " GiB to send; nothing sent", 'free space there, or use --mode meta');
        }
        $tail = $mode === 'meta' ? ['-C', $dest->dir, self::LIST_NAME] : [];
        $listed = $mode === 'meta' ? self::fileList($home, $dest->path(self::LIST_NAME), $localDir) : null;
        $name = gmdate('Ymd\THis\Z') . ".$mode.tgz.gpg";
        $low = array_merge(ToolProc::which('nice') ? ['nice', '-n', '19'] : [], ToolProc::which('ionice') ? ['ionice', '-c3'] : []);
        $tar = array_merge($low, ['tar', '--one-file-system', '--no-wildcards-match-slash'], array_map(function ($p) {
            return "--exclude=$p";
        }, $excl), ['-cf', '-', '-C', $home], $paths, $tail);
        $out = (string) tempnam(sys_get_temp_dir(), 'pk-remote-out');
        $r = RemotePipe::run([$tar, array_merge($low, ['pigz', '-p', '2', '-c']), array_merge($low, ['gpg', '--batch', '--yes',
            '--pinentry-mode', 'loopback', '--no-symkey-cache', '--passphrase-file', $pass, '--symmetric', '--cipher-algo', 'AES256',
            '--compress-algo', 'none']), $dest->ssh($d, ['put', $name])], '/dev/null', $out, 2);
        $said = (string) @file_get_contents($out);
        @unlink($out);
        @unlink($dest->path(self::LIST_NAME));
        list($tc, $zc, $gc, $sc) = $r['codes'];
        $got = preg_match('/^received\s+(\d+)$/m', $said, $m) ? (int) $m[1] : null;
        // ssh first: when the upload dies, the stages before it fail on the broken pipe, and that is not their fault.
        $why = $sc !== 0 || $got === null ? 'the upload failed (exit ' . $sc . '): ' . RemoteDest::sshTail($r['err'][3]) : ($tc > 1
            ? 'tar failed: ' . $r['err'][0] : ($zc !== 0 ? 'pigz failed: ' . $r['err'][1] : ($gc !== 0 ? 'gpg failed: ' . $r['err'][2]
            : ($got !== $r['bytes'] ? "the backup machine stored $got bytes of {$r['bytes']}" : ''))));
        if ($why === '') {
            list($cc, $cout, $cerr) = $dest->call($d, ['commit', $name, (string) $got]);
            $why = $cc === 0 && strpos($cout, "committed $name") !== false ? '' : 'the backup machine did not keep it: ' . RemoteDest::sshTail($cerr);
        }
        $rec = ['name' => $name, 'mode' => $mode, 'bytes' => $r['bytes'], 'estimated_bytes' => $est === null ? null : $est * 1024,
            'files_changed_while_read' => $tc === 1, 'file_list_complete' => $listed, 'ok' => $why === '', 'at' => time()];
        if ($why === '') {
            $dest->save('state.json', ['last_run' => $rec] + $dest->state());
        } else {
            throw new CliError(ToolText::tail($why) . '; no archive was kept', 'run again; `stats` shows the last run');
        }
        return ['records' => [$rec + ['host' => $d['host']]], 'status' => ['verified' => true]];
    }

    /** Meta mode's paths that exist, relative to the home (./x), a path inside another listed one dropped. */
    public static function metaPaths($home, $localDir)
    {
        $want = array_merge(self::PMSS_SET, self::META_MORE);
        foreach ((array) glob($home . '/.[!.]*') as $f) {
            if (is_file($f) || is_link($f)) {
                $want[] = PathName::base($f);
            }
        }
        if (HomePath::inside($localDir, $home) && $localDir !== $home) {
            $want[] = substr($localDir, strlen(rtrim($home, '/')) + 1);
        }
        $keep = [];
        foreach (array_unique($want) as $p) {
            if (file_exists("$home/$p") || is_link("$home/$p")) {
                $keep[] = $p;
            }
        }
        return array_values(array_map(function ($p) {
            return "./$p";
        }, array_filter($keep, function ($p) use ($keep) {
            foreach ($keep as $q) {
                if ($q !== $p && strpos($p, "$q/") === 0) {
                    return false;
                }
            }
            return true;
        })));
    }

    private static function excludes($home, $localDir, $mode)
    {
        $local = HomePath::inside($localDir, $home) && $localDir !== $home ? './' . substr($localDir, strlen(rtrim($home, '/')) + 1) : null;
        return array_merge(self::EXCLUDE, $local !== null ? ["$local/secrets", "$local/logs"] : [], $mode === 'meta' ? self::META_EXCLUDE : []);
    }

    /** KiB the paths hold (du, at most 2 minutes), or null when du did not finish. */
    private static function estimate($home, array $paths, array $excl)
    {
        $du = array_merge(['du', '-sxkc'], array_map(function ($p) {
            return "--exclude=$p";
        }, $excl), ['--'], $paths);
        $r = ToolProc::run(ToolProc::which('timeout') ? array_merge(['timeout', '120'], $du) : $du, [], null, $home);
        return preg_match('/^(\d+)\s+total$/m', $r['out'], $m) && $r['code'] !== 124 ? (int) $m[1] : null;
    }

    /** The whole home's file list (mode, owner, bytes, mtime, path), gzipped, at most 10 minutes; true when complete. */
    private static function fileList($home, $file, $localDir)
    {
        $prune = ['(', '-path', './.cache'];
        if (HomePath::inside($localDir, $home) && $localDir !== $home) {
            $prune = array_merge($prune, ['-o', '-path', './' . substr($localDir, strlen(rtrim($home, '/')) + 1) . '/secrets']);
        }
        $find = array_merge(['find', '.', '-xdev'], $prune, [')', '-prune', '-o', '-printf', '%m\t%u\t%s\t%T@\t%p\n']);
        $r = RemotePipe::run([ToolProc::which('timeout') ? array_merge(['timeout', '600'], $find) : $find, ['pigz', '-c']], '/dev/null', $file, -1, [], $home);
        return $r['codes'] === [0, 0];
    }
}

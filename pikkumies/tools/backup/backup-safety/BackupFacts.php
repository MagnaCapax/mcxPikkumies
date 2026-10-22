<?php
/**
 * BackupFacts: the facts of each backup method of this account, for BackupStatus::judge() (ADR-0099): restic and borg
 * through their units' own classes (snapshots, sizes, a metadata `check` when the last one is over 7 days old), backup
 * remote through its local records, the receiver's listing and the record of its own `check` (the newest archive decrypted
 * and listed; run on request, never by the refresh), and other tools from crontab lines and config files.
 * The system crontabs and config files under /etc count only when this account has root (Detect::rootAccess: direct as
 * uid 0, or passwordless sudo): on a shared server they are the provider's jobs, not this account's backups.
 * Nothing here prompts; a repository without a stored login is reported, never logged into.
 */
class BackupFacts
{
    /** @var callable|null test seam: fn() => crontab text of this account, or null */
    public static $crontab = null;

    /**
     * Every method found. $prev: the last cache (its verified_at is reused while it is under 7 days old). $verify false skips
     * the repository checks, so a first pass can be cached before a slow check runs out of time.
     */
    public static function gather($localDir, $home, array $root, $prev, $now, $verify = true)
    {
        $old = [];
        foreach (is_array($prev) && isset($prev['methods']) ? (array) $prev['methods'] : [] as $m) {
            $old[$m['kind'] . "\0" . (isset($m['where']) ? $m['where'] : '')] = $m;
        }
        $methods = [];
        foreach (['restic', 'borg'] as $kind) {
            $m = self::snapshotTool($kind, $localDir, $old, $now, $verify);
            if ($m !== null) {
                $methods[] = $m;
            }
        }
        $m = self::remote($localDir, $now);
        if ($m !== null) {
            $methods[] = $m;
        }
        $cron = self::$crontab !== null ? call_user_func(self::$crontab) : self::userCrontab();
        $methods = array_merge($methods, BackupStatus::thirdParty((string) $cron, 'your crontab'));
        $system = !empty($root['available']);
        if ($system) {
            foreach (array_merge(['/etc/crontab'], (array) glob('/etc/cron.d/*')) as $f) {
                if (is_file($f) && is_readable($f) && filesize($f) < 262144) {
                    $methods = array_merge($methods, BackupStatus::thirdParty((string) @file_get_contents($f), $f));
                }
            }
        }
        $files = array_values(array_filter(BackupStatus::CONFIG_FILES, function ($f) use ($system) {
            return $system || $f[0] === '~';
        }));
        return array_merge($methods, BackupStatus::configFiles($home, $files));
    }

    /** restic or borg: null when no repository is saved. */
    private static function snapshotTool($kind, $localDir, array $old, $now, $verify)
    {
        $store = new BackupStore($kind, "$localDir/secrets", $localDir);
        if ($store->repo() === null) {
            return null;
        }
        $script = dirname(__DIR__) . "/$kind/$kind.php";
        $m = ['kind' => $kind, 'where' => ToolText::redact($store->repo()), 'latest_at' => null, 'latest_bytes' => null, 'prev_bytes' => null,
            'verified_at' => null, 'verify_ok' => null, 'error' => null, 'backups' => []];
        if (!$store->loggedIn()) {
            return ['error' => "the $kind repository has no stored password; the user runs `php $script login --repo ...` in a terminal"] + $m;
        }
        try {
            $tool = $kind === 'restic' ? new ResticTool($store, $script) : new BorgTool($store, $script);
            $rows = BackupRules::newestFirst($tool->snapshots(), null);
            $sizes = $kind === 'restic' ? self::resticSizes($store, $script) : [];
            foreach (array_slice($rows, 0, 10) as $i => $s) {
                $bytes = isset($sizes[$s['snapshot_id']]) ? $sizes[$s['snapshot_id']] : null;
                if ($kind === 'borg' && $i < 2) {
                    $d = $tool->details($s['id']);
                    $bytes = isset($d['stats']['original_size']) ? (int) $d['stats']['original_size'] : null;
                }
                $m['backups'][] = ['name' => $s['id'], 'time' => (int) $s['time'], 'bytes' => $bytes];
            }
            if ($m['backups']) {
                $m['latest_at'] = $m['backups'][0]['time'];
                $m['latest_bytes'] = $m['backups'][0]['bytes'];
                $m['prev_bytes'] = isset($m['backups'][1]) ? $m['backups'][1]['bytes'] : null;
            }
            $was = isset($old[$kind . "\0" . $m['where']]) ? $old[$kind . "\0" . $m['where']] : [];
            if (isset($was['verified_at']) && $now - (int) $was['verified_at'] <= BackupStatus::VERIFY && (!isset($was['verify_ok']) || $was['verify_ok'] !== false)) {
                return ['verified_at' => (int) $was['verified_at'], 'verify_ok' => true] + $m;
            }
            if (!$verify) {
                return $m;
            }
            $c = $tool->check(false, null);
            if ($c['errors_found']) {
                return ['verify_ok' => false, 'verified_at' => $now] + $m;
            }
            return $c['finished'] ? ['verify_ok' => true, 'verified_at' => $now] + $m : ['check_note' => 'the check did not finish: ' . $c['output']] + $m;
        } catch (CliError $e) {
            return ['error' => $e->getMessage()] + $m;
        }
    }

    /** snapshot_id => bytes from restic's own summary (restic 0.17 and newer write one; older ones none). */
    private static function resticSizes(BackupStore $store, $script)
    {
        $out = [];
        foreach ((new ResticRepo($store, $script))->json(['snapshots', '--json']) as $s) {
            if (isset($s['id'], $s['summary']['total_bytes_processed'])) {
                $out[(string) $s['id']] = (int) $s['summary']['total_bytes_processed'];
            }
        }
        return $out;
    }

    /** backup remote: null when no destination is set up. */
    private static function remote($localDir, $now)
    {
        $dest = new RemoteDest($localDir);
        if (!is_file($dest->path('dest.json'))) {
            return null;
        }
        $m = ['kind' => 'remote', 'where' => null, 'latest_at' => null, 'latest_bytes' => null, 'prev_bytes' => null, 'verified_at' => null,
            'verify_ok' => null, 'error' => null, 'backups' => [], 'interval' => is_file("$localDir/secrets/cron/" . RemoteOps::TAG_NAME . '.cron') ? 86400 : 0];
        try {
            $d = $dest->load();
            $m['where'] = $d['user'] . '@' . $d['host'];
            $s = $dest->state();
            $m['passphrase_saved_by_user'] = $dest->passphraseConfirmed(); // custody only: never part of the verdict (ADR-0099)
            $last = isset($s['last_run']) && is_array($s['last_run']) ? $s['last_run'] : null;
            if ($last !== null && empty($last['ok'])) {
                $m['error'] = 'the last run failed: ' . (isset($last['error']) ? $last['error'] : 'no reason recorded');
            }
            $rows = RemoteOps::listing($dest);
            foreach (array_slice($rows, 0, 10) as $r) {
                $m['backups'][] = ['name' => $r['name'], 'time' => $r['time'], 'bytes' => $r['bytes']];
            }
            if ($rows) {
                $m['latest_at'] = $rows[0]['time'];
                $m['latest_bytes'] = $rows[0]['bytes'];
                foreach (array_slice($rows, 1) as $r) { // the one before of the same mode: meta and full differ by design
                    if ($r['mode'] === $rows[0]['mode']) {
                        $m['prev_bytes'] = $r['bytes'];
                        break;
                    }
                }
            }
            // Verified only by `check` (the archive decrypted and listed); a run's matching byte count says nothing about decrypting.
            $c = isset($s['last_check']) && is_array($s['last_check']) ? $s['last_check'] : null;
            $recent = $c !== null && !empty($c['checked']) && isset($c['verified_at']) && $now - (int) $c['verified_at'] <= BackupStatus::VERIFY;
            // `check` streams and decrypts the newest archive (up to 10 GiB): never inside the 120 s background refresh, where
            // it was cut off and started again every day. It runs on request (`backup remote check`); its record is read here.
            if ($c !== null && !empty($c['checked']) && isset($c['verified_at'])) {
                $m['verified_at'] = (int) $c['verified_at'];
                $m['verify_ok'] = !empty($c['verify_ok']);
                $m['check_note'] = isset($c['error']) ? (string) $c['error'] : null;
            } elseif ($c !== null && isset($c['why'])) {
                $m['check_note'] = (string) $c['why']; // no archive small enough to check: unknown, not broken
            }
            if (!$recent && $rows) {
                $m['check_note'] = 'not checked in the last 7 days; run `backup remote check` to check the newest archive';
            }
        } catch (CliError $e) {
            $m['error'] = $m['error'] !== null ? $m['error'] : $e->getMessage();
        }
        return $m;
    }

    /** This account's crontab, '' when there is none, null when it cannot be read. */
    private static function userCrontab()
    {
        list($code, $out, $err) = Update::exec(['crontab', '-l']);
        return $code === 0 ? $out : (stripos($err . $out, 'no crontab') !== false ? '' : null);
    }
}

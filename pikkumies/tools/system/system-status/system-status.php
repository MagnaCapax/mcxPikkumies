<?php
/**
 * system-status: read-only snapshot of this machine as the agent sees it (ADR-0015 tool unit).
 * show: the cached classification (host family, stack, root access, tier, ADR-0023) plus this moment's own limits,
 * read live through the same sources the cache recorded, with the limit closest to its own cap listed first (round 28
 * of the vplan: answer "can we increase performance" with the real bottleneck, not whichever key happens to be
 * declared first). The own running apps are read now, never from the cache; the server's totals are labelled as the
 * whole server's (ADR-0058). account: this account's own figures in one live record (StatusAccount, ADR-0058). No
 * writes but Detect::cached()'s own cache, no network.
 */
require dirname(__DIR__, 3) . '/lib/Detect.php';
require_once dirname(__DIR__, 3) . '/lib/Cgroup.php'; // Detect.php already require_once's this; once here too, so a direct `php system-status.php` still finds the class
require_once dirname(__DIR__, 3) . '/lib/Config.php'; // Detect.php already require_once's this; once here too, for the same reason
require dirname(__DIR__, 3) . '/lib/Cli.php';
require dirname(__DIR__, 3) . '/lib/CliHuman.php';
require_once __DIR__ . '/StatusAccount.php';

// Test seams: a test may set these before including the file. Nothing is read from the environment.
$systemStatusLocalDir = isset($systemStatusLocalDir) ? $systemStatusLocalDir : Config::localDir();
$systemStatusProcDir = isset($systemStatusProcDir) ? $systemStatusProcDir : '/proc';
$systemStatusUid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();

Cli::run($argv, [
    'show' => function () use ($systemStatusLocalDir, $systemStatusProcDir, $systemStatusUid) {
        $profile = Detect::cached(false, $systemStatusLocalDir . '/host.json', $systemStatusLocalDir . '/update.log');
        // ADR-0023: the account's and the server's names and reverse DNS stay in local/host.json; this record goes to the model.
        unset($profile['identity'], $profile['fingerprint'], $profile['user']);
        $home = (string) getenv('HOME');
        $limits = Detect::limits($profile['family'], $home, !empty($profile['tenancy']['shared']));
        $profile['limits'] = Detect::leadingLimit($limits, Cgroup::memoryCurrent());
        $profile['apps'] = Detect::runningApps($systemStatusUid, $systemStatusProcDir);
        // The cached resources were frozen at detection: their disk is an old quota snapshot (limits.disk is live) and
        // their CPU, memory and /home totals are the whole server's, never this account's ceiling.
        $server = isset($profile['resources']) && is_array($profile['resources']) ? $profile['resources'] : [];
        unset($profile['resources'], $server['disk']);
        $profile['server_totals'] = ['scope' => !empty($profile['tenancy']['shared']) ? 'whole server, shared by all accounts' : 'whole machine'] + $server;
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [];
        $profile['load'] = $load ? array_map(function ($v) {
            return round($v, 2);
        }, $load) : null;
        return [$profile];
    },
    'account' => function () use ($systemStatusLocalDir, $systemStatusProcDir, $systemStatusUid) {
        $profile = Detect::cached(false, $systemStatusLocalDir . '/host.json', $systemStatusLocalDir . '/update.log');
        // Only on PMSS, the same rule Detect::limits() follows: another host's ~/bin/userShowLimits is not this tool.
        $usl = $profile['family'] === 'pmss' ? Detect::userShowLimitsData((string) getenv('HOME')) : null;
        return [StatusAccount::record($usl, $profile['family'], Detect::runningApps($systemStatusUid, $systemStatusProcDir))];
    },
    // The account's own log files this host family/stack is known to keep (Host::TABLE logGlobs,
    // ADR-0023), optionally searched. Only files under $HOME that this account owns, after
    // resolving links, so a link can never point the search at another account's files.
    'logs' => function ($args, $opt) use ($systemStatusLocalDir) {
        $profile = Detect::cached(false, $systemStatusLocalDir . '/host.json', $systemStatusLocalDir . '/update.log');
        $home = realpath((string) getenv('HOME'));
        if ($home === false) {
            throw new CliError('HOME is not set or does not exist', 'run this as the account whose logs you want');
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $needle = isset($opt['grep']) && $opt['grep'] !== true ? (string) $opt['grep'] : null;
        $records = [];
        $left = 50; // matching lines over all files
        foreach (Host::logGlobs($profile['family'], $profile['stack']) as $glob) {
            foreach ((array) glob($home . '/' . $glob) as $path) {
                $real = realpath($path);
                if ($real === false || strpos($real, $home . '/') !== 0 || !is_file($real) || @fileowner($real) !== $uid) {
                    continue;
                }
                $rec = ['path' => substr($real, strlen($home) + 1), 'bytes' => filesize($real), 'modified' => gmdate('c', filemtime($real))];
                if ($needle !== null) {
                    $rec['matches'] = [];
                    $fh = @fopen($real, 'r');
                    if ($fh !== false) {
                        fseek($fh, max(0, $rec['bytes'] - 1048576)); // only the last 1 MiB of each file
                        while ($left > 0 && ($line = fgets($fh)) !== false) {
                            if (stripos($line, $needle) !== false) {
                                $rec['matches'][] = substr(rtrim($line), 0, 300);
                                $left--;
                            }
                        }
                        fclose($fh);
                    }
                }
                $records[] = $rec;
            }
        }
        return $records;
    },
], "usage: system-status.php show|account|logs [--grep TEXT] [--human]\n  show     one record: cached classification (host family, stack, tier, root access), own running apps (live), live limits (CPU, memory, disk), load, the server totals (labelled)\n  account  one record of this account's own figures, live: disk quota, memory, upload traffic, processes, CPU quota, versions, bonus, own apps (PMSS userShowLimits; null elsewhere)\n  logs     this account's own log files known for this host (path relative to HOME, size, modified); --grep TEXT returns matching lines (last 1 MiB of each file, 50 lines in all)", [], ['grep']);

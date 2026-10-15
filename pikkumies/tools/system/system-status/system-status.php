<?php
/**
 * system-status: read-only snapshot of this machine as the agent sees it (ADR-0015 tool unit).
 * The cached classification (host family, stack, root access, own running apps, tier, ADR-0023)
 * plus this moment's own limits, read live through the same sources the cache recorded, with the
 * limit closest to its own cap listed first (round 28 of the vplan: answer "can we increase
 * performance" with the real bottleneck, not whichever key happens to be declared first). No
 * writes, no network.
 */
require dirname(__DIR__, 3) . '/lib/Detect.php';
require_once dirname(__DIR__, 3) . '/lib/Cgroup.php'; // Detect.php already require_once's this; once here too, so a direct `php system-status.php` still finds the class
require dirname(__DIR__, 3) . '/lib/Cli.php';
require dirname(__DIR__, 3) . '/lib/CliHuman.php';

Cli::run($argv, [
    'show' => function () {
        $profile = Detect::cached();
        // ADR-0023: hostnames and reverse DNS stay in local/host.json; this record goes to the model.
        unset($profile['identity'], $profile['fingerprint']);
        $home = (string) getenv('HOME');
        $limits = Detect::limits($profile['family'], $home, !empty($profile['tenancy']['shared']));
        $profile['limits'] = Detect::leadingLimit($limits, Cgroup::memoryCurrent());
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : [];
        $profile['load'] = $load ? array_map(function ($v) {
            return round($v, 2);
        }, $load) : null;
        return [$profile];
    },
    // The account's own log files this host family/stack is known to keep (Host::TABLE logGlobs,
    // ADR-0023), optionally searched. Only files under $HOME that this account owns, after
    // resolving links, so a link can never point the search at another account's files.
    'logs' => function ($args, $opt) {
        $profile = Detect::cached();
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
], "usage: system-status.php show|logs [--grep TEXT] [--human]\n  show  one record: cached classification (host family, stack, tier, root access), own running apps, live limits (CPU, memory, disk), load\n  logs  this account's own log files known for this host (path relative to HOME, size, modified); --grep TEXT returns matching lines (last 1 MiB of each file, 50 lines in all)", [], ['grep']);

<?php
/**
 * DockerRecord: `show <container> --record <dir>` (ADR-0034, amendment of 2026-10-19). Writes what `show` prints
 * (a fixed list of fields: env NAMES only, never a value, never the command or its arguments) to <dir>/<name>.json,
 * mode 600 in a folder of mode 700, plus `recreate`, the launch-plan line that brings back what launch can make, and
 * `recreate_gaps`, what that line cannot carry. A record already there with other settings is renamed to
 * <name>.json.<its recorded_at>, never overwritten, so a re-run never loses the only copy of an earlier setting.
 * <dir> must be inside the home and outside Pikkumies' own tracked files (its local/ folder is fine).
 */
class DockerRecord
{
    /** Writes the record; returns the fields show adds: recorded_to, earlier_kept (path or null). */
    public static function write(DockerCli $d, array $inspect, array $shown, $dir)
    {
        $dir = self::folder($d, $dir);
        $file = $dir . '/' . $shown['name'] . '.json';
        $record = ['kind' => 'container-record', 'version' => 1, 'recorded_at' => gmdate('Y-m-d\TH:i:s\Z')] + $shown + self::recreate($d, $inspect, $shown);
        $kept = null;
        clearstatcache(true, $file);
        $st = @lstat($file);
        if ($st !== false) {
            if (($st['mode'] & 0170000) !== 0100000 || (int) $st['uid'] !== $d->uid) {
                throw new CliError("nothing written: $file exists and is not this account's own regular file", 'record into another folder, or move that file away');
            }
            $old = json_decode((string) @file_get_contents($file), true);
            if (!is_array($old) || self::settings($old) !== self::settings($record)) {
                $stamp = is_array($old) && isset($old['recorded_at']) && preg_match('/^[0-9TZ:-]{20}$/', (string) $old['recorded_at'])
                    ? str_replace(':', '', (string) $old['recorded_at']) : gmdate('Y-m-d\THis\Z', (int) $st['mtime']);
                $kept = "$file.$stamp";
                if (file_exists($kept) || !@rename($file, $kept)) {
                    throw new CliError("nothing written: the earlier record $file could not be kept as $kept", 'move one of them away and record again');
                }
            }
        }
        DockerDiagnose::writeFile($file, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        return ['recorded_to' => $file, 'earlier_kept' => $kept, 'recreate' => $record['recreate'], 'recreate_gaps' => $record['recreate_gaps']];
    }

    /** The settings alone (no timestamp, no run state): a stop, a start or a restart is not a new record. */
    private static function settings(array $r)
    {
        foreach (['recorded_at', 'state', 'running', 'exit_code', 'started_at', 'finished_at', 'restart_count', 'error_text', 'oom', 'health'] as $k) {
            unset($r[$k]);
        }
        ksort($r);
        return json_encode($r);
    }

    /**
     * A folder for this unit's files (also dump's; $what names the option in errors): ~/ expanded, inside the home,
     * not the home itself, not in Pikkumies' tracked tree (its local/ is fine); created with mode 700 when missing.
     */
    public static function folder(DockerCli $d, $dir, $what = '--record')
    {
        $dir = is_string($dir) ? $dir : ''; // a bare --record (no folder) arrives as true
        if (strncmp($dir, '~/', 2) === 0) {
            $dir = $d->home . substr($dir, 1);
        }
        if ($dir === '' || $dir[0] !== '/' || strpbrk($dir, "\n\r\0") !== false) {
            throw new CliError("$what takes a folder: an absolute path or one starting with ~/; nothing was written", 'e.g. ~/docker/records');
        }
        $probe = rtrim($dir, '/');
        while ($probe !== '' && !file_exists($probe)) {
            $probe = dirname($probe) === $probe ? '' : dirname($probe);
        }
        $real = $probe === '' ? false : realpath($probe);
        $tail = $real === false ? '' : substr(rtrim($dir, '/'), strlen($probe));
        $target = $real === false ? '' : rtrim($real . $tail, '/');
        $root = realpath(Config::root());
        $local = realpath((string) $d->seams['local_dir']);
        $home = DockerDiagnose::home($d);
        if ($target === '' || $target === $home || !DockerDiagnose::inside($target, $home) || strpos($tail, '/..') !== false
            || ($root !== false && DockerDiagnose::inside($target, $root) && !($local !== false && DockerDiagnose::inside($target, $local)))) {
            throw new CliError("$what needs a folder inside the home (not the home itself, not Pikkumies' own files); nothing was written", 'e.g. a folder under ~/docker');
        }
        if (!is_dir($target) && !@mkdir($target, 0700, true) && !is_dir($target)) {
            throw new CliError("cannot create $target; nothing was written", 'check that the folder above it is writable by this account');
        }
        return $target;
    }

    /** ['recreate' => launch-plan line or null, 'recreate_gaps' => what that line cannot bring back]. */
    private static function recreate(DockerCli $d, array $i, array $shown)
    {
        $gaps = [];
        $words = ['php', __DIR__ . '/docker.php', 'launch-plan', '--image=' . $shown['image'], '--name=' . $shown['name']];
        $ports = [];
        $public = [];
        foreach ($shown['ports'] as $p) {
            if (!preg_match('#^(\d+)/tcp$#', (string) $p['container_port'], $m)) {
                $gaps[] = "port {$p['container_port']}: launch publishes tcp ports only";
                continue;
            }
            $ports[] = $p['host_port'] . ':' . $m[1];
            $public[] = !DockerExposure::isLoopback($p['host_ip']);
        }
        if ($ports) {
            $words[] = '--port=' . implode(',', $ports);
        }
        if (in_array(true, $public, true)) {
            $words[] = '--public';
            if (in_array(false, $public, true)) {
                $gaps[] = 'some ports are public and some on 127.0.0.1: launch publishes all of a container\'s ports the same way';
            }
        }
        $vols = [];
        foreach ($shown['mounts'] as $m) {
            if ($m['type'] === 'bind' && $m['source'] !== null && $m['destination'] !== null) {
                $vols[] = $m['source'] . ':' . $m['destination'] . ($m['rw'] === false ? ':ro' : '');
            } else {
                $gaps[] = 'mount on ' . $m['destination'] . ' is a ' . $m['type'] . ($m['volume'] !== null ? " (volume {$m['volume']})" : '') . ': launch mounts folders only, and this record does not hold its data';
            }
        }
        if ($vols) {
            $words[] = '--volume=' . implode(',', $vols);
        }
        $img = self::image($d, $i);
        $own = array_values(array_diff($shown['env_names'], $img !== null && isset($img['Config']['Env']) && is_array($img['Config']['Env'])
            ? array_map(function ($e) {
                return explode('=', (string) $e, 2)[0];
            }, $img['Config']['Env']) : []));
        if ($shown['env_file'] !== null) {
            $words[] = '--env-file=' . $shown['env_file'];
        } elseif ($own && !array_diff($own, ['PUID', 'PGID'])) {
            // launch sets PUID/PGID itself for a LinuxServer.io image on a rootless daemon
        } elseif ($own) {
            $gaps[] = 'environment set without an env file (names: ' . implode(', ', $own) . '); values are never recorded: put them in an env file (chmod 600) and add --env-file';
        }
        foreach (['Cmd', 'Entrypoint'] as $k) {
            $mine = isset($i['Config'][$k]) ? $i['Config'][$k] : null;
            if ($img === null || !array_key_exists($k, isset($img['Config']) && is_array($img['Config']) ? $img['Config'] : [])) {
                $gaps[] = strtolower($k) . ': not recorded (it can hold passwords), and whether it differs from the image\'s is unknown';
                break;
            }
            if ($mine != $img['Config'][$k] && !(empty($mine) && empty($img['Config'][$k]))) {
                $gaps[] = strtolower($k) . ': the container was started with its own, which is not recorded (it can hold passwords)';
            }
        }
        if ($shown['restart_policy'] !== null && $shown['restart_policy'] !== 'unless-stopped') {
            $gaps[] = "restart policy {$shown['restart_policy']}: launch uses unless-stopped";
        }
        foreach (['user' => 'runs as user ' . $shown['user'], 'privileged' => 'runs privileged', 'cap_add' => 'adds kernel capabilities'] as $k => $why) {
            if (!empty($shown[$k])) {
                $gaps[] = "$why: launch never does";
            }
        }
        if (!in_array($shown['network_mode'], [null, '', 'default', 'bridge'], true)) {
            $gaps[] = "network {$shown['network_mode']}: launch uses the default network";
        }
        foreach ($shown['labels_text'] as $l) {
            if (strpos($l, 'com.docker.compose.project=') === 0) {
                $gaps[] = 'started by docker compose: bring it back with its compose file (`diagnose --compose <file>` first)';
                break;
            }
        }
        return ['recreate' => DockerRefs::validImage($shown['image']) && DockerRefs::validName($shown['name']) ? implode(' ', array_map(['DockerLaunch', 'word'], $words)) : null,
            'recreate_gaps' => $gaps];
    }

    /** The image's own settings (`image inspect` of the container's image ID), or null. */
    private static function image(DockerCli $d, array $i)
    {
        $id = isset($i['Image']) ? (string) $i['Image'] : '';
        if (!preg_match('/^sha256:[0-9a-f]{64}$/', $id)) {
            return null;
        }
        $rows = DockerCli::jsonLines(DockerOps::call($d, ['image', 'inspect', '--format', '{{json .}}', $id], DockerCli::PROBE_SECONDS)['out']);
        return $rows ? $rows[0] : null;
    }
}

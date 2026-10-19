<?php
/**
 * DockerNetwork: `diagnose --network` (ADR-0034, amendment of 2026-10-19). Why a container cannot be reached or
 * cannot reach out, from what can be read without changing anything and without pulling or running anything:
 * - this account's own processes in /proc first: rootlesskit's network options (--net, --port-driver, --mtu,
 *   --disable-host-loopback) and whether the network helper it names (slirp4netns, pasta, vpnkit) is alive. Without
 *   the helper a rootless daemon's containers have no network at all, while the daemon itself still answers.
 * - every running container's published ports against /proc/net/tcp (Host::ownsListener): docker can say a port is
 *   published while nothing of this account listens on it (the port forwarder is gone), or another account holds it.
 * - the daemon's networks (`docker network ls`).
 * Route, DNS and speed checks of the server itself belong to the performance tools, not here. Reads only.
 * Seams (DockerCli's, plus): listener_tables (Host::ownsListener).
 */
class DockerNetwork
{
    /** Network helper processes a rootlesskit --net value names (pasta's program may be pasta.avx2 or passt). */
    const HELPER = ['slirp4netns' => '/^slirp4netns$/', 'pasta' => '/^(pasta|passt)(\.|$)/', 'vpnkit' => '/^vpnkit$/'];

    public static function network(DockerCli $d)
    {
        $a = $d->absence();
        if ($a['state'] !== 'ok') {
            return ['records' => [DockerDiagnose::finding('daemon', ['state' => $a['state'], 'reason' => $a['reason'], 'next_step' => $a['hint']], 0, 'critical')],
                'status' => ['daemon' => $a['state']]];
        }
        $stack = self::stack($d);
        $probe = $d->probe();
        $ladder = DockerCli::ladder($d->observe($probe));
        $recs = [];
        $summary = ['kind' => 'network-stack', 'daemon' => $ladder['state']] + $stack;
        if ($stack['helper'] !== null && $stack['helper_running'] === false) {
            $recs[] = DockerDiagnose::finding('network-helper-missing', ['helper' => $stack['helper'],
                'next_step' => 'the daemon\'s network helper (' . $stack['helper'] . ') is gone, so containers have no network: '
                    . ($d->pmss() ? 'the platform runs the daemon: tell support; do not stop or restart it yourself' : 'restart the user\'s own daemon the way it was started (every container stops; a --rm container is deleted)')]);
        }
        if ($stack['host_loopback'] === 'disabled') {
            $recs[] = DockerDiagnose::finding('host-loopback-disabled', ['next_step' => 'containers cannot reach this server\'s 127.0.0.1 (a torrent client\'s web UI there, for one): connect to the server\'s own address instead, or publish the other side as a container']);
        }
        if (!$probe['ok']) {
            $recs[] = DockerDiagnose::finding('daemon', ['state' => $ladder['state'], 'next_step' => $ladder['next_step']], 0, 'critical');
            return ['records' => array_merge([$summary], DockerDiagnose::sortFindings($recs)), 'status' => ['daemon' => $ladder['state']]];
        }
        $tables = isset($d->seams['listener_tables']) ? $d->seams['listener_tables'] : null;
        $facts = DockerDiagnose::exposureFacts($d);
        $checked = 0;
        $unknown = false;
        foreach (DockerOps::inventory($d, ['ps', '--format', '{{json .}}']) as $row) {
            $i = $row['inspect'];
            if (!$i) {
                continue;
            }
            $who = ['name' => DockerOps::nameOf($i), 'id' => substr((string) $i['Id'], 0, 12)];
            if (isset($i['HostConfig']['NetworkMode']) && $i['HostConfig']['NetworkMode'] === 'none') {
                $recs[] = DockerDiagnose::finding('no-network', $who + ['next_step' => 'started with --network none: it has no network on purpose']);
            }
            foreach (DockerExposure::ports($i, $facts) as $p) {
                $ip = trim((string) $p['host_ip'], '[]');
                $wild = in_array($ip, ['', '0.0.0.0', '::'], true) || DockerExposure::isLoopback($ip);
                $owner = Host::ownsListener($p['host_port'], $d->uid, $tables, $wild ? [] : [$ip]);
                $checked++;
                if ($owner === 'unknown') {
                    $unknown = true;
                } elseif ($owner === 'none') {
                    $recs[] = DockerDiagnose::finding('port-not-listening', $who + ['port' => $p, 'next_step' => 'docker publishes this port but nothing of this account listens on it: the daemon\'s port forwarder is not working; `diagnose` for the daemon, then '
                        . ($d->pmss() ? 'support' : 'restart the user\'s own daemon')]);
                } elseif ($owner === 'other') {
                    $recs[] = DockerDiagnose::finding('port-taken', $who + ['port' => $p, 'next_step' => 'another account listens on this port, so connections reach its program, not this container: launch it again on another port']);
                }
            }
        }
        $summary['ports_checked'] = $unknown ? null : $checked;
        $nets = DockerOps::call($d, ['network', 'ls', '--format', '{{json .}}']);
        $own = [];
        foreach ($nets['code'] === 0 ? DockerCli::jsonLines($nets['out']) : [] as $n) {
            if (isset($n['Name']) && !in_array($n['Name'], ['bridge', 'host', 'none'], true)) {
                $own[] = (string) $n['Name'];
            }
        }
        $summary['networks'] = $nets['code'] === 0 ? $own : null;
        return ['records' => array_merge([$summary], DockerDiagnose::sortFindings($recs)), 'status' => ['daemon' => $ladder['state'], 'problems' => count($recs)]];
    }

    /**
     * From this account's own processes: ['rootless_net' => --net value|null, 'port_driver', 'mtu', 'host_loopback'
     * (disabled|enabled|null), 'helper' (the helper --net names)|null, 'helper_running' bool|null]. Only these options
     * are read; nothing else of a command line is printed.
     */
    public static function stack(DockerCli $d)
    {
        $out = ['rootless_net' => null, 'port_driver' => null, 'mtu' => null, 'host_loopback' => null, 'helper' => null, 'helper_running' => null];
        $exes = [];
        $kit = null;
        foreach ((array) glob(rtrim((string) $d->seams['proc_root'], '/') . '/[0-9]*', GLOB_ONLYDIR) as $dir) {
            if (@fileowner($dir) !== $d->uid) {
                continue;
            }
            $argv = explode("\0", rtrim((string) @file_get_contents("$dir/cmdline"), "\0"));
            $exe = basename((string) $argv[0]);
            $exes[] = $exe;
            if ($exe === 'rootlesskit' && $kit === null && preg_grep('/^--net(=|$)/', $argv)) {
                $kit = $argv;
            }
        }
        if ($kit === null) {
            return $out;
        }
        $opt = function ($name) use ($kit) {
            foreach ($kit as $n => $a) {
                if (strpos($a, "--$name=") === 0) {
                    return substr($a, strlen($name) + 3);
                }
                if ($a === "--$name" && isset($kit[$n + 1])) {
                    return $kit[$n + 1];
                }
            }
            return null;
        };
        $out['rootless_net'] = $opt('net');
        $out['port_driver'] = $opt('port-driver');
        $mtu = $opt('mtu');
        $out['mtu'] = $mtu !== null && ctype_digit($mtu) ? (int) $mtu : null;
        $out['host_loopback'] = in_array('--disable-host-loopback', $kit, true) ? 'disabled' : 'enabled';
        if (isset(self::HELPER[$out['rootless_net']])) {
            $out['helper'] = $out['rootless_net'];
            $out['helper_running'] = (bool) preg_grep(self::HELPER[$out['rootless_net']], $exes);
        }
        return $out;
    }
}

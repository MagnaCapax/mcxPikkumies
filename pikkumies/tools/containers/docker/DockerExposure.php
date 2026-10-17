<?php
/**
 * DockerExposure: who can reach a published port (ADR-0034), the one classifier `list`, `diagnose` and
 * `launch-plan` share. Facts only, never a verdict on the app's login:
 * - public: bound to every interface ('' / 0.0.0.0 / ::, docker's default for `-p 8080:80`) or to a
 *   non-loopback address, and not dropped by the provider's firewall: the internet can reach it.
 * - local-shared: loopback (127.0.0.0/8, ::1) on a server shared with other accounts, or every interface on a
 *   port the provider drops from outside (PMSS 8080, 8112): every account on the server can reach it.
 * - none: not published, or loopback on a server this account has to itself.
 * Facts: ['shared' => bool, 'inbound_dropped' => [ports]] (Host::TABLE `docker` and Detect's tenancy).
 */
class DockerExposure
{
    const SENTENCE = [
        'public' => 'reachable from the internet: the app\'s own login must be set before it starts',
        'local-shared' => 'reachable by every account on this server (127.0.0.1 is shared): the app\'s own login must be set',
        'none' => 'reachable only from this account',
    ];

    /** Facts for this host: shared tenancy and the provider's dropped inbound ports. */
    public static function facts($shared, $dockerFacts = null)
    {
        return ['shared' => (bool) $shared, 'inbound_dropped' => isset($dockerFacts['inbound_dropped']) ? array_map('intval', (array) $dockerFacts['inbound_dropped']) : []];
    }

    /** public | local-shared | none for one binding ($hostIp as docker reports it, $hostPort a number or ''). */
    public static function classify($hostIp, $hostPort, array $facts)
    {
        if ($hostPort === null || $hostPort === '' || (int) $hostPort <= 0) {
            return 'none';
        }
        $ip = trim((string) $hostIp, '[] ');
        if (self::isLoopback($ip)) {
            return !empty($facts['shared']) ? 'local-shared' : 'none';
        }
        $dropped = in_array((int) $hostPort, isset($facts['inbound_dropped']) ? $facts['inbound_dropped'] : [], true);
        if ($dropped && ($ip === '' || $ip === '0.0.0.0' || $ip === '::')) {
            return !empty($facts['shared']) ? 'local-shared' : 'none';
        }
        return 'public';
    }

    /** True for 127.0.0.0/8, ::1 and their IPv4-mapped forms. */
    public static function isLoopback($ip)
    {
        $ip = strtolower(trim((string) $ip, '[] '));
        if (strncmp($ip, '::ffff:', 7) === 0) {
            $ip = substr($ip, 7);
        }
        return $ip === '::1' || preg_match('/^127\.\d{1,3}\.\d{1,3}\.\d{1,3}$/', $ip) === 1;
    }

    /**
     * One record per published binding from inspect's NetworkSettings.Ports (running) or HostConfig.PortBindings
     * (stopped): container_port ("80/tcp"), host_ip, host_port, exposure. Unpublished ports are left out.
     */
    public static function ports(array $inspect, array $facts)
    {
        $map = isset($inspect['NetworkSettings']['Ports']) && is_array($inspect['NetworkSettings']['Ports']) && $inspect['NetworkSettings']['Ports']
            ? $inspect['NetworkSettings']['Ports']
            : (isset($inspect['HostConfig']['PortBindings']) && is_array($inspect['HostConfig']['PortBindings']) ? $inspect['HostConfig']['PortBindings'] : []);
        $out = [];
        foreach ($map as $containerPort => $bindings) {
            foreach (is_array($bindings) ? $bindings : [] as $b) {
                $ip = isset($b['HostIp']) ? (string) $b['HostIp'] : '';
                $port = isset($b['HostPort']) ? (string) $b['HostPort'] : '';
                if ($port === '') {
                    continue;
                }
                $out[] = ['container_port' => (string) $containerPort, 'host_ip' => $ip === '' ? '0.0.0.0' : $ip, 'host_port' => (int) $port,
                    'exposure' => self::classify($ip, $port, $facts)];
            }
        }
        return $out;
    }
}

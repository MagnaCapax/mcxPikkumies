<?php
/**
 * JellyfinLocate: where this user's Jellyfin answers and how it runs (README.md of this unit, "Where
 * Jellyfin is found"): --url, the PMSS media-stack install, a container on the user's own rootless
 * Docker (PMSS docker-install-lsio or any jellyfin image), then the default config directory.
 * Nothing here sends a credential; the base path is chosen by probing the public status endpoint.
 */
class JellyfinLocate
{
    /** PMSS etc/skel/install-media-stack.sh:1224-1226 (JELLYFIN_CONFIG_DIR, JELLYFIN_LOG_DIR). */
    const PMSS_CONFIG = '.config/jellyfin/config';
    const PMSS_LOG = '.config/jellyfin/log';
    /** jellyfin/jellyfin, lscr.io/linuxserver/jellyfin (PMSS scripts/lib/user/dockerInstallLsio.php:155, :229). */
    const IMAGE = '#(^|/)jellyfin(:|@|$)#i';

    /**
     * ['how', 'bases' (URLs to try, in order), 'port', 'listen', 'log_dir', 'pmss_status', 'restart' (MediaRestart plan)].
     */
    public static function find($home, $family, $uid, $url, MediaDocker $docker, ?array $tables = null)
    {
        if ($url !== '') {
            $u = MediaHttp::local($url);
            return self::found('url', [$url], $u['port'], '', MediaRestart::elsewhere($family, 'jellyfin', '--url'), $tables);
        }
        if ($family === 'pmss') {
            $conf = "$home/" . self::PMSS_CONFIG;
            // The port marker PMSS writes root-side (scripts/lib/mediaStackPorts.php:81), read first as the installer does
            // (install-media-stack.sh:1436-1448); then network.xml's ports (install-media-stack.sh:2252-2253).
            $port = MediaLocal::portFile("$home/.media-stack-port-jellyfin") ?: (int) MediaLocal::xmlTag("$conf/network.xml", 'InternalHttpPort')
                ?: (int) MediaLocal::xmlTag("$conf/network.xml", 'PublicHttpPort');
            if ($port > 0 && $port < 65536) {
                $prefix = MediaLocal::xmlTag("$conf/network.xml", 'BaseUrl') ?: MediaLocal::xmlTag("$conf/system.xml", 'BaseUrl');
                $f = self::found('native', self::bases($port, $prefix), $port, "$home/" . self::PMSS_LOG, ['how' => 'pmss-installer', 'home' => $home, 'app' => 'jellyfin'], $tables);
                $f['pmss_status'] = "$home/.media-stack-status.json";
                return $f;
            }
        }
        $c = $docker->find(self::IMAGE, 8096);
        if ($c !== null) {
            $f = self::found('docker', ['http://127.0.0.1:' . $c['host_port']], $c['host_port'], '', ['how' => 'docker', 'docker' => $docker, 'container' => $c], $tables);
            $f['listen'] = $c['host_ip'] . ':' . $c['host_port'];
            return $f;
        }
        $conf = "$home/.config/jellyfin"; // Jellyfin's default config directory; Whatbox keeps it there too (README.md, [UNVERIFIED])
        $port = (int) MediaLocal::xmlTag("$conf/network.xml", 'InternalHttpPort') ?: (int) MediaLocal::xmlTag("$conf/config/network.xml", 'InternalHttpPort');
        if ($port > 0 && $port < 65536) {
            $prefix = MediaLocal::xmlTag("$conf/network.xml", 'BaseUrl') ?: MediaLocal::xmlTag("$conf/config/network.xml", 'BaseUrl');
            return self::found('native', self::bases($port, $prefix), $port, '', MediaRestart::elsewhere($family, 'jellyfin', 'its config file'), $tables);
        }
        throw new CliError('Jellyfin was not found for this user', $family === 'pmss'
            ? 'on Pulsed Media the user installs it with the media-stack installer from the PMSS panel; a Jellyfin elsewhere: --url http://127.0.0.1:<port>'
            : 'give its address: --url http://127.0.0.1:<port>[/base path]');
    }

    /** Jellyfin 12 serves the API under a configured BaseUrl only; 10.11 also answers bare paths (install-media-stack.sh:1816-1838). */
    private static function bases($port, $prefix)
    {
        $prefix = '/' . trim((string) $prefix, '/');
        $bare = "http://127.0.0.1:$port";
        return $prefix === '/' || !preg_match('#^/[A-Za-z0-9._~/-]+$#', $prefix) ? [$bare] : [$bare . $prefix, $bare];
    }

    private static function found($how, array $bases, $port, $logDir, array $restart, $tables)
    {
        $listen = MediaLocal::listen($port, $tables);
        return ['how' => $how, 'bases' => $bases, 'port' => $port, 'listen' => $listen !== '' ? $listen : "127.0.0.1:$port", 'log_dir' => $logDir,
            'pmss_status' => '', 'restart' => $restart];
    }
}

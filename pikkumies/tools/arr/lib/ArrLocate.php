<?php
/**
 * ArrLocate: where this user's app keeps its config.xml, and what it says (tools/arr/README.md
 * "Where each app is found"). First match wins: an override in local/config.php, the PMSS installer's
 * lowercase folder, the Servarr default folder, the PMSS Docker (LinuxServer.io) folder. The first five
 * error causes are decided here, before the key is sent anywhere: not installed, config.xml empty or
 * invalid, no ApiKey (never started), turned off on PMSS (~/.<app>Disable), and a port (from config.xml
 * or from Docker) whose listener belongs to another account. lib/Http.php checks the owner again.
 */
class ArrLocate
{
    /**
     * $lenient (restart only): a missing ApiKey or Docker port is not an error, the app may be the thing
     * being started. $tables: Host::ownsListener's test seam (/proc/net/tcp and tcp6 contents).
     */
    public static function find(array $app, $home, $family, $uid, array $override = [], $lenient = false, ?array $tables = null)
    {
        $id = $app['id'];
        $tried = [];
        $skipped = '';
        foreach (self::candidates($app, $home, $family, $override) as $source => $path) {
            $sock = $source === 'docker' ? ArrShell::dockerSocket($uid) : '';
            if ($source === 'docker' && $sock === '') {
                $skipped = ArrLocate::skippedDocker($path, $home, $uid); // never read: said in "not installed"
                continue;
            }
            $tried[] = $path;
            $c = ArrConfig::read($path);
            if ($c === null) {
                continue;
            }
            $where = ['app' => $id, 'source' => $source, 'config' => $path, 'family' => $family, 'home' => $home, 'docker' => $sock,
                'uid' => (int) $uid];
            if (!$c['valid']) {
                throw new CliError("{$app['name']}'s config.xml is empty or invalid ({$c['bytes']} bytes, $path)", $family === 'pmss'
                    ? 'the user reruns `bash ~/install-media-stack.sh`: it keeps the bad file as config.xml.invalid-<time>'
                        . ' and writes a new one; this tool never edits config.xml'
                    : 'restore config.xml from a backup or reinstall the app; this tool never edits config.xml');
            }
            if ($c['key'] === '' && !$lenient) {
                throw new CliError("no ApiKey in $path: {$app['name']} has never started successfully",
                    'start it once (`restart`, or the provider\'s panel), then try again');
            }
            if ($family === 'pmss' && $source === 'pmss' && (file_exists("$home/.{$id}Disable") || is_link("$home/.{$id}Disable"))) {
                throw new CliError("{$app['name']} is turned off (~/.{$id}Disable exists)",
                    'the user turned it off in the PMSS panel; only the user turns it back on there');
            }
            $port = self::ownPort($app, $source, $c['port'], $where, $path, $lenient, $tables);
            if ($port === 0 && !$lenient) {
                throw new CliError("no <Port> in $path", 'the app writes it on start; on PMSS rerun `bash ~/install-media-stack.sh`');
            }
            return $where + ['port' => $port, 'urlBase' => $c['urlBase'], 'key' => $c['key']];
        }
        $looked = implode(', ', array_map(function ($p) use ($home) {
            return str_replace($home, '~', $p);
        }, $tried));
        $installer = $family === 'pmss' && (!isset($app['installer']) || $app['installer']);
        throw new CliError("{$app['name']} is not installed for this user (no config.xml in $looked)$skipped", $installer
            ? 'the user installs it with `bash ~/install-media-stack.sh`'
            : 'install it the way the provider documents, or name its config.xml in pikkumies/local/config.php:'
                . " 'arr' => ['$id' => ['config' => '/path/config.xml']]");
    }

    /** '; <folder> holds <file>, but ...' when a Docker folder has a config while Docker is not this user's own daemon. */
    public static function skippedDocker($path, $home, $uid)
    {
        return file_exists($path) ? '; ' . str_replace($home, '~', dirname($path)) . ' holds ' . basename($path)
            . ", but Docker is not this user's own daemon (no socket uid $uid owns at DOCKER_HOST or /run/user/$uid/docker.sock);"
            . ' not used' : '';
    }

    /**
     * This uid's home folder: $HOME when it is a folder this uid owns (as it always is when nobody changed it),
     * else the passwd entry (posix_getpwuid). An injected HOME=/home/other is never used: that folder is not ours.
     */
    public static function home($uid, $env = null)
    {
        $env = rtrim((string) ($env === null ? getenv('HOME') : $env), '/');
        if ($env !== '' && @is_dir($env) && @fileowner($env) === (int) $uid) {
            return $env;
        }
        $pw = function_exists('posix_getpwuid') ? @posix_getpwuid((int) $uid) : false;
        return is_array($pw) && isset($pw['dir']) && is_string($pw['dir']) ? rtrim($pw['dir'], '/') : '';
    }

    /**
     * The port to use: the configured one, or for Docker the host port published for it ($lenient: 0, not
     * looked up). Refused before any key is sent when another account owns its listener. Bazarr and Autobrr
     * use this too. $where needs uid and docker.
     */
    public static function ownPort(array $app, $source, $port, array $where, $path, $lenient = false, ?array $tables = null)
    {
        if ($source === 'docker') {
            $port = $lenient ? 0 : self::dockerPort($app['id'], $port, $where['docker']);
        }
        if ($port !== 0 && Host::ownsListener($port, (int) $where['uid'], $tables) === 'other') {
            $from = $source === 'docker' ? "published by the Docker container {$app['id']}" : "from $path";
            throw new CliError("port $port ($from) belongs to another account; not connecting",
                "it is not this user's {$app['name']}; never work around that");
        }
        return $port;
    }

    /** source => config.xml path, in the order they are tried. */
    public static function candidates(array $app, $home, $family, array $override = [])
    {
        $out = [];
        if (isset($override['config']) && is_string($override['config']) && $override['config'] !== '') {
            $out['override'] = $override['config'];
        }
        if ($family === 'pmss' && (!isset($app['installer']) || $app['installer'])) {
            $out['pmss'] = "$home/.config/{$app['id']}/config.xml"; // the installer's --data (install-media-stack.sh:2563-2565)
        }
        $out['servarr'] = "$home/.config/{$app['name']}/config.xml"; // AppFolderInfo.cs:26,36 (ApplicationData + AppName)
        $out['docker'] = "$home/docker/{$app['id']}/config/config.xml"; // PMSS dockerInstallLsio.php:179 (mounted at /config)
        return $out;
    }

    /**
     * The host port Docker publishes for the container's $containerPort, from `docker port <app> <port>/tcp`
     * against the user's own daemon. Only a whole number 1-65535 is accepted, the same on every line.
     */
    public static function dockerPort($id, $containerPort, $socket)
    {
        if ($containerPort === 0 || !ArrShell::has('docker')) {
            return 0;
        }
        list($code, $out) = ArrShell::run(['docker', 'port', $id, $containerPort . '/tcp'], ['DOCKER_HOST' => "unix://$socket"]);
        $ports = [];
        foreach (array_filter(array_map('trim', explode("\n", $out)), 'strlen') as $line) {
            if (!preg_match('/:([0-9]{1,5})$/', $line, $m) || (int) $m[1] < 1 || (int) $m[1] > 65535) {
                throw new CliError("`docker port $id` printed something that is not a port: " . substr($line, 0, 80),
                    'check the container with `docker ps`');
            }
            $ports[(int) $m[1]] = true;
        }
        if ($code !== 0 || count($ports) !== 1) {
            throw new CliError("the Docker container $id publishes " . ($ports ? 'several host ports' : "no host port for $containerPort")
                . ($code !== 0 ? ' (or is not running)' : ''), "start it with `docker start $id`, or check `docker port $id`");
        }
        return (int) key($ports);
    }
}

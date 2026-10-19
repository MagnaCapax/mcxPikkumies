<?php
/**
 * DockerExpose: `expose <app> --port <port>` (ADR-0034, amendment of 2026-10-19). On PMSS, puts a container's web
 * port behind the account's own web server at https://<server>/user-<user>/<app>/, inside the panel login, by writing
 * one fragment, ~/.lighttpd/custom.d/pikkumies-<app>.conf (the folder PMSS keeps for the customer's own fragments,
 * PMSS ADR-0032). `--public` uses /public-<user>/<app>/ instead: no panel login, the app's own login is all there is.
 *
 * Refused, with nothing written: a port this account does not listen on (Host::ownsListener: on a shared server
 * another account's port must never be proxied), a fragment already there, a name PMSS or the web server uses, a
 * folder of that name under ~/www (it would be shadowed), or a URL another fragment already handles. `lighttpd -t`
 * checks the config as the user before (it must already pass), then a private copy that includes the new fragment,
 * and only then is the fragment put in place and the real config checked again (a failure removes it). The web server is
 * not restarted here: the platform's lighttpd watchdog restarts it within about two minutes when a fragment is newer
 * than the running server (PMSS root.cron, checkLighttpdInstances.php), the same rule the doctrine keeps for the
 * Docker daemon. The panel login's Authorization header is not passed to the app (an image is someone else's code),
 * as PMSS does for its own qBittorrent fragment (PMSS ADR-0111). `expose <app> --remove` deletes only a fragment
 * this tool wrote (its first line says so) and prints the line that writes it again.
 * Seams (DockerCli's, plus): user (account name), listener_tables (Host::ownsListener), lighttpd_bin.
 */
class DockerExpose
{
    const APP_RE = '/^[a-z](?:[a-z0-9-]{0,30}[a-z0-9])?\z/';
    const MARK = '# pikkumies expose';
    /**
     * Paths PMSS or the web server already uses: template.lighttpd (console), lib/lighttpd/proxyFragments.php
     * (deluge, rclone, qbittorrent, transmission, invidious, apps), template.nginx-user (the media stack's apps),
     * etc/skel/www/toggleAppCatalog.php (slskd, copyparty, qui, scryer), and the aliases of template.lighttpd.
     */
    const RESERVED = ['console', 'rutorrent', 'deluge', 'rclone', 'qbittorrent', 'transmission', 'invidious', 'apps', 'sabnzbd', 'lidarr',
        'radarr', 'prowlarr', 'readarr', 'sonarr', 'jellyfin', 'komga', 'autobrr', 'whisparr', 'slskd', 'copyparty', 'qui', 'scryer',
        'public', 'owncloud', 'webdav', 'error'];
    const TAKES_EFFECT = 'within about two minutes: the platform restarts your web server when a fragment is newer than it';

    public static function expose(DockerCli $d, array $args, array $opt)
    {
        if (count($args) !== 1 || !preg_match(self::APP_RE, (string) $args[0])) {
            throw new CliError('expose takes one name: lowercase letters, digits and -, up to 32; nothing was changed', 'e.g. expose web --port 38001');
        }
        $app = (string) $args[0];
        if (in_array($app, self::RESERVED, true)) {
            throw new CliError("nothing changed: $app is a path the platform or the web server already uses", 'pick another name, e.g. my-' . $app);
        }
        if (!$d->pmss()) {
            throw new CliError('nothing changed: expose writes a fragment for the PMSS web server, and this server is not PMSS', 'publish the port with launch (`--public` for the internet) or set up a web server yourself');
        }
        $user = isset($d->seams['user']) ? (string) $d->seams['user'] : Detect::userName($d->uid);
        if (!preg_match('/^[a-z_][a-z0-9_-]{0,31}\z/', $user)) {
            throw new CliError('nothing changed: the account name could not be read', 'run it as the account itself');
        }
        $file = $d->home . '/.lighttpd/custom.d/pikkumies-' . $app . '.conf';
        if (!empty($opt['remove'])) {
            if (isset($opt['port']) || !empty($opt['public']) || !empty($opt['keep-path'])) {
                throw new CliError('--remove takes only the name; nothing was changed', "expose $app --remove");
            }
            return self::remove($d, $app, $file);
        }
        $port = isset($opt['port']) && is_string($opt['port']) && ctype_digit($opt['port']) ? (int) $opt['port'] : 0;
        if ($port < 1 || $port > 65535) {
            throw new CliError('--port takes the host port the container publishes (1-65535); nothing was changed', '`list` shows each container\'s host_port');
        }
        $public = !empty($opt['public']);
        $keep = !empty($opt['keep-path']);
        $owner = Host::ownsListener($port, $d->uid, isset($d->seams['listener_tables']) ? $d->seams['listener_tables'] : null);
        if ($owner !== 'own') {
            throw new CliError('nothing changed: ' . ['none' => "nothing of this account listens on port $port", 'other' => "port $port belongs to another account; it is never proxied",
                'unknown' => "who listens on port $port cannot be read (/proc/net)"][$owner], 'start the container first; `list` shows its host_port');
        }
        $folder = $d->home . '/www/' . ($public ? 'public/' : '') . $app;
        if (file_exists($folder) || is_link($folder)) {
            throw new CliError("nothing changed: $folder is already served at that address and would be hidden", 'pick another name');
        }
        if (file_exists($file) || is_link($file)) {
            throw new CliError("nothing changed: $file is already there", "`expose $app --remove` first, then expose it again");
        }
        list($bin, $conf) = self::checker($d);
        $base = $public ? "/$app" : "/user-$user/$app";
        self::conflict($d, $base);
        $before = self::test($bin, $conf);
        if ($before !== null) {
            throw new CliError("nothing changed: the web server's config already fails its check, before any change: $before", 'tell the user and support; a fragment in ~/.lighttpd/custom.d may be broken');
        }
        $text = self::fragment($user, $app, $port, $public, $keep);
        // First a copy of the config that includes the fragment from a private folder, so the web server's watchdog
        // never sees a fragment that has not passed; then the real one.
        $tmp = rtrim((string) $d->seams['local_dir'], '/') . '/containers/expose-check-' . getmypid();
        DockerDiagnose::writeFile("$tmp/fragment.conf", $text);
        DockerDiagnose::writeFile("$tmp/lighttpd.conf", (string) @file_get_contents($conf) . "\ninclude \"$tmp/fragment.conf\"\n");
        $err = self::test($bin, "$tmp/lighttpd.conf");
        @unlink("$tmp/fragment.conf");
        @unlink("$tmp/lighttpd.conf");
        @rmdir($tmp);
        if ($err === null) {
            DockerDiagnose::writeFile($file, $text);
            $err = self::test($bin, $conf);
            if ($err !== null) {
                @unlink($file);
            }
        }
        if ($err !== null) {
            throw new CliError("nothing changed: the web server's config check fails with the fragment: $err", 'tell the user; the fragment text is in this tool\'s code (DockerExpose::fragment)');
        }
        return [['kind' => 'expose', 'app' => $app, 'url_path' => ($public ? "/public-$user/" : "/user-$user/") . "$app/", 'port' => $port, 'listener' => 'own',
            'access' => $public ? 'public' : 'panel-login', 'path' => $keep ? 'kept' : 'stripped', 'fragment' => $file, 'config_check' => 'ok', 'takes_effect' => self::TAKES_EFFECT,
            'note' => ($public ? 'anyone on the internet can open it: the app\'s own login must be set first' : 'behind the panel login; the panel login itself is not passed on to the app')
                . ($keep ? '' : '; the app sees itself at / (set its base URL and use --keep-path if its pages break)'),
            'undo' => self::line([$app, '--remove'])]];
    }

    /** --remove: only this tool's fragment for $app; prints the line that writes it again. */
    private static function remove(DockerCli $d, $app, $file)
    {
        clearstatcache(true, $file);
        $st = @lstat($file);
        if ($st === false) {
            throw new CliError("nothing changed: there is no fragment for $app ($file)", 'nothing to remove');
        }
        $head = ($st['mode'] & 0170000) === 0100000 && (int) $st['uid'] === $d->uid ? (string) @file_get_contents($file, false, null, 0, 200) : '';
        if (!preg_match('/^' . preg_quote(self::MARK, '/') . ' app=([a-z0-9-]+) port=(\d+) access=(panel|public) path=(strip|keep)\n/', $head, $m) || $m[1] !== $app) {
            throw new CliError("nothing changed: $file was not written by this tool", 'it is the user\'s own fragment; leave it, or let the user edit it');
        }
        list($bin, $conf) = self::checker($d);
        if (!@unlink($file)) {
            throw new CliError("cannot remove $file", 'check that ~/.lighttpd/custom.d is writable');
        }
        $again = array_merge([$app, '--port', $m[2]], $m[3] === 'public' ? ['--public'] : [], $m[4] === 'keep' ? ['--keep-path'] : []);
        return [['kind' => 'expose', 'app' => $app, 'removed' => true, 'fragment' => $file, 'config_check' => self::test($bin, $conf) === null ? 'ok' : 'failed',
            'takes_effect' => self::TAKES_EFFECT, 'again' => self::line($again)]];
    }

    /** The fragment, in the form PMSS writes its own (lib/lighttpd/proxyFragments.php); the first line is the marker --remove reads. */
    public static function fragment($user, $app, $port, $public, $keep)
    {
        $base = $public ? "/$app" : "/user-$user/$app";
        $shown = ($public ? "/public-$user/" : "/user-$user/") . "$app/";
        $text = self::MARK . " app=$app port=$port access=" . ($public ? 'public' : 'panel') . ' path=' . ($keep ? 'keep' : 'strip') . "\n"
            . "# Written by Pikkumies (docker expose): $shown -> 127.0.0.1:$port" . ($public ? ', no panel login' : ', behind the panel login') . ".\n"
            . '# Undo: ' . self::line([$app, '--remove']) . "\n\n"
            . '$HTTP["url"] =~ "^' . $base . "(\$|/)\" {\n";
        if (!$public) {
            $text .= '  setenv.set-request-header = ( "Authorization" => "" )' . "\n";
        }
        $text .= "  proxy.server = ( \"\" => ( (\n    \"host\" => \"127.0.0.1\",\n    \"port\" => $port\n  ) ) ),\n"
            . "  proxy.forwarded = ( \"for\" => 1,\n                      \"host\" => 1,\n                      \"by\" => 1\n  ),\n"
            . "  proxy.header = (\n      \"upgrade\" => \"enable\"";
        if (!$keep) {
            $text .= ",\n      \"map-urlpath\" => (\n         \"$base/\"  => \"/\",\n         \"$base\" => \"\"\n       )";
        }
        return $text . "\n  )\n}\n";
    }

    /** Refuses when another fragment (or ~/.lighttpd/custom) already handles $base, or cannot be read to tell. */
    private static function conflict(DockerCli $d, $base)
    {
        $dir = $d->home . '/.lighttpd/custom.d';
        if (is_link($dir)) {
            throw new CliError("nothing changed: $dir is a link", 'it must be a folder of this account');
        }
        $files = array_merge(is_dir($dir) ? (array) glob("$dir/*.conf") : [], is_file($d->home . '/.lighttpd/custom') ? [$d->home . '/.lighttpd/custom'] : []);
        foreach ($files as $f) {
            $text = is_readable($f) ? @file_get_contents($f) : false;
            if (!is_string($text)) {
                throw new CliError("nothing changed: $f cannot be read, so whether it already handles $base is unknown", 'tell the user');
            }
            // The path as a lighttpd condition or a map entry writes it: right after ^ or a quote, ending at a non-name character.
            if (preg_match('#(?<=[\^"])' . preg_quote($base, '#') . '(?![A-Za-z0-9_.-])#', $text)) {
                throw new CliError("nothing changed: $f already handles $base", 'pick another name');
            }
        }
    }

    /** [lighttpd binary, the account's config]; refused when the config cannot be checked (nothing is written unchecked). */
    private static function checker(DockerCli $d)
    {
        $bin = isset($d->seams['lighttpd_bin']) ? (string) $d->seams['lighttpd_bin'] : ToolProc::which('lighttpd');
        if ($bin === null && is_executable('/usr/sbin/lighttpd')) {
            $bin = '/usr/sbin/lighttpd';
        }
        $conf = $d->home . '/.lighttpd.conf';
        if ($bin === null || !is_executable($bin) || !is_file($conf)) {
            throw new CliError('nothing changed: the web server\'s config cannot be checked here (no lighttpd, or no ~/.lighttpd.conf)', 'tell the user; support can say whether the web server is set up for this account');
        }
        return [$bin, $conf];
    }

    /** `lighttpd -t -f <config>` as the user: null when it passes, else its first error line. */
    private static function test($bin, $conf)
    {
        ToolProc::need('timeout', 'coreutils provides it');
        $r = ToolProc::run(['timeout', '20', $bin, '-t', '-f', $conf]);
        if ($r['code'] === 0) {
            return null;
        }
        $lines = preg_split('/\r?\n/', trim($r['err'] . "\n" . $r['out']));
        $first = substr((string) $lines[0], 0, Cli::TEXT_MAX);
        return $first !== '' ? $first : 'exit ' . $r['code'];
    }

    private static function line(array $words)
    {
        return implode(' ', array_map(['DockerLaunch', 'word'], array_merge(['php', __DIR__ . '/docker.php', 'expose'], $words)));
    }
}

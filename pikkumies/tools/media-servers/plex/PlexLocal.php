<?php
/**
 * PlexLocal: the Plex data directory on disk (its Logs/ folder and Preferences.xml). Plex keeps it in
 * "$PLEX_MEDIA_SERVER_APPLICATION_SUPPORT_DIR/Plex Media Server", and that variable defaults to the running user's
 * "$HOME/Library/Application Support" (Plex support article 202915258: "$PLEX_HOME/Library/Application Support/
 * Plex Media Server/"; the pms-docker image's /etc/services.d/plex/run:5 and the package's plexmediaserver.service:12
 * set it the same way). A container keeps it under its /config mount; the PMSS recipe mounts ~/docker/plex/config
 * there (PMSS docs/linuxserver.io.md, "Plex"). Only the user's own folders are looked in, never /var/lib.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class PlexLocal
{
    const TAIL = '/Plex Media Server';
    const SUPPORT = '/Library/Application Support';
    /** The server's own log; the scanner, tuner and crash uploader write others next to it. */
    const MAIN_LOG = 'Plex Media Server.log';

    /**
     * The folders to look in, in order: the container's /config mount when Plex was found on Docker (only that one),
     * else $env (PLEX_MEDIA_SERVER_APPLICATION_SUPPORT_DIR), ~/Library/Application Support and the PMSS recipe's mount.
     */
    public static function dirs($dockerConfig, $home, $env)
    {
        if ($dockerConfig !== '') {
            return [$dockerConfig . self::SUPPORT . self::TAIL];
        }
        $out = [];
        if (is_string($env) && strncmp($env, '/', 1) === 0) {
            $out[] = rtrim($env, '/') . self::TAIL;
        }
        if ($home !== '' && strncmp($home, '/', 1) === 0) {
            $out[] = $home . self::SUPPORT . self::TAIL;
            $out[] = "$home/docker/plex/config" . self::SUPPORT . self::TAIL;
        }
        return array_values(array_unique($out));
    }

    /** The first of $dirs holding the file $rel ('' when none). */
    public static function first(array $dirs, $rel)
    {
        foreach ($dirs as $d) {
            if (is_file("$d/$rel")) {
                return "$d/$rel";
            }
        }
        return '';
    }

    /** [file, lines]: the last $lines lines of the log named $name ('' = MAIN_LOG) in Logs/ of the first folder that has it. */
    public static function log(array $dirs, $name, $lines)
    {
        $name = $name === '' ? self::MAIN_LOG : (substr($name, -4) === '.log' ? $name : "$name.log");
        if (!preg_match('#^[A-Za-z0-9][A-Za-z0-9 ._()-]{0,99}\.log$#', $name)) {
            throw new CliError('--log takes a log file name from the Plex Logs folder, e.g. "Plex Media Scanner"; nothing was read',
                'the default is ' . self::MAIN_LOG);
        }
        $file = self::first($dirs, "Logs/$name");
        if ($file !== '') {
            return ['file' => $file, 'lines' => MediaLocal::tail($file, $lines)];
        }
        foreach ($dirs as $d) {
            $there = array_map(['PathName', 'base'], (array) glob("$d/Logs/*.log"));
            if ($there) {
                sort($there);
                throw new CliError("no \"$name\" in $d/Logs", 'logs there: ' . implode(', ', array_slice($there, 0, 12))
                    . (count($there) > 12 ? ', ...' : '') . '; choose one with --log <name>');
            }
        }
        $tried = $dirs ? implode(', ', array_map(function ($d) {
            return "$d/Logs";
        }, $dirs)) : 'no folder (HOME is not set)';
        throw new CliError("no Plex log files found; looked in: $tried",
            'Plex keeps its logs in the Logs folder of its data directory; if it runs elsewhere, the Plex dashboard can download them');
    }
}

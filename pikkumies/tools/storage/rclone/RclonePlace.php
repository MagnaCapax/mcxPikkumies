<?php
/**
 * RclonePlace: the sources and destinations this unit lets rclone touch (README.md here): "remote:path" with a remote
 * name, or an absolute local path inside the home. Never an option, never a connection string (":sftp,pass=...:"
 * carries credentials on the command line).
 */
class RclonePlace
{
    /** $s checked as a place; a local path must be inside $home ($mustExist: it exists; otherwise its parent does). */
    public static function of($s, $mustExist, $home)
    {
        $s = (string) $s;
        if ($s === '' || $s[0] === '-' || $s[0] === ':' || strpos($s, "\n") !== false) {
            throw new CliError("not a place rclone may use: $s", 'use remote:path with a remote from `list`, or a full local path;'
                . ' connection strings are refused because they carry passwords');
        }
        if ($s[0] !== '/') {
            if (!preg_match('/^[\w.+@ -]+:/', $s)) {
                throw new CliError("not remote:path and not a full local path: $s", 'remote names come from `list`; local paths'
                    . ' start with /');
            }
            return $s;
        }
        if ($mustExist || file_exists($s)) {
            return HomePath::existing($s, $home);
        }
        $parent = realpath(dirname($s));
        if ($parent === false || !HomePath::inside($parent, $home)) {
            throw new CliError("$s is not inside the home directory", 'local paths must be under ' . $home);
        }
        return $parent . '/' . basename($s);
    }

    public static function isLocal($place)
    {
        return $place !== '' && $place[0] === '/';
    }
}

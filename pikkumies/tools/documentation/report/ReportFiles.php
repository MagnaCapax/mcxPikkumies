<?php
/**
 * ReportFiles: where the report unit reads and writes. Input: any regular file the account can read (at most 8 MiB).
 * Output: only ~/pikkumies/local/reports/ (0700, never a symlink), each file 0600, written through a temp file and a
 * rename so a half-written report is never left behind. --out takes a file name, not a path: the unit never writes
 * outside its own directory; copy the file where you want it.
 */
require_once dirname(__DIR__, 3) . '/lib/PathName.php';

class ReportFiles
{
    /** Pages larger than this are not handed to Chromium: a report this big is raw data, and printing it costs minutes. */
    const MAX_HTML = 20971520;

    /** '' when $html may be printed, else why not. */
    public static function printable($html)
    {
        $n = strlen((string) $html);
        return $n > self::MAX_HTML ? 'the page is ' . round($n / 1048576, 1) . ' MiB, over the 20 MiB print limit: summarise first (fewer rows, bucketed series)' : '';
    }

    public static function dir($local)
    {
        $dir = "$local/reports";
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new CliError('cannot create ' . Config::shown($dir), 'check that the pikkumies/local directory is yours and writable');
        }
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        if (is_link($dir) || @fileowner($dir) !== $uid) {
            throw new CliError(Config::shown($dir) . ' is a symlink or not owned by this account; nothing was written', 'remove it; the report tool creates it again');
        }
        @chmod($dir, 0700);
        return $dir;
    }

    /** "$dir/<name>": --out when given (a plain file name with the extension), else <slug of title>-<UTC time>.<ext>. */
    public static function target($dir, $out, $title, $ext, $now)
    {
        if ($out !== null && $out !== true && $out !== '') {
            $name = (string) $out;
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,120}$/', $name) || strpos($name, '..') !== false) {
                throw new CliError("--out $name: give a file name (letters, digits, . _ -), not a path", 'reports are written to ' . Config::shown($dir) . '; copy the file elsewhere afterwards');
            }
            return "$dir/" . (substr($name, -strlen(".$ext")) === ".$ext" ? $name : "$name.$ext");
        }
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $title)), '-');
        return "$dir/" . ($slug !== '' ? substr($slug, 0, 60) : 'report') . '-' . gmdate('Ymd-His', $now) . ".$ext";
    }

    public static function write($path, $content)
    {
        if (is_link($path)) {
            throw new CliError(Config::shown($path) . ' is a symlink; nothing was written', 'remove it or choose another --out name');
        }
        $tmp = dirname($path) . '/.' . PathName::base($path) . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $content) !== strlen($content) || !@chmod($tmp, 0600) || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new CliError('could not write ' . Config::shown($path), 'check the free space and the permissions of ' . Config::shown(dirname($path)));
        }
    }

    /** The text of a report.json given on the command line. */
    public static function read($path)
    {
        if ($path === null || $path === '') {
            throw new CliError('no report.json given', 'pass the file: render <report.json>; `example` writes one to start from');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new CliError("$path is not a readable file", 'give the path of a report JSON file; `example` writes one');
        }
        if (filesize($path) > ReportKit::MAX_BYTES) {
            throw new CliError("$path is larger than 8 MiB", 'a report holds figures, not raw data: summarise first');
        }
        return (string) file_get_contents($path);
    }
}

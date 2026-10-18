<?php
/**
 * FfmpegVerbs: `probe`, `convert` and `jobs` of the ffmpeg unit (ADR-0045, USAGE.md). `hw` is
 * FfmpegHw.php; the shared ffmpeg/ffprobe runner is tools/media/lib/Ffmpeg.php.
 */
class FfmpegVerbs
{
    const DEFAULT_HOURS = 6;
    const MAX_HOURS = 20; // the host may stop ffmpeg after about a day; this stays well under that
    const OWN_NAMES = ['ffmpeg', 'ffprobe'];

    public static function probe(array $args, array $opt)
    {
        if (!isset($args[0])) {
            throw new CliError('probe needs a file', 'php ffmpeg.php probe <file>');
        }
        $home = HomePath::home();
        $file = Ffmpeg::inputFile($args[0], $home);
        $p = Ffmpeg::probe($file);
        $p['verdict'] = Ffmpeg::verdict($p, $file);
        return $p;
    }

    public static function convert(array $args, array $opt)
    {
        if (!isset($args[0], $args[1]) || empty($opt['preset'])) {
            throw new CliError('convert needs <in> <out> --preset P', 'presets: ' . implode(', ', array_keys(Ffmpeg::PRESETS)));
        }
        $home = HomePath::home();
        $in = Ffmpeg::inputFile($args[0], $home);
        $force = !empty($opt['force']);
        $out = self::outputPath($args[1], $home, $force);
        if ($out === $in) {
            throw new CliError('the output is the same file as the input; nothing converted', 'name a different output file');
        }
        $hours = isset($opt['max-hours']) ? max(1, min(self::MAX_HOURS, (int) $opt['max-hours'])) : self::DEFAULT_HOURS;
        $bin = Ffmpeg::ffmpegPath();
        if ($bin === null) {
            throw new CliError('ffmpeg is not installed on this machine (not found in PATH); nothing was done',
                'the provider or this account installs ffmpeg');
        }
        $need = @filesize($in);
        $free = Quota::fields($home)['quota_free_bytes'];
        if ($need !== false && $free !== null && $need > $free) {
            throw new CliError("not enough free quota: the input is " . $need . ' bytes, ' . $free . ' bytes free; nothing converted',
                'free space first (storage stats), or convert on a machine with more room');
        }
        $started = time();
        $r = Ffmpeg::convert($bin, $in, $out, $opt['preset'], $hours * 3600, $force);
        if ($r['exists']) {
            // ffmpeg's own overwrite refusal exits 0; the existing file is the user's, so it is left alone
            throw new CliError("ffmpeg refused to overwrite $out; nothing converted", 'add --force to overwrite it');
        }
        if ($r['code'] !== 0 || !is_file($out)) {
            @unlink($out);
            throw new CliError('ffmpeg failed' . ($r['stopped'] ? ' (stopped: time limit reached)' : '') . ($r['err'] !== '' ? ': ' . $r['err'] : ''),
                'try a shorter input, a different preset, or raise --max-hours');
        }
        $size = @filesize($out);
        return ['in' => $in, 'out' => $out, 'preset' => $opt['preset'], 'output_bytes' => $size === false ? null : $size,
            'seconds' => time() - $started];
    }

    public static function jobs(array $args, array $opt)
    {
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        $out = [];
        foreach ((array) glob('/proc/[0-9]*', GLOB_ONLYDIR) as $dir) {
            $pid = (int) basename($dir);
            if (@fileowner($dir) !== $uid) {
                continue;
            }
            $comm = trim((string) @file_get_contents("$dir/comm"));
            if (!in_array($comm, self::OWN_NAMES, true)) {
                continue;
            }
            $out[] = ['pid' => $pid, 'command' => $comm, 'elapsed_seconds' => self::uptime($dir)];
        }
        return $out;
    }

    /** Seconds since the process at $procDir started (stat field 22, clock ticks of 100/s); null when unreadable. */
    private static function uptime($procDir)
    {
        $stat = @file_get_contents("$procDir/stat");
        $up = @file_get_contents('/proc/uptime');
        if (!is_string($stat) || !is_string($up)) {
            return null;
        }
        $f = explode(' ', substr($stat, strrpos($stat, ')') + 2));
        return isset($f[19]) ? max(0, (int) ((float) $up - ((int) $f[19]) / 100)) : null;
    }

    /**
     * The output path: realpath'd parent inside $home, the file itself not yet existing unless $force,
     * and never a symlink (a dangling one passes file_exists() as false, and ffmpeg would follow
     * either kind out of the home); the caller refuses in==out.
     */
    private static function outputPath($path, $home, $force)
    {
        if (!is_string($path) || $path === '' || $path[0] !== '/') {
            throw new CliError('--out must be a full path', 'e.g. ' . $home . '/converted.mp4');
        }
        $dir = @realpath(dirname($path));
        if ($dir === false || !is_dir($dir) || !HomePath::inside($dir, $home)) {
            throw new CliError("the output directory does not exist inside the home: $path", 'create the directory first, or name one under ' . $home);
        }
        $name = basename($path);
        $real = $dir . '/' . $name;
        if ($name === '.' || $name === '..' || is_link($real)) {
            throw new CliError("$real is a symlink or not a file name; nothing converted", 'name a new file under ' . $home);
        }
        if (file_exists($real) && !$force) {
            throw new CliError("$real already exists; nothing converted", 'add --force to overwrite it');
        }
        return $real;
    }
}

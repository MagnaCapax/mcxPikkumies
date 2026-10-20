<?php
/**
 * RffmpegState: where this unit's own files live -- always under pikkumies/local (AGENTS.md "State is
 * never tracked"; SPEC.md "Temp files under ~/pikkumies/local/state/tmp/, never /tmp"), never a
 * system path -- and the one state file that records exactly what install/add-host created, so
 * `remove` undoes exactly that and nothing else (SPEC.md "rffmpeg" section).
 */
class RffmpegState
{
    public static function base($localDir)
    {
        return rtrim($localDir, '/') . '/state/rffmpeg';
    }

    /**
     * Every path this unit ever writes to, in one place, so install/add-host/remove never hand-build
     * one. `transcode_*` is a SEPARATE tree whose own path never contains the substring "rffmpeg"
     * (Review fixes 2, D2): the pinned script's own `__main__` reads `"rffmpeg" in cmd_name` where
     * cmd_name is sys.argv[0] -- the INVOKED path, not the resolved symlink target -- so the path
     * Jellyfin is told to call must never contain it, while `script`/`base` legitimately do (that is
     * what makes `init`/`add`/`status` dispatch to the control CLI instead of transcode mode).
     */
    public static function paths($localDir)
    {
        $base = self::base($localDir);
        $transcode = rtrim($localDir, '/') . '/state/gpu-transcode';
        return [
            'base'                  => $base,
            'venv'                  => "$base/venv",
            'script'                => "$base/rffmpeg",
            'config'                => "$base/rffmpeg.yml",
            'db_dir'                => "$base/db",
            'run_dir'               => "$base/run",
            'log_dir'               => "$base/log",
            'wrappers'              => "$base/wrappers",
            'state_file'            => "$base/state.json",
            'ssh_wrapper'           => "$base/ssh-wrapper.php",
            'requirements'          => "$base/requirements.txt",
            'transcode_base'        => $transcode,
            'transcode_ffmpeg'      => "$transcode/ffmpeg",
            'transcode_ffprobe'     => "$transcode/ffprobe",
            'transcode_target_ffmpeg'  => "$transcode/.target-ffmpeg",
            'transcode_target_ffprobe' => "$transcode/.target-ffprobe",
        ];
    }

    /** The install/add-host record, never guessed: read() returns what was written, or an empty default. */
    public static function read($localDir)
    {
        $file = self::paths($localDir)['state_file'];
        $data = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
        if (!is_array($data)) {
            $data = [];
        }
        return $data + ['installed' => false, 'script_sha256' => null, 'script_commit' => null, 'deps_frozen' => [], 'hosts' => []];
    }

    public static function write($localDir, array $state)
    {
        $file = self::paths($localDir)['state_file'];
        if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0700, true)) {
            throw new CliError('cannot create ' . dirname($file), 'check that pikkumies/local is writable by this account');
        }
        $json = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $tmp = $file . '.tmp' . getmypid();
        if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new CliError("cannot write $file", 'check that the state directory is writable');
        }
    }
}

<?php
/**
 * RffmpegPlan: `plan --gpu <nick> [--mount <path>]` -- read-only, never throws for anything the
 * operator can fix (a missing GPU nick, no --allow-shell, no ffmpeg on the GPU host, an invisible
 * mount): every gap becomes a step in the returned record instead, so plan always answers with the
 * exact next steps (SPEC.md "rffmpeg": "prints the exact steps"). It only refuses genuine misuse
 * (no --gpu at all).
 */
class RffmpegPlan
{
    public static function run(RffmpegServices $gw, array $args, array $opt, $home, $uid)
    {
        if (empty($opt['gpu']) || !is_string($opt['gpu'])) {
            throw new CliError('plan needs --gpu <nick> (a service from the Services list)', 'php rffmpeg.php plan --gpu mygpu');
        }
        $nick = $opt['gpu'];
        $ready = true;
        $steps = [];

        $jf = RffmpegLocal::find('jellyfin', $home, $uid);
        $eb = RffmpegLocal::find('emby', $home, $uid);
        if (!$jf['found'] && !$eb['found']) {
            $ready = false;
            $steps[] = 'install Jellyfin or Emby first (seedbox-apps skill: "Install Media Stack"); rffmpeg has nothing to delegate transcodes for yet';
        }

        $svc = null;
        try {
            $svc = $gw->shellService($nick, $opt);
        } catch (CliError $e) {
            $ready = false;
            $steps[] = $e->getMessage() . ' -- ' . $e->hint;
        }

        $remote = ['probed' => false];
        if ($svc !== null) {
            $remote = RffmpegRemote::probe($gw, $nick) + ['probed' => true];
            if ($remote['ffmpeg_path'] === '' || $remote['ffprobe_path'] === '') {
                $ready = false;
                $steps[] = "install ffmpeg (and ffprobe) on $nick ({$svc['host']}): none of the usual paths were found there";
            }
            if (!$remote['gpu_usable']) {
                $ready = false;
                $steps[] = "fix the GPU on $nick: {$remote['gpu_gap']}";
            }
        }

        $mount = isset($opt['mount']) && is_string($opt['mount']) ? rtrim($opt['mount'], '/') : '';
        $mountCheck = ['checked' => false];
        if ($svc !== null && $mount !== '') {
            $mountCheck = RffmpegRemote::mountVisible($gw, $nick, $mount);
            if (!$mountCheck['visible']) {
                $ready = false;
                $steps[] = "the mount $mount is not visible to a plain SSH-launched command on $nick -- this is the systemd --user mount gotcha"
                    . ' (a mount started as a systemd user service is invisible to a non-interactive SSH login, which is how rffmpeg runs the'
                    // Review fixes 2, item 9: escapeshellarg the host/mount in every suggested command (the agent may run the suggested line).
                    . ' remote ffmpeg); confirm it yourself with `ssh ' . escapeshellarg($svc['host']) . ' ls ' . escapeshellarg($mount)
                    . '`, then start the mount a way the SSH session sees';
            }
        } elseif ($svc !== null) {
            $ready = false;
            $steps[] = "give --mount <path> to check the same-path mount on $nick (the rclone sftp mount of this box's home there); without it"
                . ' Jellyfin and this box are not at the same path on the GPU host and every remote transcode will fail to find its input';
        }

        if ($ready) {
            $steps = ['install rffmpeg here (if not done already): php rffmpeg.php install',
                "wire in $nick: php rffmpeg.php add-host $nick --allow-shell --mount " . escapeshellarg($mount)];
        }

        return [['gpu' => $nick, 'ready' => $ready,
            'local_media_server' => $jf['found'] ? 'jellyfin' : ($eb['found'] ? 'emby' : null),
            'local_note' => $jf['found'] ? $jf['note'] : ($eb['found'] ? $eb['note'] : $jf['note']),
            'remote' => $remote, 'mount_check' => $mountCheck, 'steps' => $steps]];
    }
}

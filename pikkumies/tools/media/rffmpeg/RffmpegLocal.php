<?php
/**
 * RffmpegLocal: is there a Jellyfin or Emby for THIS account to delegate transcodes from (plan's
 * first check). Presence only, no credential: the same markers tools/media-servers/jellyfin/
 * JellyfinLocate.php and tools/media-servers/emby/emby.php already use (the PMSS media-stack config
 * path, or a container on this account's own rootless Docker) read here directly, rather than pulling
 * in the auth-heavy Mediabrowser/MediabrowserServer chain those units need for real API calls -- plan
 * only needs a yes/no and where it was found, never a session or a credential.
 */
require_once dirname(__DIR__, 3) . '/lib/Host.php';
require_once dirname(__DIR__, 2) . '/media-servers/lib/MediaDocker.php';

class RffmpegLocal
{
    /** tools/media-servers/jellyfin/JellyfinLocate.php PMSS_CONFIG and IMAGE; emby/emby.php's Docker image regex and port. */
    const JELLYFIN_PMSS_CONFIG = '.config/jellyfin/config';
    const JELLYFIN_IMAGE = '#(^|/)jellyfin(:|@|$)#i';
    const EMBY_IMAGE = '#(^|/)(emby|embyserver)(:|@|$)#i';
    const MEDIA_PORT = 8096;

    /** ['found' => bool, 'how' => string, 'note' => string] for one server name ('jellyfin'|'emby'). */
    public static function find($name, $home, $uid, $pmssDir = '/etc/seedbox/config', $dockerSock = null)
    {
        $family = Host::family($pmssDir);
        if ($name === 'jellyfin' && $family === 'pmss' && is_dir(rtrim($home, '/') . '/' . self::JELLYFIN_PMSS_CONFIG)) {
            return ['found' => true, 'how' => 'pmss-installer', 'note' => 'PMSS media-stack install at ~/' . self::JELLYFIN_PMSS_CONFIG];
        }
        $docker = new MediaDocker($uid, $dockerSock);
        $image = $name === 'jellyfin' ? self::JELLYFIN_IMAGE : self::EMBY_IMAGE;
        $c = $docker->find($image, self::MEDIA_PORT);
        if ($c !== null) {
            return ['found' => true, 'how' => 'docker', 'note' => "container {$c['name']} ({$c['image']}) on 127.0.0.1:{$c['host_port']}"];
        }
        if ($name === 'jellyfin' && is_dir(rtrim($home, '/') . '/.config/jellyfin')) {
            return ['found' => true, 'how' => 'native', 'note' => 'a Jellyfin config directory exists at ~/.config/jellyfin (not the PMSS media-stack path)'];
        }
        return ['found' => false, 'how' => '', 'note' => "no $name found (no PMSS media-stack install, no container on this account's own rootless Docker)"];
    }
}
